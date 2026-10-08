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
    case DocsEdit = 'docs.edit';
    case DocsComment = 'docs.comment';
    case GmailSend = 'gmail.send';
    case CalendarWrite = 'calendar.write';
    case SlackRead = 'slack.read';
    case SlackSearch = 'slack.search';
    case SlackFiles = 'slack.files';
    case SlackSend = 'slack.send';

    public function provider(): Provider
    {
        return match ($this) {
            self::CalendarRead,
            self::GmailRead,
            self::DocsRead,
            self::SheetsRead,
            self::DriveRead,
            self::DocsEdit,
            self::DocsComment,
            self::GmailSend,
            self::CalendarWrite => Provider::Google,
            self::SlackRead,
            self::SlackSearch,
            self::SlackFiles,
            self::SlackSend => Provider::Slack,
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
        };
    }

    /**
     * The scope of a capability that needs only one.
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
            self::DocsEdit => 'Editar documentos',
            self::DocsComment => 'Comentar documentos',
            self::GmailSend => 'Enviar emails',
            self::CalendarWrite => 'Gestionar el calendario',
            self::SlackRead => 'Leer canales y mensajes',
            self::SlackSearch => 'Buscar mensajes',
            self::SlackFiles => 'Abrir archivos',
            self::SlackSend => 'Enviar mensajes',
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
        return in_array($this, [self::DocsEdit, self::DocsComment, self::GmailSend, self::CalendarWrite, self::SlackSend], true);
    }

    /**
     * @return list<self>
     */
    public static function of(Provider $provider): array
    {
        return array_values(array_filter(self::cases(), fn (self $capability): bool => $capability->provider() === $provider));
    }
}
