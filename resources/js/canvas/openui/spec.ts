import { action, createLibrary, defineAction, defineComponent, defineFunction, reactive } from '@openuidev/react-lang';
import type { ComponentRenderer, Library } from '@openuidev/react-lang';
import { z } from 'zod/v4';

import datasets from '../../../openui/datasets.json' with { type: 'json' };

/**
 * The building blocks the assistant may compose a canvas from, with OpenUI.
 * This file holds no JSX: the same definitions feed the renderers on screen
 * and `npm run openui:prompt`, which writes the prompt Laravel gives the
 * voice. To add a block: describe it here, render it in library.tsx, and run
 * the prompt script.
 */
export type Renderers = Partial<Record<BlockName, ComponentRenderer<any>>>; // eslint-disable-line @typescript-eslint/no-explicit-any

export type BlockName =
    | 'View'
    | 'Row'
    | 'Card'
    | 'Text'
    | 'Metric'
    | 'Table'
    | 'BarChart'
    | 'LineChart'
    | 'DonutChart'
    | 'Image'
    | 'Document'
    | 'List'
    | 'Button'
    | 'Buttons'
    | 'ItemList'
    | 'Chips'
    | 'Tabs'
    | 'Proposal'
    | 'Quote'
    | 'Draft';

/** What the grouping functions return: chart-ready labels and values, in the same order. */
export type Series = { labels: string[]; values: number[] };

/** The datasets a view may Query(), written by `php artisan openui:datasets`. */
export const datasetNames: string[] = datasets.map((dataset) => dataset.name);

type Row = Record<string, unknown>;

const text = (value: unknown): string => (value === null || value === undefined || value === '' ? '(sin valor)' : String(value));
const number = (value: unknown): number => (typeof value === 'number' ? value : Number(value) || 0);

/** Count rows by a field, the largest group first. */
export function groupCount(rows: Row[], field: string): Series {
    const totals = new Map<string, number>();

    for (const row of rows) {
        totals.set(text(row[field]), (totals.get(text(row[field])) ?? 0) + 1);
    }

    return sorted(totals);
}

/** Add up a numeric field by another field, the largest group first. */
export function groupSum(rows: Row[], field: string, value: string): Series {
    const totals = new Map<string, number>();

    for (const row of rows) {
        totals.set(text(row[field]), (totals.get(text(row[field])) ?? 0) + number(row[value]));
    }

    return sorted(totals);
}

/** The first n groups, and the rest folded into "Otros". */
export function top(series: Series, n: number): Series {
    if (series.labels.length <= n) {
        return series;
    }

    const rest = series.values.slice(n - 1).reduce((sum, value) => sum + value, 0);

    return { labels: [...series.labels.slice(0, n - 1), 'Otros'], values: [...series.values.slice(0, n - 1), rest] };
}

/** Rows per day (or the sum of a field per day), oldest first, for a line. Days are YYYY-MM-DD. */
export function byDay(rows: Row[], field: string, value?: string): Series {
    const totals = new Map<string, number>();

    for (const row of rows) {
        const day = text(row[field]).slice(0, 10);
        totals.set(day, (totals.get(day) ?? 0) + (value ? number(row[value]) : 1));
    }

    const days = [...totals.keys()].filter((day) => /^\d{4}-\d{2}-\d{2}$/.test(day)).sort();

    return { labels: days, values: days.map((day) => totals.get(day) ?? 0) };
}

function sorted(totals: Map<string, number>): Series {
    const entries = [...totals.entries()].sort((a, b) => b[1] - a[1]);

    return { labels: entries.map(([label]) => label), values: entries.map(([, value]) => value) };
}

const nothing: ComponentRenderer<any> = () => null; // eslint-disable-line @typescript-eslint/no-explicit-any

