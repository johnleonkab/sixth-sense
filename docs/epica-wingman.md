# Épica · Wingman

**Qué es:** Wingman, un agente aparte que escucha tus llamadas de Google Meet sin que nadie lo note, toma notas en tiempo real y, cuando detecta algo importante, te da en pantalla un dato o una propuesta: un hueco para el seguimiento, el estado de un ticket, la página de Notion de la que se habla, el mensaje que acabas de prometer mandar. Al terminar queda un documento de la reunión con todas las acciones propuestas, que ejecutas o descartas.

**Por qué:** en una llamada no puedes buscar en Jira, mirar el calendario y tomar notas a la vez. Wingman lo hace por ti y solo interrumpe cuando aporta algo.

**Estimación:** ≈12,5 días (unas 2,5 semanas). Va después de la ronda de solidez (estado del sistema, workers automáticos).

**Rama:** `feature/wingman`.

## Nombre

La función se llama **Wingman** en todo el sistema:

| Dónde | Nombre |
|---|---|
| Interfaz y voz | "Wingman" ("Wingman está escuchando", "Configuración → Wingman") |
| Código PHP | namespace `App\Wingman`, modelos `WingmanMeeting`, `WingmanNote`, `WingmanInsight` |
| Configuración | `config/wingman.php`, variables `WINGMAN_*` |
| Rutas | `/wingman` (documentos), `/wingman/{meeting}`, API `/wingman/extension/*` |
| Frontend | `resources/js/wingman/`, vistas `wingman.live` y `wingman.doc` |
| Extensión de Chrome | "Wingman para Meet", en `extensions/wingman-meet/` |
| Eventos | `WingmanMeetingStarted`, `WingmanInsightCreated`, canal privado del usuario |

---

## Principios

1. **Invisible en la llamada.** Ningún bot, participante ni aviso en Meet. Para los demás, la reunión es exactamente igual.
2. **Silencioso.** Solo tarjetas en tu pantalla: ni sonidos ni voz, que podrían colarse en la llamada.
3. **Solo aporta.** Una tarjeta solo aparece si trae algo que no se ha dicho (un dato, un enlace, una propuesta), y siempre dice **por qué** y **de dónde** lo saca.
4. **Leer libre, escribir con "sí".** Durante la llamada Wingman solo lee. Todo lo que cambia algo (crear una reunión, mandar un mensaje) queda como propuesta, que confirmas desde tu pantalla, nunca por voz en la llamada.
5. **Sin ruido en la pantalla de inicio.** Wingman aparece cuando estás en una llamada; el resto del tiempo, sus documentos están a mano pero no a la vista.

## Cómo funciona

```
Google Calendar (cuentas conectadas)      Extensión de Chrome "Wingman para Meet"   
  └─ reuniones de hoy con enlace de Meet    ├─ detecta que entras / sales de la llamada
     + contexto previo (asistentes,         ├─ captura lo que se dice, con quién lo dice
       descripción, tickets, emails)        └─ lo envía a sixth-sense (token personal)
                     │                                         │
                     └──────────────┬──────────────────────────┘
                                    ▼
                    Reunión en curso (WingmanMeeting) ── Reverb ──► Burbuja de Wingman
                                    │                         └─ abre el contexto de la llamada
                     transcripción por bloques (~20 s)
                                    ▼
                    Agente Wingman (Gemini, con herramientas de solo lectura)
                    ├─ notas en vivo: temas, decisiones, preguntas abiertas
                    ├─ datos: estado de un ticket, página de Notion
                    └─ propuestas: hueco en el calendario, mensaje preparado
                                    ▼
                    Documento de la reunión  ──►  al terminar: Ejecutar / Descartar cada acción
```

---

## Historias

### H0 · Spike: captura invisible en Meet (1 día)
Decidir cómo se captura la llamada. Las opciones, todas desde una extensión de Chrome:

| Opción | Cómo | A favor | En contra |
|---|---|---|---|
| **A. Subtítulos de Meet** | La extensión activa los subtítulos solo para ti, los oculta y los lee | Trae el **nombre de quien habla**, no procesa audio, es barata | Depende de la interfaz de Meet; la calidad es la de los subtítulos de Google |
| **B. Audio de la pestaña** (`tabCapture`) | La extensión captura el audio de la pestaña de Meet, más tu micrófono | Calidad controlada; sirve para otras plataformas | Chrome pide un clic en la extensión por llamada; hay que separar a quién habla |
| **C. Compartir pestaña desde sixth-sense** | Diálogo de "compartir pestaña" de Chrome | No hace falta extensión | Un diálogo en cada llamada: no es automático |

