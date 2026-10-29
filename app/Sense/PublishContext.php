<?php

namespace App\Sense;

use App\Connectors\GitHub\GithubContents;
use App\Knowledge\Gaps\GapKind;
use App\Knowledge\Gaps\GapStatus;
use App\Models\KnowledgeGap;
use App\Models\User;
use App\WorkContext\WorkDigest;
use Throwable;

/**
 * Writes the nightly context into the work archive: how the person writes,
 * who they talk to, what is hot, and a review of the work. The services
 * themselves stay read-only; only this repository is updated, and only when
 * they allowed code updates on it. What the person answered when asked goes
 * to memory/.
 */
class PublishContext
{
    public function __construct(
        private GithubContents $contents,
        private SyncAgents $agents,
    ) {}

    public function publish(User $user, WorkDigest $digest): void
    {
        $repository = $user->workRepository();

        if ($repository === null || ! $repository->mayUpdateCode()) {
            return;
        }

        $files = [
            'now.md' => $this->now($digest),
            ClaudeLayout::agentPath('gmail-writer') => $this->writer(
                'gmail-writer',
                'Escribe correos con la voz de la persona. Úsalo cuando haya que redactar, contestar o reenviar un email.',
                $digest->writingGmail,
                $digest->contacts,
                'Gmail',
            ),
            ClaudeLayout::agentPath('slack-writer') => $this->writer(
                'slack-writer',
                'Escribe mensajes de Slack con la voz de la persona. Úsalo cuando haya que escribir en un canal, un hilo o un mensaje directo.',
                $digest->writingSlack,
                $digest->contacts,
                'Slack',
            ),
            ClaudeLayout::agentPath('review') => $this->review($digest),
            ...$this->memory($user),
        ];

        foreach ($files as $path => $contents) {
            $this->contents->update($repository, $path, $contents, 'Actualiza el contexto de trabajo');
        }

        try {
            $this->agents->sync($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What the person answered when the system asked, one file per kind, so
     * the archive (and Claude Code reading it) knows it too.
     *
     * @return array<string, string>
     */
    private function memory(User $user): array
    {
        $files = [];
        $answers = $user->knowledgeGaps()->where('status', GapStatus::Answered)->orderBy('subject')->get()->groupBy(fn (KnowledgeGap $gap): string => $gap->kind->value);

        foreach ([GapKind::Meeting->value => ['memory/meetings.md', 'Reuniones'], GapKind::Term->value => ['memory/terms.md', 'Siglas y proyectos']] as $kind => [$path, $title]) {
            $gaps = $answers->get($kind);

            if ($gaps === null || $gaps->isEmpty()) {
                continue;
            }

            $lines = ["# {$title}\n", 'Lo que se ha explicado cuando sixth-sense lo preguntó. Se reescribe cada noche desde sixth-sense.', ''];

            foreach ($gaps as $gap) {
                $lines[] = "## {$gap->subject}\n\n".trim((string) $gap->answer)."\n";
            }

            $files[$path] = implode("\n", $lines);
        }

        return $files;
    }

    private function now(WorkDigest $digest): string
    {
        $lines = ["# Ahora\n", $digest->summary, "\n## Frentes\n"];

        foreach ($digest->workstreams as $stream) {
            $lines[] = '- **'.$stream['name'].'.** '.$stream['what'].($stream['next'] ? ' Siguiente: '.$stream['next'].'.' : '');
        }

        $lines[] = "\n## Cómo escribo\n";
        $lines[] = "### Slack\n\n".($digest->writingSlack !== '' ? $digest->writingSlack : 'Aún no hay bastante.');
        $lines[] = "\n### Gmail\n\n".($digest->writingGmail !== '' ? $digest->writingGmail : 'Aún no hay bastante.');
        $lines[] = "\n## Contactos frecuentes\n";

        foreach ($digest->contacts as $contact) {
            $lines[] = '- **'.$contact['name'].'**'
                .($contact['language'] !== '' ? ' · '.$contact['language'] : '')
                .($contact['where'] !== '' ? ' · '.$contact['where'] : '')
                .($contact['note'] !== '' ? '. '.$contact['note'] : '');
        }

        $lines[] = "\n## Archivos calientes\n";

        foreach ($digest->hotFiles as $file) {
            $lines[] = '- '.$file['title'].($file['why'] !== '' ? ': '.$file['why'] : '');
        }

        $lines[] = "\n## Páginas calientes\n";

        foreach ($digest->hotPages as $page) {
            $lines[] = '- '.$page['title'].($page['why'] !== '' ? ': '.$page['why'] : '');
        }

        $lines[] = "\n## Jira\n";

        foreach ($digest->jira as $update) {
            $lines[] = '- '.$update;
        }

        $lines[] = "\n## Cabos sueltos\n";

        foreach ($digest->openLoops as $loop) {
            $lines[] = '- '.$loop;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array{name: string, language: string, where: string, note: string}>  $contacts
     */
    private function writer(string $name, string $description, string $writing, array $contacts, string $where): string
    {
        $theirs = array_values(array_filter($contacts, fn (array $contact): bool => $contact['where'] === '' || str_contains(mb_strtolower($contact['where']), mb_strtolower($where))));
        $list = $theirs === [] ? 'No hay contactos frecuentes todavía.' : implode("\n", array_map(
            fn (array $contact): string => '- '.$contact['name'].($contact['language'] !== '' ? ' ('.$contact['language'].')' : '').($contact['note'] !== '' ? ': '.$contact['note'] : ''),
            $theirs,
        ));

        return <<<MD
            ---
            name: {$name}
            description: {$description}
            ---

            Escribe como la persona, no como un asistente. No inventes datos que no estén en el hilo.

            Cómo escribe:
            {$writing}

            Con quién, y en qué idioma:
            {$list}
            MD;
    }

    private function review(WorkDigest $digest): string
    {
        $fronts = implode("\n", array_map(
            fn (array $stream): string => '- '.$stream['name'].': '.$stream['what'],
            $digest->workstreams,
        ));
        $jira = implode("\n", array_map(fn (string $update): string => '- '.$update, $digest->jira));
        $loops = implode("\n", array_map(fn (string $loop): string => '- '.$loop, $digest->openLoops));

        return <<<MD
            ---
            name: review
            description: Repasa en qué está la persona y qué conviene mirar ahora. Úsalo cuando pida una revisión del trabajo, un resumen del día o qué tiene abierto.
            ---

            Parte de esto, y dilo corto. El detalle ya está en now.md.

            {$digest->summary}

            Frentes:
            {$fronts}

            Jira:
            {$jira}

            Sigue abierto:
            {$loops}
            MD;
    }
}
