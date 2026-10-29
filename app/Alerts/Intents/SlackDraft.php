<?php

namespace App\Alerts\Intents;

use App\Alerts\Replies\AlertBrief;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\ChatSearch;
use App\Connectors\Docs\DocumentLinks;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearch;
use App\Knowledge\KnowledgeBase;
use App\Models\SenseAgent;
use App\Models\SlackTriageDecision;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Before a Slack reply or redirect is drafted, writes what it would need and
 * looks only until that is answered: the thread, links, the person and the
 * project, recent documents, the work archive, then her own past replies.
 * One agent is asked when its description matches. A more private source is not
 * copied into a more open channel.
 */
class SlackDraft
{
    public function __construct(private Chats $chats, private Documents $documents, private KnowledgeBase $knowledge) {}

    public function prepare(User $user, AlertBrief $brief, SlackTriageDecision $decision): SlackDraftBrief
    {
        $ask = trim($brief->subject.' '.$brief->text);
        $words = $this->words($ask);
        $open = in_array($decision->place, ['channel', 'mention', 'thread'], true);
        $threadFact = $this->fact(implode("\n", array_column($brief->conversation, 'text')));
        $conflict = null;

        $citation = $this->fromThread($brief, $words);

        if ($citation === null || $threadFact !== null) {
            if ($citation === null) {
                $citation = $this->fromLinks($user, $brief, $words) ?? $this->fromArchive($user, $words);
            }

            if ($citation === null || $threadFact !== null) {
                $document = $this->fromDocuments($user, $words, $threadFact, $conflict);

                if ($citation === null) {
                    $citation = $document;
                }
            }

            if ($citation === null) {
                $citation = $this->fromPrecedents($user, $words, $open);
            }
        }

        $agent = $this->agent($user, $ask);
        $note = $this->note($ask, $citation, $conflict, $agent);

        return new SlackDraftBrief(
            findings: $this->findings($brief, $note),
            note: $note,
            sufficient: $citation !== null || $agent !== null,
            agentAsked: $agent !== null,
            conflict: $conflict !== null,
        );
    }

    /**
     * @param  list<string>  $words
     * @return ?array{kind: string, who: string, text: string}
     */
    private function fromThread(AlertBrief $brief, array $words): ?array
    {
        $hit = null;

        foreach ($brief->conversation as $message) {
            $text = trim($message['text']);

            if (mb_strlen($text) < 12 || ! $this->overlaps($text, $words)) {
                continue;
            }

            $hit = $message;
        }

        if ($hit === null) {
            return null;
        }

        return ['kind' => 'thread', 'who' => $hit['from'], 'text' => Str::limit($hit['text'], 160)];
    }

