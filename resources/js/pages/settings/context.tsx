import { Link, router, usePoll } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';

import { Button, buttonClass } from '@/components/button';
import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type Tab = 'people' | 'topics' | 'areas' | 'known' | 'rules' | 'pending' | 'channels' | 'urgent' | 'changes';

type Origin = 'user' | 'correction' | 'observed' | 'inferred';

type WorkContext = {
    enabled: boolean;
    status: 'idle' | 'running' | 'ready' | 'failed';
    status_label: string;
    error: string | null;
    started_at: string | null;
    generated_at: string | null;
    /** On and read in the last three days. */
    recent: boolean;
    sources: { slack?: number; gmail?: number; docs?: number; notion?: number; jira?: number; failed?: string[] } | null;
};

type KnowledgeSource = {
    source: 'archive' | 'jira' | 'notion' | 'meeting' | 'memory';
    label: string;
    total: number;
    copied: number;
    summarized: number;
    last_copied_at: string | null;
};

type Fact = {
    id: number;
    ref: string;
    kind: string;
    subject: string;
    attribute: string;
    text: string;
    value: Record<string, unknown>;
    origin: Origin;
    at: string;
    status: string;
    audiences: string[];
    refs: string[];
    uses: number;
    last_used_at: string | null;
    contradicts: string | null;
};

type Rule = Fact & { audience_labels: string[] };

type Person = {
    id: number;
    name: string;
    relation: string;
    weight: number;
    role: string | null;
    team: string | null;
    ref: string;
    origins: Record<string, Origin>;
};
type Area = { id: number; kind: string; ref: string; name: string; keywords: string[] };
type Preference = { timezone: string; locale: string; work_start: string; work_end: string; work_days: number[] };
type Option = { value: string; label: string; weight?: number };

type Channel = {
    id: string;
    name: string;
    members: number;
    messages: number;
    included: boolean;
    selected: boolean;
};

type UrgentRule = {
    kind: 'person' | 'keyword' | 'channel' | 'person_weight';
    ref: string;
    sources: string[];
    active: boolean;
    label: string;
};

type KeywordPreview = {
    count: number;
    examples: { id: number; text: string }[];
};

type Inertia = {
    rate: number | null;
    sends: number;
    sample: { id: number; actor: string | null; title: string | null; text: string; at: string }[];
};

type MergeProposal = {
    question: string;
    evidence: string;
    names: string[];
    from: string;
    into: string;
};

type TopicNickname = { id: number; alias: string };

type Topic = {
    subject: string;
    name: string;
    summary: string;
    state: string;
    people: string[];
    channels: string[];
    nicknames: TopicNickname[];
    proposed: boolean;
};

type NightLine = {
    id: number;
    op: string;
    quote: string | null;
    why: string | null;
    disposition: string;
    answers: string[];
};

type NightClass = { key: string; label: string; on: boolean };

type NightChanges = { lines: NightLine[]; classes: NightClass[] };

type ContextProps = {
    tab: Tab;
    context: WorkContext;
    run_at: string;
    knowledge: KnowledgeSource[];
    people: Person[];
    topics: Topic[];
    merge_proposals: MergeProposal[];
    areas: Area[];
    preference: Preference;
    relations: Option[];
    kinds: Option[];
    timezones: string[];
    tones: Fact[];
    known: Fact[];
    rules: Rule[];
    pending: Fact[];
    inertia: Inertia;
    channels: Channel[];
    urgent_rules: UrgentRule[];
    night_changes: NightChanges;
};

const tabs: { value: Tab; label: string }[] = [
    { value: 'people', label: 'Personas' },
    { value: 'topics', label: 'Temas' },
    { value: 'areas', label: 'Áreas y horario' },
    { value: 'known', label: 'Lo que sé' },
    { value: 'rules', label: 'Reglas' },
    { value: 'pending', label: 'Pendiente de confirmar' },
    { value: 'channels', label: 'Canales de Slack' },
    { value: 'urgent', label: 'Qué es urgente' },
    { value: 'changes', label: 'Cambios de la noche' },
];

const originLabel: Record<Origin, string> = {
    user: 'lo dijiste tú',
    correction: 'aprendido',
    observed: 'visto',
    inferred: 'deducido',
};

const knownLabel: Record<string, string> = {
    summary: 'Resumen',
    state: 'En qué estás',
    meaning: 'Lo que significa',
    note: 'Con quién',
    hot: 'Caliente',
    moved: 'Jira reciente',
};

const knowledgeUnits: Record<KnowledgeSource['source'], string> = {
    archive: 'archivos',
    jira: 'issues',
    notion: 'páginas',
    meeting: 'notas y transcripciones',
    memory: 'respuestas',
};

const statusDot: Record<WorkContext['status'], string> = {
    idle: 'bg-faint',
    running: 'bg-accent animate-pulse',
    ready: 'bg-ok',
    failed: 'bg-crit',
};

const sourceNames: [keyof NonNullable<WorkContext['sources']>, string, string][] = [
    ['slack', 'Slack', 'mensajes'],
    ['gmail', 'Gmail', 'emails'],
    ['docs', 'Drive', 'archivos'],
    ['notion', 'Notion', 'páginas'],
    ['jira', 'Jira', 'issues'],
];

const days = [
    { value: 1, label: 'Lunes' },
    { value: 2, label: 'Martes' },
    { value: 3, label: 'Miércoles' },
    { value: 4, label: 'Jueves' },
    { value: 5, label: 'Viernes' },
    { value: 6, label: 'Sábado' },
    { value: 7, label: 'Domingo' },
];

const fieldClass = 'w-full rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink outline-none focus:border-accent';
const cardClass = 'rounded-2xl border border-line bg-surface px-[22px] py-[18px]';