**Hipótesis:** A como vía principal (automática e invisible, con nombres), con B como alternativa si los subtítulos no bastan.

**Hecho cuando:** hay una prueba en una llamada real en español que confirma calidad, nombres y que **nadie más ve nada**, y la decisión queda escrita aquí.

**Prototipo:** `extensions/wingman-meet/`, sin servidor. Detecta la llamada, activa y oculta los subtítulos, y guarda quién dice qué. Se exporta desde su ventana.

**Cómo probarlo:**
1. Chrome → `chrome://extensions` → activar *Modo desarrollador* → *Cargar descomprimida* → elegir `extensions/wingman-meet`.
2. Fijar la extensión en la barra (icono de puzle → chincheta).
3. En Meet, idioma de los subtítulos en **español**: Ajustes → Subtítulos.
4. Entrar en una llamada de prueba (mejor con otra persona, o con el móvil como segundo participante) y hablar 3–5 minutos, con turnos largos y cortos.
5. Abrir la ventana de Wingman: ver las frases y quién habla. Probar *Mostrar subtítulos* y *Probar 6 s de audio* (opción B).
6. Al colgar: *Exportar transcripción* y *Exportar HTML de subtítulos*, y pasar los dos archivos.

**Resultados** (a rellenar tras la prueba):

Prueba 1 (13/10, una persona, ~2 min):

| Pregunta | Resultado |
|---|---|
| ¿Detecta la entrada y la salida (y no la sala de espera)? | ✅ Entrada y código. Salida: pendiente (se exportó durante la llamada) |
| ¿Activa los subtítulos solo? ¿Quedan ocultos? | ✅ Los activa solo y quedan ocultos |
| ¿Alguien más vio algo distinto en la llamada? | Pendiente: hace falta otra persona |
| Calidad en español (¿se entiende lo que se dijo?) | ⚠️ Arranque flojo ("Que Dallas. Quetal Estas…"); después, buena |
| ¿Nombres correctos? ¿"Tú" para tu voz? | ✅ "Tú". Otros nombres: pendiente |
| ¿Turnos largos completos, sin cortes ni repeticiones? | ✅ Un turno de ~35 palabras, entero y sin repeticiones |
| Opción B: ¿captura el audio? ¿Se sigue oyendo la llamada? | Pendiente |
| **Decisión** | Pendiente de la prueba 2 |

Lo aprendido del HTML real: los subtítulos están en `role="region"` con `aria-label="Subtítulos"`. Cada intervención es un bloque con avatar, nombre (`span`) y texto. Meet **guarda el historial** en la región, que tiene desplazamiento y un botón "Ir al final", así que no solo muestra las últimas líneas.

### H1 · Reuniones desde Google Calendar (1 día)
- De todas las cuentas de Google conectadas con permiso de calendario, las reuniones de hoy con enlace de Meet se guardan como `WingmanMeeting`, enlazadas por el código de la reunión (`abc-defg-hij`).
- **Contexto previo**, preparado unos minutos antes:
  - asistentes y descripción del evento;
  - tickets de Jira y páginas de Notion que se mencionan;
  - últimos emails con los asistentes.

**Hecho cuando:** al abrir una reunión de hoy se ven sus asistentes y su contexto, sin estar todavía en la llamada.

**Estado:** ✅ hecho (13/10).
- `wingman:sync` corre cada 10 min para quien activó Wingman: `App\Wingman\WingmanMeetings`, el modelo `WingmanMeeting` y el job `PrepareWingmanContext`.
- `App\Wingman\MeetingContext` reúne el contexto: documentos del evento, tickets nombrados (con sus bloqueos), páginas de Notion enlazadas o relacionadas y los últimos emails de los asistentes. Si un servicio falla, el resto sigue.
- Páginas `/wingman` (las de hoy se buscan al abrir; también se ven las anteriores) y `/wingman/{id}` (contexto, se prepara al abrirla si hace falta).
- Configuración → Wingman: el interruptor. Wingman empieza apagado.

### H2 · Extensión "Wingman para Meet" (2 días)
- Detecta que **entras** en la llamada (no la sala de espera) y que **sales**, y cuál es por su código.
- Se empareja con sixth-sense una vez, desde Configuración, con un token personal revocable.
- Envía a Laravel el inicio, el fin y los fragmentos de lo que se dice (quién, qué, cuándo).
- Todo ocurre solo en tu navegador: no se ve nada en la llamada, y los subtítulos quedan ocultos también para ti si los usa.
- Se instala sin publicarla en la Chrome Web Store (uso personal).

