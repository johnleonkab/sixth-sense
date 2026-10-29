import type { ReactNode } from 'react';

export type Service = 'slack' | 'gmail' | 'calendar' | 'meet' | 'docs' | 'sheets' | 'drive' | 'google' | 'jira' | 'notion' | 'github' | 'agenda' | 'wingman';

const labels: Record<Service, string> = {
    slack: 'Slack',
    gmail: 'Gmail',
    calendar: 'Google Calendar',
    meet: 'Google Meet',
    docs: 'Google Docs',
    sheets: 'Google Sheets',
    drive: 'Google Drive',
    google: 'Google',
    jira: 'Jira',
    notion: 'Notion',
    github: 'GitHub',
    agenda: 'Agendador',
    wingman: 'Wingman',
};

/** Each mark drawn on a 24 × 24 grid, in its own colours (ours in the accent). */
const marks: Record<Service, ReactNode> = {
    slack: (
        <>
            <rect x="8.4" y="2" width="3.2" height="9.2" rx="1.6" fill="#36C5F0" />
            <rect x="12.8" y="8.4" width="9.2" height="3.2" rx="1.6" fill="#2EB67D" />
            <rect x="12.4" y="12.8" width="3.2" height="9.2" rx="1.6" fill="#ECB22E" />
            <rect x="2" y="12.4" width="9.2" height="3.2" rx="1.6" fill="#E01E5A" />
            <rect x="2" y="8.4" width="5" height="3.2" rx="1.6" fill="#36C5F0" />
            <rect x="12.8" y="2" width="3.2" height="5" rx="1.6" fill="#2EB67D" />
            <rect x="17" y="12.4" width="5" height="3.2" rx="1.6" fill="#ECB22E" />
            <rect x="8" y="17" width="3.2" height="5" rx="1.6" fill="#E01E5A" />
        </>
    ),
    gmail: (
        <>
            <path d="M3.5 19H6v-8.6L2 7.4v10.1A1.5 1.5 0 0 0 3.5 19Z" fill="#4285F4" />
            <path d="M18 19h2.5a1.5 1.5 0 0 0 1.5-1.5V7.4l-4 3Z" fill="#34A853" />
            <path d="M18 5.6v4.8l4-3V6.2c0-1.7-1.9-2.6-3.2-1.6Z" fill="#FBBC04" />
            <path d="M6 10.4V5.6l6 4.5 6-4.5v4.8l-6 4.5Z" fill="#EA4335" />
            <path d="M2 6.2v1.2l4 3V5.6l-.8-.6C3.9 4 2 4.6 2 6.2Z" fill="#C5221F" />
        </>
    ),
    calendar: (
        <>
            <rect x="3" y="3" width="18" height="18" rx="3" fill="#fff" stroke="#DADCE0" />
            <path d="M3 6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v2.2H3Z" fill="#4285F4" />
            <text x="12" y="17.6" textAnchor="middle" fontSize="8.2" fontWeight="700" fill="#1A73E8" fontFamily="system-ui, sans-serif">
                31
            </text>
        </>
    ),
    meet: (
        <>
            <rect x="2" y="6" width="13.5" height="12" rx="2.6" fill="#00AC47" />
            <path d="M15.5 10.6 21 6.8a.6.6 0 0 1 1 .5v9.4a.6.6 0 0 1-1 .5l-5.5-3.8Z" fill="#00832D" />
            <path d="M2 8.6a2.6 2.6 0 0 1 2.6-2.6H8v4.2H2Z" fill="#FFBA00" />
        </>
    ),
    docs: (
        <>
            <path d="M6.5 2H14l5 5v13.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 20.5v-17A1.5 1.5 0 0 1 6.5 2Z" fill="#4285F4" />
            <path d="M14 2v5h5Z" fill="#A1C2FA" />
            <path d="M8 11h8M8 14h8M8 17h5" stroke="#fff" strokeWidth="1.4" strokeLinecap="round" />
        </>
    ),
    sheets: (
        <>
            <path d="M6.5 2H14l5 5v13.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 20.5v-17A1.5 1.5 0 0 1 6.5 2Z" fill="#0F9D58" />
            <path d="M14 2v5h5Z" fill="#87CEAC" />
            <path d="M8 11h8v7H8ZM8 14.5h8M12 11v7" fill="none" stroke="#fff" strokeWidth="1.3" />
        </>
    ),
    drive: (
        <>
            <path d="M8.3 3.2h7.4l6.8 11.7h-7.4Z" fill="#FFBA00" />
            <path d="M1.5 14.9 8.3 3.2l3.7 6.4-6.8 11.7Z" fill="#00AC47" />
            <path d="M5.2 21.3 8.9 14.9h13.6l-3.7 6.4Z" fill="#2684FC" />
        </>
    ),
    google: (
        <>
            <path d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.7 3-4.3 3-7.3Z" fill="#4285F4" />
            <path d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3.1v2.6A10 10 0 0 0 12 22Z" fill="#34A853" />
            <path d="M6.4 14c-.2-.6-.3-1.3-.3-2s.1-1.4.3-2V7.4H3.1a10 10 0 0 0 0 9.2Z" fill="#FBBC05" />
            <path d="M12 6c1.5 0 2.8.5 3.8 1.5l2.8-2.8A10 10 0 0 0 3.1 7.4L6.4 10c.8-2.3 3-4 5.6-4Z" fill="#EA4335" />
        </>
    ),
    jira: (
        <path
            d="M21.6 11.4 12.6 2.4 12 1.8 4.8 9 2.4 11.4a.85.85 0 0 0 0 1.2l6.2 6.2L12 22.2l7.2-7.2.1-.1 2.3-2.3a.85.85 0 0 0 0-1.2ZM12 15.4 8.6 12 12 8.6l3.4 3.4Z"
            fill="#2684FF"
        />
    ),
    notion: (
        <>
            <rect x="3" y="3" width="18" height="18" rx="3.5" fill="#fff" stroke="#191919" strokeWidth="1.6" />
            <path d="M8.6 16.6V7.6l6.8 9V7.4" fill="none" stroke="#191919" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
        </>
    ),
    github: (
        <path
            fill="currentColor"
            d="M12 2C6.48 2 2 6.58 2 12.26c0 4.52 2.87 8.35 6.84 9.7.5.1.68-.22.68-.48 0-.24-.01-.87-.01-1.7-2.78.62-3.37-1.37-3.37-1.37-.45-1.18-1.11-1.5-1.11-1.5-.91-.64.07-.63.07-.63 1 .07 1.53 1.06 1.53 1.06.9 1.57 2.36 1.12 2.94.86.09-.67.35-1.12.63-1.38-2.22-.26-4.55-1.14-4.55-5.06 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.71 0 0 .84-.27 2.75 1.05a9.3 9.3 0 0 1 2.5.67 9.3 9.3 0 0 1 2.5-.67c1.91-1.32 2.75-1.05 2.75-1.05.55 1.41.2 2.45.1 2.71.64.72 1.03 1.63 1.03 2.75 0 3.93-2.34 4.8-4.57 5.05.36.32.68.94.68 1.9 0 1.38-.01 2.49-.01 2.83 0 .26.18.59.69.48A10.04 10.04 0 0 0 22 12.26C22 6.58 17.52 2 12 2Z"
        />
    ),
    agenda: (
        <g fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
            <path d="M3.5 9.5h17M8 3v3.5M16 3v3.5M12 12.5v3.2l2.2 1.3" />
        </g>
    ),
    wingman: <path d="M2.5 7.5 6.6 17 12 10l5.4 7 4.1-9.5" fill="none" stroke="currentColor" strokeWidth="2.1" strokeLinecap="round" strokeLinejoin="round" />,
};