const formatMoment = (iso: string) =>
    new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

const dayFormat = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });

const factName = (fact: Fact) => String(fact.value.name ?? fact.value.title ?? fact.subject.split(':').slice(1).join(':') ?? fact.subject);

const factText = (fact: Fact) => fact.text || String(fact.value.title ?? fact.attribute);

const post = (url: string) => router.post(url, {}, { preserveScroll: true });

function Section({ id, title, description, children }: { id: string; title: string; description?: string; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className={cardClass}>
            <h2 id={id} className="text-[15px] font-medium tracking-[-0.01em]">
                {title}
            </h2>
            {description && <p className="mt-0.5 max-w-[520px] text-[13px] text-muted">{description}</p>}
            <div className="mt-4">{children}</div>
        </section>
    );
}

/** One fact: where it came from, how old it is, and a way to correct or delete it. */
function FactRow({ fact, labels = [] }: { fact: Fact; labels?: string[] }) {
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState(factText(fact));

    const save = () => router.put(`/settings/context/facts/${fact.id}`, { text }, { preserveScroll: true, onSuccess: () => setEditing(false) });

    return (
        <li className="grid gap-1.5 border-b border-line py-3 last:border-b-0">
            {editing ? (
                <textarea
                    aria-label="Corregir"
                    value={text}
                    maxLength={240}
                    onChange={(event) => setText(event.target.value)}
                    rows={2}
                    className="w-full resize-y rounded-md border border-line bg-surface px-2.5 py-2 text-[13.5px] leading-[1.5] outline-none focus:border-accent"
                />
            ) : (
                <p className="text-[13.5px] leading-[1.5]">{factText(fact)}</p>
            )}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted">
                {labels.map((label) => (
                    <span key={label} className="rounded-full bg-soft px-2 py-[2px] text-[11px] font-medium text-ink/80">
                        {label}
                    </span>
                ))}
                {fact.subject !== 'user' && <span>{factName(fact)}</span>}
                <span>{originLabel[fact.origin]}</span>
                <span>{dayFormat.format(new Date(fact.at))}</span>
                {fact.uses > 0 && <span>{fact.uses === 1 ? 'Usado 1 vez' : `Usado ${fact.uses} veces`}</span>}
                {fact.last_used_at && <span>Último uso {dayFormat.format(new Date(fact.last_used_at))}</span>}
                {fact.refs.length > 0 && <span title={fact.refs.join(', ')}>{fact.refs.length === 1 ? fact.refs[0] : `${fact.refs.length} fuentes`}</span>}
                <span className="ml-auto flex gap-1.5">
                    {editing ? (
                        <>
                            <Button variant="primary" onClick={save} disabled={text.trim() === ''}>
                                Guardar
                            </Button>
                            <Button
                                variant="tertiary"
                                onClick={() => {
                                    setEditing(false);
                                    setText(factText(fact));
                                }}
                            >
                                Cancelar
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button variant="tertiary" onClick={() => setEditing(true)}>
                                Corregir
                            </Button>
                            <Button variant="tertiary" destructive onClick={() => post(`/settings/context/facts/${fact.id}/reject`)}>
                                Borrar
                            </Button>
                        </>
                    )}
                </span>
            </div>
        </li>
    );
}

