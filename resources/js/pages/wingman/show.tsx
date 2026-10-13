import { router, usePoll } from '@inertiajs/react';
import { type ReactNode, useEffect, useState } from 'react';

import { IssueKey, StatusChip } from '@/canvas/jira/jira-bits';
import { dayLabel, timeRange } from '@/wingman/format';
import type { WingmanContext, WingmanMeeting } from '@/wingman/types';
import WingmanLayout from '@/wingman/wingman-layout';

const emailDate = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });

const serviceNames: Record<string, string> = { jira: 'Jira', notion: 'Notion', gmail: 'Gmail', calendar: 'Calendar' };

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="mt-8">
            <h2 className="mb-2 text-[13px] text-muted">{title}</h2>
            {children}
        </section>
    );
}

function Context({ context }: { context: WingmanContext }) {
    const empty = !context.issues.length && !context.pages.length && !context.documents.length && !context.emails.length;

    return (
        <>
            {context.failed.length > 0 && (
                <p className="mt-6 rounded-xl bg-warn-soft px-3.5 py-2.5 text-[13px] text-warn">
                    No pude consultar {context.failed.map((service) => serviceNames[service] ?? service).join(', ')}. Lo demás está completo.
                </p>
            )}

            {empty && context.failed.length === 0 && <p className="mt-8 text-[14px] text-faint">No encontré tickets, páginas, documentos ni emails relacionados.</p>}

            {context.issues.length > 0 && (
                <Section title="Tickets de Jira">
                    <ul className="space-y-1.5">
                        {context.issues.map((issue) => (
                            <li key={issue.ref} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-[14px]">
                                <IssueKey issue={issue} />
                                <span className="min-w-0">{issue.summary}</span>
                                <StatusChip issue={issue} />
                                {issue.blocked_by.length > 0 && <span className="text-[12.5px] text-muted">bloqueado por {issue.blocked_by.join(', ')}</span>}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {context.pages.length > 0 && (
                <Section title="Páginas de Notion">
                    <ul className="space-y-1">
                        {context.pages.map((page) => (
                            <li key={page.ref} className="text-[14px]">
                                {page.url ? (
                                    <a href={page.url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                                        {page.title}
                                    </a>
                                ) : (
                                    page.title
                                )}
                                <span className="ml-2 text-[12px] text-faint">{page.linked ? 'enlazada en el evento' : 'relacionada'}</span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {context.documents.length > 0 && (
                <Section title="Documentos del evento">
                    <ul className="space-y-1">
                        {context.documents.map((document) => (
                            <li key={document.url} className="text-[14px]">
                                <a href={document.url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                                    {document.title ?? document.url}
                                </a>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {context.emails.length > 0 && (
                <Section title="Últimos emails con los asistentes">
                    <ul className="divide-y divide-line rounded-xl border border-line">
                        {context.emails.map((email) => (
                            <li key={email.ref} className="px-3.5 py-2.5">
                                <div className="flex items-baseline justify-between gap-3 text-[12.5px] text-muted">
                                    <span className="truncate">{email.from}</span>
                                    <time className="flex-none font-mono">{emailDate.format(new Date(email.date))}</time>
                                </div>
                                <p className="mt-0.5 truncate text-[14px] font-medium">{email.subject}</p>
                                <p className="line-clamp-2 text-[13px] text-muted">{email.snippet}</p>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </>
    );
}

function Preparing() {
    return (
        <div aria-label="Preparando el contexto" className="mt-8 space-y-3">
            <p className="flex items-center gap-2 text-[13px] text-muted">
                <i aria-hidden className="size-[7px] animate-pulse rounded-full bg-accent" />
                Reuniendo tickets, páginas, documentos y emails…
            </p>
            {[0, 1, 2].map((row) => (
                <div key={row} className="h-[44px] animate-pulse rounded-xl bg-soft" />
            ))}
        </div>
    );
}

export default function WingmanShow({ meeting }: { meeting: WingmanMeeting }) {
    const preparing = meeting.context_prepared_at === null;
    const [refreshing, setRefreshing] = useState(false);

    // While it is being gathered, ask for it every little while.
    const { start, stop } = usePoll(2500, { only: ['meeting'] }, { autoStart: false, mode: 'rest' });

    useEffect(() => {
        if (preparing) {
            start();
        } else {
            stop();
            setRefreshing(false);
        }
    }, [preparing, start, stop]);

    const prepareAgain = () => {
        setRefreshing(true);
        router.post(`/wingman/${meeting.id}/prepare`, {}, { preserveScroll: true });
    };

    return (
        <WingmanLayout title={meeting.title} back={{ href: '/wingman', label: 'Reuniones' }}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-[13px] text-muted">
                        {dayLabel(meeting.starts_at)} · <span className="font-mono tabular-nums">{timeRange(meeting.starts_at, meeting.ends_at)}</span> · {meeting.status_label}
                    </p>
                    <h1 className="mt-1 text-[22px] font-medium tracking-[-0.02em]">{meeting.title}</h1>
                    {meeting.organizer && <p className="mt-0.5 text-[13px] text-muted">Organiza {meeting.organizer}</p>}
                </div>
                <div className="flex flex-none items-center gap-2">
                    <button
                        type="button"
                        onClick={prepareAgain}
                        disabled={preparing || refreshing}
                        className="rounded-full border border-line px-3.5 py-1.5 text-[13px] text-muted hover:border-accent hover:text-accent disabled:opacity-50"
                    >
                        Actualizar contexto
                    </button>
                    <a href={meeting.meeting_url} target="_blank" rel="noreferrer noopener" className="rounded-full bg-accent px-3.5 py-1.5 text-[13px] font-medium text-accent-ink">
                        Abrir Meet
                    </a>
                </div>
            </div>

            {meeting.description && <p className="mt-5 max-w-[640px] text-[14px] whitespace-pre-line text-muted">{meeting.description}</p>}

            <Section title={`Asistentes · ${meeting.attendees.length}`}>
                <ul className="flex flex-wrap gap-1.5">
                    {meeting.attendees.map((attendee) => (
                        <li key={attendee.email ?? attendee.name} title={attendee.email ?? undefined} className="rounded-full bg-soft px-2.5 py-1 text-[13px]">
                            {attendee.name || attendee.email}
                            {attendee.response === 'declined' && <span className="ml-1 text-faint">· no viene</span>}
                        </li>
                    ))}
                </ul>
            </Section>

            {preparing || !meeting.context ? <Preparing /> : <Context context={meeting.context} />}
        </WingmanLayout>
    );
}
