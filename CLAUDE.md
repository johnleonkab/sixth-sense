<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>


# sixth-sense

sixth-sense es una interfaz web para gestionar la jornada laboral completa **hablando**. La persona habla; la IA responde con voz y, a la vez, **muestra en pantalla la interfaz que hace falta en ese momento**: un calendario, un hilo de Slack, un email, un PDF, un ticket de Jira, una hoja de cálculo o una página de Notion.

No es un dashboard ni un chatbot con voz. Es una "sixth-sense compartida": lo que se dice y lo que se ve se refieren a lo mismo, y la persona puede señalar lo que hay en pantalla con la voz ("abre el 2", "contesta a este", "muévelo al jueves").

Servicios conectados: Google Calendar, Gmail, Google Sheets, Google Docs, Slack, Jira y Notion.

Usuario inicial: una sola persona (uso personal). Multiusuario queda fuera de la v1, pero el modelo de datos ya va por `user_id`.

El prototipo visual de referencia está en `docs/prototype.html`. Es la fuente de verdad para el aspecto y el comportamiento de la interfaz.

---

## Principios de producto (no negociables)

1. **Voz primero.** Todo se puede hacer hablando. El teclado es solo una alternativa.
2. **La pantalla es lo que hablas.** El agente decide qué interfaz mostrar. Como máximo **dos columnas** de contenido a la vez.
3. **Solo dos líneas de texto de voz:** lo que la persona está diciendo ahora (transcripción en vivo) y lo que responde la IA. No hay historial de chat visible.
4. **Leer es libre; escribir siempre pide un "sí".** Cualquier acción que cambie algo en un servicio externo (enviar, mover, comentar, crear) se muestra antes como borrador o propuesta y espera confirmación por voz o con un botón. Después hay unos segundos para deshacer.
5. **Escucha activa.** Modo en el que la sixth-sense escucha de forma continua. Se puede pausar y reanudar por voz o con un botón.
6. **Proactividad con criterio.** Solo avisa de lo relevante (menciones, mensajes directos, emails de clientes o del responsable, comentarios en sus páginas). Cada aviso dice **por qué** avisa.
7. **Diseño limpio.** Mucho espacio en blanco, una sola tipografía, un solo color de acento, sin decoración. Modo claro y oscuro.

---

## Stack

| Capa | Tecnología |
|---|---|
| Backend | Laravel (última versión estable), PHP 8.3+ |
| Frontend | Inertia + React + TypeScript, Vite, Tailwind |
| Base de datos | PostgreSQL |
| Colas y caché | Redis + Laravel Horizon |
| Tiempo real | Laravel Reverb (WebSockets) + Laravel Echo en el cliente |
| IA (cerebro) | Claude (Anthropic) mediante `prism-php/prism`, con llamadas a herramientas |
| Voz | Proveedor externo de transcripción en streaming + síntesis de voz, conectado **directamente desde el navegador** (WebRTC/WebSocket). Laravel solo emite un token temporal. Proveedor pendiente de decidir (ver "Decisiones abiertas"). |
| OAuth | Laravel Socialite + SocialiteProviders (Slack, Atlassian, Notion); Google con Socialite |
| APIs | `google/apiclient` para Google; cliente HTTP de Laravel para Slack, Jira y Notion |
| Tests | Pest |

Regla: **Laravel nunca procesa audio.** El audio va del navegador al proveedor de voz y vuelve.

---

## Arquitectura

```
Navegador                                   Laravel
──────────────────────────────              ─────────────────────────────────────
Micrófono ─► Proveedor de voz (STT) ─► texto
                                        │
             Bucle rápido (local) ◄─────┤  órdenes sobre lo que hay en pantalla
                                        │
                                        └─► POST /turns ─► Agente (Claude + tools)
                                                              │
Lienzo ◄── directivas de UI (JSON) ◄────────────────────────────┘
TTS ◄── texto de respuesta
                                                        Conectores (Google, Slack, Jira, Notion)
Avisos/inbox ◄── Reverb ◄── InboxItemCreated ◄── Relevancia ◄── Webhooks / polling
```

