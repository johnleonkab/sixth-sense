import { useEffect, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import type { MdastImportVisitor, MDXEditorProps, RealmPlugin } from '@mdxeditor/editor';

type EditorModule = typeof import('@mdxeditor/editor');

type Bundle = {
    Editor: EditorModule['MDXEditor'];
    plugins: RealmPlugin[];
};

const spanish: Record<string, string> = {
    'contentArea.editableMarkdown': 'Documento en markdown',
    'toolbar.undo': 'Deshacer {{shortcut}}',
    'toolbar.redo': 'Rehacer {{shortcut}}',
    'toolbar.blockTypeSelect.placeholder': 'Bloque',
    'toolbar.blockTypeSelect.selectBlockTypeTooltip': 'Tipo de bloque',
    'toolbar.blockTypes.paragraph': 'Párrafo',
    'toolbar.blockTypes.quote': 'Cita',
    'toolbar.blockTypes.heading': 'Título {{level}}',
    'toolbar.bold': 'Negrita',
    'toolbar.removeBold': 'Quitar negrita',
    'toolbar.italic': 'Cursiva',
    'toolbar.removeItalic': 'Quitar cursiva',
    'toolbar.inlineCode': 'Código',
    'toolbar.removeInlineCode': 'Quitar código',
    'toolbar.bulletedList': 'Lista',
    'toolbar.numberedList': 'Lista numerada',
    'toolbar.checkList': 'Tareas',
    'toolbar.link': 'Enlace',
    'toolbar.thematicBreak': 'Separador',
    'toolbar.codeBlock': 'Bloque de código',
    'toolbar.editFrontmatter': 'Editar metadatos',
    'frontmatterEditor.title': 'Metadatos del documento',
    'frontmatterEditor.dialogDescription': 'Añade, edita o quita los campos del inicio del documento.',
    'frontmatterEditor.key': 'Campo',
    'frontmatterEditor.value': 'Valor',
    'frontmatterEditor.addEntry': 'Añadir campo',
    'createLink.url': 'URL',
    'createLink.urlPlaceholder': 'Pega una URL',
    'createLink.text': 'Texto',
    'createLink.textTooltip': 'El texto visible del enlace',
    'createLink.saveTooltip': 'Guardar el enlace',
    'createLink.cancelTooltip': 'Cancelar',
    'dialogControls.save': 'Guardar',
    'dialogControls.cancel': 'Cancelar',
    'dialog.close': 'Cerrar',
    'linkPreview.edit': 'Editar enlace',
    'linkPreview.remove': 'Quitar enlace',
    'linkPreview.copyToClipboard': 'Copiar',
    'linkPreview.copied': 'Copiado',
    'codeBlock.language': 'Lenguaje',
    'codeBlock.selectLanguage': 'Elegir lenguaje',
    'codeblock.delete': 'Borrar el bloque de código',
};

/**
 * Markdown editor. Formatting comes from MDXEditor: # and ** take effect in
 * the same surface, and the value handed back is markdown.
 */
export default function LiveMarkdown({
    value,
    onChange,
    readOnly = false,
    resetKey = '',
    label,
    onSave,
}: {
    value: string;
    onChange: (value: string) => void;
    readOnly?: boolean;
    resetKey?: string;
    label: string;
    onSave?: () => void;
}) {
    const [bundle, setBundle] = useState<Bundle | null>(null);
    const [dark, setDark] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;
        const root = document.documentElement;
        const query = window.matchMedia('(prefers-color-scheme: dark)');
        const syncTheme = () => setDark(isDarkTheme(root, query));

        syncTheme();
        query.addEventListener('change', syncTheme);
        const observer = new MutationObserver(syncTheme);
        observer.observe(root, { attributes: true, attributeFilter: ['data-theme'] });

        void Promise.all([import('@mdxeditor/editor'), import('@mdxeditor/editor/style.css'), import('lexical')])
            .then(([mod, , lexical]) => {
                if (!cancelled) {
                    setBundle({ Editor: mod.MDXEditor, plugins: pluginsFor(mod, lexical) });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
            query.removeEventListener('change', syncTheme);
            observer.disconnect();
        };
    }, []);

    const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            onSave?.();
        }
    };

    const translate: MDXEditorProps['translation'] = (key, fallback, interpolations) => {
        if (key === 'contentArea.editableMarkdown') {
            return label;
        }

        return applyInterpolations(spanish[key] ?? fallback, interpolations);
    };

    return (
        <div className={`flex min-h-0 flex-1 flex-col ${dark ? 'dark-theme' : ''}`} onKeyDown={onKeyDown}>
            {failed && <p className="px-8 py-5 text-[13.5px] text-crit">No he podido abrir el editor.</p>}
            {!failed && bundle === null && <div className="min-h-64 flex-1" />}
            {bundle && (
                <bundle.Editor
                    key={resetKey}
                    markdown={value}
                    plugins={bundle.plugins}
                    readOnly={readOnly}
                    trim={false}
                    suppressHtmlProcessing
                    spellCheck
                    placeholder="Escribe # o ** para dar formato…"
                    translation={translate}
                    className={`sense-markdown mdxeditor-full-height min-h-0 flex-1 ${dark ? 'dark-theme' : ''}`}
                    onChange={(markdown, initialMarkdownNormalize) => {
                        if (initialMarkdownNormalize || markdown === value) {
                            return;
                        }

                        onChange(markdown);
                    }}
                />
            )}
        </div>
    );
}