export function buildLibrary(renderers: Renderers = {}): Library {
    const render = (name: BlockName) => renderers[name] ?? nothing;

    const Text = defineComponent({
        name: 'Text',
        description: 'Un párrafo corto. muted para notas secundarias, warn para un aviso (algo que no se pudo leer).',
        props: z.object({
            text: z.string(),
            tone: z.enum(['default', 'muted', 'warn']).optional(),
        }),
        component: render('Text'),
    });

    const Metric = defineComponent({
        name: 'Metric',
        description: 'Una cifra clave con su etiqueta, y opcionalmente el cambio ("+12%") y una nota.',
        props: z.object({
            label: z.string(),
            value: z.string(),
            change: z.string().optional(),
            hint: z.string().optional(),
        }),
        component: render('Metric'),
    });

    const Table = defineComponent({
        name: 'Table',
        description: 'Una tabla: cabeceras y filas de celdas (texto o número). Para comparar o listar registros.',
        props: z.object({
            columns: z.array(z.string()),
            rows: z.array(z.array(z.union([z.string(), z.number()]))),
        }),
        component: render('Table'),
    });

    const BarChart = defineComponent({
        name: 'BarChart',
        description: 'Barras horizontales para comparar cantidades entre categorías. labels y values en el mismo orden.',
        props: z.object({
            title: z.string().optional(),
            labels: z.array(z.string()),
            values: z.array(z.number()),
            unit: z.string().optional(),
        }),
        component: render('BarChart'),
    });

    const LineChart = defineComponent({
        name: 'LineChart',
        description: 'Una línea para ver una tendencia en el tiempo. labels (fechas) y values en el mismo orden.',
        props: z.object({
            title: z.string().optional(),
            labels: z.array(z.string()),
            values: z.array(z.number()),
            unit: z.string().optional(),
        }),
        component: render('LineChart'),
    });

    const DonutChart = defineComponent({
        name: 'DonutChart',
        description:
            'Un donut para ver qué parte del total es cada categoría, de un vistazo. Solo para partes de un todo y como mucho 6 partes (el resto va a «Otros» solo). Muestra el total en el centro y una leyenda con valor y %. highlight: la etiqueta a destacar, y el resto en gris. No sirve para comparar valores parecidos: para eso, BarChart.',
        props: z.object({
            title: z.string().optional(),
            labels: z.array(z.string()),
            values: z.array(z.number()),
            unit: z.string().optional(),
            highlight: z.string().optional(),
        }),
        component: render('DonutChart'),
    });

    const Image = defineComponent({
        name: 'Image',
        description: 'Una imagen por su URL https, con un pie opcional.',
        props: z.object({
            url: z.string(),
            caption: z.string().optional(),
        }),
        component: render('Image'),
    });

    const Document = defineComponent({
        name: 'Document',
        description: 'Un documento o archivo enlazado (PDF, Doc, página, hoja). Muestra título y un botón para abrirlo.',
        props: z.object({
            title: z.string(),
            url: z.string(),
            kind: z.enum(['pdf', 'doc', 'sheet', 'page', 'link']).optional(),
            note: z.string().optional(),
        }),
        component: render('Document'),
    });

    const List = defineComponent({
        name: 'List',
        description: 'Una lista de puntos cortos.',
        props: z.object({
            items: z.array(z.string()),
            ordered: z.boolean().optional(),
        }),
        component: render('List'),
    });

    const Button = defineComponent({
        name: 'Button',
        description:
            'Un botón. Sin acción, le dice su texto al asistente. Con @ToAssistant("…") le pide algo concreto; con @OpenUrl("…") abre un enlace. variant: primary para lo principal (uno por vista como mucho), secondary (por defecto) o tertiary para lo menos importante.',
        props: z.object({
            label: z.string(),
            action: action().optional(),
            variant: z.enum(['primary', 'secondary', 'tertiary']).optional(),
        }),
        component: render('Button'),
    });

    const Quote = defineComponent({
        name: 'Quote',
        description: 'Un texto citado tal cual: el fragmento de un documento o lo que dijo alguien (author).',
        props: z.object({
            text: z.string(),
            author: z.string().optional(),
        }),
        component: render('Quote'),
    });

    const Draft = defineComponent({
        name: 'Draft',
        description: 'Un borrador que aún no se ha enviado ni publicado (un comentario, un mensaje): label dice qué es y de quién.',
        props: z.object({
            label: z.string(),
            text: z.string(),
        }),
        component: render('Draft'),
    });

    const Proposal = defineComponent({
        name: 'Proposal',
        description:
            'Un cambio propuesto que espera el sí: botones Sí y Cancelar y, después, unos segundos para deshacer. action es el pending_action_id que devolvió una herramienta que propone (nunca te lo inventes); verb lo que hará (aplicar, enviar, ejecutar, guardar); done lo que dirá al terminar.',
        props: z.object({
            action: z.number(),
            verb: z.string().optional(),
            done: z.string().optional(),
        }),
        component: render('Proposal'),
    });

    const Buttons = defineComponent({
        name: 'Buttons',
        description: 'Botones que van juntos, en fila: el principal primero. Úsalo siempre que haya más de un Button.',
        props: z.object({
            buttons: z.array(Button.ref),
        }),
        component: render('Buttons'),
    });

    const ItemList = defineComponent({
        name: 'ItemList',
        description:
            'Una lista numerada de cosas que se pueden abrir (emails, issues, páginas, archivos, mensajes). Cada elemento: n (su número, el que la persona dice: «abre el 2»), title, y opcionalmente eyebrow (línea pequeña de arriba: remitente, clave, tipo), snippet, match (palabras del snippet a resaltar), meta (fecha, responsable), status, highlight (sin leer, importante), ref (cómo se abre, si viene en las filas de una Query) y actions (botones pequeños bajo el elemento: label y ref). Pulsar uno lo abre. Conserva los n, ref y actions que vengan en los datos.',
        props: z.object({
            items: z.array(
                z.object({
                    n: z.number(),
                    title: z.string(),
                    eyebrow: z.string().optional(),
                    snippet: z.string().optional(),
                    match: z.string().optional(),
                    meta: z.string().optional(),
                    status: z.string().optional(),
                    highlight: z.boolean().optional(),
                    ref: z.string().optional(),
                    actions: z.array(z.object({ label: z.string(), ref: z.string() })).optional(),
                }),
            ),
            empty: z.string().optional(),
        }),
        component: render('ItemList'),
    });

    const Chips = defineComponent({
        name: 'Chips',
        description: 'Etiquetas pequeñas en fila: los filtros de una búsqueda o categorías.',
        props: z.object({
            items: z.array(z.string()),
        }),
        component: render('Chips'),
    });

    const Tabs = defineComponent({
        name: 'Tabs',
        description: 'Pestañas para filtrar: cada opción tiene value y label, y la elegida se guarda en una $variable que las Query usan en sus argumentos.',
        props: z.object({
            options: z.array(z.object({ value: z.string(), label: z.string() })),
            value: reactive(z.string()),
        }),
        component: render('Tabs'),
    });

    const leaves = [Tabs.ref, Text.ref, Metric.ref, Table.ref, BarChart.ref, LineChart.ref, DonutChart.ref, Image.ref, Document.ref, List.ref, Button.ref, Buttons.ref, ItemList.ref, Chips.ref, Quote.ref, Draft.ref, Proposal.ref];

    const Row = defineComponent({
        name: 'Row',
        description: 'Pone sus hijos uno al lado del otro (se apilan en el móvil). Ideal para 2 a 4 Metric; para botones, Buttons.',
        props: z.object({
            children: z.array(z.union(leaves)),
        }),
        component: render('Row'),
    });

    const Card = defineComponent({
        name: 'Card',
        description: 'Agrupa bloques bajo un título.',
        props: z.object({
            title: z.string().optional(),
            children: z.array(z.union([...leaves, Row.ref])),
        }),
        component: render('Card'),
    });

    const View = defineComponent({
        name: 'View',
        description: 'La vista entera: un título y sus bloques, de arriba abajo.',
        props: z.object({
            title: z.string(),
            children: z.array(z.union([...leaves, Row.ref, Card.ref])),
        }),
        component: render('View'),
    });

    const OpenItem = defineAction({
        name: 'OpenItem',
        description: 'Abre el elemento con ese número de una ItemList de la vista.',
        params: z.object({ n: z.number(), ref: z.string().optional() }),
    });

    const Do = defineAction({
        name: 'Do',
        description: 'Hace lo que dice ref sin pasar por ti: abrir algo, o proponer un cambio que espera el sí. Solo con refs que vengan de los datos o de la vista; nunca te los inventes.',
        params: z.object({ ref: z.string() }),
    });

    const seriesSchema = z.object({ labels: z.array(z.string()), values: z.array(z.number()) });

    const GroupCount = defineFunction({
        name: 'GroupCount',
        description: 'Cuenta las filas por un campo: {labels, values}, el grupo mayor primero. Para DonutChart o BarChart.',
        params: z.object({ rows: z.array(z.any()), field: z.string() }),
        returns: seriesSchema,
        fn: ({ rows, field }) => groupCount(rows, field),
    });

    const GroupSum = defineFunction({
        name: 'GroupSum',
        description: 'Suma un campo numérico (value) por otro campo (field): {labels, values}, el mayor primero.',
        params: z.object({ rows: z.array(z.any()), field: z.string(), value: z.string() }),
        returns: seriesSchema,
        fn: ({ rows, field, value }) => groupSum(rows, field, value),
    });

    const Top = defineFunction({
        name: 'Top',
        description: 'Los n primeros grupos de un {labels, values}, y el resto junto en «Otros».',
        params: z.object({ series: seriesSchema, n: z.number() }),
        returns: seriesSchema,
        fn: ({ series, n }) => top(series, n),
    });

    const ByDay = defineFunction({
        name: 'ByDay',
        description: 'Filas por día de un campo de fecha (o la suma de value por día), del más antiguo al más reciente: {labels, values}. Para LineChart.',
        params: z.object({ rows: z.array(z.any()), field: z.string(), value: z.string().optional() }),
        returns: seriesSchema,
        fn: ({ rows, field, value }) => byDay(rows, field, value),
    });

    return createLibrary({
        components: [View, Row, Card, Tabs, Text, Metric, Table, BarChart, LineChart, DonutChart, Image, Document, List, Button, Buttons, ItemList, Chips, Quote, Draft, Proposal],
        functions: [GroupCount, GroupSum, Top, ByDay],
        componentGroups: [
            { name: 'Estructura', components: ['View', 'Row', 'Card'] },
            { name: 'Contenido', components: ['Text', 'List', 'Chips', 'Tabs', 'Image', 'Document', 'Button', 'Buttons'] },
            {
                name: 'Datos',
                components: ['Metric', 'Table', 'BarChart', 'LineChart', 'DonutChart'],
                notes: ['Elige por la pregunta: partes de un todo (6 o menos) → DonutChart; comparar cantidades → BarChart; evolución en el tiempo → LineChart; 1 a 4 cifras → Metric; detalle → Table.'],
            },
            { name: 'Listas que se abren', components: ['ItemList'], notes: ['Úsala para resultados de búsqueda y listas de issues, emails, páginas o mensajes.'] },
            { name: 'Cambios', components: ['Quote', 'Draft', 'Proposal'], notes: ['Un Proposal por cambio propuesto, al final de la vista, con lo que cambiará encima (Draft para el texto, Quote para lo que responde).'] },
        ],
        actions: [OpenItem, Do],
        root: 'View',
    });
}

