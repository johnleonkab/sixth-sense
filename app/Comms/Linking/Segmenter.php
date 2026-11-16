<?php

namespace App\Comms\Linking;

use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Comms\Windows\AssembledMessage;
use App\Comms\Windows\AssembledWindow;
use App\Comms\Windows\WindowAssembler;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsWindow;

/**
 * Splits one conversation window into exchanges that stand alone.
 * The model proposes; this class checks that every new message survived
 * and that every id was in the window.
 */
class Segmenter
{
    public function __construct(private WindowAssembler $assembler, private ModelRouter $router) {}

    /**
     * @return list<CommsSegment>
     */
    public function run(CommsWindow $window): array
    {
        $window->loadMissing('user');
        $assembled = $this->assembler->for($window);
        $new = array_values(array_filter(
            $assembled->messages,
            fn (AssembledMessage $row): bool => $row->new,
        ));

        if ($new === [] || $window->user === null) {
            return [];
        }

        $answer = $this->router->ask(
            user: $window->user,
            task: 'segment',
            input: $this->input($window, $assembled),
            schema: Schemas::segment(),
            eventIds: array_map(fn (AssembledMessage $row): int => $row->message->event_id, $new),
            windowId: $window->id,
        );

        $parsed = $answer->kind === ModelAnswerKind::Accepted
            ? $this->accepted($answer->output ?? [], $assembled)
            : null;

        $this->write($window, $assembled, $parsed ?? [$this->unsegmented($assembled)], $answer->event->id);

        return CommsSegment::query()->where('window_id', $window->id)->orderBy('id')->get()->all();
    }

    private function input(CommsWindow $window, AssembledWindow $assembled): string
    {
        $people = array_map(function ($person): string {
            $line = 'person:'.$person->id.' '.$this->plain($person->display_name);

            if (filled($person->role)) {
                $line .= ' '.$this->plain($person->role);
            }

            return $line;
        }, $assembled->people);

        $messages = array_map(function (AssembledMessage $row): string {
            $message = $row->message;

            return '<source id="m'.$message->id.'"'
                .' author="person:'.$message->author_person_id.'"'
                .' at="'.$message->occurred_at->toIso8601String().'"'
                .' new="'.($row->new ? 'true' : 'false').'">'
                .$this->escape($message->text)
                .'</source>';
        }, $assembled->messages);

        return implode("\n", [
            'CHANNEL',
            $window->source.' '.$window->group_ref.' '.$window->kind,
            '',
            'PEOPLE',
            $people === [] ? '' : implode("\n", $people),
            '',
            'WINDOW',
            implode("\n", $messages),
        ]);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array{message_ids: list<int>, new_message_ids: list<int>, participants: list<string>, restated: string, unresolved_refs: list<string>}>|null
     */
    private function accepted(array $output, AssembledWindow $assembled): ?array
    {
        $segments = $output['segments'] ?? null;

        if (! is_array($segments)) {
            return null;
        }

        $known = [];
        $fresh = [];

        foreach ($assembled->messages as $row) {
            $known[$row->message->id] = true;

            if ($row->new) {
                $fresh[$row->message->id] = true;
            }
        }

        $people = [];

        foreach ($assembled->people as $person) {
            $people[$person->id] = true;
        }

        $kept = [];
        $covered = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                return null;
            }

            $messageIds = $this->messageIds($segment['message_ids'] ?? null);
            $newIds = $this->messageIds($segment['new_message_ids'] ?? null);
            $participants = $this->participants($segment['participants'] ?? null);
            $refs = $this->strings($segment['unresolved_refs'] ?? null);

            if ($messageIds === null || $newIds === null || $participants === null || $refs === null || ! is_string($segment['restated'] ?? null)) {
                return null;
            }

            foreach ([...$messageIds, ...$newIds] as $id) {
                if (! isset($known[$id])) {
                    return null;
                }
            }

            foreach ($newIds as $id) {
                if (! isset($fresh[$id]) || ! in_array($id, $messageIds, true)) {
                    return null;
                }
            }

            foreach ($participants as $id) {
                if (! isset($people[$id])) {
                    return null;
                }
            }

            if ($newIds === []) {
                continue;
            }

            foreach ($newIds as $id) {
                $covered[$id] = true;
            }

            $kept[] = [
                'message_ids' => $messageIds,
                'new_message_ids' => $newIds,
                'participants' => array_map(fn (int $id): string => 'person:'.$id, $participants),
                'restated' => $segment['restated'],
                'unresolved_refs' => $refs,
            ];
        }

        foreach (array_keys($fresh) as $id) {
            if (! isset($covered[$id])) {
                return null;
            }
        }

        return $kept;
    }

    /**
     * @return array{message_ids: list<int>, new_message_ids: list<int>, participants: list<string>, restated: string, unresolved_refs: list<string>, unsegmented: true}
     */
    private function unsegmented(AssembledWindow $assembled): array
    {
        $messageIds = [];
        $newIds = [];

        foreach ($assembled->messages as $row) {
            $messageIds[] = $row->message->id;

            if ($row->new) {
                $newIds[] = $row->message->id;
            }
        }

        return [
            'message_ids' => $messageIds,
            'new_message_ids' => $newIds,
            'participants' => array_map(fn ($person): string => 'person:'.$person->id, $assembled->people),
            'restated' => '',
            'unresolved_refs' => [],
            'unsegmented' => true,
        ];
    }

    /**
     * @param  list<array{message_ids: list<int>, new_message_ids: list<int>, participants: list<string>, restated: string, unresolved_refs: list<string>, unsegmented?: bool}>  $segments
     */
    private function write(CommsWindow $window, AssembledWindow $assembled, array $segments, int $eventId): void
    {
        CommsSegment::query()->where('window_id', $window->id)->delete();

        foreach ($segments as $segment) {
            CommsSegment::query()->create([
                'user_id' => $window->user_id,
                'window_id' => $window->id,
                'message_ids' => $segment['message_ids'],
                'new_message_ids' => $segment['new_message_ids'],
                'participants' => $segment['participants'],
                'restated' => $segment['restated'],
                'unresolved_refs' => $segment['unresolved_refs'],
                'unsegmented' => $segment['unsegmented'] ?? false,
                'decision_event_id' => $eventId,
            ]);
        }

        $newIds = [];

        foreach ($assembled->messages as $row) {
            if ($row->new) {
                $newIds[] = $row->message->id;
            }
        }

        if ($newIds !== []) {
            CommsMessage::query()->whereIn('id', $newIds)->update(['window_id' => $window->id]);
        }
    }

    /**
     * @return list<int>|null
     */
    private function messageIds(mixed $tokens): ?array
    {
        if (! is_array($tokens)) {
            return null;
        }

        $ids = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || preg_match('/^m(\d+)$/', $token, $match) !== 1) {
                return null;
            }

            $ids[] = (int) $match[1];
        }

        return $ids;
    }

    /**
     * @return list<int>|null
     */
    private function participants(mixed $tokens): ?array
    {
        if (! is_array($tokens)) {
            return null;
        }

        $ids = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || preg_match('/^person:(\d+)$/', $token, $match) !== 1) {
                return null;
            }

            $ids[] = (int) $match[1];
        }

        return $ids;
    }

    /**
     * @return list<string>|null
     */
    private function strings(mixed $tokens): ?array
    {
        if (! is_array($tokens)) {
            return null;
        }

        $values = [];

        foreach ($tokens as $token) {
            if (! is_string($token)) {
                return null;
            }

            $values[] = $token;
        }

        return $values;
    }

    private function plain(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
