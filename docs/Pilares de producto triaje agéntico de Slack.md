# Pilares de producto: triaje agéntico de Slack

Oct 28, 2026 · @John

El sistema no debe clasificar mensajes en abstracto, sino **aprender la política de atención de esa persona**: a quién responde, sobre qué, en cuánto tiempo y qué deja pasar. Todo lo demás se apoya en eso.

## Pilar 1 — La verdad sale del comportamiento, no de reglas genéricas

El histórico de Slack ya trae etiquetas implícitas:

- **Respondió rápido** → importante y le tocaba a él.
- **No respondió y otro lo resolvió** → importante, pero no le tocaba.
- **No respondió y nadie volvió a preguntar** → ruido.
- **No respondió y le volvieron a pinchar** ("¿viste esto?", re-mención, DM posterior) → **error grave**. Es la señal más valiosa del dataset: importancia que se le escapó.
- Reacciones con emoji, hilos que sigue o abre más tarde, mensajes guardados → señales débiles de interés.

Salvedad: el comportamiento pasado no es el ideal, porque la persona puede estar respondiendo de más. Este pilar sirve para arrancar; el feedback explícito (pilar 5) corrige.

## Pilar 2 — Importancia ≠ urgencia ≠ requiere respuesta

La salida no es "importante sí/no", sino una acción:

| Salida | Ejemplo |
| --- | --- |
| **Responde tú** | Tu manager te pregunta directamente por el presupuesto |
| **Debería responder otro** | Pregunta técnica en tu canal que domina alguien de tu squad |
| **Convertir en tarea** | "Cuando puedas revisa el doc", sin respuesta inmediata |
| **Solo para saber** | Anuncio relevante para tus proyectos |
| **Ignorar** | Ruido de canal |

Señales para puntuar importancia:

- **Quién:** grafo de relaciones (jerarquía, frecuencia, reciprocidad, cliente o externo).
- **Dónde:** DM > mención directa > hilo en el que participa > canal privado > @here > canal masivo.
- **Qué:** pregunta dirigida, petición de decisión, bloqueo, plazo, o meramente informativo.
- **Vínculo con prioridades actuales:** cruce con el repo de Claude y con Drive. Un mensaje sobre el OKR del trimestre pesa más que uno sobre algo cerrado.
- **Coste de no responder:** bloquea a alguien, hay plazo, hay un externo esperando.

## Pilar 3 — Modelo de ownership: ¿le toca a él o a otro?

Es el pilar más diferencial, porque casi nadie lo resuelve bien.

- **Dirigido explícitamente:** pregunta con su nombre, DM, o hilo que él inició.
- **Ownership declarado:** áreas, squads y responsabilidades sacados del repo y de Drive (organigramas, RACI, specs donde figura como owner).
- **Ownership revelado:** en ese canal, ¿quién responde históricamente a este tipo de pregunta? Es un mapa de expertise aprendido.
- **Exclusividad de contexto:** ¿es el único con la información (autor del doc citado, tomó la decisión en otro hilo)? Si sí, le toca aunque no le mencionen.
- **Efecto espectador:** pregunta lanzada al grupo, sin respuesta tras X horas, y él es owner del área → se escala a "responde tú".
- **Ya en marcha:** si otro ya respondió bien, baja la prioridad; si respondió mal o a medias, sube.

Cuando la salida es "debería responder otro", el producto propone **a quién** y con qué mensaje de redirección.

## Pilar 4 — Recuperación de contexto: planificar antes de buscar

El agente primero se pregunta **"¿qué necesitaría saber para responder bien?"** y luego busca, en lugar de lanzar una búsqueda genérica.

Capas, de más cercana a más lejana:

1. **El propio hilo** y los mensajes previos del canal.
2. **Artefactos enlazados:** docs, tickets, PRs mencionados.
3. **Resolución de entidades:** quién es esta persona, qué proyecto es, qué cliente.
4. **Drive:** documentos de esa entidad, priorizando recientes y de los que es autor.
5. **Repo de Claude:** conocimiento estable (rol, prioridades, decisiones tomadas, tono).
6. **Precedentes:** cómo respondió antes a preguntas parecidas; la mejor guía de contenido y estilo.

Principios:

- **Frescura y jerarquía:** una decisión reciente en Slack invalida un doc antiguo. Si hay conflicto, se señala, no se elige en silencio.
- **Chequeo de suficiencia:** si falta información, el sistema dice "para responder necesitas confirmar X" en vez de inventar.
- **Fronteras de permisos:** nada de un canal privado o doc restringido se filtra a una respuesta en un canal más abierto.

## Pilar 5 — Preferencias legibles y editables

El repo de Claude no es solo contexto: es **el modelo de preferencias en formato humano**.

- **Arranque en frío:** análisis del histórico + onboarding corto (personas clave, proyectos activos, canales que son ruido).
- **Feedback de un toque** en cada elemento mostrado: "no era importante", "esto le toca a Ana", "siempre avísame de esto".
- Las correcciones se convierten en **reglas explícitas propuestas al repo** ("Mensajes de cliente X: siempre prioridad alta"), que el usuario aprueba. El sistema queda auditable.
- **Deriva:** las prioridades cambian cada trimestre. Las señales antiguas pierden peso y el repo y los OKRs se releen periódicamente.

## Pilar 6 — Autonomía progresiva y errores asimétricos

- **Coste asimétrico:** perderse una pregunta del CEO es mucho peor que mostrar un mensaje irrelevante. Los umbrales optimizan recall en "responde tú", aceptando algo de ruido.
- **Red de seguridad:** resumen diario de "lo que he despriorizado" para detectar falsos negativos.
- **Escalera de autonomía por categoría:** observar y ordenar → proponer borrador → ejecutar acciones de bajo riesgo (acuses de recibo, redirecciones). Solo se sube de nivel cuando la precisión medida en esa categoría lo justifica.
- **Explicabilidad en cada decisión.** Ejemplo: "Te lo marco porque Laura (tu manager) te pregunta directamente por el budget de Q4 y el plazo es el viernes."

## Pilar 7 — Cómo saber si funciona

- **Tasa de "se me escapó":** re-pings y "¿viste esto?" sobre mensajes que el sistema no subió. Métrica norte.
- **Precisión y recall** en "requiere tu respuesta".
- **% de redirecciones aceptadas** en "le toca a otro".
- **Aceptación de borradores** y distancia de edición. Si los reescribe enteros, fallan el contexto o el estilo.
- **Tiempo de respuesta** en lo importante frente a tiempo total en Slack.

## Prioridad para el MVP

Pilares 1, 3 y 6: aprender del histórico, resolver bien el "¿me toca a mí?" y no perder nada importante. La generación de respuestas con contexto (pilar 4) aporta mucho, pero sin un buen triaje es pulir lo que viene después.
