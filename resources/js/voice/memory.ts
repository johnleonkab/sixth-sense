/**
 * What was said lately, kept only in this page's memory: when a conversation
 * drops (connectivity, the provider's time limit) the next one starts knowing
 * it. Nothing is stored, and reloading the page forgets it.
 */
export type Exchange = { role: 'user' | 'assistant'; text: string; at: number };

/** Exchanges older than this are no longer context. */
const MAX_AGE = 30 * 60_000;

const MAX_EXCHANGES = 12;

const MAX_LENGTH = 400;

export class ConversationMemory {
    private exchanges: Exchange[] = [];

    add(role: Exchange['role'], text: string): void {
        const clean = text.replace(/\s+/g, ' ').trim();

        if (clean !== '') {
            this.exchanges = [...this.exchanges, { role, text: clean, at: Date.now() }].slice(-MAX_EXCHANGES);
        }
    }

    recent(now = Date.now()): Exchange[] {
        return this.exchanges.filter((exchange) => now - exchange.at < MAX_AGE);
    }
}

/**
 * The note a new conversation starts with: what was being talked about and
 * what is on screen. Null when there is nothing to pick up.
 */
export function resumeNote(exchanges: Exchange[], screen: string | null): string | null {
    if (exchanges.length === 0 && !screen) {
        return null;
    }

    const lines = ['[Nota de la app, no la dice el usuario. No la comentes ni saludes: si ya estabais hablando, sigue donde lo dejasteis.]'];

    if (exchanges.length > 0) {
        lines.push(
            'Lo último que habéis hablado (la conversación se cerró o se cortó):',
            ...exchanges.map((exchange) => `${exchange.role === 'user' ? 'Usuario' : 'Tú'}: ${exchange.text.slice(0, MAX_LENGTH)}`),
        );
    }

    if (screen) {
        lines.push('Lo que hay ahora en pantalla (con sus ids, para usarlos sin volver a buscar):', screen);
    }

    return lines.join('\n');
}
