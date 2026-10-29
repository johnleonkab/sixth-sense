# Épica · Triaje agéntico de Slack

**Qué es:** Slack deja de entrar como «importante sí/no». Cada mensaje nuevo se decide como una acción —responde tú, le toca a otro, conviértelo en tarea, solo para saber, ignóralo— con la política de atención de esa persona, aprendida de cómo ha respondido y corregida cuando ella lo dice.

**Por qué:** el clasificador actual (`LlmIntentClassifier`) pide un booleano y, en la duda, no interrumpe. Eso invierte el coste: perderse una pregunta del responsable pesa más que un aviso de más. Tampoco distingue «me toca» de «le toca a otro», ni recuerda a quién esta persona contesta siempre.

**De dónde sale:** [`docs/Pilares de producto triaje agéntico de Slack.md`](Pilares%20de%20producto%20triaje%20ag%C3%A9ntico%20de%20Slack.md). Esta épica implementa los siete pilares. El orden sigue la prioridad del propio documento: primero la política, el ownership y no perder lo importante; el borrador con contexto viene cuando el triaje ya se puede medir.

**Relación con lo que hay:** el intent sigue siendo la unidad visible. `SeenMessage` sigue evitando reprocesar. Leer sigue siendo libre y escribir sigue pidiendo un «sí», hasta que una categoría sube de autonomía (fase 6) y la persona lo ha aceptado. Gmail y Jira no cambian de contrato en esta épica: si llegan aquí, se traducen a la acción `reply` o `ignore` con la regla de hoy.

---

## Cómo está hoy

`SlackWatcher` trae mensajes directos, grupos y menciones. `AlertInbox` descarta el ruido de Gmail y encola `AssignIntent`. El clasificador devuelve `important`, un intent existente o ninguno, y un motivo. Si es importante, `AlertFollowUp` redacta un borrador. `SceneMatch` solo desvía un Jira con proyecto a historias, o un correo de reunión a buscar hueco.

No hay política aprendida, ni acción distinta de «interrumpe / no interrumpas», ni resumen de lo despriorizado, ni PostHog.

---

## Principios de esta épica

1. **La decisión se guarda aquí.** Cada triaje escribe una fila. PostHog recibe una copia para mirar si la fase cumple. Si PostHog no está configurado o cae, el triaje sigue.
2. **PostHog no ve el mensaje.** Ni texto, ni nombres, ni enlaces, ni el motivo en lenguaje natural. Solo la acción, el sitio (dm, grupo, mención, hilo, canal), banderas y contadores. `distinct_id` es el id del usuario.
3. **Una acción por mensaje.** `reply`, `redirect`, `task`, `notice`, `ignore`. En la duda entre `ignore` y `reply`, sale `reply`.
4. **El «sí» no se salta solo.** Proponer un borrador o una redirección no los envía. Ejecutar sin preguntar es la fase 6, por categoría, y solo después de que PostHog enseñe que esa categoría ya se acepta.
5. **Gmail y Jira esperan.** El nuevo contrato nace en Slack. El asignador acepta la acción cuando existe y, si no, sigue con `important`.

---

## Medición

Servidor, con el SDK oficial `posthog/posthog-php` (`composer require posthog/posthog-php`). El triaje corre en un job: el navegador no ve la decisión.

En `.env` (y `.env.example`, sin valor real):

```
POSTHOG_API_KEY=
POSTHOG_HOST=https://eu.i.posthog.com
```

`config/services.php` lee las dos. `AppServiceProvider` llama a `PostHog::init` solo si hay clave. Un `App\Analytics\ProductAnalytics` es el único sitio que captura. En tests se sustituye por un doble que guarda los eventos. `phpunit.xml` deja la clave vacía.

Los eventos usan verbo en pasado y un objeto estable. Propiedades siempre de este conjunto, y ninguna otra:

| Propiedad | Valores |
| --- | --- |
| `action` | `reply`, `redirect`, `task`, `notice`, `ignore` |
| `place` | `dm`, `group`, `mention`, `thread`, `channel` |
| `owner` | `self`, `other`, `unknown` |
| `uncertain` | bool |
| `already_answered` | bool |
| `has_target` | bool |
| `policy_present` | bool |
| `signal` | `re_ping`, `re_mention` |
| `correction` | `not_important`, `someone_else`, `always_notify`, `right` |
| `sufficient` | bool |
| `agent_asked` | bool |
| `conflict` | bool |
| `edited` | `none`, `light`, `rewritten` |
| `autonomy` | `propose`, `auto` |
| `level` | `watch`, `draft`, `act` |

Contadores (`threads`, `replied`, `missed`, `deprioritized_count`) son enteros. Ningún id de Slack, ningún slug de agente, ningún texto.

### Catálogo