/** The datasets, as the voice is told about them in one line. */
const datasetLine = (): string => datasets.map((dataset) => dataset.name).join(', ');

/** What every view follows, said once in the always-loaded prompt. */
const viewRules = (): string[] => [
    'Como mucho 12 bloques: la pantalla es para mirar de un vistazo, no un informe.',
    'Si en pantalla hay una vista (openui.view) y piden cambiarla (filtrar, ordenar, quitar o añadir algo), llama a show_view con su programa completo ya cambiado, partiendo del que hay en pantalla; conserva los n, ref y actions de cada elemento de una ItemList y los Proposal tal cual.',
    'Usa Metric para 1 a 4 cifras clave, LineChart para series en el tiempo, BarChart para comparar categorías y Table para el detalle.',
    'Solo URLs https que vengan de los datos; no inventes enlaces ni imágenes.',
    'Añade como mucho 3 Button con lo siguiente que el usuario querría pedir, juntos en un Buttons al final.',
    'No uses Mutation: desde una vista no se escribe nada. Para cambiar algo, usa las herramientas que lo proponen y esperan el sí.',
    `Para gráficos y cifras de datos vivos (datasets: ${datasetLine()}), o para cambiar una vista que tenga Query o $variables, llama antes a view_data_guide: explica Query, $variables, las funciones y cada dataset.`,
];