**Hecho cuando:** al entrar en una llamada, Laravel registra el inicio y empiezan a llegar fragmentos con nombre.

**Estado:** ✅ hecho (13/10).
- La extensión tiene un worker (`background.js`) que envía a sixth-sense con el token personal y guarda lo que no pudo enviar para reintentarlo.
- La página de Meet (`content.js`) avisa al entrar, manda lo nuevo cada 2 s y cierra al salir o al cerrar la pestaña.
- API en `/api/wingman/extension/*` (`ping`, `calls`, `calls/{id}/segments`, `calls/{id}/end`), con `AuthenticateWingmanExtension`.
- Una llamada se enlaza con su reunión del calendario por el código; si no está en ningún calendario, se sigue igual.
- Las frases (`WingmanSegment`) se guardan por id y crecen al reenviarse. "Tú" se marca como la persona.
- Una llamada sin noticias durante 15 min se da por terminada.
- El token se genera y se revoca en Configuración → Wingman.

### H3 · Burbuja de Wingman (1 día)
- Al entrar en una llamada aparece **siempre** la burbuja de Wingman en sixth-sense, aparte de la de alertas y con el mismo estilo.
- Muestra el estado en vivo: "Wingman escuchando · 12 notas · 2 propuestas".
- Al abrirla se ve el **contexto de la llamada**: reunión, asistentes, notas en vivo, tarjetas de Wingman y acciones propuestas.
- La burbuja permite **pausar la escucha** de esa llamada.
- Desaparece al salir. El documento queda accesible (H7).

**Hecho cuando:** entrar en Meet hace aparecer la burbuja en menos de 5 s, y al abrirla se ve la llamada en curso.

**Estado:** ✅ hecho (14/10).
- `WingmanBubble` va junto a la burbuja de avisos en todas las páginas y solo aparece durante una llamada.
- Se entera al momento con los eventos `wingman.meeting.started/ended/updated` y, mientras la llamada sigue, pide `/wingman/live` cada 4 s.
- Abierta muestra: tiempo en la llamada, asistentes, tickets y páginas del contexto, y las últimas frases ("Tú" resaltado).
- **Pausar** (`paused_at`): lo que se dice durante la pausa no se guarda, y la extensión lo indica.
- **Abrir** lleva a `/wingman/{id}`, que muestra la conversación y se actualiza sola mientras la llamada sigue.
- Las notas y propuestas llegarán con H4 y H5; de momento la burbuja cuenta frases.

### H4 · Notas en tiempo real (1 día)
- La transcripción se procesa por bloques: cada ~20 s o al cambiar de turno.
- Un agente de notas mantiene el documento vivo: temas, decisiones, preguntas abiertas y quién dijo qué cuando importa.
- Distingue tu voz de la del resto, porque H5 lo necesita para "si **yo** digo que…".

**Hecho cuando:** durante una llamada de prueba, las notas se actualizan solas y reflejan lo hablado.

**Estado:** ✅ hecho (14/10).
- `LiveNotes` lleva a las notas las frases cerradas que faltan: como mucho cada 20 s mientras se habla, y al colgar en el momento. Lo hace a través del job `TakeWingmanNotes`, único por reunión.
- `GeminiNoteTaker` (`gemini-3.8-flash`, con modelo de reserva) recibe las notas hasta ahora y lo nuevo, y devuelve las notas completas: resumen, temas, decisiones, preguntas abiertas y compromisos ("Tú" para la persona). Con una conversación de ejemplo tardó unos 4 s y acertó decisiones, preguntas y compromisos.
- Si falla, las frases quedan pendientes para la siguiente pasada.
- La burbuja muestra el resumen, las preguntas abiertas y "N notas"; la página de la reunión, el documento entero.

### H5 · Agente Wingman (2,5 días)
Es un agente distinto del de voz. Lee los bloques y el contexto y decide si hay algo que aportar. Con herramientas de solo lectura:

| Detecta | Hace | Tarjeta |
|---|---|---|
| Se habla de buscar un hueco ("¿lo vemos la semana que viene?") | Busca huecos libres para **todos los asistentes** (calendario y disponibilidad) | "Jueves 10:00 está libre para los cuatro" → **acción propuesta: crear la reunión** |
| Se habla de una tarea de Jira cuyo estado no se dice o no está claro | La busca | "CPD-412 sigue en *To Do* y depende de CPD-405 (*In Progress*)" + enlace |
| Se menciona una página de Notion | La busca | "La página *Prepago v4* es esta" + enlace |
| **Tú** dices que mandarás un mensaje a X sobre Y | Prepara el mensaje (Slack o email, según cómo habléis X y tú) | Borrador listo → **acción propuesta: enviar** |