| Evento | Cuándo |
| --- | --- |
| `slack_attention_profile_built` | El job termina un perfil. Propiedades: `threads`, `replied`, `missed`. |
| `slack_triage_decided` | Hay una acción para un mensaje nuevo. |
| `slack_triage_missed` | Un re-pinche cae sobre algo que no se subió como `reply`. |
| `slack_daily_digest_read` | La persona abre el resumen de lo despriorizado. `deprioritized_count`. |
| `slack_reply_planned` | El borrador o la redirección quedan propuestos. `sufficient`, `agent_asked`, `conflict`. |
| `slack_triage_feedback` | Un toque de corrección. `action` es lo que se había mostrado; `correction` es el toque. |
| `slack_preference_rule_proposed` | Se propone una regla al archivo. |
| `slack_preference_rule_accepted` | La persona acepta esa regla. |
| `slack_draft_confirmed` | Confirma el envío, la redirección o la tarea. `edited`, `autonomy`. |
| `slack_autonomy_promoted` | Una categoría sube de nivel. `action`, `level`. |

### Insights que tienen que existir desde la fase 0

Se crean en PostHog a mano (o con el proyecto ya autenticado) y no se dan por buenos hasta que hay eventos reales. Cada uno es el outcome de una fase:

| Insight | Definición | Fase que lo cumple |
| --- | --- | --- |
| Política presente | % de `slack_triage_decided` con `policy_present = true`, últimos 7 días | 1 |
| Mix de acciones | recuento de `slack_triage_decided` por `action` | 2 |
| Redirección con destinatario | % de `action = redirect` con `has_target = true` | 2 |
| Duda hacia responder | entre `uncertain = true`, % con `action = reply` frente a `ignore` | 3 |
| Se me escapó | `slack_triage_missed` / (`slack_triage_decided` donde `action = reply` + `slack_triage_missed`), 7 días | 3 |
| Resumen visto | `slack_daily_digest_read` al menos uno por semana con uso | 3 |
| Borrador suficiente | % de `slack_reply_planned` con `sufficient = true` | 4 |
| Agente cuando hace falta | % de `slack_reply_planned` con `agent_asked = true` (se mira, no se fuerza) | 4 |
| Corrección que llega a regla | `slack_preference_rule_proposed` / `slack_triage_feedback` donde `correction ≠ right` | 5 |
| Regla aceptada | `slack_preference_rule_accepted` / `slack_preference_rule_proposed` | 5 |
| Borrador que no se reescribe | % de `slack_draft_confirmed` con `edited ≠ rewritten`, por `action` | 4 y 6 |
| Redirección aceptada | `slack_draft_confirmed` con `action = redirect` / `slack_triage_decided` con `action = redirect` | 6 |
| Tiempo en lo importante | mediana de horas entre `slack_triage_decided` (`reply`) y `slack_draft_confirmed` | 7 |

Umbrales para subir de autonomía (fase 6), revisables, con muestra mínima:

- Redirección: aceptación ≥ 70 % en ≥ 30 propuestas de esa categoría.
- Borrador de respuesta: `edited ≠ rewritten` ≥ 60 % en ≥ 20 confirmaciones.
- Por debajo de la muestra, la categoría se queda en proponer.

---

## Fase 0 · El medidor

`ProductAnalytics`, la config, el doble de tests y los once nombres de evento como constantes. Capturar con la clave vacía no llama a la red. Un test de humo captura `slack_triage_decided` de mentira y comprueba que las propiedades están en la lista blanca: si alguien añade el texto del mensaje, el test falla.

**Hecho cuando:** la suite prueba el filtro de propiedades sin clave, y con clave de prueba un evento llega al proyecto de PostHog.

**Outcome:** los insights de la tabla existen, aunque estén a cero. A partir de aquí cada fase se da por cumplida solo si su insight se mueve.

## Fase 1 · Pilar 1 · La política sale del histórico

Job `LearnSlackAttention`, una vez al conectar Slack y luego cada semana.

Lee hilos en los que la persona aparece (los mismos dos buscadores de hoy, más lo que ella escribió). Por hilo clasifica, sin modelo:

- respondió en menos de un día → le tocaba;
- no respondió y otro respondió → no le tocaba;
- no respondió y el hilo murió → ruido;
- no respondió y hubo un re-pinche o una re-mención → se le escapó.

Eso se agrega a un texto corto en español (a quién contesta, qué canales pesan, qué temas reaparecen) y a contadores. Tabla `slack_attention_profiles`: `user_id`, `body`, `signals` (json), `built_at`. El cuerpo entra en el contexto que `IntentAssigner` ya pasa al clasificador.

El histórico arranca la política. No se trata como el ideal: la fase 5 lo corrige.

**Hecho cuando:** tras el job, el perfil tiene cuerpo y contadores, y el siguiente triaje de Slack lo recibe en el prompt.