### 1. Voz (navegador)
- Transcripción en streaming con resultados parciales (se muestran en vivo en la línea "lo que dices").
- Detección de fin de frase (VAD) para cerrar el turno sin pulsar nada.
- Interrumpir: si la persona habla mientras la IA responde, se corta la voz de la IA.
- Escucha activa on/off. En pausa: no se envía audio; los avisos solo suenan y se guardan en el inbox.

### 2. Bucle rápido (navegador, sin IA)
Órdenes que se resuelven en el cliente, en menos de 100 ms, sin llamar al backend:
`siguiente / anterior página`, `abre el N`, `amplía`, `vuelve`, `cierra todo`, `sí / cancela / deshaz` (cuando hay algo pendiente), `ábrelo / luego` (cuando hay un aviso ofrecido), `pausa / activa la escucha`, `solo Slack / Gmail / Jira / Notion` (filtro del inbox).
Si una frase no encaja con ninguna orden local, va al agente.

### 3. Agente (Laravel)
- Endpoint `POST /turns` con `{ transcript, screen_context }`.
- `screen_context` describe lo que está visible: lista de vistas con su número, tipo e ids (`[{index:1, view:"calendar.day", date:"2026-10-07"}, {index:2, view:"inbox.list", item_ids:[...]}]`). Así el agente resuelve "ese", "el segundo", "este email".
- Llama a Claude con el catálogo de herramientas (ver abajo) y devuelve **directivas de UI**, nunca HTML.
- Respuestas de herramientas de solo lectura: se ejecutan directamente.
- Herramientas de escritura: **no ejecutan**; crean una `PendingAction` y devuelven una directiva `confirm`.

### 4. Protocolo del lienzo (contrato agente → frontend)
```json
{
  "say": "Marta te pide revisar el flujo de prepago antes de las 12.",
  "layout": "split",
  "show": [
    { "view": "slack.thread", "props": { "channel_id": "C123", "thread_ts": "1728290000.1234" } },
    { "view": "gmail.message", "props": { "message_id": "18c..." } }
  ],
  "confirm": null
}
```
- `layout`: `focus` (una columna centrada), `split` (dos columnas), `wide` (una columna ancha, para semana o tablas), `inbox` (lista + detalle).
- `show`: 0 a 2 vistas. Nunca más de 2.
- `confirm`: `{ pending_action_id, label }` cuando hay una acción de escritura esperando el "sí".

Registro de vistas del frontend (`resources/js/views/`), cada una un componente React que recibe `props` y pide sus datos al backend:
`calendar.day`, `calendar.week`, `brief.pending`, `slack.thread`, `gmail.message`, `gmail.thread`, `pdf.viewer`, `jira.issue`, `jira.list`, `sheets.range`, `docs.document`, `notion.page`, `inbox.list`, `inbox.item`, `settings.sounds`.

### 5. Conectores
Cada servicio implementa una interfaz común en `app/Connectors/`:
```php
interface Connector {
    public function source(): Source;            // enum: google, slack, jira, notion
    public function tools(): array;              // herramientas Prism que expone
    public function handleWebhook(Request $r): array; // normaliza a InboxCandidate[]
}
```
Cada conector tiene un `Fake` con datos de ejemplo (los del prototipo) para tests y para el **modo demo**, que permite usar la sixth-sense sin cuentas conectadas.

### 6. Avisos proactivos e inbox
1. El servicio avisa (webhook) o se consulta cada X minutos (Scheduler) si no tiene webhooks fiables.
2. Se verifica la firma del webhook y se normaliza a `InboxCandidate`.
3. Un job en cola calcula la relevancia (reglas primero; IA solo para casos dudosos) y el motivo (`why`).
4. Si es relevante, se crea `InboxItem` y se emite `InboxItemCreated` por el canal privado del usuario (Reverb).
5. El cliente: toca el sonido configurado para esa fuente, muestra un aviso breve y lo añade al inbox. Si la escucha activa está encendida y esa fuente tiene "leer en voz alta", la IA lo anuncia y ofrece abrirlo.

