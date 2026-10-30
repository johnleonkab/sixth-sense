# Datos vivos en una vista

Las vistas no escriben nada: Query solo lee estos datasets, y no existe Mutation. Para filtros, el bloque Tabs con una $variable (no hay Select ni formularios). Para cambiar algo, usa las herramientas que proponen y esperan el sí.

## Built-in Functions

Data functions prefixed with `@` to distinguish from components. These are the ONLY functions available — do NOT invent new ones.
Use @-prefixed built-in functions (@Count, @Sum, @Avg, @Min, @Max, @Round) on Query results. Do NOT hardcode computed values.

@Count(array: any[]) → number — Returns array length
@First(array: any[]) → any — Returns first element of array
@Last(array: any[]) → any — Returns last element of array
@Sum(numbers: number[]) → number — Sum of numeric array
@Avg(numbers: number[]) → number — Average of numeric array
@Min(numbers: number[]) → number — Minimum value in array
@Max(numbers: number[]) → number — Maximum value in array
@Sort(array: any[], field: string, direction?: "asc" | "desc") → any[] — Sort array by field. Direction: "asc" (default) or "desc"
@Filter(array: any[], field: string, operator: "==" | "!=" | ">" | "<" | ">=" | "<=" | "contains", value: any) → any[] — Filter array by field value
@Round(number: number, decimals?: number) → number — Round to N decimal places (default 0)
@Abs(number: number) → number — Absolute value
@Floor(number: number) → number — Round down to nearest integer
@Ceil(number: number) → number — Round up to nearest integer
@Each(array: any[], varName: string, template: any) — Evaluate template for each element. varName is the loop variable — use it ONLY inside the template expression (inline). Do NOT create a separate statement for the template.
@GroupCount(rows: any[], field: string) → {labels: string[], values: number[]} — Cuenta las filas por un campo: {labels, values}, el grupo mayor primero. Para DonutChart o BarChart.
@GroupSum(rows: any[], field: string, value: string) → {labels: string[], values: number[]} — Suma un campo numérico (value) por otro campo (field): {labels, values}, el mayor primero.
@Top(series: {labels: string[], values: number[]}, n: number) → {labels: string[], values: number[]} — Los n primeros grupos de un {labels, values}, y el resto junto en «Otros».
@ByDay(rows: any[], field: string, value?: string) → {labels: string[], values: number[]} — Filas por día de un campo de fecha (o la suma de value por día), del más antiguo al más reciente: {labels, values}. Para LineChart.

Builtins compose — output of one is input to the next:
`@Count(@Filter(data.rows, "field", "==", "val"))` for KPIs/chart values, `@Round(@Avg(data.rows.score), 1)`, `@Each(data.rows, "item", Comp(item.field))` for per-item rendering.
Array pluck: `data.rows.field` extracts a field from every row → use with @Sum, @Avg, charts, tables.

IMPORTANT @Each rule: The loop variable (e.g. "item") is ONLY available inside the @Each template expression. Always inline the template — do NOT extract it to a separate statement.
CORRECT: `list = @Each(rows, "t", Comp(t.name))`
WRONG: `item = Comp(t.name)` then `list = @Each(rows, "t", item)`, because t is undefined in item.

## Query — Live Data Fetching

Fetch data from available tools. Returns defaults instantly, swaps in real data when it arrives.

```
metrics = Query("tool_name", {arg1: value, arg2: $binding}, {defaultField: 0, defaultData: []}, refreshInterval?)
```

- First arg: tool name (string)
- Second arg: arguments object (may reference $bindings — re-fetches automatically on change)
- Third arg: default data (rendered immediately before fetch resolves)
- Fourth arg (optional): refresh interval in seconds (e.g. 30 for auto-refresh every 30s)
- Use dot access on results: metrics.totalEvents, metrics.data.day (array pluck)
- Query results must use regular identifiers: `metrics = Query(...)`, NOT `$metrics = Query(...)`
- Manual refresh: `Button("Refresh", Action([@Run(query1), @Run(query2)]), "secondary")` — re-fetches the listed queries
- Refresh all queries: create Action with @Run for each query