/** Integrations a reference may name, first by what precedes the dot ("chat.send"), then by its start ("Jira · borrador"). */
const prefixes: [string, Service][] = [
    ['chat', 'slack'],
    ['slack', 'slack'],
    ['mail', 'gmail'],
    ['gmail', 'gmail'],
    ['email', 'gmail'],
    ['calendar', 'calendar'],
    ['calendario', 'calendar'],
    ['meet', 'meet'],
    ['docs', 'docs'],
    ['google docs', 'docs'],
    ['sheets', 'sheets'],
    ['drive', 'drive'],
    ['google', 'google'],
    ['jira', 'jira'],
    ['atlassian', 'jira'],
    ['notion', 'notion'],
    ['github', 'github'],
    ['agenda', 'agenda'],
    ['agendador', 'agenda'],
    ['questions', 'agenda'],
    ['preguntas', 'agenda'],
    ['wingman', 'wingman'],
];

/**
 * The integration something belongs to, from any of the ways the app names
 * it: an alert source ("slack"), a change type ("chat.send"), a provider
 * ("atlassian") or what a canvas says it shows ("Jira · borrador").
 */
export function serviceOf(reference: string | null | undefined): Service | null {
    if (!reference) {
        return null;
    }

    const value = reference.trim().toLowerCase();
    const head = value.split(/[.·:]/)[0].trim();

    return (prefixes.find(([prefix]) => head === prefix) ?? prefixes.find(([prefix]) => value.startsWith(prefix)))?.[1] ?? null;
}

/**
 * The mark of an integration, at the size of the text it goes with.
 */
export default function ServiceIcon({ service, size = 16, className = '', label }: { service: Service; size?: number; className?: string; label?: string | false }) {
    const name = label === false ? undefined : (label ?? labels[service]);

    return (
        <svg
            viewBox="0 0 24 24"
            width={size}
            height={size}
            role={name ? 'img' : undefined}
            aria-label={name}
            aria-hidden={name ? undefined : true}
            className={`inline-block flex-none ${service === 'agenda' || service === 'wingman' ? 'text-accent' : ''} ${service === 'github' ? 'text-ink' : ''} ${className}`}
        >
            {name && <title>{name}</title>}
            {marks[service]}
        </svg>
    );
}

/** The mark of whatever a reference names, or nothing if it names no integration. */
export function ServiceIconFor({ reference, size, className, label }: { reference: string | null | undefined; size?: number; className?: string; label?: string | false }) {
    const service = serviceOf(reference);

    return service ? <ServiceIcon service={service} size={size} className={className} label={label} /> : null;
}
