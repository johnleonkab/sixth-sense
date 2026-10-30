import { action, createLibrary, defineComponent } from '@openuidev/react-lang';
import type { ComponentRenderer, Library } from '@openuidev/react-lang';
import { z } from 'zod/v4';

/**
 * The building blocks the assistant may compose a canvas from, with OpenUI.
 * This file holds no JSX: the same definitions feed the renderers on screen
 * and `npm run openui:prompt`, which writes the prompt Laravel gives the
 * voice. To add a block: describe it here, render it in library.tsx, and run
 * the prompt script.
 */
export type Renderers = Partial<Record<BlockName, ComponentRenderer<any>>>; // eslint-disable-line @typescript-eslint/no-explicit-any

export type BlockName = 'View' | 'Row' | 'Card' | 'Text' | 'Metric' | 'Table' | 'BarChart' | 'LineChart' | 'Image' | 'Document' | 'List' | 'Button';

const nothing: ComponentRenderer<any> = () => null; // eslint-disable-line @typescript-eslint/no-explicit-any

export function buildLibrary(renderers: Renderers = {}): Library {
    const render = (name: BlockName) => renderers[name] ?? nothing;

    const Text = defineComponent({
        name: 'Text',
        description: 'Un párrafo corto. muted para notas secundarias.',
        props: z.object({
            text: z.string(),
            tone: z.enum(['default', 'muted']).optional(),
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
        description: 'Un botón. Sin acción, le dice su texto al asistente. Con @ToAssistant("…") le pide algo concreto; con @OpenUrl("…") abre un enlace.',
        props: z.object({
            label: z.string(),
            action: action().optional(),
        }),
        component: render('Button'),
    });

    const leaves = [Text.ref, Metric.ref, Table.ref, BarChart.ref, LineChart.ref, Image.ref, Document.ref, List.ref, Button.ref];

    const Row = defineComponent({
        name: 'Row',
        description: 'Pone sus hijos uno al lado del otro (se apilan en el móvil). Ideal para 2 a 4 Metric o Button.',
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

    return createLibrary({
        components: [View, Row, Card, Text, Metric, Table, BarChart, LineChart, Image, Document, List, Button],
        root: 'View',
    });
}

/**
 * What the voice is told when it has to compose a view. Written to
 * resources/openui/prompt.md by `npm run openui:prompt`.
 */
export function viewPrompt(): string {
    return buildLibrary().prompt({
        preamble:
            'Para mostrar en pantalla datos que no tienen una vista propia (resultados de un servidor MCP, analítica, comparativas, archivos, imágenes), llama a show_view con una interfaz escrita en OpenUI Lang. Lo dicho en voz sigue siendo corto: el detalle va en la vista. Textos en español.',
        additionalRules: [
            'Como mucho 12 bloques: la pantalla es para mirar de un vistazo, no un informe.',
            'Usa Metric para 1 a 4 cifras clave, LineChart para series en el tiempo, BarChart para comparar categorías y Table para el detalle.',
            'Solo URLs https que vengan de los datos; no inventes enlaces ni imágenes.',
            'Añade como mucho 3 Button con lo siguiente que el usuario querría pedir.',
        ],
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