**Outcome en PostHog:** `slack_attention_profile_built` con `threads > 0`. El insight «Política presente» pasa a 100 % en cuanto hay decisiones de la fase 2. Si `missed` es alto, se anota: la política nace sabiendo que ya se le escapan cosas.

## Fase 2 · Pilares 2 y 3 · La acción y a quién le toca

`IntentDecision` gana `action`, `owner`, `hasTarget` y `uncertain`. En Slack el modelo deja de devolver `important`. Devuelve la acción, y el motivo sigue siendo la frase que ve la persona («Te lo marco porque Laura te pregunta por el budget y el plazo es el viernes»).

El prompt lleva el perfil, el contexto de trabajo, el sitio del mensaje y, si el mensaje va en un hilo, si alguien ya respondió. Esa lectura del hilo es la única búsqueda nueva de esta fase. Reglas fijas encima del modelo, para que el ownership no dependa de un párrafo:

- mensaje directo, o hilo que ella abrió → `owner = self`, y la duda cae en `reply`;
- alguien ya respondió de forma completa → no `reply` (baja a `notice` o `ignore`);
- el perfil dice que ese canal o esa persona no le tocan, y no va dirigido a ella → `redirect` si hay un destinatario habitual, si no `notice`.

`IntentAssigner`:

- `reply` → el intent de hoy, suena;
- `redirect` → intent pendiente cuya propuesta es el mensaje de redirección, no una respuesta en el canal;
- `task` → intent y tarea de agenda, sin borrador de chat;
- `notice` e `ignore` → no suenan. Se guardan en `slack_triage_decisions` para el resumen y para pillar el re-pinche.

La fila guarda acción, sitio, motivo, ids internos y `uncertain`. El evento lleva solo la lista blanca.

Al final de la fase, el watcher añade una tercera búsqueda: canales que el perfil marca como suyos, no todos los canales. Una pregunta al grupo, sin mención, en uno de esos canales, entra al mismo triaje. Si nadie responde en unas horas y el área es suya, un job la sube a `reply` (efecto espectador).

**Hecho cuando:** un mensaje directo de prueba sale `reply` con motivo; un mensaje de canal que el perfil marca como de otra persona sale `redirect` con destinatario y no se envía; un «ok» sale `ignore` y no crea alerta; una pregunta sin mención en un canal propio entra y, pasadas las horas, sube a `reply` si sigue sola.

**Outcome en PostHog:** cada candidato emite un `slack_triage_decided`. «Mix de acciones» muestra las cinco. «Redirección con destinatario» está en 100 %. `policy_present` es verdadero.

## Fase 3 · Pilar 6 · No perder lo importante

El umbral ya está en la fase 2 (la duda va a `reply`). Esta fase lo hace visible y recuperable.

- Resumen del día, en el lienzo, con lo marcado `notice` o `ignore` desde ayer. Abrirlo emite `slack_daily_digest_read`.
- Si un mensaje nuevo es un re-pinche («¿viste esto?», re-mención, mensaje directo posterior) sobre una decisión que no fue `reply`, se abre como `reply`, se marca la fila anterior y se emite `slack_triage_missed`.

**Hecho cuando:** lo ignorado aparece al día siguiente en el resumen; un re-pinche sobre un ignorado abre la alerta y deja la métrica.

**Outcome en PostHog:** «Duda hacia responder» se queda por encima de la mitad. «Se me escapó» tiene denominador (hay `reply` y, cuando ocurra, `missed`). «Resumen visto» cuenta aperturas. La fase no exige que «se me escapó» sea cero: exige que se pueda leer cada semana. Bajarlo es el trabajo de las fases 4 y 5.

## Fase 4 · Pilar 4 · Planificar antes de buscar, y un agente si encaja

Solo para `reply` y `redirect`, dentro de `AlertFollowUp`, antes del borrador.

El planificador escribe primero qué necesitaría saber (una lista corta). Luego busca en este orden, parando cuando basta: el hilo, los enlaces del mensaje, quién es la persona y de qué proyecto habla, documentos recientes de eso, el archivo de trabajo, precedentes de respuestas suyas. Si un «cuándo» de agente encaja, se le pregunta como en `ask_agent`: briefing y referencias, una sola vez, sin que el agente envíe nada. La respuesta entra en el borrador.

Si una decisión reciente del hilo contradice un documento viejo, el borrador lo dice. Si falta un dato, el borrador dice qué hay que confirmar y `sufficient` va a falso. Nada de un canal privado se usa para redactar en un canal más abierto: si la fuente es más cerrada que el destino, no se copia.

**Hecho cuando:** una alerta `reply` enseña un borrador que cita el hilo o el archivo, o dice qué falta. Un tema que encaja con un agente usa su briefing. Un conflicto entre el hilo y un doc se ve en el texto.

