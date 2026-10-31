# Pilares de interacción humana

Oct 31, 2026 · @John · Documento vivo: se actualiza cada vez que se implementa o cambia un comportamiento (ver el registro al final).

Cuando llega un mensaje (Slack o email), el sistema decide **si hay que hacer algo, qué y con quién**, lo deja preparado y la persona solo revisa: envía con un botón, lo cambia, lo descarta o lo da por resuelto. Lo que haga la persona es la señal con la que el sistema aprende.

Complementa a [Pilares de producto: triaje agéntico de Slack](Pilares%20de%20producto%20triaje%20agéntico%20de%20Slack.md), que decide **si un mensaje importa y a quién le toca**. Este documento empieza donde aquel acaba: una vez que importa, **qué acción toca y cómo interviene la persona**.

---

## Pilar 0 — Quién decide que hay que actuar

Después del filtro de importancia, cada mensaje cae en una de estas clases, y cada clase tiene su propio flujo:

| Clase | Ejemplo | Flujo |
| --- | --- | --- |
| **Llamada** | «¿Lo vemos en una llamada?» dirigido a él | Pilar 1 |
| **Tarea** | Le piden un informe; él se compromete a algo con fecha | Pilar 2 |
| **Pregunta** | «¿Cómo va la feature X?», «¿dónde está la doc de Y?» | Pilar 3 |
| **Solo responder** | Un «ok», una confirmación, un dato que ya sabe | Respuesta o reacción, sin más |
| **En duda** | Se habla de una llamada, pero no está claro que sea con él | Aviso en duda: se le pregunta, no se actúa |

Reglas:

- **Ante la duda, preguntar; nunca suponer.** Un aviso «en duda» dice qué no está claro («no sé si esta llamada te incluye») y deja elegir.
- **Una acción solo se ejecuta tras el «sí»** de la persona (botón o voz), con la ventana para deshacer. Esto incluye crear eventos en su propio calendario.
- Cada clase se puede combinar con una respuesta: agendar una llamada y contestar en Slack van en la misma propuesta y se confirman con un solo toque.

## Pilar 1 — Llamadas

Alguien, en un DM o en un canal, pide una llamada.

1. **¿Es con él?** Por el contexto de la conversación hay que inferir que la llamada **le incluye específicamente**. Si no está claro → aviso **en duda** («no sé si hace falta una llamada contigo»), sin preparar nada.
2. **¿Con quién?** El agente debe estar seguro de quiénes tienen que estar.
   - Si lo está, sigue.
   - Si no, pregunta, mostrando un **selector de personas** (human in the loop) con las candidatas ya marcadas.
3. **¿Cuánto dura?** Entre 15 minutos y 1 hora la decide el sistema sin validarla. Si no sabe, pregunta la duración, hasta que aprenda las duraciones habituales de la persona.
4. **Buscar hueco** en las agendas de todos, en una ventana que depende de la urgencia.
5. **Si hay hueco en la ventana:** se prepara el evento **y** una respuesta corta y natural en Slack (o email) con la propuesta. Un «sí» crea el evento y envía el mensaje.
6. **Si no hay hueco:** se prepara igualmente una respuesta que dice que no se encontró, y pregunta qué días o franjas tienen más flexibilidad.

## Pilar 2 — Tareas

Una tarea en el calendario tiene que merecer la pena: «Revisar petición de Luis» no lo es. La IA tiene que mejorar mucho en decidir si algo **es de verdad una tarea**.

Es una tarea cuando hay un entregable concreto, alguien lo espera y hay (o se puede inferir) un plazo. Ejemplos reales:

- **Él se compromete:** le dice a alguien «creo la tarea en Jira y te la paso antes del día X». El sistema registra **también sus propios mensajes**, y le pregunta si quiere añadirlo al calendario para que no se le olvide.
- **Le piden algo:** un informe, que mande X… Mientras no haya respondido, y tras pasar el filtro de importancia, salta un aviso que pregunta si quiere añadirlo al calendario y si quiere responder.

No es una tarea: leer un hilo, revisar algo corto, contestar, «cuando puedas échale un ojo» sin entregable.

Flujo:

1. Aviso: «Patricia te pide el informe de X para el jueves».
2. Ya preparados: **la plantilla del mensaje** de respuesta y **4 o 5 huecos** posibles en el calendario, con la duración elegible.
3. Él elige hueco y duración (o los deja como están) y dice que sí.
4. Solo entonces se crea la tarea en el calendario **y** se envía el mensaje.