- **Freno de ruido:** umbral de confianza, sin repetir lo ya dicho, como mucho una tarjeta cada ~2 minutos salvo que sea importante, y 👍/👎 en cada tarjeta para afinar.
- **Ejecutar:** las propuestas se crean como `PendingAction` y no se ejecutan nunca solas. Su "sí" llega desde tu pantalla (H6).
- **Latencia objetivo:** una tarjeta en menos de 15 s desde que se dice.

**Hecho cuando:** en una llamada de prueba con esas cuatro situaciones, aparecen las cuatro tarjetas correctas y ninguna sobra.

**Estado:** ✅ hecho (14/10).
- Funciona en dos pasos: **Gemini detecta** (`GeminiDetector`, que solo dice qué merece comprobarse) y **el código comprueba con datos reales** (`Lookups`: SlotFinder con la disponibilidad de los asistentes, Jira, Notion, Slack y Gmail). Así una tarjeta nunca se inventa datos.
- `WingmanAgent` lee lo nuevo cada 12 s mientras se habla, con el job `WatchWingmanCall`.
- Freno de ruido: confianza ≥ 0,6, nada repetido en la misma llamada (`dedupe_key`), como mucho 2 tarjetas por lectura, y 90 s de silencio tras una tarjeta salvo que sea urgente.
- Lo que propone (convocar, mandar un Slack o un email) se guarda en la tarjeta y se convierte en acción pendiente al revisarlo (H6), porque las acciones pendientes caducan a los 10 min.
- Lo dicho de pasada se encuentra igual: «el 412» → CPD-412, y un nombre de página aproximado se busca acortándolo.
- Si Gemini detecta un mensaje prometido sin su texto, lo redacta en una segunda llamada.
- Prueba real con Gemini, con una conversación de ejemplo: 4 tarjetas correctas en ~3,5 s (CPD-412 y su bloqueo, la página del plan, el mensaje a Marta listo en Slack y el lunes 19 a las 10:00 libre para los tres).
- La burbuja muestra las tarjetas arriba, anuncia en su píldora la nueva durante 10 s, en silencio, y permite 👍/👎 y descartar. La página de la reunión las lista todas.

### H6 · Documento de la reunión (1,5 días)
- Durante la llamada se va formando: resumen, decisiones, notas, datos aportados y **acciones propuestas**.
- Al terminar, Wingman lo cierra con un resumen final y la lista de acciones: crear la reunión de seguimiento, hablar con X, mandar el mensaje a Y, crear el ticket…
- Cada acción tiene **Ejecutar** y **Descartar**, con la misma confirmación y los mismos segundos para deshacer de siempre.
- Por voz: "repásame lo de la reunión", "ejecuta la 2", "descarta las demás".

**Hecho cuando:** tras una llamada de prueba, el documento tiene el resumen y las acciones, y ejecutar una la hace de verdad.

**Estado:** ✅ hecho (14/10).
- **`WingmanAction`:** lo que queda por hacer. Entra al momento lo que traen las tarjetas preparadas y, al colgar, lo que sale de tus compromisos.
- **Cierre:** `CloseWingmanMeeting` ejecuta `MeetingCloser` cuando cuelgas o cuando la llamada se da por abandonada.
  - Primero pasa las últimas líneas a las notas.
  - Después `GeminiFollowUpPlanner` convierte los compromisos de «Tú» en cosas listas para revisar: un mensaje redactado (Slack o email), una reunión con hueco para todos, un ticket de Jira en el proyecto de tus reglas, o tiempo reservado para una tarea tuya (`agenda.schedule`, nuevo tipo de acción que usa el agendador).
  - No repite nada de lo que ya estaba preparado.
  - Prueba real: unos 5 s. Saltó el compromiso de otra persona, redactó el mensaje a Andrés y convirtió «el jueves» en fecha límite.
- **Revisar → «Sí» → unos segundos para deshacer**, con la misma `ActionBar` de siempre; también Descartar y Recuperar. Una acción cancelada, deshecha o caducada se puede volver a revisar.
- **Por voz:** `review_meeting` («repásame lo de la reunión», canvas `wingman.doc`), `propose_meeting_action` («ejecuta la 2») y `dismiss_meeting_actions` («descarta las demás»).