/**
 * What the voice is told when it has to compose a view, always: the syntax,
 * the blocks and the rules. Written to resources/openui/prompt.md by
 * `npm run openui:prompt`. Live data is in dataGuide(), asked for on demand.
 */
export function viewPrompt(): string {
    return buildLibrary().prompt({
        toolCalls: false,
        bindings: false,
        builtinFunctions: false,
        preamble:
            'Para mostrar en pantalla datos que no tienen una vista propia (resultados de un servidor MCP, analítica, comparativas, archivos, imágenes), llama a show_view con una interfaz escrita en OpenUI Lang. Lo dicho en voz sigue siendo corto: el detalle va en la vista. Textos en español.',
        additionalRules: viewRules(),
        examples: [
            [
                'root = View("Tráfico, últimos 7 días", [kpis, trend, top])',
                'kpis = Row([users, sessions])',
                'users = Metric("Usuarios", "1.204", "+8%")',
                'sessions = Metric("Sesiones", "1.873", "-2%")',
                'trend = LineChart("Usuarios por día", ["24 oct", "25 oct", "26 oct"], [160, 172, 181])',
                'top = Table(["Página", "Vistas"], [["/precios", 412], ["/blog", 220]])',
            ].join('\n'),
        ],
    });
}

/** Sections of OpenUI's own prompt that do not apply here: views never write, and there are no forms. */
const LEFT_OUT = ['Mutation', 'Forms'];

