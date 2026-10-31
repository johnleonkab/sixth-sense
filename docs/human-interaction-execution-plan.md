# Plan de ejecución: pilares de interacción humana

Oct 31, 2026 · @John

Cómo llevar a código [Pilares de interacción humana](human-interaction-pillars.md), paso a paso. Cada etapa deja algo que se puede probar y no rompe lo anterior; al cerrar una, se actualiza la tabla de estado y el registro de cambios de los pilares.

## Orden y por qué

1. **Etapa 0 — Lo urgente:** las tareas propias dejan de crearse sin «sí» (incumple el principio 1).
2. **Etapa 1 — Registrar el resultado de cada propuesta:** es la base de los pilares 4 y 5 y de las métricas; sin ella, nada de lo demás se puede medir ni aprender.
3. **Etapas 2 a 6 — Los flujos:** primero el clasificador (pilar 0), del que dependen llamadas, tareas y preguntas.
4. **Etapas 7 a 9 — Principios transversales, ciclo de vida del aviso y aprendizaje.**
5. **Etapa 10 — Métricas.**

| Etapa | Pilar | Depende de | Tamaño |
| --- | --- | --- | --- |
| 0 · Tareas solo tras el «sí» | Principio 1 | — | S |
| 1 · Resultado de cada propuesta | 4, 5 | — | M |
| 2 · Descartar con motivo y resolver | 4 | 1 | M |
| 3 · Clasificador de acciones | 0 | 1 | L |
| 4 · Llamadas | 1 | 3 | L |
| 5 · Tareas | 2 | 0, 3 | L |
| 6 · Preguntas | 3 | 3 | M |
| 7 · Fuente, fecha y destinatario | Principios 4 y 5 | 6 | M |
| 8 · Ciclo de vida del aviso | 4 | 1 | M |
| 9 · Aprender de cada interacción | 5 | 1, 2 | L |
| 10 · Métricas | Métricas | 1, 2, 8 | M |

Cada etapa sigue las reglas de siempre: tests con los `Fake` de cada conector, Pint, tipos y build antes de dar el paso por cerrado, y nada en producción sin decir «súbelo».

---

## Etapa 0 — Las tareas propias solo tras el «sí»

**Objetivo:** el agendador ya no crea nada en el calendario sin confirmación; una tarea propia pasa por la misma barra que una reunión.

Pasos:

1. En `Scheduler::schedule()` ([app/Scheduling/Scheduler.php](../app/Scheduling/Scheduler.php)), el camino sin personas deja de llamar a `calendar->create()`: propone una `calendar.change` de tipo `create` igual que el camino con personas, y la tarea queda `Proposed` con su `pending_action_id`.
2. Si había que encoger otras tareas propias para hacer hueco (`squeeze`), eso va dentro de la misma propuesta y solo se aplica tras el «sí» (ya ocurre así en el camino con personas).
3. La vista de la tarea ([AgendaTaskView](../app/Voice/OpenUi/Views/AgendaTaskView.php)) muestra la barra de propuesta también para tareas propias, con «crear» como verbo.
4. El texto de voz de `schedule_task` deja de decir «Agendado»: «Te propongo … el jueves a las 10; dime si lo pongo».
5. Tests: una tarea propia termina `Proposed`, no hay evento en el `FakeCalendarWriter` hasta confirmar, y tras confirmar se crea con su hueco.

**Hecho cuando:** ninguna tarea, ni propia ni con otros, aparece en el calendario sin un «sí».

## Etapa 1 — Registrar el resultado de cada propuesta

**Objetivo:** saber, para cada aviso, qué pasó con lo que se propuso. Es la materia prima de los pilares 4 y 5 y de las métricas.

Pasos:

1. Tabla `proposal_outcomes`: persona, aviso, clase (llamada, tarea, pregunta, responder), confianza, acción propuesta (tipo de `PendingAction`), resultado (`sent_as_is`, `edited`, `discarded`, `resolved`, `expired`, `undone`), motivo de descarte, tamaño de la edición, y tiempo desde el aviso.
2. Registrar en cada punto donde se cierra algo:
   - Envío con un toque ([AlertReplyController](../app/Http/Controllers/AlertReplyController.php)): `sent_as_is`.
   - Confirmación de un borrador editado (las barras que guardan cambios antes del «sí»): `edited`, con la diferencia entre el texto propuesto y el enviado.
   - «Deshacer» dentro de la ventana: `undone`.
   - `resolve_alert` y el botón «Ya está resuelta»: `resolved`; «Descartar»: `discarded`.
   - El aviso que se cierra solo porque respondió por su cuenta (`AlertFollowUp::checkReplies`): `resolved` con origen «fuera del sistema».
