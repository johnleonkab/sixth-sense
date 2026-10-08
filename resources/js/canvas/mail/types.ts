export type MailPerson = { name: string | null; email: string | null };

export type MailExcerpt = { before: string; match: string; after: string };

export type MailResult = {
    number: number;
    ref: string;
    from: MailPerson | null;
    subject: string;
    /** ISO 8601 in the person's timezone. */
    date: string;
    snippet: string;
    excerpt: MailExcerpt | null;
    unread: boolean;
    account: string;
};

export type MailResultsProps = {
    title: string;
    search: Record<string, string>;
    timezone: string;
    accounts: string[];
    failed_accounts: string[];
    may_have_more: boolean;
    scanned: number;
    results: MailResult[];
};

export type MailMessageProps = {
    ref: string;
    subject: string;
    from: MailPerson | null;
    to: MailPerson[];
    cc: MailPerson[];
    date: string;
    account: string;
    body: { text: string; match: boolean }[];
    attachments: { name: string; mime_type: string | null; size: number }[];
    documents: { title: string | null; url: string }[];
    url: string | null;
};

export const personLabel = (person: MailPerson | null): string => person?.name || person?.email || 'Desconocido';

export function initials(person: MailPerson | null): string {
    return personLabel(person)
        .split(/[\s@.]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

const searchLabels: Record<string, (value: string) => string> = {
    from: (value) => `de ${value}`,
    to: (value) => `para ${value}`,
    subject: (value) => `asunto: ${value}`,
    text: (value) => `«${value}»`,
    pattern: (value) => `/${value}/`,
    after: (value) => `desde ${shortDate(value)}`,
    before: (value) => `hasta ${shortDate(value)}`,
    has_attachment: () => 'con adjuntos',
    unread_only: () => 'sin leer',
    folder: (value) => (value === 'sent' ? 'enviados' : 'recibidos'),
};

/** Chips that describe the search, in the order the person would say it. */
export function searchChips(search: Record<string, string>): { key: string; label: string }[] {
    return Object.entries(search)
        .filter(([key]) => key in searchLabels)
        .map(([key, value]) => ({ key, label: searchLabels[key](value) }));
}

function shortDate(date: string): string {
    return new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', timeZone: 'UTC' })
        .format(new Date(`${date.slice(0, 10)}T12:00:00Z`))
        .replace('.', '');
}

/** "10:12" today, "ayer", "lun" this week, "7 oct" before. */
export function relativeDate(iso: string, timezone: string): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
    const day = iso.slice(0, 10);
    const daysAgo = Math.round((Date.parse(`${today}T12:00:00Z`) - Date.parse(`${day}T12:00:00Z`)) / 86_400_000);

    if (daysAgo <= 0) {
        return iso.slice(11, 16);
    }

    if (daysAgo === 1) {
        return 'ayer';
    }

    if (daysAgo < 7) {
        return new Intl.DateTimeFormat('es-ES', { weekday: 'short', timeZone: 'UTC' }).format(new Date(`${day}T12:00:00Z`)).replace('.', '');
    }

    return shortDate(day);
}

export function fileSize(bytes: number): string {
    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1).replace('.', ',')} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}
