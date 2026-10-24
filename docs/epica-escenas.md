# Épica · Escenas

**Qué es:** una forma de cumplir un encargo de varios pasos sin que el modelo de voz los encadene. La pantalla enseña el trabajo mientras ocurre. Por detrás, como mucho una lectura y luego sus propuestas. DeepSeek redacta y responde. Gemini solo habla.

**Por qué:** hoy Gemini Live es quien habla y quien llama a las herramientas. El navegador espera a que terminen todas antes de dejarle seguir. Si el encargo es «busca hueco y contesta a Marta», el correo nace sin el hueco, o se olvida a mitad. Si es «deja las historias de la reunión», las escribe de una en una dentro de la sesión y se corta. Quien usa la sixth-sense no ve qué está pasando hasta el final, si es que llega.

**Relación con el resto:** no sustituye un «abre el calendario» ni un «léeme el correo». Esos siguen siendo una herramienta. La escena solo aparece cuando una frase, o un aviso, necesita más de un paso.

---

## Principios

1. **Dos capas, y se acaban.** La primera es una lectura. La segunda son las propuestas que dependen de esa lectura. Pueden ser una o varias a la vez. Ningún paso inventa el siguiente. Si hiciera falta una tercera capa, no es un solo encargo y se pregunta.
2. **La pantalla es la conversación.** En cuanto acaba la frase, se ve el sitio vacío. Las piezas aparecen según llegan, en ese mismo sitio. No hay una barra de «paso 2 de 5» ni un registro de procesos.
3. **La voz no narra el trabajo.** Dice una frase al abrir la escena y calla. Vuelve a hablar cuando lo que dice ya está en pantalla, o cuando algo ha fallado.
4. **Leer es libre. Escribir pide un «sí».** Igual que el resto de la sixth-sense. Varias propuestas de la misma escena se confirman juntas. Cada una sigue siendo una `PendingAction`, con la ventana de deshacer.
5. **Un agente se pregunta. No actúa.** Recibe una pregunta y devuelve una respuesta, con sus instrucciones y sus referencias. Puede apoyarse en lecturas que el código ya ha hecho. No envía, no commitea y no llama a otro agente.
6. **Gemini solo en la API en vivo.** Clasificar la escena, preguntar a un agente y redactar los pasa DeepSeek, con `DeepSeekTextModel` directo. No el `TextModel` resuelto por configuración: si `AI_TEXT_PROVIDER` fuera Gemini, este trabajo no debe irse con él.
7. **El grafo no lo elige un modelo.** Los tipos de escena son clases PHP. Añadir un tipo es escribir esa clase, no un prompt que descubra pasos.

---

## Cómo está hoy

```
Navegador ──audio──► Gemini Live
                │
                └─ herramienta, y espera el resultado
                      │
                      ▼
                 Laravel ejecuta la herramienta
                      │
                      └─ canvas + output ──► Gemini sigue, y puede llamar a otra
```

El client ya lanza en paralelo las llamadas que llegan en el mismo mensaje (`use-voice-session.ts`). El problema es el modelo: encadena una llamada, espera, y decide la siguiente. Los avisos ya tienen el otro patrón: `PlanAlertReply` abre la pantalla y rellena la propuesta después, por `InboxItemUpdated`. La escena es ese patrón, con más de un resultado y con un agente al que preguntar.

`focus_sense_agent` no pregunta a un agente. Le entrega el turno al modelo de voz, que pasa a seguir el briefing. Eso se queda para «este tema lo llevas tú». Preguntar es otra cosa.

---

## Cómo queda

```
Frase o aviso
      │
      ▼
open_scene ──► escena vacía en pantalla, al momento
      │         (Gemini ya puede seguir oyendo)
      ▼
Job en la cola scenes
      ├─ capa 1: lectura en PHP (calendario, notas, archivo)
      │          DeepSeek solo si la lectura es un texto (títulos, respuesta de un agente)
      └─ capa 2: una propuesta, o una por elemento, en paralelo
                 cada una avisa por SceneUpdated
      ▼
Lienzo relleno
      ├─ «sí» confirma el lote
      └─ cada ficha pasa a hecha cuando su PendingAction termina
```

Gemini, en una frase compuesta, llama solo a `open_scene`. No llama al calendario, al correo ni a GitHub para ese encargo. Las frases de una sola herramienta no cambian.

---

## La escena

Dos tablas.

`scenes`: usuario, tipo, estado (`open`, `ready`, `failed`), origen (`voice` o `alert`), aviso si nace de uno, y el lienzo que está en pantalla.

`scene_steps`: escena, capa (1 o 2), estado (`pending`, `running`, `done`, `failed`), payload y error. Una propuesta guarda el id de su `PendingAction`.

Un tipo es una clase en `app/Scenes/Kinds/`. Declara la capa 1 y cómo se parte la capa 2. No llama a un modelo para saber qué hacer después. El resultado de la capa 1 es datos: un hueco, una lista de títulos, un texto.

Estados que ve la persona, en la misma ficha:

| Momento | Qué se ve |
|---|---|
| Al abrir | El sitio, vacío. Calendario y borrador sin hora, o la lista del proyecto con fichas en blanco. |
| Al llegar una pieza | Esa ficha se rellena. Las otras no esperan. |
| Si falla | El motivo en esa ficha. El resto sigue. |
| Tras el «sí» | «Guardando», y luego «en main» con el enlace del commit, según responde GitHub. |

La voz dice «los cuatro están en main» solo cuando las cuatro fichas ya lo dicen.

El evento es `SceneUpdated`, por el canal privado del usuario, igual que los avisos. El navegador, al recibir `open_scene`, se suscribe y parchea el lienzo. No vuelve a pasar por Gemini.

---

## Tipos de la primera entrega

### `slot_then_reply`

«Busca hueco y contesta a este correo.»

1. PHP busca el hueco con el conector de calendario.
2. DeepSeek redacta la respuesta con esa hora ya metida.
3. El lienzo es el calendario y el borrador. La hora aparece en los dos a la vez.
4. Si no hay hueco, el borrador no se crea y la ficha lo dice.

### `stories_from_notes`

«De las notas de la reunión, deja las historias en el proyecto.»

1. DeepSeek, con el briefing del agente cuyo «cuándo» encaja (si no hay, el de Jira que esté sincronizado; si no hay ninguno, se dice y no se escribe nada), devuelve los títulos.
2. Un job por título, a la vez. El mismo briefing, las mismas notas, un título distinto.
3. Cada job propone un archivo. La ruta y la cabecera las marca el tipo del archivo (`FileRules`), no el modelo.
4. El lienzo es la lista. Cada ficha se rellena al llegar.

### `ask_agent`

«Pregúntale al agente de Jira si esto es una épica.»

1. No hay capa 2.
2. DeepSeek responde con el briefing de ese agente: instrucciones y referencias.
3. La respuesta cae en la ficha. No se propone ninguna escritura.

Elegir el agente, cuando la frase no dice el nombre, lo hace DeepSeek ligero: compara la pregunta con el «cuándo» de cada agente sincronizado. Si no encaja ninguno, se dice y no se inventa uno.

---

## En conversación

Sirve para un encargo dicho ahora.

- Una sola herramienta, como hasta ahora. Sin escena.
- Un encargo de las dos capas. La pantalla enseña el sitio al momento y las piezas según llegan. Un «sí» confirma el lote.
- Una pregunta a un agente. La respuesta se ve. La voz no la lee entera.

No puede abrir una investigación mientras se habla de otra cosa, ni añadir un paso que el tipo no tenga. El modelo de voz no consulta el estado de la escena para decidir más trabajo. Si quiere decir qué ha pasado, mira el lienzo, que ya está actualizado, igual que hoy mira el estado de una `PendingAction` antes de afirmar que algo se envió.

## En proactivo

Sirve cuando Gmail, Slack, Notion o Jira dejan un aviso que ya pasó el filtro de relevancia. El aviso abre la misma escena, sin que nadie haya hablado.

`PlanAlertReply` deja de pedir al modelo de voz que encadene herramientas. Si el aviso encaja con un tipo, abre la escena. DeepSeek ligero elige el agente por su «cuándo». Un comentario de Jira puede acabar en las fichas de las historias. Un correo puede acabar en el calendario con un hueco y el borrador ya relleno.

Con la escucha apagada, la escena espera dentro del aviso, hecha. Abrirla no vuelve a llamar a un modelo. Con la escucha activa, la voz ofrece la escena en una frase cuando las fichas ya tienen contenido, del mismo modo que hoy ofrece un aviso.

El agente no actúa solo. Pregunta, lee lo que el tipo le pone delante y propone. Quien envía o guarda sigue siendo la persona.

Si el aviso no encaja con ningún tipo, sigue como ahora: una propuesta de texto y el modelo de voz, si hace falta, usa una herramienta.

---

## Proveedores

| Qué | Quién |
|---|---|
| Oír y hablar | Gemini, API en vivo. No cambia. |
| Elegir el tipo de escena a partir de la frase | No hace falta: Gemini llama a `open_scene` con el tipo, de una lista cerrada en la herramienta. Si la frase no encaja con ninguno, no llama. |
| Elegir agente cuando no se ha nombrado | DeepSeek ligero (`deepseek-flash`). |
| Redactar títulos, historias, la respuesta al correo, la respuesta del agente | DeepSeek (`deepseek-v4-pro`), siempre `DeepSeekTextModel`. |
| Buscar hueco, leer notas, escribir en GitHub tras el «sí» | PHP y los conectores. Sin modelo. |

`TextModel` sigue siendo la interfaz del resto de la aplicación (avisos, contexto, Wingman). Las escenas no la usan: llaman a DeepSeek por su clase, para no depender de `AI_TEXT_PROVIDER`.

DeepSeek entra solo por `json()`: un sistema, un usuario, un esquema. No hay un bucle de herramientas. Lo que el modelo necesita leer se lo pone delante el job, en el prompt.