## Pilar 3 — Preguntas

Si el mensaje es una pregunta, el sistema sí debe ser **muy proactivo**: buscar la respuesta y dejarla redactada.

- **Estado de una feature** → buscar en el índice de Jira los issues que coinciden, listar los candidatos y afinar con una búsqueda cerrada sobre el que encaja; la respuesta cita el issue y su estado.
- **Documentación, proyectos, contexto** → el Archivo, el contexto personal y Notion.
- Si no encuentra la respuesta, lo dice en el aviso; no la inventa.

## Pilar 4 — La interacción humana

La persona solo revisa. Para cada aviso:

1. **Recibe el aviso** con lo que se pide y lo que está preparado: «Patricia te pide …, revisa esto antes de que lo envíe».
2. **Envía o modifica.** Enviar con un botón siempre es posible; modificar abre el borrador.
3. **Descarta** → queda marcado como **interacción no importante**, como señal de entrenamiento para el futuro.
4. **Marca como ya resuelta** → el sistema hace, sin prioridad, una ronda para entender **cómo se resolvió** (respondió en otro sitio, lo habló en persona, lo hizo otro…) y la registra para entrenamiento.

## Pilar 5 — Aprender de cada interacción

Cada decisión de la persona es una señal:

| Señal | Qué se aprende |
| --- | --- |
| Envía tal cual | La clase y el borrador eran correctos |
| Modifica antes de enviar | La diferencia: tono, datos, destinatarios, duración |
| Descarta | No era importante (o no era para él) |
| Marca como resuelta | Cómo se resuelve ese tipo de cosa fuera del sistema |
| Elige personas en el selector | Quién suele estar en esas llamadas |
| Cambia la duración | Duraciones habituales por tipo de reunión |

Se registran por persona y alimentan las lecciones de los agentes y sus casos de evaluación (ver el bucle de aprendizaje en la [arquitectura multiagente](https://claude.ai/artifact/25Z4jaUXhsjgazq5SVtNx6)).

---

## Estado actual frente a los pilares

| Pilar | Hoy | Falta |
| --- | --- | --- |
| 0 · Quién decide | El juez y el triaje deciden si importa y a quién le toca; el motor de respuestas propone respuesta, reacción, encargo a otro y una «tarea manual» con un criterio amplio | Las clases llamada / tarea / pregunta / en duda; el aviso «en duda» |
| 1 · Llamadas | Si la tarea manual tiene personas, el agendador busca hueco (también en su ocupación) y propone la reunión, que espera el «sí» | Comprobar que la llamada le incluye; selector de personas; preguntar duración; responder en Slack con el hueco en el mismo «sí»; el mensaje cuando no hay hueco |
| 2 · Tareas | Una tarea propia **se crea directamente en el calendario, sin «sí»**; el criterio de qué es una tarea es amplio | Criterio estricto; detectar sus propios compromisos; aviso con 4-5 huecos y duración elegible; crear solo tras el «sí», junto con el mensaje |
| 3 · Preguntas | Antes de redactar se busca en el hilo, enlaces, archivo, documentos y respuestas anteriores | Búsqueda en el índice de Jira con lista de candidatos y búsqueda cerrada para el estado de una feature; Notion y contexto personal en esa búsqueda |
| 4 · Interacción | Aviso con propuesta; enviar con un toque (Slack y Gmail); descartar; marcar como resuelta | Que descartar quede como señal «no importante»; la ronda de «cómo se resolvió» |
| 5 · Aprender | Lecciones del motor de respuestas; correcciones de triaje que proponen reglas; registro de cada ejecución de agente | Registrar el resultado de cada propuesta (enviada, modificada, descartada, resuelta) y convertirlo en lecciones y casos de evaluación |

## Decisiones abiertas

- [ ] Ventana de búsqueda de hueco por urgencia (por ejemplo: ahora → hoy; hoy → 24 h; esta semana → 5 días laborables).
- [ ] Cuándo deja de preguntar la duración: tras cuántas confirmaciones del mismo tipo de reunión.
- [ ] El selector de personas: solo las de la conversación, o también las de su grafo habitual.
- [ ] Si los compromisos propios se detectan solo en Slack o también en emails enviados.

## Registro de cambios

| Fecha | Cambio |
| --- | --- |
| 2026-10-31 | Documento creado con los pilares 0 a 5 y el estado actual. |