function pluginsFor(mod: EditorModule, lexical: typeof import('lexical')): RealmPlugin[] {
    const htmlAsText = mod.realmPlugin({
        init(realm) {
            const visitor: MdastImportVisitor<import('mdast').Html> = {
                testNode: 'html',
                visitNode({ mdastNode, lexicalParent, actions }) {
                    const text = lexical.$createTextNode(mdastNode.value);

                    if (lexical.$isElementNode(lexicalParent) && lexicalParent.getType() === 'root') {
                        const paragraph = lexical.$createParagraphNode();
                        paragraph.append(text);
                        lexicalParent.append(paragraph);

                        return;
                    }

                    actions.addAndStepInto(text);
                },
            };

            realm.pubIn({
                [mod.addImportVisitor$]: visitor,
            });
        },
    })();

    return [
        htmlAsText,
        mod.headingsPlugin(),
        mod.listsPlugin(),
        mod.quotePlugin(),
        mod.thematicBreakPlugin(),
        mod.linkPlugin(),
        mod.linkDialogPlugin(),
        mod.frontmatterPlugin(),
        mod.codeBlockPlugin(),
        mod.codeMirrorPlugin({
            codeBlockLanguages: {
                txt: 'Texto',
                md: 'Markdown',
                js: 'JavaScript',
                ts: 'TypeScript',
                tsx: 'TSX',
                jsx: 'JSX',
                php: 'PHP',
                py: 'Python',
                yml: 'YAML',
                yaml: 'YAML',
                json: 'JSON',
                bash: 'Shell',
                sh: 'Shell',
                css: 'CSS',
                html: 'HTML',
            },
        }),
        mod.tablePlugin(),
        mod.imagePlugin(),
        mod.markdownShortcutPlugin(),
        mod.toolbarPlugin({
            toolbarContents: () => (
                <>
                    <mod.UndoRedo />
                    <mod.Separator />
                    <mod.BlockTypeSelect />
                    <mod.Separator />
                    <mod.BoldItalicUnderlineToggles options={['Bold', 'Italic']} />
                    <mod.CodeToggle />
                    <mod.Separator />
                    <mod.ListsToggle />
                    <mod.CreateLink />
                    <mod.InsertThematicBreak />
                    <mod.InsertCodeBlock />
                </>
            ),
        }),
    ];
}

function isDarkTheme(root: HTMLElement, query: MediaQueryList): boolean {
    const forced = root.getAttribute('data-theme');

    if (forced === 'dark') {
        return true;
    }

    if (forced === 'light') {
        return false;
    }

    return query.matches;
}

function applyInterpolations(template: string, interpolations?: Record<string, unknown>): string {
    let value = template;

    for (const [name, replacement] of Object.entries(interpolations ?? {})) {
        value = value.replaceAll(`{{${name}}}`, String(replacement));
    }

    return value;
}