function PersonRow({ person, relations }: { person: Person; relations: Option[] }) {
    const [relation, setRelation] = useState(person.relation);
    const [weight, setWeight] = useState(String(person.weight));
    const details = [
        person.role && `${person.role}`,
        person.team && `equipo ${person.team}`,
        person.origins.relation && `relación: ${originLabel[person.origins.relation]}`,
        person.origins.language && `idioma: ${originLabel[person.origins.language]}`,
    ].filter(Boolean);

    return (
        <li className="grid items-center gap-2 border-t border-line py-3 sm:grid-cols-[minmax(0,1fr)_180px_88px_auto]">
            <span className="min-w-0">
                <span className="block truncate text-[14px]">{person.name}</span>
                {details.length > 0 && <span className="block truncate text-[12px] text-muted">{details.join(' · ')}</span>}
            </span>
            <select aria-label={`Relación de ${person.name}`} value={relation} onChange={(event) => setRelation(event.target.value)} className={fieldClass}>
                {relations.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <input aria-label={`Peso de ${person.name}`} inputMode="numeric" value={weight} onChange={(event) => setWeight(event.target.value)} className={fieldClass} />
            <button
                type="button"
                className={buttonClass({ size: 'small' })}
                onClick={() =>
                    router.put(`/settings/context/people/${person.id}`, { relation, importance_weight: weight === '' ? null : Number(weight) }, { preserveScroll: true })
                }
            >
                Guardar
            </button>
        </li>
    );
}

function PeopleTab({ people, relations, merge_proposals }: Pick<ContextProps, 'people' | 'relations' | 'merge_proposals'>) {
    return (
        <>
            {merge_proposals.length > 0 && (
                <Section id="context-people-merge" title="¿Son la misma persona?" description="Confírmalo una vez. El nombre, por sí solo, no las une.">
                    <ul>
                        {merge_proposals.map((merge) => (
                            <li key={`${merge.from}:${merge.into}`} className="grid items-center gap-2 border-t border-line py-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <span className="min-w-0">
                                    <span className="block truncate text-[14px]">{merge.names.join(' y ')}</span>
                                    <span className="block text-[12px] text-muted">{merge.evidence}</span>
                                </span>
                                <Button
                                    type="button"
                                    onClick={() => router.post('/settings/context/people/merge', { from: merge.from, into: merge.into }, { preserveScroll: true })}
                                >
                                    Sí, son la misma
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
            <Section id="context-people" title="Personas" description="Quién importa y por qué. Junto a cada valor, de dónde viene. Lo que dices tú gana.">
                {people.length === 0 ? (
                    <p className="text-[14px] text-muted">Todavía no hay nadie. Aparecerán cuando lleguen mensajes.</p>
                ) : (
                    <ul>
                        {people.map((person) => (
                            <PersonRow key={person.id} person={person} relations={relations} />
                        ))}
                    </ul>
                )}
            </Section>
        </>
    );
}

function ToneForm({ channel, label, current }: { channel: 'slack' | 'gmail'; label: string; current?: Fact }) {
    const [text, setText] = useState(current ? factText(current) : '');

    return (
        <form
            className="grid gap-2 sm:grid-cols-[88px_minmax(0,1fr)_auto] sm:items-center"
            onSubmit={(event) => {
                event.preventDefault();
                router.put('/settings/context/tone', { channel, text }, { preserveScroll: true });
            }}
        >
            <span className="text-[13px] text-muted">{label}</span>
            <input aria-label={`Cómo escribes en ${label}`} value={text} maxLength={240} onChange={(event) => setText(event.target.value)} className={fieldClass} />
            <button type="submit" className={buttonClass({ size: 'small' })} disabled={text.trim() === ''}>
                Guardar
            </button>
            {current && <span className="text-[12px] text-muted sm:col-start-2">{originLabel[current.origin]}</span>}
        </form>
    );
}

function AreasTab({ areas, preference, kinds, timezones, tones }: Pick<ContextProps, 'areas' | 'preference' | 'kinds' | 'timezones' | 'tones'>) {
    const saveHours = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);

        router.put(
            '/settings/context/preference',
            {
                timezone: data.get('timezone'),
                locale: data.get('locale'),
                work_start: data.get('work_start'),
                work_end: data.get('work_end'),
                work_days: data.getAll('work_days').map(Number),
            },
            { preserveScroll: true },
        );
    };

    const saveArea = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = new FormData(form);

        router.post(
            '/settings/context/areas',
            { kind: data.get('kind'), ref: data.get('ref'), name: data.get('name'), keywords: data.get('keywords') },
            { preserveScroll: true, onSuccess: () => form.reset() },
        );
    };

    const tone = (channel: string) => tones.find((fact) => fact.subject === `channel:${channel}`);

    return (
        <div className="flex flex-col gap-4">
            <Section id="context-hours" title="Horario">
                <form onSubmit={saveHours} className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="block text-[13px] text-muted">
                            Zona horaria
                            <select name="timezone" defaultValue={preference.timezone} className={`${fieldClass} mt-1`}>
                                {timezones.map((zone) => (
                                    <option key={zone}>{zone}</option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-[13px] text-muted">
                            Idioma
                            <select name="locale" defaultValue={preference.locale} className={`${fieldClass} mt-1`}>
                                <option value="es">Español</option>
                                <option value="en">English</option>
                            </select>
                        </label>
                        <label className="block text-[13px] text-muted">
                            Empiezo
                            <input name="work_start" type="time" defaultValue={preference.work_start} required className={`${fieldClass} mt-1`} />
                        </label>
                        <label className="block text-[13px] text-muted">
                            Termino
                            <input name="work_end" type="time" defaultValue={preference.work_end} required className={`${fieldClass} mt-1`} />
                        </label>
                    </div>
                    <fieldset>
                        <legend className="text-[13px] text-muted">Días</legend>
                        <div className="mt-2 flex flex-wrap gap-3">
                            {days.map((day) => (
                                <label key={day.value} className="flex items-center gap-1.5 text-[13px]">
                                    <input type="checkbox" name="work_days" value={day.value} defaultChecked={preference.work_days.includes(day.value)} className="accent-[var(--accent)]" />
                                    {day.label}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <button type="submit" className={buttonClass({ variant: 'primary', size: 'small' })}>
                        Guardar horario
                    </button>
                </form>
            </Section>

            <Section id="context-tone" title="Cómo escribes" description="El tono que usan los borradores en cada sitio. Si no lo escribes, se deduce cada noche.">
                <div className="space-y-3">
                    <ToneForm channel="slack" label="Slack" current={tone('slack')} />
                    <ToneForm channel="gmail" label="Gmail" current={tone('gmail')} />
                </div>
            </Section>

            <Section id="context-areas" title="Lo que llevas">
                <ul className="space-y-2">
                    {areas.map((area) => (
                        <li key={area.id} className="flex items-center justify-between gap-3 text-[14px]">
                            <span>
                                {area.name} <span className="text-muted">{area.ref}</span>
                            </span>
                            <button
                                type="button"
                                className={buttonClass({ variant: 'tertiary', size: 'extra-small', destructive: true })}
                                onClick={() => router.delete(`/settings/context/areas/${area.id}`, { preserveScroll: true })}
                            >
                                Quitar
                            </button>
                        </li>
                    ))}
                </ul>
                <form onSubmit={saveArea} className="mt-4 grid gap-3 sm:grid-cols-2">
                    <label className="block text-[13px] text-muted">
                        Tipo
                        <select name="kind" className={`${fieldClass} mt-1`} defaultValue={kinds[0]?.value}>
                            {kinds.map((kind) => (
                                <option key={kind.value} value={kind.value}>
                                    {kind.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="block text-[13px] text-muted">
                        Nombre
                        <input name="name" required className={`${fieldClass} mt-1`} />
                    </label>
                    <label className="block text-[13px] text-muted">
                        Referencia
                        <input name="ref" required className={`${fieldClass} mt-1`} />
                    </label>
                    <label className="block text-[13px] text-muted">
                        Palabras, separadas por comas
                        <input name="keywords" className={`${fieldClass} mt-1`} />
                    </label>
                    <button type="submit" className={buttonClass({ size: 'small' })}>
                        Añadir
                    </button>
                </form>
            </Section>
        </div>
    );
}

function Nightly({ context, runAt }: { context: WorkContext; runAt: string }) {
    const building = context.status === 'running';
    const toggle = (enabled: boolean) => router.put('/settings/context', { enabled }, { preserveScroll: true });

    return (
        <section aria-labelledby="context-nightly" className="rounded-2xl border border-line bg-surface">
            <header className="flex items-start gap-3 px-[22px] pt-[18px] pb-4">
                <div className="min-w-0 flex-1">
                    <h2 id="context-nightly" className="text-[15px] font-medium tracking-[-0.01em]">
                        Lectura de cada noche
                    </h2>
                    <p className="mt-0.5 max-w-[480px] text-[13px] text-muted">
                        Lee Slack y Gmail por conversación, cómo escribes en cada uno, el Drive de los últimos 30 días y Notion desde las páginas más altas. Lo que deduce entra aquí como «deducido»: se usa mientras tenga menos de tres días y caduca si nada lo confirma.
                    </p>
                </div>

                <Switch checked={context.enabled} onChange={toggle} className="mt-1 flex-none">
                    <span className="sr-only">Lectura de cada noche</span>
                </Switch>
            </header>

            <div className="border-t border-line px-[22px] py-4 text-[13px]">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="flex items-center gap-2 font-medium">
                            <i aria-hidden className={`size-2 rounded-full ${statusDot[context.status]}`} />
                            {context.status_label}
                        </p>
                        <p className="mt-0.5 text-muted">
                            {context.generated_at ? `Último: ${formatMoment(context.generated_at)}. ` : 'Aún no se ha generado. '}
                            {context.enabled ? `Se actualiza cada noche a las ${runAt}.` : 'Actívalo para que se actualice cada noche.'}
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() => post('/settings/context/build')}
                        disabled={!context.enabled || building}
                        className="rounded-lg border border-line px-3 py-1.5 text-[13px] font-medium hover:bg-soft disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {building ? 'Generando…' : 'Generar ahora'}
                    </button>
                </div>

                {context.error && <p className="mt-3 rounded-xl bg-crit-soft px-3.5 py-2.5 text-crit">{context.error}</p>}

                {context.sources && (
                    <ul className="mt-3 flex flex-wrap gap-1.5">
                        {sourceNames.map(([key, name, unit]) => {
                            const failed = context.sources?.failed?.includes(key);

                            return (
                                <li key={key} className={`rounded-full bg-soft px-2.5 py-1 text-[12.5px] ${failed ? 'text-crit' : 'text-muted'}`}>
                                    {name} · {failed ? 'no se pudo leer' : `${context.sources?.[key] ?? 0} ${unit}`}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {context.enabled && context.generated_at && !context.recent && !building && (
                    <p className="mt-3 rounded-xl bg-warn-soft px-3.5 py-2.5 text-warn">Tiene más de tres días: el asistente no usa lo deducido hasta que se vuelva a leer.</p>
                )}
            </div>
        </section>
    );
}

/**
 * How much of the archive, Jira and Notion is indexed for every agent to search.
 */
function Knowledge({ sources }: { sources: KnowledgeSource[] }) {
    return (
        <Section
            id="context-knowledge"
            title="Base de conocimiento"
            description="Índice del archivo de trabajo, de Jira, de Notion, de tus reuniones y de lo que has respondido cuando te pregunté. Guarda título, enlace, palabras y un resumen corto; lo que se necesita se lee en vivo."
        >
            <ul className="divide-y divide-line">
                {sources.map((item) => {
                    const done = item.total > 0 && item.copied >= item.total;
                    const share = item.total === 0 ? 0 : Math.round((item.copied / item.total) * 100);

                    return (
                        <li key={item.source} className="py-3 text-[13px] first:pt-0">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                <span className="font-medium">{item.label}</span>
                                <span className="text-muted">
                                    {item.total === 0
                                        ? 'Nada indexado todavía'
                                        : done
                                          ? `${item.total} ${knowledgeUnits[item.source]}, todo indexado`
                                          : `${item.copied} de ${item.total} ${knowledgeUnits[item.source]} indexados`}
                                    {item.summarized > 0 && item.source !== 'memory' ? ` · ${item.summarized} con resumen` : ''}
                                </span>
                            </div>
                            {item.total > 0 && (
                                <div className="mt-2 h-1 overflow-hidden rounded-full bg-soft" role="progressbar" aria-label={`${item.label} indexado`} aria-valuenow={share} aria-valuemin={0} aria-valuemax={100}>
                                    <div className={`h-full rounded-full ${done ? 'bg-ok' : 'bg-accent'}`} style={{ width: `${share}%` }} />
                                </div>
                            )}
                            <p className="mt-1.5 text-[12.5px] text-faint">{item.last_copied_at ? `Último indexado: ${formatMoment(item.last_copied_at)}` : 'Sin indexar todavía'}</p>
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}

function KnownTab({ known, context, runAt, knowledge }: Pick<ContextProps, 'known' | 'context' | 'knowledge'> & { runAt: string }) {
    const groups = Object.keys(knownLabel)
        .map((attribute) => ({ attribute, facts: known.filter((fact) => fact.attribute === attribute) }))
        .filter((group) => group.facts.length > 0);

    return (
        <div className="flex flex-col gap-4">
            <Nightly context={context} runAt={runAt} />

            <Section id="context-known" title="Lo que sé" description="Resumen, en qué estás y lo que significan las cosas aquí. Borrar algo hace que no vuelva; corregirlo lo deja como tuyo.">
                {groups.length === 0 ? (
                    <p className="text-[13px] text-muted">Todavía no hay nada guardado aquí.</p>
                ) : (
                    groups.map((group) => (
                        <div key={group.attribute} className="mt-4 first:mt-0">
                            <h3 className="mb-1 text-[13px] text-muted">{knownLabel[group.attribute]}</h3>
                            <ul>
                                {group.facts.map((fact) => (
                                    <FactRow key={fact.id} fact={fact} />
                                ))}
                            </ul>
                        </div>
                    ))
                )}
            </Section>

            <Knowledge sources={knowledge} />
        </div>
    );
}

function RulesTab({ rules, inertia }: Pick<ContextProps, 'rules' | 'inertia'>) {
    return (
        <div className="flex flex-col gap-4">
            {inertia.sample.length > 0 && (
                <Section
                    id="context-inertia"
                    title="Revisa estos envíos"
                    description={`Esta semana has enviado tal cual el ${Math.round((inertia.rate ?? 0) * 100)} % de ${inertia.sends} propuestas. Comprueba que eran buenas y no solo cómodas de aceptar.`}
                >
                    <ul className="grid gap-2.5">
                        {inertia.sample.map((sent) => (
                            <li key={sent.id} className="rounded-lg bg-soft px-3 py-2.5">
                                <p className="text-[12px] text-muted">
                                    {sent.actor} · {sent.title}
                                </p>
                                <p className="mt-1 text-[13px] leading-[1.5] whitespace-pre-wrap">{sent.text}</p>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Section
                id="context-rules"
                title="Reglas"
                description="Lo aprendido de tus correcciones: cuando modificas, descartas con motivo, eliges personas o cambias una duración. Solo añaden contexto; nunca cambian el «sí»."
            >
                {rules.length === 0 ? (
                    <p className="text-[13.5px] text-muted">Todavía nada. Cada vez que corrijas una propuesta, lo que enseña aparecerá aquí.</p>
                ) : (
                    <ul>
                        {rules.map((rule) => (
                            <FactRow key={rule.id} fact={rule} labels={rule.audience_labels.length > 0 ? rule.audience_labels : ['General']} />
                        ))}
                    </ul>
                )}
            </Section>
        </div>
    );
}

function PendingTab({ pending }: Pick<ContextProps, 'pending'>) {
    return (
        <Section id="context-pending" title="Pendiente de confirmar" description="Lo que he visto que no cuadra con lo que me dijiste. No lo uso hasta que lo confirmes.">
            {pending.length === 0 ? (
                <p className="text-[13.5px] text-muted">Nada que confirmar.</p>
            ) : (
                <ul>
                    {pending.map((fact) => (
                        <li key={fact.id} className="grid gap-1.5 border-b border-line py-3 last:border-b-0">
                            <p className="text-[13.5px] leading-[1.5]">
                                {fact.subject !== 'user' && <span className="text-muted">{factName(fact)} · </span>}
                                {factText(fact)}
                            </p>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted">
                                {fact.contradicts && <span>Ahora: {fact.contradicts}</span>}
                                <span>{originLabel[fact.origin]}</span>
                                <span>{dayFormat.format(new Date(fact.at))}</span>
                                <span className="ml-auto flex gap-1.5">
                                    <Button variant="primary" onClick={() => post(`/settings/context/facts/${fact.id}/accept`)}>
                                        Aceptar
                                    </Button>
                                    <Button variant="tertiary" destructive onClick={() => post(`/settings/context/facts/${fact.id}/reject`)}>
                                        Rechazar
                                    </Button>
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function TopicRow({ topic, others }: { topic: Topic; others: Topic[] }) {
    const [name, setName] = useState(topic.name);
    const [alias, setAlias] = useState('');
    const [into, setInto] = useState('');

    return (
        <li className="grid gap-3 border-b border-line py-4 last:border-b-0">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-[15px] font-medium tracking-[-0.01em]">{topic.name}</h3>
                {topic.proposed && <span className="rounded-full bg-soft px-2 py-[2px] text-[11px] font-medium text-ink/80">Propuesto</span>}
            </div>
            {topic.summary !== '' && <p className="max-w-[560px] text-[13.5px] leading-[1.5]">{topic.summary}</p>}
            <dl className="grid gap-1 text-[13px] text-muted">
                {topic.state !== '' && (
                    <div>
                        <dt className="inline">Estado: </dt>
                        <dd className="inline text-ink">{topic.state}</dd>
                    </div>
                )}
                {topic.people.length > 0 && (
                    <div>
                        <dt className="inline">Personas: </dt>
                        <dd className="inline text-ink">{topic.people.join(', ')}</dd>
                    </div>
                )}
                {topic.channels.length > 0 && (
                    <div>
                        <dt className="inline">Canales: </dt>
                        <dd className="inline text-ink">{topic.channels.join(', ')}</dd>
                    </div>
                )}
            </dl>
            <div>
                <p className="text-[12px] text-muted">Apodos</p>
                {topic.nicknames.length === 0 ? (
                    <p className="mt-1 text-[13px] text-muted">Ninguno todavía.</p>
                ) : (
                    <ul className="mt-1 flex flex-wrap gap-1.5">
                        {topic.nicknames.map((nickname) => (
                            <li key={nickname.id}>
                                <button
                                    type="button"
                                    className="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-soft px-2.5 text-[12.5px] text-ink"
                                    onClick={() =>
                                        router.delete('/settings/context/topics/nicknames', {
                                            data: { subject: topic.subject, fact: nickname.id },
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {nickname.alias}
                                    <span className="text-muted">Quitar</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    className="mt-2 flex flex-col gap-2 sm:flex-row"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const next = alias.trim();

                        if (next === '') {
                            return;
                        }

                        router.post('/settings/context/topics/nicknames', { subject: topic.subject, alias: next }, { preserveScroll: true, onSuccess: () => setAlias('') });
                    }}
                >
                    <input aria-label={`Apodo de ${topic.name}`} value={alias} maxLength={80} onChange={(event) => setAlias(event.target.value)} placeholder="Otro nombre" className={`${fieldClass} min-h-11 sm:max-w-xs`} />
                    <Button type="submit" variant="secondary" disabled={alias.trim() === ''}>
                        Añadir
                    </Button>
                </form>
            </div>
            <form
                className="flex flex-col gap-2 sm:flex-row"
                onSubmit={(event) => {
                    event.preventDefault();
                    const next = name.trim();

                    if (next === '') {
                        return;
                    }

                    router.put('/settings/context/topics', { subject: topic.subject, name: next }, { preserveScroll: true });
                }}
            >
                <input aria-label={`Nombre de ${topic.name}`} value={name} maxLength={120} onChange={(event) => setName(event.target.value)} className={`${fieldClass} min-h-11 sm:max-w-sm`} />
                <Button type="submit" variant="secondary" disabled={name.trim() === '' || name.trim() === topic.name}>
                    Renombrar
                </Button>
            </form>
            {others.length > 0 && (
                <div className="flex flex-col gap-2 sm:flex-row">
                    <select aria-label={`Unir ${topic.name} con`} value={into} onChange={(event) => setInto(event.target.value)} className={`${fieldClass} min-h-11 sm:max-w-sm`}>
                        <option value="">Unirlo a…</option>
                        {others.map((other) => (
                            <option key={other.subject} value={other.subject}>
                                {other.name}
                            </option>
                        ))}
                    </select>
                    <Button
                        variant="secondary"
                        disabled={into === ''}
                        onClick={() => router.post('/settings/context/topics/merge', { from: topic.subject, into }, { preserveScroll: true })}
                    >
                        Unir
                    </Button>
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                {topic.proposed && (
                    <Button variant="primary" onClick={() => router.post('/settings/context/topics/confirm', { subject: topic.subject }, { preserveScroll: true })}>
                        Confirmar
                    </Button>
                )}
                <Button variant="tertiary" destructive onClick={() => router.delete('/settings/context/topics', { data: { subject: topic.subject }, preserveScroll: true })}>
                    Descartar
                </Button>
            </div>
        </li>
    );
}

function TopicsTab({ topics }: { topics: Topic[] }) {
    return (
        <Section id="context-topics" title="Temas" description="Los asuntos que vuelven. Confirma los que reconozcas, corrige el nombre o descártalos.">
            {topics.length === 0 ? (
                <p className="text-[13.5px] text-muted">Todavía no hay temas. Aparecen al leer el historial.</p>
            ) : (
                <ul>
                    {topics.map((topic) => (
                        <TopicRow key={topic.subject} topic={topic} others={topics.filter((other) => other.subject !== topic.subject)} />
                    ))}
                </ul>
            )}
        </Section>
    );
}

function ChangesTab({ night }: { night: NightChanges }) {
    const [classes, setClasses] = useState<Record<string, boolean>>(() => Object.fromEntries(night.classes.map((item) => [item.key, item.on])));

    return (
        <div className="flex flex-col gap-8">
            <Section
                id="context-night-changes"
                title="Cambios de la noche"
                description="Lo que la noche cambió o dejó como propuesta. Cada línea lleva la cita en la que se apoya."
            >
                {night.lines.length === 0 ? (
                    <p className="text-[13.5px] text-muted">Esta noche no hay cambios.</p>
                ) : (
                    <ul>
                        {night.lines.map((line) => (
                            <li key={line.id} className="border-t border-line py-4">
                                <p className="text-[14.5px] text-ink">{line.quote}</p>
                                {line.why && <p className="mt-1 text-[13px] text-muted">{line.why}</p>}
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {line.answers.map((answer) => (
                                        <Button
                                            key={answer}
                                            variant="secondary"
                                            onClick={() => router.post(`/settings/context/changes/${line.id}/answer`, { answer }, { preserveScroll: true })}
                                        >
                                            {answer}
                                        </Button>
                                    ))}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section id="context-night-classes" title="Aplicar solo" description="Enciende una clase para que la próxima noche la aplique sin preguntar.">
                <ul>
                    {night.classes.map((item) => (
                        <li key={item.key} className="border-t border-line py-3">
                            <Switch checked={classes[item.key] ?? false} onChange={(on) => setClasses((current) => ({ ...current, [item.key]: on }))}>
                                <span className="text-[14px] text-ink">{item.label}</span>
                            </Switch>
                        </li>
                    ))}
                </ul>
                <div className="mt-4">
                    <Button variant="primary" onClick={() => router.put('/settings/context/changes', { classes }, { preserveScroll: true })}>
                        Guardar
                    </Button>
                </div>
            </Section>
        </div>
    );
}

function ChannelsTab({ channels }: { channels: Channel[] }) {
    const [query, setQuery] = useState('');
    const [activeOnly, setActiveOnly] = useState(false);
    const [selected, setSelected] = useState(() => channels.filter((channel) => channel.selected).map((channel) => channel.id));
    const needle = query.trim().toLowerCase();
    const shown = channels.filter((channel) => {
        if (activeOnly && channel.messages === 0) {
            return false;
        }

        return needle === '' || channel.name.toLowerCase().includes(needle);
    });

    const toggle = (id: string) => setSelected((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));

    return (
        <Section
            id="context-channels"
            title="Canales de Slack"
            description="Marca los canales que quieres seguir enteros, aunque nadie te mencione. Los que ya usas se leen igual."
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input
                    aria-label="Buscar canal"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Buscar canal"
                    className={fieldClass}
                />
                <label className="inline-flex min-h-11 shrink-0 items-center gap-2 text-[13px] text-muted">
                    <input type="checkbox" checked={activeOnly} onChange={(event) => setActiveOnly(event.target.checked)} className="size-4 accent-[var(--accent)]" />
                    Solo los que tienen actividad
                </label>
            </div>
            {shown.length === 0 ? (
                <p className="mt-4 text-[13.5px] text-muted">{channels.length === 0 ? 'No veo canales en tu Slack.' : 'Ningún canal coincide.'}</p>
            ) : (
                <ul className="mt-2">
                    {shown.map((channel) => (
                        <li key={channel.id} className="border-t border-line">
                            <label className="flex min-h-11 items-start gap-3 py-3">
                                <input
                                    type="checkbox"
                                    checked={selected.includes(channel.id)}
                                    onChange={() => toggle(channel.id)}
                                    aria-label={channel.name}
                                    className="mt-1 size-4 shrink-0 accent-[var(--accent)]"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[14px]">#{channel.name}</span>
                                    <span className="block text-[12px] text-muted">
                                        {channel.members === 1 ? '1 miembro' : `${channel.members} miembros`}
                                        {' · '}
                                        {channel.messages === 1 ? '1 mensaje en 30 días' : `${channel.messages} mensajes en 30 días`}
                                    </span>
                                </span>
                                {channel.included && <span className="shrink-0 rounded-full bg-soft px-2 py-0.5 text-[11px] font-medium text-ink/80">Ya incluido</span>}
                            </label>
                        </li>
                    ))}
                </ul>
            )}
            <Button variant="primary" className="mt-4" onClick={() => router.put('/settings/context/channels', { slack_channel_ids: selected }, { preserveScroll: true })}>
                Guardar
            </Button>
        </Section>
    );
}

function saveUrgent(rule: { kind: string; ref: string; active: boolean; sources: string[] }) {
    router.put('/settings/context/urgent', rule, { preserveScroll: true });
}

function UrgentRuleRow({ rule }: { rule: UrgentRule }) {
    return (
        <li className="flex min-h-11 flex-col gap-2 border-t border-line py-3 sm:flex-row sm:items-center">
            <span className="min-w-0 flex-1 truncate text-[14px]">{rule.label}</span>
            <span className="flex items-center gap-3">
                <Switch checked={rule.active} onChange={(active) => saveUrgent({ kind: rule.kind, ref: rule.ref, active, sources: rule.sources })}>
                    <span className="text-[13px] text-muted">Activo</span>
                </Switch>
                <Button variant="tertiary" size="small" destructive onClick={() => router.delete('/settings/context/urgent', { data: { kind: rule.kind, ref: rule.ref }, preserveScroll: true })}>
                    Quitar
                </Button>
            </span>
        </li>
    );
}

function UrgentTab({ people, channels, rules }: { people: Person[]; channels: Channel[]; rules: UrgentRule[] }) {
    const [personRef, setPersonRef] = useState('');
    const [channelRef, setChannelRef] = useState('');
    const [keyword, setKeyword] = useState('');
    const [preview, setPreview] = useState<KeywordPreview | null>(null);
    const takenPeople = new Set(rules.filter((rule) => rule.kind === 'person').map((rule) => rule.ref));
    const takenChannels = new Set(rules.filter((rule) => rule.kind === 'channel').map((rule) => rule.ref));
    const availablePeople = people.filter((person) => !takenPeople.has(person.ref));
    const availableChannels = channels.filter((channel) => !takenChannels.has(`slack:${channel.id}`));
    const keywords = rules.filter((rule) => rule.kind === 'keyword');
    const personRules = rules.filter((rule) => rule.kind === 'person');
    const channelRules = rules.filter((rule) => rule.kind === 'channel');
    const weight = rules.find((rule) => rule.kind === 'person_weight');

    useEffect(() => {
        const word = keyword.trim();

        if (word === '') {
            setPreview(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/settings/context/urgent/preview?keyword=${encodeURIComponent(word)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: KeywordPreview | null) => {
                    if (body) {
                        setPreview(body);
                    }
                })
                .catch(() => undefined);
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [keyword]);

    const addKeyword = (event: FormEvent) => {
        event.preventDefault();
        const word = keyword.trim();

        if (word === '') {
            return;
        }

        saveUrgent({ kind: 'keyword', ref: word, active: true, sources: [] });
        setKeyword('');
    };

    return (
        <div className="grid gap-4">
            <Section id="context-urgent" title="Qué es urgente" description="Un mensaje que encaja aquí se trata al momento. El resto espera a que la conversación se calme.">
                {weight ? (
                    <ul>
                        <UrgentRuleRow rule={weight} />
                    </ul>
                ) : (
                    <p className="text-[13.5px] text-muted">La regla por defecto está quitada. Un mensaje directo ya no se adelanta solo por el peso de quien lo envía.</p>
                )}
            </Section>

            <Section id="context-urgent-people" title="Personas" description="Lo que escriba esta persona se trata al momento.">
                <div className="flex flex-col gap-2 sm:flex-row">
                    <select aria-label="Persona" value={personRef} onChange={(event) => setPersonRef(event.target.value)} className={`${fieldClass} min-h-11`}>
                        <option value="">Elige a alguien</option>
                        {availablePeople.map((person) => (
                            <option key={person.ref} value={person.ref}>
                                {person.name}
                            </option>
                        ))}
                    </select>
                    <Button variant="primary" className="min-h-11" disabled={personRef === ''} onClick={() => saveUrgent({ kind: 'person', ref: personRef, active: true, sources: [] })}>
                        Añadir
                    </Button>
                </div>
                {personRules.length === 0 ? (
                    <p className="mt-3 text-[13.5px] text-muted">Nadie está marcado.</p>
                ) : (
                    <ul className="mt-2">
                        {personRules.map((rule) => (
                            <UrgentRuleRow key={rule.ref} rule={rule} />
                        ))}
                    </ul>
                )}
            </Section>

            <Section id="context-urgent-keywords" title="Palabras" description="Una palabra entera, sin distinguir mayúsculas ni acentos.">
                <form onSubmit={addKeyword} className="flex flex-col gap-2 sm:flex-row">
                    <input aria-label="Palabra" value={keyword} onChange={(event) => setKeyword(event.target.value)} placeholder="prepago" maxLength={80} className={`${fieldClass} min-h-11`} />
                    <Button variant="primary" className="min-h-11" type="submit" disabled={keyword.trim() === ''}>
                        Añadir
                    </Button>
                </form>
                {preview && (
                    <div className="mt-3 text-[13px] text-muted">
                        <p>
                            {preview.count === 0
                                ? 'Ningún mensaje de los últimos 30 días contiene esa palabra.'
                                : preview.count === 1
                                  ? 'Encaja en 1 mensaje de los últimos 30 días.'
                                  : `Encaja en ${preview.count} mensajes de los últimos 30 días.`}
                        </p>
                        {preview.examples.length > 0 && (
                            <ul className="mt-2 grid gap-1">
                                {preview.examples.map((example) => (
                                    <li key={example.id} className="truncate text-ink/80">
                                        {example.text}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
                {keywords.length === 0 ? (
                    <p className="mt-3 text-[13.5px] text-muted">No hay palabras.</p>
                ) : (
                    <ul className="mt-2">
                        {keywords.map((rule) => (
                            <UrgentRuleRow key={rule.ref} rule={rule} />
                        ))}
                    </ul>
                )}
            </Section>

            <Section id="context-urgent-channels" title="Canales" description="Todo lo que se diga en el canal, aunque no te mencionen.">
                <div className="flex flex-col gap-2 sm:flex-row">
                    <select aria-label="Canal" value={channelRef} onChange={(event) => setChannelRef(event.target.value)} className={`${fieldClass} min-h-11`}>
                        <option value="">Elige un canal</option>
                        {availableChannels.map((channel) => (
                            <option key={channel.id} value={`slack:${channel.id}`}>
                                #{channel.name}
                            </option>
                        ))}
                    </select>
                    <Button variant="primary" className="min-h-11" disabled={channelRef === ''} onClick={() => saveUrgent({ kind: 'channel', ref: channelRef, active: true, sources: [] })}>
                        Añadir
                    </Button>
                </div>
                {channelRules.length === 0 ? (
                    <p className="mt-3 text-[13.5px] text-muted">No hay canales marcados.</p>
                ) : (
                    <ul className="mt-2">
                        {channelRules.map((rule) => (
                            <UrgentRuleRow key={rule.ref} rule={rule} />
                        ))}
                    </ul>
                )}
            </Section>
        </div>
    );
}

export default function Context(props: ContextProps) {
    const { tab, context, run_at: runAt } = props;
    const building = context.status === 'running';
    const { start, stop } = usePoll(3000, { only: ['context', 'known'] }, { autoStart: false });

    useEffect(() => {
        if (building) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [building, start, stop]);

    return (
        <SettingsLayout title="Contexto" description="Todo lo que sixth-sense sabe de tu trabajo, en un solo sitio: de dónde viene cada cosa y cómo corregirla.">
            <nav aria-label="Partes del contexto" className="mb-5 flex gap-1 overflow-x-auto [scrollbar-width:none]">
                {tabs.map((item) => (
                    <Link
                        key={item.value}
                        href={`/settings/context?tab=${item.value}`}
                        preserveScroll
                        preserveState
                        aria-current={item.value === tab ? 'page' : undefined}
                        className="inline-flex min-h-9 items-center rounded-lg px-3 text-[13px] whitespace-nowrap text-muted hover:bg-soft hover:text-ink aria-[current=page]:bg-soft aria-[current=page]:font-medium aria-[current=page]:text-ink"
                    >
                        {item.label}
                        {item.value === 'pending' && props.pending.length > 0 && <span className="ml-1.5 text-accent">{props.pending.length}</span>}
                    </Link>
                ))}
            </nav>

            {tab === 'people' && <PeopleTab people={props.people} relations={props.relations} merge_proposals={props.merge_proposals ?? []} />}
            {tab === 'topics' && <TopicsTab topics={props.topics ?? []} />}
            {tab === 'areas' && <AreasTab areas={props.areas} preference={props.preference} kinds={props.kinds} timezones={props.timezones} tones={props.tones} />}
            {tab === 'known' && <KnownTab known={props.known} context={context} runAt={runAt} knowledge={props.knowledge} />}
            {tab === 'rules' && <RulesTab rules={props.rules} inertia={props.inertia} />}
            {tab === 'pending' && <PendingTab pending={props.pending} />}
            {tab === 'channels' && <ChannelsTab channels={props.channels} />}
            {tab === 'urgent' && <UrgentTab people={props.people} channels={props.channels} rules={props.urgent_rules} />}
            {tab === 'changes' && <ChangesTab night={props.night_changes ?? { lines: [], classes: [] }} />}
        </SettingsLayout>
    );
}
