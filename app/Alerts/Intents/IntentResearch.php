<?php

namespace App\Alerts\Intents;

use App\Alerts\Replies\AlertBrief;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\ChatSearch;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearch;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Wiki\Wiki;
use App\Knowledge\KnowledgeBase;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the reply agent reads before it drafts: the work context, what it has
 * learned before, the best excerpts of the archive, Jira and Notion, and a
 * short live look through mail, chat, documents, pages and issues. A source that fails is skipped; the draft still happens.
 */
class IntentResearch
{
    public function __construct(
        private Mailbox $mailbox,
        private Chats $chats,
        private Documents $documents,
        private Wiki $wiki,
        private IssueTracker $issues,
        private KnowledgeBase $knowledge,
    ) {}

    public function gather(User $user, AlertBrief $brief): string
    {
        $query = Str::words(trim($brief->subject ?: $brief->text), 8, '');
        $lines = [];
        $work = $user->workContext;

        if ($work !== null && $work->usable()) {
            $lines[] = "Contexto de trabajo:\n".$work->summary;
        }

        $lessons = $user->intentLessons()->latest('id')->limit(8)->pluck('body');

        if ($lessons->isNotEmpty()) {
            $lines[] = "Ya aprendido:\n".$lessons->implode("\n");
        }

        $lines[] = $this->safely(fn (): string => $this->knowledge->context($user, trim($brief->subject.' '.Str::limit($brief->text, 600, ''))));

        if ($query !== '') {
            array_push($lines, ...$this->look($user, $query));
        }

        return implode("\n\n", array_filter($lines));
    }

    /**
     * @return list<string>
     */
    private function look(User $user, string $query): array
    {
        $found = [];

        $found[] = $this->safely(function () use ($user, $query): ?string {
            $messages = $this->mailbox->search($user, new MailSearch(text: $query, limit: 3))->messages;
            $lines = array_map(fn ($found): string => $found->message->subject.': '.Str::limit($found->message->snippet, 120), array_slice($messages, 0, 3));

            return $lines === [] ? null : "Emails relacionados:\n".implode("\n", $lines);
        });

        $found[] = $this->safely(function () use ($user, $query): ?string {
            $messages = array_slice($this->chats->search($user, new ChatSearch(text: $query, limit: 3)), 0, 3);
            $lines = array_map(fn ($message): string => $message->authorName.': '.Str::limit($message->text, 120), $messages);

            return $lines === [] ? null : "Mensajes relacionados:\n".implode("\n", $lines);
        });

        $found[] = $this->safely(function () use ($user, $query): ?string {
            $documents = array_slice($this->documents->search($user, new DocumentSearch(text: $query, limit: 3))->documents, 0, 3);
            $lines = array_map(fn ($document): string => $document->title, $documents);

            return $lines === [] ? null : "Documentos:\n".implode("\n", $lines);
        });

        $found[] = $this->safely(function () use ($user, $query): ?string {
            $pages = array_slice($this->wiki->search($user, $query, 3), 0, 3);
            $lines = array_map(fn ($page): string => $page->title, $pages);

            return $lines === [] ? null : "Páginas:\n".implode("\n", $lines);
        });

        $found[] = $this->safely(function () use ($user, $query): ?string {
            $issues = array_slice($this->issues->search($user, null, $query, 3), 0, 3);
            $lines = array_map(fn ($issue): string => $issue->key.' '.$issue->summary, $issues);

            return $lines === [] ? null : "Issues:\n".implode("\n", $lines);
        });

        return array_values(array_filter($found));
    }

    private function safely(callable $search): ?string
    {
        try {
            return $search();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