/** Sections of OpenUI's own prompt that teach live data, kept for the guide (its filters assume Select, so ours are written below). */
const DATA_SECTIONS = ['Built-in Functions', 'Query', 'Available Tools'];

/** Filters and paging with this library's blocks. */
const FILTERS = [
    '## Filtros y «cargar más»',
    '1. Declara la $variable con su valor inicial: `$status = "pending"`.',
    '2. Tabs con las opciones y la $variable: `tabs = Tabs([{value: "pending", label: "Pendientes"}, {value: "all", label: "Todos"}], $status)`.',
    '3. Pásala en los argumentos de la Query: `alerts = Query("alerts", {status: $status, limit: $limit}, {rows: []})`. Al cambiar de pestaña, vuelve a pedir los datos sola.',
    '4. Para «cargar más», sube el límite: `more = @Count(alerts.rows) >= $limit ? Button("Cargar más", @Set($limit, $limit + 30)) : null`.',
    'Si se ve un filtro, toda Query a la que afecte lleva su $variable. Las $variables guardan textos o números, nunca listas.',
].join('\n');

/**
 * How to use live data in a view: Query, $variables and filters, the
 * functions and every dataset. The voice asks for it with view_data_guide.
 * Written to resources/openui/data-guide.md by `npm run openui:prompt`.
 */
export function dataGuide(): string {
    const full = buildLibrary().prompt({
        tools: datasets,
        toolCalls: true,
        bindings: true,
        additionalRules: viewRules(),
        toolExamples: [
            [
                'root = View("Issues de EXP", [tabs, donut])',
                '$status = "all"',
                'tabs = Tabs([{value: "all", label: "Todos"}, {value: "open", label: "Abiertos"}], $status)',
                'issues = Query("jira_issues", {jql: "project = EXP"}, {rows: []})',
                'byStatus = @Top(@GroupCount(issues.rows, "status"), 6)',
                'donut = DonutChart("Por estado", byStatus.labels, byStatus.values)',
            ].join('\n'),
        ],
    });

    const sections = full.split(/^(?=## )/m).filter((section) => {
        const heading = section.split('\n', 1)[0];

        return DATA_SECTIONS.some((name) => heading.includes(name)) && !LEFT_OUT.some((name) => heading.includes(name));
    });

    return [
        '# Datos vivos en una vista',
        'Las vistas no escriben nada: Query solo lee estos datasets, y no existe Mutation. Para filtros, el bloque Tabs con una $variable (no hay Select ni formularios). Para cambiar algo, usa las herramientas que proponen y esperan el sí.',
        ...sections.map((section) =>
            section
                .trim()
                // Never made-up data: with no dataset for it, say so.
                .replace(/^CRITICAL: Use ONLY the tools listed above.*$/m, 'Usa solo estos datasets en Query, con estos nombres. Si ninguno sirve, dilo: nunca escribas datos inventados.')
                .replace(/ or Mutation\(\) for write operations\. The LLM decides which is appropriate based on the tool's purpose\./, '.'),
        ),
        FILTERS,
    ].join('\n\n');
}