3. Un servicio `ProposalOutcomes` con un método por resultado, para que ningún controlador escriba en la tabla a mano.
4. Tests: cada camino deja exactamente una fila con el resultado correcto.

**Hecho cuando:** cada aviso cerrado tiene su resultado registrado.

## Etapa 2 — Descartar con motivo y resolver con inferencia

**Objetivo:** pilar 4, puntos 3 y 4.

Pasos:

1. **Descartar con motivo.** El botón «Descartar» de la revisión del aviso abre tres opciones de un toque: *no es importante*, *no es para mí*, *la propuesta está mal*. Se puede cerrar sin elegir (cuenta como «sin motivo»).
2. Cada motivo alimenta su capa: *no es importante* → el juez de relevancia; *no es para mí* → el triaje de a quién le toca (las correcciones de Slack que ya existen); *la propuesta está mal* → el motor de respuestas.
3. **Resolver con inferencia.** Al marcar «Ya está resuelta», un job en cola baja intenta saber cómo:
   - respondió en otro hilo o canal a esa persona (`repliedAfter` amplio, por persona y no por mensaje);
   - hay una reunión con esa persona entre el aviso y ahora (calendario);
   - otra persona respondió y lo cerró en el mismo hilo.
4. Si no lo averigua, una pregunta del tipo de las de conocimiento (sin sonido), como mucho una por semana: «El aviso de Patricia lo diste por resuelto: ¿cómo se resolvió?».
5. Tests: cada motivo queda registrado; las tres inferencias, con conectores falsos; el límite de preguntas por semana.

**Hecho cuando:** descartar pide el motivo con un toque y «resuelta» deja registrado cómo, casi siempre sin preguntar.

## Etapa 3 — El clasificador de acciones (pilar 0)

**Objetivo:** después del filtro de importancia, cada mensaje recibe una o varias clases con su confianza, y un solo aviso las reúne.

Pasos:

1. Un `ActionClassifier` (pipeline nuevo en `config/agents.php`, modelo capaz) que devuelve una lista de acciones:
   `[{class: call|task|question|reply, confidence: sure|doubt, doubt: «qué no está claro», ...datos de la clase}]`.
   Datos por clase: llamada → quién la pide, si le incluye, personas, duración inferida, urgencia; tarea → entregable, quién lo espera, plazo, origen (le piden / se compromete); pregunta → sobre qué (feature, documentación, contexto) y términos para buscar.
2. Reglas en las instrucciones, sacadas tal cual de los pilares: la llamada tiene que incluirle específicamente; la tarea necesita las tres condiciones (si falta una, `doubt` como mucho); ante la duda, `doubt` con el motivo.
3. Sustituye a la «tarea manual» del motor de respuestas ([LlmReplyPlanner](../app/Alerts/Replies/LlmReplyPlanner.php)): el motor sigue redactando respuesta, reacción y encargo a otro; la decisión de llamada o tarea pasa al clasificador.
4. El aviso guarda las acciones en `proposal['actions']`. La revisión del aviso las muestra como una lista: cada una con su casilla para quitarla, y las «en duda» con su pregunta y botones «sí, es una llamada» / «no».
5. Un «sí» general confirma todas las acciones marcadas; por debajo, la confirmación conjunta que ya existe en [AlertReplyController](../app/Http/Controllers/AlertReplyController.php) se generaliza a cualquier lista de acciones.
6. Un conjunto de evaluación de unos 25 mensajes reales (incluido «revisar petición de Luis», que no es tarea, y el compromiso «te lo paso antes del día X»), con `agents:eval`.
7. Tests: clases y confianza se guardan; un mensaje con pregunta + tarea genera un solo aviso; quitar una acción la excluye del «sí».

**Necesita decidir:** el umbral de confianza (decisión abierta). Propuesta para empezar: lo decide el modelo con las reglas, y se revisa con los datos de la etapa 10.

**Hecho cuando:** los avisos llegan clasificados, con su confianza, y las dudas se resuelven con un toque.

## Etapa 4 — Llamadas (pilar 1)

**Objetivo:** de «¿lo vemos en una llamada?» a evento y respuesta preparados, con un solo «sí».

Pasos:

1. **¿Le incluye?** Lo decide el clasificador (etapa 3). En duda, no se prepara nada.
2. **Selector de personas.** Si el clasificador no está seguro de los asistentes, la revisión del aviso muestra un selector: personas de la conversación marcadas; sus colaboradores habituales (de `Chats::familiar`) como sugerencia sin marcar. Al cambiar la selección se vuelve a buscar hueco.
3. **Duración.** Entre 15 y 60 minutos inferidos, se usa sin preguntar. Si no, el aviso pregunta (15 / 30 / 45 / 60 en un toque). Se aprende de la etapa 1: tras 3 confirmaciones iguales para el mismo tipo de reunión con las mismas personas, deja de preguntar; vuelve a hacerlo si él la cambia.
4. **Hueco.** Una ventana por urgencia (propuesta: ahora → hoy; hoy → 24 h; esta semana → 5 días laborables) con [SlotFinder](../app/Scheduling/SlotFinder.php), que ya mira la ocupación de los demás. Sin hueco, se amplía una vez al nivel siguiente.
5. **Externos.** Si un asistente es de fuera de la organización y su agenda no se ve, no se busca en ella: el mensaje propone 2 o 3 huecos libres de los internos.
6. **Propuesta conjunta.** Con hueco: evento (`calendar.change`) + respuesta corta en Slack o email (`chat.send` o `mail.send`), confirmados con un solo «sí». Sin hueco: solo la respuesta, que lo dice y pregunta por días o franjas más flexibles.
7. La respuesta la redacta el motor de respuestas con el hueco elegido y las personas ya decididas.
8. Tests: le incluye / en duda; selector con marcadas y sugeridas; las cuatro ramas de duración; con hueco, sin hueco y ampliando; externos; un «sí» crea el evento y envía el mensaje.

**Necesita decidir:** ventana por urgencia, cuándo deja de preguntar la duración y selector de personas (decisiones abiertas; se usan las propuestas mientras tanto).

**Hecho cuando:** pedir una llamada en Slack acaba en un aviso con el evento y el mensaje listos, o en una pregunta concreta si falta algo.

## Etapa 5 — Tareas (pilar 2)

**Objetivo:** solo tareas que merecen la pena, propuestas con hueco y mensaje, y creadas tras el «sí».

Pasos:

1. **Criterio estricto** en el clasificador (etapa 3): entregable, alguien lo espera, plazo.
2. **Sus propios compromisos.**
   - Slack: el vigilante ([SlackWatcher](../app/Connectors/Slack/SlackWatcher.php)) lee también sus mensajes enviados en conversaciones relevantes y los pasa al clasificador como origen «se compromete».
   - Gmail: un sondeo de la carpeta de enviados (el buzón ya busca en `sent`) cada cierto tiempo, con el mismo clasificador.
   - El aviso: «Le dijiste a Luis que le pasas la historia antes del jueves: ¿lo pongo en el calendario?».
3. **Aviso de tarea pedida:** solo mientras no haya respondido y tras el filtro de importancia.
4. **Tres huecos antes del plazo** y con margen, de la duración inferida; la duración se puede cambiar en el aviso (15 / 30 / 45 / 60 / 90 / 120) y los huecos se recalculan.
5. **Un «sí»** crea la tarea en el calendario (con la etapa 0, ya es una propuesta) **y** envía la respuesta, si la hay.
6. **Seguimiento:** 24 h antes del plazo, si no hay señal de entrega (un mensaje suyo a esa persona, un archivo o un issue creado), un aviso único.
7. Tests: casos que sí y que no son tarea; compromiso propio en Slack y en email; tres huecos antes del plazo; cambiar la duración; confirmación conjunta; seguimiento con y sin señal de entrega.

**Necesita decidir:** compromisos propios (decisión abierta; se usa la propuesta: Slack y emails enviados).

**Hecho cuando:** «revisar petición de Luis» ya no llega al calendario, y «te lo paso antes del jueves» sí, tras el «sí».

## Etapa 6 — Preguntas (pilar 3)

**Objetivo:** ante una pregunta, buscar la respuesta y dejarla redactada.

Pasos:

1. Cuando el clasificador marca una pregunta, el motor de respuestas recibe la búsqueda hecha por el agente Investigación ([config/agents.php](../config/agents.php)) antes de redactar.
2. **Estado de una feature:** búsqueda en el índice de Jira (`search_knowledge` con fuente Jira), lista de candidatos, y búsqueda cerrada (`open_jira_issue`) sobre el que encaja. La respuesta cita la clave, el estado y la fecha de la última actualización.
3. **Documentación, proyectos, contexto:** Archivo, contexto personal y Notion.
4. **Varios candidatos:** si hay más de uno plausible, el aviso los lista para elegir y el borrador espera a la elección.
5. **No encontrado:** el aviso lo dice y el borrador no inventa la respuesta.
6. **Datos viejos:** si la fuente lleva más de 14 días sin actualizarse, el aviso lo marca.
7. Tests: feature con un candidato, con varios y sin ninguno; documentación desde Archivo y Notion; marca de datos viejos.

**Necesita decidir:** datos viejos (decisión abierta; se usa la propuesta: 14 días).