    /**
     * @param  list<string>  $words
     * @return ?array{kind: string, who: string, text: string}
     */
    private function fromLinks(User $user, AlertBrief $brief, array $words): ?array
    {
        foreach (DocumentLinks::in($brief->text) as $link) {
            $text = $this->safely(fn (): ?string => $this->documents->find($user, $link['id'])?->plainText());

            if ($text !== null && $this->overlaps($text, $words)) {
                return ['kind' => 'document', 'who' => 'el enlace', 'text' => Str::limit($text, 160)];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $words
     * @return ?array{kind: string, who: string, text: string}
     */
    private function fromArchive(User $user, array $words): ?array
    {
        $hit = $this->knowledge->search($user, implode(' ', $words), [], 1)[0] ?? null;

        if ($hit !== null) {
            return ['kind' => 'archive', 'who' => "{$hit['source']}, {$hit['title']}", 'text' => Str::limit($hit['summary'] ?? $hit['title'], 160)];
        }

        $work = $user->workContext;

        if ($work === null || ! $work->usable() || ! $this->overlaps((string) $work->summary, $words)) {
            return null;
        }

        return ['kind' => 'archive', 'who' => '', 'text' => Str::limit((string) $work->summary, 160)];
    }

    /**
     * @param  list<string>  $words
     * @param  ?array{thread: string, document: string, title: string}  $conflict
     * @return ?array{kind: string, who: string, text: string}
     */
    private function fromDocuments(User $user, array $words, ?string $threadFact, ?array &$conflict): ?array
    {
        $query = implode(' ', array_slice($words, 0, 3));

        if ($query === '') {
            return null;
        }

        $found = $this->safely(function () use ($user, $query): ?array {
            $summary = $this->documents->search($user, new DocumentSearch(text: $query, limit: 1))->documents[0] ?? null;

            if ($summary === null) {
                return null;
            }

            $text = $this->documents->find($user, $summary->ref)?->plainText() ?? '';

            return ['title' => $summary->title, 'text' => trim($text.' '.$summary->title)];
        });

        if ($found === null) {
            return null;
        }

        $documentFact = $this->fact($found['text']);

        if ($threadFact !== null && $documentFact !== null && $documentFact !== $threadFact) {
            $conflict = ['thread' => $threadFact, 'document' => $documentFact, 'title' => $found['title']];
        }

        if (! $this->overlaps($found['text'], $words)) {
            return null;
        }

        return ['kind' => 'document', 'who' => $found['title'], 'text' => Str::limit($found['text'], 160)];
    }

    /**
     * @param  list<string>  $words
     * @return ?array{kind: string, who: string, text: string}
     */
    private function fromPrecedents(User $user, array $words, bool $open): ?array
    {
        $query = implode(' ', array_slice($words, 0, 3));

        if ($query === '') {
            return null;
        }

        $messages = $this->safely(fn (): array => $this->chats->search($user, new ChatSearch(text: $query, from: $user->name, limit: 3))) ?? [];

        foreach ($messages as $message) {
            if (! $message->isMine || ($open && $this->closed($message)) || ! $this->overlaps($message->text, $words)) {
                continue;
            }

            return ['kind' => 'precedent', 'who' => $user->name, 'text' => Str::limit($message->text, 160)];
        }

        return null;
    }

    private function agent(User $user, string $ask): ?SenseAgent
    {
        $words = $this->words($ask);
        $best = null;
        $score = 0;

        foreach ($user->senseAgents()->orderBy('id')->get() as $agent) {
            $hits = count(array_intersect($this->words($agent->description), $words));

            if ($hits > $score) {
                $best = $agent;
                $score = $hits;
            }
        }

        return $best;
    }

    /**
     * @param  ?array{kind: string, who: string, text: string}  $citation
     * @param  ?array{thread: string, document: string, title: string}  $conflict
     */
    private function note(string $ask, ?array $citation, ?array $conflict, ?SenseAgent $agent): string
    {
        $parts = [];

        if ($conflict !== null) {
            $parts[] = "El hilo dice «{$conflict['thread']}» y el documento «{$conflict['title']}» dice «{$conflict['document']}»; no coinciden.";
        }

        if ($citation !== null) {
            $parts[] = match ($citation['kind']) {
                'thread' => "Según el hilo, {$citation['who']}: «{$citation['text']}».",
                'archive' => $citation['who'] !== '' ? "Según {$citation['who']}: «{$citation['text']}»." : "Según el archivo: «{$citation['text']}».",
                'precedent' => "La última vez respondió: «{$citation['text']}».",
                default => "Según «{$citation['who']}»: «{$citation['text']}».",
            };
        }

        if ($agent !== null) {
            $line = trim(Str::before($agent->briefing(), "\n"));
            $parts[] = 'El agente '.$agent->name.': '.Str::limit($line, 180);
        }

        if ($parts === []) {
            $parts[] = 'Para responder hay que confirmar: '.Str::limit(trim($ask), 120).'.';
        }

        return implode(' ', $parts);
    }

    private function findings(AlertBrief $brief, string $note): string
    {
        $needs = ['Qué se ha dicho en el hilo.'];

        if (DocumentLinks::in($brief->text) !== []) {
            $needs[] = 'Qué hay en los enlaces.';
        }

        array_push($needs, 'Quién habla y de qué proyecto.', 'Documentos recientes sobre eso.', 'El archivo de trabajo.', 'Cómo ha respondido antes.');

        return "Para responder haría falta:\n- ".implode("\n- ", $needs)."\n\n".$note;
    }

    private function closed(ChatMessage $message): bool
    {
        return ! str_starts_with($message->conversationLabel, '#');
    }

    /**
     * @param  list<string>  $words
     */
    private function overlaps(string $text, array $words): bool
    {
        $haystack = mb_strtolower($text);

        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $matches);

        return array_values(array_unique(array_diff($matches[0] ?? [], ['cómo', 'como', 'para', 'esto', 'esta', 'este', 'desde', 'tiene', 'puede', 'puedes'])));
    }

    private function fact(string $text): ?string
    {
        if (preg_match('/(?:plazo|fecha)\s+(?:queda|es|en)\s+(?:el\s+|la\s+)?(\p{L}{3,})/iu', $text, $match) !== 1) {
            return null;
        }

        return mb_strtolower($match[1]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