---

## Qué hay que modificar

Se añade:

- `app/Models/Scene.php`, `app/Models/SceneStep.php`, migración y factories.
- `app/Scenes/OpenScene.php`, que crea la escena, pinta el lienzo vacío y encola el job.
- `app/Scenes/Kinds/`, una clase por tipo.
- `app/Jobs/RunScene.php`. La capa 2, cuando son varias, despacha un job por elemento a la cola `scenes`.
- `app/Events/SceneUpdated.php`.
- `app/Voice/Tools/Scenes/OpenSceneTool.php`, registrada en `Toolbox`.
- `resources/js/canvas/scenes/scene.tsx`, el lienzo que nace vacío y se parchea.
- Cola `scenes` en `config/horizon.php`, con más de un proceso. La cola `default` se queda en uno y no debe tragar este trabajo.

Se cambia:

- `resources/js/voice/use-voice-session.ts`. `open_scene` devuelve enseguida. El lienzo posterior llega por Reverb, no como respuesta de la herramienta. El comentario de ese archivo («the assistant waits for every response») sigue siendo cierto para el resto de herramientas.
- `app/Jobs/PlanAlertReply.php`. Si el aviso encaja con un tipo, abre una escena de origen `alert` en lugar de dejar solo un texto.
- `app/Voice/AssistantProfile.php`. Una frase: si el encargo necesita más de una herramienta, la única llamada es `open_scene`. No encadenes.
- La barra de confirmación, para aceptar todas las propuestas de una escena de una vez. Cada acción conserva su propio deshacer.

No se cambia:

- El proveedor de voz, el token, el transporte Gemini.
- `focus_sense_agent`.
- `PendingAction`, la ventana de deshacer, `FileRules`, los conectores.
- El filtro de relevancia de los avisos. Una escena proactiva solo nace de un aviso que ya era relevante.

---

## Fases

Cada fase termina con algo que se puede probar. La siguiente no empieza si la anterior no se ve en pantalla.

### Fase 1 · La escena vacía

Sin DeepSeek. Un tipo falso rellena dos fichas a los pocos segundos.

**Hecho cuando:** al decir una frase de prueba, el lienzo aparece al momento, las fichas se rellenan solas y la voz no espera a que termine el job para poder oír otra cosa.

### Fase 2 · Preguntar a un agente

`ask_agent` con DeepSeek y el briefing real, referencias incluidas.

**Hecho cuando:** «pregúntale al agente de Jira si esto es una épica» muestra la respuesta en la ficha, y esa respuesta usa una referencia que está en `agents/{slug}/references/`. No se crea ningún archivo.

### Fase 3 · Los dos encargos de verdad

`slot_then_reply` y `stories_from_notes`.

**Hecho cuando:**

- «Busca hueco y contesta a Marta» enseña el calendario y el borrador, la hora coincide, y sin hueco no hay borrador.
- «Deja las historias de la reunión en el proyecto» enseña una ficha por título, varias a la vez, y un «sí» las deja en `main`. Cada ficha pasa a «en main» con su enlace. Una que no cumple las reglas se queda en error y las otras se guardan.

### Fase 4 · El mismo camino desde un aviso

**Hecho cuando:** un comentario relevante de Jira, con la escucha apagada, deja el aviso con las fichas ya escritas. Al abrirlo no hay otra llamada a un modelo. Con la escucha activa, la voz lo ofrece en una frase cuando las fichas ya tienen contenido.

---

## Qué no entra

- Una tercera capa, o un paso que cree pasos nuevos.
- Un bucle de herramientas en DeepSeek.
- Que un agente llame a otro agente.
- Que Gemini redacte, clasifique fuera de la herramienta, o llame a las herramientas del encargo una a una.
- Confirmar cada propuesta por separado cuando pertenecen a la misma escena.
- Ejecutar escrituras sin el «sí», ni en conversación ni desde un aviso.
- Replanificar si un paso falla. Se ve el error en su ficha y el resto sigue.

---

## Pruebas

Por tipo, en Pest, con `DeepSeekTextModel` sustituido por el doble que ya usa el proyecto (`RecordingTextModel` o el que esté en `tests/Support`).

- `open_scene` responde antes de que el job termine, y el lienzo inicial no tiene contenido.
- `SceneUpdated` llega una vez por ficha, no una vez al final.
- `slot_then_reply` no llama a DeepSeek si el calendario no devuelve hueco.
- `stories_from_notes` despacha un job por título y no espera a que uno termine para lanzar el siguiente.
- Un archivo que rompe `FileRules` no se propone. Los demás sí.
- El «sí» de la escena confirma todas las propuestas pendientes de esa escena, y el deshacer de una no deshace las otras.
- `ask_agent` manda a DeepSeek el cuerpo del agente y el texto de sus referencias, y no crea una `PendingAction`.
- Un aviso que no encaja con ningún tipo no abre escena.
- Ningún test de escenas llama a la API de Gemini ni resuelve `TextModel` hacia Gemini.