**Hecho cuando:** «¿cómo va la feature X?» llega con la respuesta redactada, el issue citado y su fecha.

## Etapa 7 — Citar la fuente y controlar el destinatario (principios 4 y 5)

**Objetivo:** que todo dato diga de dónde sale y que nada interno salga a quien no debe.

Pasos:

1. **Fuente y fecha.** Cada propuesta guarda las fuentes que usó (`proposal['sources']`: tipo, título, enlace, actualizado). La revisión del aviso las muestra bajo el borrador.
2. **Destinatario.** Un `RecipientScope` que clasifica a quien recibe: interno (misma organización en Slack, mismo dominio en email), otro equipo (interno fuera de su grafo habitual) o externo (invitado de Slack, otro dominio).
3. Si es externo u otro equipo y el borrador contiene datos internos (claves de Jira, enlaces privados, plazos internos), el aviso lo marca y el envío con un toque pide una confirmación más.
4. Tests: clasificación de destinatarios; marca y confirmación extra con datos internos.

**Hecho cuando:** ningún dato interno sale a un externo sin que él lo haya visto marcado.

## Etapa 8 — Ciclo de vida del aviso

**Objetivo:** pilar 4, ciclo de vida.

Pasos:

1. **Caducidad** (hoy existe en parte): además de «respondió él», se cierra solo si otra persona respondió y lo resolvió en el mismo hilo, o si la llamada pedida ya ocurrió. Queda como `expired` en la etapa 1.
2. **Orden:** la lista de avisos y el centro de avisos ordenan por plazo más cercano y por bloqueo («le está esperando»), después el resto.
3. **Resumen agrupado:** los avisos de baja urgencia no suenan uno a uno; se agrupan en un resumen (propuesta: dos al día, a las 9:30 y a las 15:30) que se abre como una lista.
4. Tests: las tres caducidades; el orden; que un aviso de baja urgencia no suena y aparece en el resumen.

**Necesita decidir:** resumen agrupado (decisión abierta; se usa la propuesta).

**Hecho cuando:** los avisos que ya no hacen falta desaparecen solos y los de baja urgencia no interrumpen.

## Etapa 9 — Aprender de cada interacción (pilar 5)

**Objetivo:** que cada resultado de la etapa 1 mejore las siguientes propuestas, con su peso.

Pasos:

1. Pesos de la tabla del pilar 5 en código (alto, medio, bajo).
2. **Lecciones** por persona y por clase o agente: un job (modelo ligero) lee los resultados de peso alto (ediciones, descartes con motivo, selección de personas, cambios de duración) y escribe lecciones cortas («Con Marta, tono informal», «las 1:1 con Pablo duran 30 minutos»).
3. Las lecciones relevantes entran en las instrucciones del clasificador, del motor de respuestas y de los agentes. Nunca cambian las reglas (el «sí», la autonomía, las herramientas).
4. **Casos de evaluación:** cada fallo de peso alto se añade al conjunto de evaluación de su clase o agente.
5. **Inercia:** si la tasa de «envía tal cual» supera un umbral, una muestra semanal de esos envíos aparece para revisarla a mano.
6. Una página en Configuración con las lecciones, para borrarlas o corregirlas.
7. Tests: una edición genera su lección; una lección llega a las instrucciones; las lecciones caducan si no se usan o se contradicen.

**Hecho cuando:** corregir dos veces lo mismo basta para que no vuelva a pasar.

## Etapa 10 — Métricas

**Objetivo:** saber si los pilares funcionan.

Pasos:

1. Las seis métricas de los pilares, calculadas de la etapa 1 por semana y por clase.
2. **Cosas que se escaparon:** respuestas, reuniones o entregas suyas sin aviso previo cuando el mensaje de origen pasó el filtro de importancia (se apoya en la detección de «se me escapó» de Slack que ya existe).
3. Una sección en Configuración (o en el impacto que ya existe) con las métricas.
4. Tras dos semanas de datos, fijar los objetivos en los pilares.

**Hecho cuando:** las seis métricas se ven por semana y por clase.

---

## Seguimiento

| Etapa | Estado |
| --- | --- |
| 0 · Tareas solo tras el «sí» | Pendiente |
| 1 · Resultado de cada propuesta | Pendiente |
| 2 · Descartar con motivo y resolver | Pendiente |
| 3 · Clasificador de acciones | Pendiente |
| 4 · Llamadas | Pendiente |
| 5 · Tareas | Pendiente |
| 6 · Preguntas | Pendiente |
| 7 · Fuente, fecha y destinatario | Pendiente |
| 8 · Ciclo de vida del aviso | Pendiente |
| 9 · Aprender de cada interacción | Pendiente |
| 10 · Métricas | Pendiente |
