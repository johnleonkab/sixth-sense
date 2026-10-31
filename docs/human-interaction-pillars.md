# Pilares de interacción humana

Oct 31, 2026 · @John

Cuando llega un mensaje (Slack o email), el sistema decide **si hay que hacer algo, qué y con quién**, lo deja preparado y la persona solo revisa: envía con un botón, lo cambia, lo descarta o lo da por resuelto. Lo que haga la persona es la señal con la que el sistema aprende.

Complementa a [Pilares de producto: triaje agéntico de Slack](Pilares%20de%20producto%20triaje%20agéntico%20de%20Slack.md), que decide **si un mensaje importa y a quién le toca**. Este documento empieza donde aquel acaba: **qué acción toca y cómo interviene la persona**.

Documento vivo: se actualiza cada vez que se implementa o cambia un comportamiento (ver el registro al final). Copia editable: [Pilares de interacción humana](https://claude.ai/artifact/4XTh1jfyqa3AgQinn9ZhJZ). Plan de ejecución: [human-interaction-execution-plan.md](human-interaction-execution-plan.md).

## Principios transversales

Estas reglas valen para todos los pilares y están por encima de cualquier flujo concreto.

1. **Nada se ejecuta sin el «sí».** Ni mensajes, ni eventos, ni tareas en su propio calendario. El «sí» llega por botón o por voz. **Única excepción:** lo que la persona haya subido explícitamente al nivel «Hacer» de la autonomía de Slack, por tipo de mensaje; se envía solo, con la misma ventana para deshacer, y se puede bajar en cualquier momento.
2. **Ante la duda, preguntar; nunca suponer.** La duda no es una clase aparte: es un nivel de confianza que puede tener cualquier clase (ver Pilar 0).
3. **Deshacer siempre es posible.** Tras el «sí», el envío espera **6 segundos**, en toda la app, durante los que se puede cancelar. Los eventos y tareas creados se pueden borrar desde el propio aviso.
4. **Citar la fuente.** Todo dato que el sistema aporta (estado de un issue, un documento, un hueco) dice de dónde sale y cuándo se actualizó por última vez.
5. **No compartir más de la cuenta.** Antes de redactar con información interna, se comprueba quién recibe el mensaje (interno, otro equipo, externo).

## Pilar 0 — Qué tipo de acción pide el mensaje

Después del filtro de importancia, cada mensaje recibe **una o varias clases**, cada una con su **nivel de confianza**.

| Clase | Ejemplo | Flujo |
| --- | --- | --- |
| **Llamada** | «¿Lo vemos en una llamada?» dirigido a él | Pilar 1 |
| **Tarea** | Le piden un informe; él se compromete a algo con fecha | Pilar 2 |
| **Pregunta** | «¿Cómo va la feature X?», «¿dónde está la doc de Y?» | Pilar 3 |
| **Solo responder** | Un «ok», una confirmación, un dato que ya sabe | Respuesta o reacción, sin más |

**Confianza.** Cada clase lleva una de dos etiquetas:

- **Segura:** se prepara la propuesta completa del flujo.
- **En duda:** no se prepara nada; el aviso dice qué no está claro y deja elegir. Ejemplos: «No sé si esta llamada te incluye», «Parece una tarea para ti, pero no estoy seguro: ¿lo es?», «No sé si esta pregunta va contigo».

Así cada flujo tiene su camino de baja confianza, en lugar de una clase «en duda» genérica.

**Varias clases en un mensaje.** «¿Cómo va X? Y mándame el informe el jueves» es pregunta + tarea. Se genera **un solo aviso** con todas las acciones propuestas, y cada una se puede aceptar o quitar por separado. Un «sí» general confirma todas las que sigan marcadas.

**Respuesta combinada.** Cualquier clase puede ir con una respuesta: agendar una llamada y contestar en Slack van en la misma propuesta y se confirman con un solo toque.

## Pilar 1 — Llamadas

Alguien, en un DM o en un canal, pide una llamada.

1. **¿Es con él?** El contexto tiene que indicar que la llamada **le incluye específicamente**. Si no está claro → aviso en duda («no sé si hace falta una llamada contigo»), sin preparar nada.
2. **¿Con quién?** Si el agente está seguro de los asistentes, sigue. Si no, muestra un **selector de personas** con las de la conversación ya marcadas y sus colaboradores habituales como sugerencia.
3. **¿Cuánto dura?** Si el sistema infiere una duración entre 15 minutos y 1 hora, la usa sin preguntar. Si no puede inferirla, o sale fuera de ese rango, pregunta, hasta que aprenda las duraciones habituales de la persona.
4. **Buscar hueco** en las agendas de todos, dentro de una ventana que depende de la urgencia. Si no hay hueco, **amplía la ventana una vez** antes de rendirse.
5. **Si hay hueco:** se prepara el evento **y** una respuesta corta y natural en Slack (o email) con la propuesta. Un «sí» crea el evento y envía el mensaje.
6. **Si no hay hueco:** se prepara igualmente una respuesta que lo dice y pregunta qué días o franjas tienen más flexibilidad.

**Personas externas.** Si algún asistente es de fuera de la organización y su agenda no es visible, el sistema no busca en su calendario: propone en el mensaje 2 o 3 huecos libres de los internos para que elija.

## Pilar 2 — Tareas

Una tarea en el calendario tiene que merecer la pena: «Revisar petición de Luis» no lo es.

**Es una tarea** cuando se cumplen las tres:

1. Hay un **entregable concreto** (un informe, un documento, un issue creado, un envío).
2. **Alguien lo espera**.
3. Hay un **plazo** explícito o que se puede inferir.

**No es una tarea:** leer un hilo, revisar algo corto, contestar, «cuando puedas échale un ojo» sin entregable. Si falta uno de los tres criterios, la clase queda **en duda** como mucho.

**Dos orígenes:**

- **Él se compromete:** le dice a alguien «creo la tarea en Jira y te la paso antes del día X». El sistema lee **también sus propios mensajes**, en Slack y en emails enviados.
- **Le piden algo:** un informe, que mande X… Mientras no haya respondido, y tras pasar el filtro de importancia, salta el aviso.

**Flujo:**

1. Aviso: «Patricia te pide el informe de X para el jueves».
2. Ya preparados: **el borrador de respuesta** y **3 huecos** en el calendario, todos **antes del plazo** y con margen, con la duración elegible.
3. Él elige hueco y duración (o los deja como están) y dice que sí.
4. Solo entonces se crea la tarea en el calendario **y** se envía el mensaje.

**Seguimiento.** Si se acerca el plazo (propuesta: 24 h antes) y no hay señal de entrega (mensaje, archivo, issue), el sistema le avisa una vez.

## Pilar 3 — Preguntas

Si el mensaje es una pregunta, el sistema es **muy proactivo**: busca la respuesta y la deja redactada.

- **Estado de una feature** → buscar en el índice de Jira los issues que coinciden, listar los candidatos y afinar con una búsqueda cerrada sobre el que encaja. La respuesta cita el issue, su estado y **cuándo se actualizó**.
- **Documentación, proyectos, contexto** → el Archivo, el contexto personal y Notion.

**Guardarraíles:**

1. **Si no encuentra la respuesta, lo dice** en el aviso; no la inventa.
2. **Datos viejos.** Si la fuente lleva más de 2 semanas sin actualizarse (propuesta), el aviso lo marca: «el issue no se toca desde el 12/10, puede que no esté al día».
3. **Quién pregunta.** Si el destinatario es externo o de otro equipo, el borrador no incluye datos internos (issues, documentos privados, plazos internos) sin que él lo confirme.
4. **Varios candidatos.** Si hay más de un issue o documento plausible, el aviso los muestra para que elija en vez de quedarse con uno.

## Pilar 4 — La interacción humana

La persona solo revisa. Para cada aviso:

1. **Recibe el aviso** con lo que se pide y lo que está preparado: «Patricia te pide …, revisa esto antes de que lo envíe».
2. **Envía o modifica.** Enviar con un botón siempre es posible; modificar abre el borrador.
3. **Descarta**, eligiendo con un toque el motivo: *no es importante*, *no es para mí* o *la propuesta está mal*. Sin motivo, cuenta como señal débil.
4. **Marca como ya resuelta.** El sistema intenta **inferir cómo se resolvió** (respondió en otro hilo, hay una reunión con esa persona, lo hizo otro). Solo si no puede, le pregunta de vez en cuando, nunca en cada aviso.

**Ciclo de vida del aviso:**

- **Caduca** si deja de tener sentido: otro ya respondió, la llamada ya pasó, él contestó por su cuenta. Se retira solo y se registra como resuelto fuera del sistema.
- **Orden:** primero lo que tiene plazo más cercano y lo que bloquea a otra persona; después el resto.
- **Volumen:** los avisos de baja urgencia se agrupan en un resumen (propuesta: dos al día) en lugar de llegar uno a uno.

## Pilar 5 — Aprender de cada interacción

Cada decisión de la persona es una señal, pero no todas pesan igual.

| Señal | Qué se aprende | Peso |
| --- | --- | --- |
| Envía tal cual | La clase y el borrador eran correctos | Medio: puede ser confianza por inercia |
| Modifica antes de enviar | La diferencia: tono, datos, destinatarios, duración | Alto |
| Descarta: no es importante | El filtro de importancia falló | Alto |
| Descarta: no es para mí | El triaje de a quién le toca falló | Alto |
| Descarta: la propuesta está mal | La clase o el borrador fallaron, el mensaje sí importaba | Alto |
| Descarta sin motivo | Ambiguo | Bajo |
| Marca como resuelta | Cómo se resuelve ese tipo de cosa fuera del sistema | Medio |
| Aviso caducado | Llegó tarde o no hacía falta | Medio |
| Elige personas en el selector | Quién suele estar en esas llamadas | Alto |
| Cambia la duración | Duraciones habituales por tipo de reunión | Alto |

Se registran por persona y alimentan las lecciones de los agentes y sus casos de evaluación (ver el bucle de aprendizaje en la [arquitectura multiagente](https://claude.ai/artifact/25Z4jaUXhsjgazq5SVtNx6)).

**Vigilar la inercia.** Si la tasa de «envía tal cual» es muy alta, se revisa una muestra a mano de vez en cuando para comprobar que los borradores son buenos y no solo aceptados.

## Métricas

Sin estas métricas no sabremos si los pilares funcionan. Objetivos por definir tras dos semanas de datos.

| Métrica | Qué mide | Cómo se calcula |
| --- | --- | --- |
| Tasa de aceptación por clase | Si las propuestas son buenas | Enviadas tal cual o modificadas / avisos de esa clase |
| Tasa y tamaño de edición | Cuánto hay que corregir | % de enviados con cambios; diferencia media entre borrador y envío |
| Tasa de descarte por motivo | Falsos positivos de cada capa | Descartes de cada motivo / avisos totales |
| Tiempo hasta la acción | Si el aviso llega a tiempo y es fácil de resolver | Mediana entre aviso y envío, descarte o resolución |
| Avisos caducados | Avisos tarde o innecesarios | Caducados / avisos totales |
| Cosas que se escaparon | Lo que no detectó (recall) | Respuestas, reuniones o entregas suyas sin aviso previo, cuando el mensaje de origen pasó el filtro de importancia |

La última es la más difícil y la más importante: las demás solo miden precisión.

Hoy se calculan por semana y por tipo de acción en Configuración › Impacto («Cómo van los avisos»), a partir del resultado de cada propuesta. «Se escaparon» usa por ahora la detección de Slack (mensajes que el triaje no dio por suyos y luego sí lo eran); faltan reuniones y entregas. Los objetivos se fijarán tras dos semanas de datos.

## Estado actual frente a los pilares

**Lo urgente ya está corregido:** ninguna tarea, propia o con otros, llega al calendario sin el «sí».

| Pilar | Hoy | Falta |
| --- | --- | --- |
| Principios | El agendador espera el «sí» para reuniones y para tareas propias; ventana para deshacer de 6 s en toda la app; la autonomía «Hacer» es la excepción del principio 1. Cada propuesta guarda sus fuentes (título, enlace y fecha) y se muestran bajo el borrador. Quien recibe se clasifica como de su organización, de otro equipo (fuera de sus colaboradores habituales) o de fuera (otro dominio o invitado); si no es de su organización y el borrador lleva datos internos (claves de issues, enlaces a Jira, Notion, Drive o Slack), se marca y el envío con un toque pide un «sí» más | Plazos internos como dato interno |
| 0 · Clasificación | El juez y el triaje deciden si importa y a quién le toca; un clasificador aparte decide qué pide el mensaje (llamada, tarea, pregunta o solo responder, una o varias) con su nivel de confianza; en duda dice qué no está claro y no prepara nada hasta un «sí» o un «no» (el «no» deja una lección); tiene un conjunto de evaluación de 25 mensajes | Usar la clase pregunta para investigar (etapa 6) |
| 1 · Llamadas | Solo si el clasificador está seguro de que le incluye; si no está seguro de quiénes van, selector con las personas de la conversación marcadas y sus colaboradores habituales sugeridos; duración inferida entre 15 y 60 min, o la habitual con esas personas tras tres iguales, o la pregunta (15/30/45/60); hueco en una ventana por urgencia (ahora → hoy, hoy → 24 h, esta semana → 5 días laborables), ampliada una vez; con alguien de fuera cuya agenda no se ve, la respuesta ofrece 3 huecos (uno por día) sin invitación; sin hueco, la respuesta lo dice y pregunta por días más flexibles, sin otro aviso; la respuesta la escribe el motor con el hueco y un «sí» convoca y envía | Que la persona de fuera elija uno de los huecos y se convoque solo |
| 2 · Tareas | Solo es tarea segura con las tres condiciones (entregable, quién lo espera, plazo); si falta una queda en duda. Sus propios compromisos en Slack y en emails enviados («te lo paso antes del jueves») abren un aviso que ofrece reservar tiempo. Tres huecos antes del plazo, con un día laborable de margen; se elige otro hueco o se cambia la duración (15 min a 2 h) con un toque. Un «sí» reserva la tarea y envía la respuesta. El día antes del plazo, si no hay señal de entrega (respuesta suya donde se pidió, o un mensaje nuevo a esa persona), un único aviso pregunta si va bien | Señales de entrega por archivo o issue creado |
| 3 · Preguntas | Cuando el clasificador ve una pregunta, se busca la respuesta antes de redactar: el estado de una feature en el índice de Jira y luego el issue que encaja (clave, estado, responsable y fecha); documentación y contexto en el Archivo, Notion, las reuniones y la memoria. Con varios candidatos, el aviso los lista y el borrador espera a que elija; si no encuentra nada lo dice y no lo inventa; si la fuente lleva más de 14 días sin tocarse, lo marca. La búsqueda es directa sobre los conectores, sin pasar por el agente Investigación, por latencia | — |
| 4 · Interacción | Aviso con propuesta y sus acciones; un solo «sí» envía la respuesta y reserva la llamada o la tarea, y lo que se desmarca se queda fuera; enviar con un toque (Slack y Gmail) o modificar el texto antes de enviarlo; descartar con motivo (cada motivo deja una lección para su capa); marcar como resuelta, deduciendo cómo (respondió, otro respondió, hubo reunión) o preguntándolo como mucho una vez por semana. Caducidad en parte: el aviso se cierra solo si responde en Slack o Gmail, y un mensaje nuevo de la misma conversación sustituye al anterior Caduca solo si otra persona lo resolvió en el hilo (sin devolverle la pregunta) o si la llamada pedida ya pasó, y queda registrado como caducado. La lista y el centro de avisos ordenan por plazo más cercano y por quién le espera. Los avisos de baja urgencia (relevancia baja, nadie esperando y sin plazo en dos días) no suenan y se agrupan en un resumen a las 9:30 y a las 15:30 | Umbral de baja urgencia afinado con datos |
| 5 · Aprender | Cada señal tiene su peso en código (alto: modificar, descartar con motivo, elegir personas, cambiar la duración; medio: enviar tal cual, resolver, caducar; bajo: descartar sin motivo). Las de peso alto se convierten en lecciones cortas (un modelo ligero) por capa (importancia, a quién le toca, qué se pide, respuestas, agenda) y, si es sobre alguien, por persona; cada capa lee las suyas, primero las de quien escribe, y también el agente Comunicación. Una lección nueva del mismo asunto sustituye a la anterior y las que no se usan en 60 días dejan de leerse. Un «sí» o un «no» a una acción en duda entra en el conjunto de evaluación del clasificador. Si casi todo sale tal cual, se muestra una muestra semanal para revisarla. Las lecciones se ven, corrigen y borran en Configuración › Lecciones | Lecciones para los demás agentes; casos de evaluación desde ediciones de respuestas |

## Decisiones abiertas

Cada una lleva una propuesta para validar o cambiar.

- [ ] **Ventana de hueco por urgencia.** Propuesta: ahora → hoy; hoy → 24 h; esta semana → 5 días laborables. Si no hay hueco, ampliar una vez al siguiente nivel.
- [ ] **Cuándo deja de preguntar la duración.** Propuesta: tras 3 confirmaciones iguales para el mismo tipo de reunión con las mismas personas. Vuelve a preguntar si él la cambia.
- [ ] **Selector de personas.** Propuesta: las de la conversación marcadas; las de su grafo habitual como sugerencia, sin marcar.
- [ ] **Compromisos propios.** Propuesta: Slack y emails enviados (los compromisos con externos suelen estar en el email).
- [ ] **Umbral de confianza.** Qué nivel separa «segura» de «en duda» en cada clase, y si se ajusta por persona.
- [x] **Ventana para deshacer.** Decidido: 6 segundos, igual para todas las acciones.
- [ ] **Datos viejos.** Propuesta: marcar fuentes sin actualizar en más de 2 semanas.
- [ ] **Resumen agrupado.** Cuántas veces al día y a qué horas.

## Registro de cambios

| Fecha | Cambio |
| --- | --- |
| 2026-10-31 | Etapa 10: las seis métricas por semana y por tipo de acción en Configuración › Impacto; «se escaparon» desde la detección de Slack. Objetivos pendientes de dos semanas de datos. |
| 2026-10-31 | Etapa 9: aprender de cada interacción. Pesos de las señales, lecciones por capa y persona escritas por un modelo ligero, que se sustituyen o caducan; casos de evaluación desde las correcciones; revisión de la inercia; página de Lecciones en Configuración. |
| 2026-10-31 | Etapa 8: ciclo de vida. Caducidad cuando otro lo resuelve o la llamada ya pasó; orden por plazo y bloqueo; los avisos de baja urgencia no suenan y van a un resumen dos veces al día. |
| 2026-10-31 | Etapa 7: cada propuesta cita sus fuentes con fecha; el destinatario se clasifica (organización, otro equipo, fuera) y los datos internos que van fuera piden un «sí» más. |
| 2026-10-31 | Etapa 6: preguntas. Búsqueda antes de redactar (Jira con búsqueda cerrada, Archivo, Notion, reuniones y memoria), candidatos para elegir, «no encontrado» sin inventar y marca de datos viejos a los 14 días. |
| 2026-10-31 | Etapa 5: tareas. Compromisos propios en Slack y email, tres huecos antes del plazo con margen, cambiar hueco o duración con un toque, un «sí» para la tarea y la respuesta, y seguimiento el día antes del plazo. |
| 2026-10-31 | Etapa 4: llamadas. Ventana por urgencia ampliada una vez, selector de personas, duración inferida, aprendida o preguntada, huecos ofrecidos a quien no tiene agenda visible, respuesta escrita con el hueco y un solo «sí» para el evento y el mensaje. |
| 2026-10-31 | Etapa 3: clasificador de acciones aparte del motor de respuestas (que deja de proponer «tareas manuales»); cada aviso guarda sus acciones con su confianza, una duda se resuelve con un «sí» o un «no» por voz o con un toque, y un solo «sí» confirma todo lo marcado. Conjunto de evaluación de 25 mensajes (`agents:eval clasificador`). |
| 2026-10-31 | Etapa 2: descartar pide el motivo con un toque y cada motivo deja una lección que leen el juez, el triaje y el motor de respuestas; «resuelta» deduce cómo se resolvió o lo pregunta como mucho una vez por semana. |
| 2026-10-31 | Etapa 1: se registra qué pasa con cada propuesta de un aviso (enviada tal cual, editada y cuánto, deshecha, descartada, resuelta o caducada), venga del botón, de la voz o de un agente; la respuesta se puede modificar antes de enviarla. |
| 2026-10-31 | Etapa 0: las tareas propias se proponen y solo se crean en el calendario tras el «sí»; al confirmarse quedan agendadas con su evento y se pueden quitar. |
| 2026-10-31 | Plan de ejecución por etapas (0 a 10) en `human-interaction-execution-plan.md`. |
| 2026-10-31 | Excepción del principio 1 para la autonomía «Hacer» de Slack. Ventana para deshacer: 6 segundos en toda la app (antes 8). Estado del pilar 4: la caducidad ya existe en parte. |
| 2026-10-31 | Revisión: principios transversales; la duda pasa a ser nivel de confianza por clase; varias clases por aviso; criterio de tarea con tres condiciones y huecos antes del plazo; guardarraíles en preguntas; motivo al descartar; ciclo de vida del aviso; pesos de las señales; métricas; propuestas para las decisiones abiertas. |
| 2026-10-31 | Documento creado con los pilares 0 a 5 y el estado actual. |
