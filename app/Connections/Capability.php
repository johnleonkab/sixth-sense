<?php

namespace App\Connections;

/**
 * Something sixth-sense can do with a connected account. A capability is
 * available on an account when the provider granted its scope and the user
 * has not switched it off.
 */
enum Capability: string
{
    case CalendarRead = 'calendar.read';
    case GmailRead = 'gmail.read';
    case DocsRead = 'docs.read';
    case SheetsRead = 'sheets.read';
    case DriveRead = 'drive.read';
    case AnalyticsRead = 'analytics.read';
    case DocsEdit = 'docs.edit';
    case DocsComment = 'docs.comment';
    case GmailSend = 'gmail.send';
    case CalendarWrite = 'calendar.write';
    case SlackRead = 'slack.read';
    case SlackSearch = 'slack.search';
    case SlackFiles = 'slack.files';
    case SlackSend = 'slack.send';
    case NotionRead = 'notion.read';
    case NotionEdit = 'notion.edit';
    case NotionComment = 'notion.comment';
    case JiraRead = 'jira.read';
    case JiraWrite = 'jira.write';
    case JiraComment = 'jira.comment';
    case GithubRead = 'github.read';
    case GithubWrite = 'github.write';

    public function provider(): Provider
    {
        return match ($this) {
            self::CalendarRead,
            self::GmailRead,
            self::DocsRead,
            self::SheetsRead,
            self::DriveRead,
            self::AnalyticsRead,
            self::DocsEdit,
            self::DocsComment,
            self::GmailSend,
            self::CalendarWrite => Provider::Google,
            self::SlackRead,
            self::SlackSearch,
            self::SlackFiles,
            self::SlackSend => Provider::Slack,
            self::NotionRead,
            self::NotionEdit,
            self::NotionComment => Provider::Notion,
            self::JiraRead,
            self::JiraWrite,
            self::JiraComment => Provider::Atlassian,
            self::GithubRead,
            self::GithubWrite => Provider::GitHub,
        };
    }

    /**
     * The OAuth scopes the provider must grant for this capability.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::CalendarRead => ['https://www.googleapis.com/auth/calendar.readonly'],
            self::GmailRead => ['https://www.googleapis.com/auth/gmail.readonly'],
            self::DocsRead => ['https://www.googleapis.com/auth/documents.readonly'],
            self::SheetsRead => ['https://www.googleapis.com/auth/spreadsheets.readonly'],
            self::DriveRead => ['https://www.googleapis.com/auth/drive.readonly'],
            self::AnalyticsRead => ['https://www.googleapis.com/auth/analytics.readonly'],
            self::DocsEdit => ['https://www.googleapis.com/auth/documents'],
            // Comments live in the Drive API, which has no narrower scope for them.
            self::DocsComment => ['https://www.googleapis.com/auth/drive'],
            self::GmailSend => ['https://www.googleapis.com/auth/gmail.send'],
            self::CalendarWrite => ['https://www.googleapis.com/auth/calendar.events'],
            self::SlackRead => [
                'channels:read', 'groups:read', 'im:read', 'mpim:read',
                'channels:history', 'groups:history', 'im:history', 'mpim:history',
                'users:read', 'users:read.email',
            ],
            self::SlackSearch => ['search:read'],
            self::SlackFiles => ['files:read'],
            self::SlackSend => ['chat:write', 'im:write'],
            // Notion has no OAuth scopes: what a connection may do is set on the connection itself.
            self::NotionRead, self::NotionEdit, self::NotionComment => [],
            self::JiraRead => ['read:jira-work', 'read:jira-user'],
            self::JiraWrite, self::JiraComment => ['write:jira-work'],
            // Classic OAuth has no read-only scope for private repositories: `repo` is both.
            self::GithubRead, self::GithubWrite => ['repo'],
        };
    }

    /**
     * The scope of a capability that needs only one (none for Notion).
     */
    public function scope(): string
    {
        return $this->scopes()[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::CalendarRead => 'Ver el calendario',
            self::GmailRead => 'Leer el correo',
            self::DocsRead => 'Leer documentos',
            self::SheetsRead => 'Leer hojas de cálculo',
            self::DriveRead => 'Abrir archivos de Drive',
            self::AnalyticsRead => 'Ver Google Analytics',
            self::DocsEdit => 'Editar documentos',
            self::DocsComment => 'Comentar documentos',
            self::GmailSend => 'Enviar emails',
            self::CalendarWrite => 'Gestionar el calendario',
            self::SlackRead => 'Leer canales y mensajes',
            self::SlackSearch => 'Buscar mensajes',
            self::SlackFiles => 'Abrir archivos',
            self::SlackSend => 'Enviar mensajes',
            self::NotionRead => 'Leer páginas y comentarios',
            self::NotionEdit => 'Crear y editar páginas',
            self::NotionComment => 'Comentar páginas',
            self::JiraRead => 'Ver issues y comentarios',
            self::JiraWrite => 'Crear y editar issues',
            self::JiraComment => 'Comentar issues',
            self::GithubRead => 'Leer repositorios',
            self::GithubWrite => 'Actualizar código',
        };
    }

    /**
     * @return list<self>
     */
    /**
     * Whether using it changes something in the external service, so it always
     * needs the person's confirmation.
     */
    public function writes(): bool
    {
        return in_array($this, [self::DocsEdit, self::DocsComment, self::GmailSend, self::CalendarWrite, self::SlackSend, self::NotionEdit, self::NotionComment, self::JiraWrite, self::JiraComment, self::GithubWrite], true);
    }

    /**
     * @return list<self>
     */
    public static function of(Provider $provider): array
    {
        return array_values(array_filter(self::cases(), fn (self $capability): bool => $capability->provider() === $provider));
    }
}