### H7 · Sección Wingman, a mano y sin ruido (1 día)
- **Sin nada nuevo en la pantalla de inicio.** Se llega a ella:
  - desde la burbuja ("Reuniones anteriores");
  - por voz ("abre las notas de la reunión con Marta");
  - en `/wingman`, con una lista por fecha y búsqueda.
- **Configuración → Wingman:**
  - activar o desactivar Wingman;
  - en qué calendarios;
  - reuniones que nunca escucha (por ejemplo, las que contengan "1:1" o "RRHH");
  - cuánto se guarda (H8).

**Hecho cuando:** cualquier reunión pasada se abre en dos pasos desde la burbuja o con una frase.

**Estado:** ✅ hecho (14/10).
- Sin nada nuevo en la pantalla de inicio.
- Se llega a las reuniones:
  - desde la burbuja (enlace «Reuniones»);
  - desde Configuración;
  - por voz, con `review_meeting`, que busca por título, asistentes o tema («la reunión con Marta»);
  - en `/wingman`, con búsqueda en las anteriores (título, persona o tema).
- Configuración → Wingman: interruptor, calendarios que sigue (todos por defecto), reuniones que nunca escucha (palabras del título) y transcripción (`users.wingman_settings`, `WingmanSettings`).

### H8 · Privacidad y conservación (0,5 días)
- **Por defecto** no se guarda la transcripción: se borra al cerrar el documento. Se guardan las notas, el resumen y las acciones. Es configurable.
- Pausar o reanudar la escucha en cualquier momento desde la burbuja.
- Aviso claro en Configuración de que el audio o el texto de la llamada se procesa con Gemini (Google).
- Nada se escribe en ningún servicio durante la llamada sin tu "sí".

**Hecho cuando:** con la configuración por defecto no queda transcripción tras la reunión, y la pausa corta la captura al instante.

**Estado:** ✅ hecho (14/10).
- Por defecto, `MeetingCloser` borra lo que se dijo al cerrar la reunión, después de llevarlo a las notas. Se puede guardar en Configuración.
- Pausar descarta en el servidor lo que llega desde ese momento, y la extensión lo indica.
- Una reunión cubierta por una regla («1:1») empieza en pausa (`paused_reason`). La burbuja dice por qué y ofrece «Escuchar esta vez».
- Aviso claro en Configuración: el texto se procesa con Gemini, no se graba audio y en Meet nadie ve nada distinto.

### H9 · Modo demo y tests (1 día)
- Un `Fake` que reproduce una llamada de ejemplo con los datos del prototipo: Marta, CPD-412, la página de prepago, un seguimiento la semana que viene.
- Pest: cada detector con su `Fake`, el freno de ruido, la conservación, el token de la extensión y que las acciones esperen al "sí".

**Hecho cuando:** sin cuentas conectadas, la demo recorre una llamada completa y produce el documento.

---

## Orden y dependencias

```
Ronda de solidez ─► H0 spike ─► H1 + H2 ─► H3 ─► H4 ─► H5 ─► H6 ─► H7 + H8 ─► H9
```

H0 decide la captura, y H2 depende de él. H5 y H6 son el núcleo de valor. H7 y H8 pueden ir en paralelo.

## Decisiones abiertas

- **Captura** (H0): subtítulos de Meet, audio de la pestaña o ambos.
- **Conservación por defecto** (H8): propuesta, borrar la transcripción y guardar notas y acciones.
- **Canal de "mandaré un mensaje a X"** (H5): propuesta, el canal por el que más habláis X y tú (Slack o email), y en la tarjeta se puede cambiar.

## Riesgos

- **Meet cambia su interfaz** y la extensión deja de detectar la llamada o de leer lo que se dice. Mitigación: detectores aislados, un aviso en el estado del sistema y la vía B como alternativa.
- **Datos de clientes procesados por Gemini.** Hay que confirmar que encaja con la política de datos de CoverManager y con los contratos de clientes antes de usarlo en llamadas con ellos.
- **Coste de IA.** Bloques cada ~20 s con un modelo ligero: estimado en céntimos por hora de llamada. Se medirá en H9.
- **Solo Chrome de escritorio** y solo Google Meet en la v1.

## Fuera de alcance (v1)

- Hablar en la llamada (el bot que responde cuando se le habla).
- Zoom, Teams y apps de escritorio.
- Grabar el audio.
- Llamadas de otras personas.
