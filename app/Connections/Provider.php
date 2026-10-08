<?php

namespace App\Connections;

/**
 * An external account provider. A user may connect any number of accounts of
 * each provider (for example, a work and a personal Google account).
 */
enum Provider: string
{
    case Google = 'google';
    case Slack = 'slack';
    case Atlassian = 'atlassian';
    case Notion = 'notion';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Slack => 'Slack',
            self::Atlassian => 'Atlassian',
            self::Notion => 'Notion',
        };
    }

    /**
     * The services sixth-sense uses through this provider.
     *
     * @return list<string>
     */
    public function services(): array
    {
        return match ($this) {
            self::Google => ['Calendar', 'Gmail', 'Docs', 'Sheets'],
            self::Slack => ['Canales', 'Mensajes directos', 'Archivos'],
            self::Atlassian => ['Jira'],
            self::Notion => ['Páginas', 'Comentarios'],
        };
    }

    /**
     * Whether users can already connect accounts of this provider.
     */
    public function isConnectable(): bool
    {
        return in_array($this, [self::Google, self::Slack], true);
    }

    /**
     * What sixth-sense can do with accounts of this provider.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return Capability::of($this);
    }

    /**
     * Permissions sixth-sense asks for when connecting, on top of the user's
     * basic identity: everything its capabilities need, all at once.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (Capability $capability): array => $capability->scopes(), $this->capabilities()))));
    }
}