**Outcome en PostHog:** `slack_reply_planned` en cada `reply` y `redirect`. «Borrador suficiente» y «Agente cuando hace falta» se pueden leer. «Borrador que no se reescribe» empieza a tener datos en cuanto confirman envíos; el umbral del 60 % es la puerta de la fase 6, no de esta.

## Fase 5 · Pilar 5 · Preferencias que se pueden leer y corregir

En la alerta y en el resumen, tres toques: «no era importante», «esto le toca a otra persona», «siempre avísame de esto». Cada toque emite `slack_triage_feedback` y propone una frase de regla («Mensajes de este tipo: prioridad alta»). La regla no entra en el archivo hasta el «sí». Aceptarla emite `slack_preference_rule_accepted` y el perfil la incorpora, por encima del histórico.

El job semanal de la fase 1 relee el archivo y el contexto de trabajo, y baja el peso de las señales de más de un trimestre. El perfil lo dice en una línea («esto venía del trimestre pasado»).

Arranque en frío, si el histórico es corto: el mismo perfil más tres preguntas (personas a las que siempre respondes, proyectos de este trimestre, canales que son ruido). Las respuestas son reglas aceptadas desde el primer día.

**Hecho cuando:** un toque deja una regla pendiente de «sí»; al aceptarla, el siguiente mensaje parecido cambia de acción; una señal vieja pesa menos tras el job semanal.

**Outcome en PostHog:** «Corrección que llega a regla» cerca de 100 % (todo toque que no sea `right` propone regla). «Regla aceptada» se lee aparte: aceptarla es de la persona, y un porcentaje bajo significa que las reglas propuestas están mal escritas, no que falte el evento.

## Fase 6 · Pilar 6 · Subir de autonomía, y pilar 7 · Mirar si funciona

Tres niveles por acción (`reply`, `redirect`, `task`):

1. **Ver.** Fases 2 y 3: se ordena y se explica.
2. **Proponer.** Fase 4: hay borrador.
3. **Hacer.** Se ejecuta y queda la ventana de deshacer. Solo si el insight de esa acción supera el umbral y la muestra, y la persona confirma el ascenso. El ascenso emite `slack_autonomy_promoted`. Bajar de nivel es inmediato, sin umbral.

El tablero de PostHog queda con los insights de la tabla. La métrica norte es «Se me escapó». Al lado: precisión aproximada (`right` y confirmaciones sin `not_important`, sobre `reply`), recall (ese mismo sobre `reply` + `missed` + feedback `always_notify`), redirecciones aceptadas, borradores reescritos, y horas hasta confirmar lo marcado `reply`.

**Hecho cuando:** se puede ascender y bajar una categoría, el ascenso no ocurre bajo el umbral, y los cinco números del pilar 7 tienen insight con eventos de las fases anteriores.

**Outcome en PostHog:** «Redirección aceptada» y «Borrador que no se reescribe» son la puerta. «Se me escapó» es el número que esta épica existe para bajar. El tiempo hasta confirmar un `reply` es el complemento: si el recall sube y el tiempo no baja, se está avisando de más.

---

## Qué se añade

- `posthog/posthog-php`.
- `config` en `services.posthog`, init en `AppServiceProvider`.
- `app/Analytics/ProductAnalytics.php` y `SlackTriageEvent` (los nombres y la lista blanca).
- `slack_attention_profiles`, `slack_triage_decisions`.
- `app/Slack/LearnSlackAttention.php` y su job semanal.
- `app/Slack/SlackTriage.php`: la decisión de acción, el hilo, el espectador, el re-pinche.
- El resumen del día como vista del lienzo.
- Los tres toques y la propuesta de regla al archivo.
- El nivel de autonomía por acción, guardado con el usuario.

## Qué se cambia

- `IntentDecision` y `LlmIntentClassifier`, con la acción en Slack.
- `IntentAssigner`, que obedece la acción.
- `SlackWatcher`, con la búsqueda extra de canales del perfil.
- `AlertFollowUp`, que planifica la búsqueda y puede preguntar a un agente.
- `FakeIntentClassifier`, para que los tests de hoy sigan teniendo una acción.

## Qué no se cambia

- El contrato de Gmail y Jira, salvo la traducción a `reply` / `ignore`.
- `PendingAction` y la ventana de deshacer.
- Que un agente se pregunta y no envía.
- El audio, que no pasa por Laravel.

---

## Orden

0 → 1 → 2 → 3 se pueden usar sin borrador nuevo. 4 depende de 2. 5 depende de 3, porque el toque vive en la alerta y en el resumen. 6 depende de que 4 y 5 ya emitan, si no los umbrales no tienen muestra. Ninguna fase se cierra con el insight de la anterior en cero teniendo ya tráfico de Slack: cero con tráfico es un evento que no se está capturando.