## Available Tools

Use these with Query() for read operations.

- jira_issues(jql?: string, text?: string, limit?: number) → {rows?: {key?: string, summary?: string, type?: string, status?: string, status_category?: string, priority?: string, assignee?: string, updated?: string}[]}
  Issues de Jira como filas (key, summary, type, status, status_category, priority, assignee, updated). Con jql, los de esa consulta; con text, los que lo mencionan en los proyectos de las reglas.
- analytics_rows(property: string, metrics: string[], dimensions?: string[], start_date?: string, end_date?: string) → {rows?: object[]}
  Un informe de Google Analytics 4 como filas: un campo por dimensión y por métrica (mismos nombres que analytics_report). property por nombre, cuenta o id.
- alerts(status?: "all" | "pending" | "addressed" | "routine", source?: "gmail" | "slack" | "calendar", days?: number, limit?: number) → {rows?: {n?: number, ref?: string, source?: string, actor?: string, title?: string, why?: string, status?: string, received?: string, headline?: string, context?: string, when?: string, proposal?: string, closed?: string, pending?: boolean}[]}
  Avisos como filas, los más recientes primero. status: pending (por defecto, los que esperan respuesta), all, addressed (resueltos o descartados) o routine (sin importancia). Para listarlos: ItemList con n, ref, headline, context, when, proposal, closed y pending (highlight).
- calendar_events(from: string, to: string, title?: string, attendee?: string) → {rows?: {title?: string, day?: string, weekday?: string, start?: string, minutes?: number, calendar?: string, organizer?: string, attendees?: number, mine?: boolean, declined?: boolean, all_day?: boolean}[]}
  Eventos del calendario como filas (title, day, weekday, start, minutes, calendar, organizer, attendees, mine, declined, all_day), de from a to (YYYY-MM-DD, como mucho 62 días). Para horas por día: @ByDay(rows, "day", "minutes") y divide entre 60.
- knowledge(source?: "archive" | "jira" | "notion" | "meeting" | "memory", limit?: number) → {rows?: {source?: string, title?: string, views?: number, summarized?: boolean, last_viewed?: string, updated?: string, indexed?: string}[]}
  El índice de conocimiento como filas (source, title, views, summarized, last_viewed, updated, indexed), uno por documento. source: archive, jira, notion, meeting o memory.
- mcp_rows(server: string, tool: string, arguments?: object) → {rows?: object[]}
  Filas que devuelve una herramienta de solo lectura de un servidor MCP conectado (server, tool y sus arguments, como en mcp_tools). Los campos dependen de la herramienta; mira las filas antes de agruparlas.

### Default values for Query results

Use these shapes as minimal Query defaults:
- jira_issues: `{"rows":[]}`
- analytics_rows: `{"rows":[]}`
- alerts: `{"rows":[]}`
- calendar_events: `{"rows":[]}`
- knowledge: `{"rows":[]}`
- mcp_rows: `{"rows":[]}`

Usa solo estos datasets en Query, con estos nombres. Si ninguno sirve, dilo: nunca escribas datos inventados.

## Filtros y «cargar más»
1. Declara la $variable con su valor inicial: `$status = "pending"`.
2. Tabs con las opciones y la $variable: `tabs = Tabs([{value: "pending", label: "Pendientes"}, {value: "all", label: "Todos"}], $status)`.
3. Pásala en los argumentos de la Query: `alerts = Query("alerts", {status: $status, limit: $limit}, {rows: []})`. Al cambiar de pestaña, vuelve a pedir los datos sola.
4. Para «cargar más», sube el límite: `more = @Count(alerts.rows) >= $limit ? Button("Cargar más", @Set($limit, $limit + 30)) : null`.
Si se ve un filtro, toda Query a la que afecte lleva su $variable. Las $variables guardan textos o números, nunca listas.