Reglas de relevancia v1:
- Slack: menciones directas y mensajes directos.
- Gmail: remitentes marcados como importantes (clientes, responsable), respuestas a hilos que la persona inició y emails con petición explícita.
- Jira: comentarios que mencionan a la persona.
- Notion: comentarios en páginas de las que es responsable y tareas asignadas.

### 7. Sonidos
Configurables por fuente (Slack, Gmail, Jira, Notion): sonido, y si se lee en voz alta con escucha activa. Se guardan en `alert_settings`. Los sonidos se generan con Web Audio API en el cliente (sin archivos), como en el prototipo.

---

## Modelo de datos (v1)

| Modelo | Campos principales |
|---|---|
| `User` | — |
| `ConnectedAccount` | `user_id`, `provider`, `external_account_id`, `access_token` (cifrado), `refresh_token` (cifrado), `scopes`, `expires_at` |
| `InboxItem` | `user_id`, `source`, `external_id`, `external_url`, `actor_name`, `title`, `body`, `why`, `relevance`, `payload` (json), `read_at`, `received_at` |
| `AlertSetting` | `user_id`, `source`, `sound`, `read_aloud` |
| `PendingAction` | `user_id`, `type`, `payload` (json), `label`, `status` (pending/confirmed/cancelled/executed/undone), `undo_until` |
| `VoiceTurn` | `user_id`, `transcript`, `reply`, `directives` (json), `tools_used` (json), `latency_ms` |
| `Person` (fase 8) | identidad cruzada de una persona entre Slack, Gmail, Jira y Notion |

`external_id` + `source` + `user_id` es único en `InboxItem` (los webhooks pueden repetirse).

---

## Herramientas del agente

Solo lectura (se ejecutan directamente):
`calendar_list_events`, `calendar_find_free_slot`, `gmail_search`, `gmail_get_message`, `slack_search`, `slack_get_thread`, `jira_search`, `jira_get_issue`, `notion_search`, `notion_get_page`, `sheets_get_range`, `docs_get_document`, `drive_get_pdf`, `inbox_list`.

Escritura (crean `PendingAction`, nunca ejecutan sin confirmación):
`slack_send_message`, `gmail_send_reply`, `gmail_create_draft`, `calendar_move_event`, `calendar_create_event`, `jira_add_comment`, `jira_transition_issue`, `notion_add_comment`.

Al confirmar, un job ejecuta la acción con un retraso igual a la ventana de deshacer (8 s). "Deshaz" dentro de esa ventana cancela el job.

---

## Convenciones

- Código, nombres de clases, tablas y commits **en inglés**. Textos de la interfaz y de la voz **en español**.
- Toda la lógica de un servicio vive en su conector. Ni controladores ni el agente llaman a APIs externas directamente.
- Tokens OAuth siempre cifrados (`encrypted` cast). Nunca en logs.
- Cada webhook verifica su firma antes de hacer nada.
- Cada herramienta tiene test con el `Fake` de su conector.
- Medir latencia de cada turno (`VoiceTurn.latency_ms`). Objetivo: menos de 1,5 s desde fin de frase hasta empezar a hablar en turnos con herramientas; menos de 100 ms en el bucle rápido.
- Los textos que dice la IA son cortos (1-3 frases) y en lenguaje natural. El detalle está en pantalla, no en la voz.

---

## Fuera de alcance de la v1

Multiusuario y equipos, app móvil, palabra de activación ("Oye, sixth-sense"), edición de documentos dentro de la sixth-sense, otros servicios distintos de los siete listados.

## Decisiones abiertas

- **Proveedor de voz** (transcripción en streaming + síntesis): comparar latencia, calidad en español y coste. Requisito: resultados parciales, detección de fin de frase y posibilidad de interrumpir.
- **Reglas de relevancia de Gmail**: cómo marca la persona a sus remitentes importantes.
- **Polling o webhooks** para Notion y Gmail según fiabilidad en la práctica.

El orden de implementación está en `docs/PLAN.md`.
