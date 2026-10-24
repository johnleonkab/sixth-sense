# sixth-sense · Orden de implementación

Criterio: **primero algo que se pueda usar, luego hacerlo real, y al final dejar que escriba.**

- La interfaz se construye pronto con datos de ejemplo, para poder probar la experiencia antes de conectar nada.
- Un servicio de punta a punta antes que siete a medias.
- Leer antes que escribir. Las acciones de escritura llegan cuando la lectura es fiable.

Cada fase termina con algo que se puede probar hablando.

---

## Fase 0 · Esqueleto y modo demo
**Objetivo:** la sixth-sense funcionando en Laravel con datos falsos y entrada por teclado.

- Proyecto Laravel + Inertia + React + TypeScript + Tailwind, PostgreSQL, Redis, Horizon, Reverb, Pest.
- Login (una sola persona).
- Layout de la sixth-sense según `docs/prototype.html`: zona de contenido (máx. 2 columnas) y zona de voz (lo que dices / lo que responde).
- Registro de vistas con todas las vistas del prototipo, alimentadas por los `Fake` de cada conector.
- Interfaz `Connector` y un `Fake` por servicio.

**Hecho cuando:** escribiendo las frases del prototipo, la sixth-sense muestra las mismas pantallas que el prototipo.

## Fase 1 · Bucle de voz
**Objetivo:** hablar en lugar de escribir.

- Endpoint que emite el token temporal del proveedor de voz.
- Transcripción en streaming con texto parcial en vivo.
- Detección de fin de frase, síntesis de voz de la respuesta, interrupción.
- Botón y orden de **escucha activa** (on/pausa).
- Bucle rápido en el cliente: siguiente/anterior, abre el N, amplía, vuelve, cierra todo.

**Hecho cuando:** se recorre la jornada del prototipo solo hablando, con los datos falsos.

## Fase 2 · Agente y protocolo del lienzo
**Objetivo:** que las frases las entienda Claude, no reglas fijas.

- `POST /turns` con `screen_context`.
- Prism + Claude con las herramientas de solo lectura, conectadas a los `Fake`.
- Respuesta en directivas de UI (`say`, `layout`, `show`), validadas contra un esquema.
- Registro de `VoiceTurn` con latencia.

**Hecho cuando:** "¿cómo es mi día?", "¿qué me ha pedido Marta?" o "abre el segundo" funcionan con frases distintas a las del prototipo.

## Fase 3 · Google de verdad (solo lectura)
**Objetivo:** primer servicio real de punta a punta.

- OAuth con Google, tokens cifrados y refresco automático.
- Calendar (día, semana, huecos libres) y Gmail (buscar, leer, adjuntos PDF en el visor).

**Hecho cuando:** "¿cómo es mi día?" muestra el calendario real y "enséñame el último email de X" abre el email real.

## Fase 4 · Slack y Jira (solo lectura)
- OAuth de Slack y Atlassian.
- Slack: buscar, leer hilos. Jira: buscar, ver tickets y comentarios.

**Hecho cuando:** "¿qué tickets tengo bloqueados?" y "enséñame el hilo de Marta" usan datos reales.

## Fase 5 · Avisos proactivos, inbox y sonidos
**Objetivo:** que la sixth-sense te avise sin preguntar.

- Webhooks de Slack y Jira con verificación de firma; push de Gmail y Calendar.
- Normalización a `InboxCandidate`, job de relevancia con su motivo, `InboxItem`, evento `InboxItemCreated` por Reverb.
- Inbox (lista + detalle, filtros por servicio, números para señalar por voz).
- `AlertSetting`: sonido por servicio y "leer en voz alta".
- Con escucha activa: la IA anuncia el aviso y ofrece abrirlo ("ábrelo" / "luego").

**Hecho cuando:** una mención real en Slack suena con su sonido, aparece en el inbox y, con escucha activa, se anuncia en voz alta.

## Fase 6 · Notion, Docs y Sheets (lectura y avisos)
- OAuth de Notion. Lectura de páginas y comentarios; avisos de comentarios y tareas asignadas.
- Google Docs (documento) y Sheets (rango + gráfico simple).

**Hecho cuando:** los siete servicios se pueden consultar y mostrar hablando.

## Fase 7 · Escritura con confirmación y deshacer
**Objetivo:** actuar, no solo mirar.

- `PendingAction` + directiva `confirm` + botones Sí/Cancela + orden por voz.
- Ejecución diferida con ventana de deshacer de 8 s.
- Herramientas, por orden: responder en Slack → borrador/respuesta en Gmail → mover/crear evento → comentar en Jira → cambiar estado en Jira → comentar en Notion.

**Hecho cuando:** "responde a Marta que lo reviso a las 11" muestra el borrador, se envía al decir "sí" y se puede deshacer.

## Fase 8 · Memoria del día
- Modelo `Person` que une la misma persona en Slack, Gmail, Jira y Notion.
- "Lo de Marta" cruza todos los servicios.
- Resumen de la mañana y de cierre del día.

**Hecho cuando:** "¿qué tengo pendiente con Pablo?" junta Slack, email y Jira en una sola respuesta.

## Fase 9 · Pulido
- Latencia (objetivo: menos de 1,5 s con herramientas), caché de lecturas frecuentes.
- Errores contados por voz en lenguaje natural ("no puedo entrar en Jira, hay que volver a conectarlo").
- Reconexión de cuentas caducadas, estados sin conexión, coste por día.

---

## Épicas siguientes
- **Wingman:** un agente que escucha tus llamadas de Meet sin que se note, toma notas y propone acciones. Ver [`docs/epica-wingman.md`](epica-wingman.md).
- **Escenas:** un encargo de varios pasos se ve en pantalla mientras ocurre. Gemini solo habla. DeepSeek redacta y responde a los agentes. Ver [`docs/epica-escenas.md`](epica-escenas.md).

---

## Si hay que recortar

El mínimo que ya es útil en el día a día: **fases 0 a 3 + la fase 5 solo con Gmail.** Es decir, hablar con tu calendario y tu correo, con avisos de los emails importantes.