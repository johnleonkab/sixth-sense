<?php

namespace App\WorkContext;

use App\Connectors\Chat\ChatMessage;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\MailMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The activity, arranged the way the nightly summary should read it: Slack
 * by chat, Gmail by thread, the person's own writing, recent Drive files,
 * Notion from the top down, Jira as each site's rules already filter it, and
 * what changed in the work archive.
 */
class ActivityBrief
{
    public function text(User $user, WorkActivity $activity, string $jiraRules = ''): string
    {
        $timezone = config('app.timezone');
        $when = fn (CarbonImmutable $date): string => $date->setTimezone($timezone)->locale('es')->isoFormat('ddd D MMM HH:mm');
        $line = fn (?string $value, int $limit): string => Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $value)), $limit);
        $people = fn (array $addresses): string => implode(', ', array_map(fn (MailAddress $address): string => $address->label(), $addresses));

        $sections = [
            $this->slack($user, $activity, $when, $line),
            $this->gmail($user, $activity, $when, $line, $people),
            $this->voice($user, $activity, $when, $line, $people),
            $this->drive($activity, $when, $line),
            $this->notion($activity, $when, $line),
            $this->jira($activity, $jiraRules),
            $this->archive($activity),
        ];

        return implode("\n\n", array_filter($sections, fn (string $section): bool => $section !== ''));
    }

    /**
     * @param  callable(CarbonImmutable): string  $when
     * @param  callable(?string, int): string  $line
     */
    private function slack(User $user, WorkActivity $activity, callable $when, callable $line): string
    {
        $chats = [];

        foreach (array_reverse($activity->messages) as $message) {
            $chat = &$chats[$message->conversationRef];
            $chat['label'] = $message->conversationLabel;
            $chat['messages'][] = $message;
            $chat['score'] = ($chat['score'] ?? 0) + ($message->mentionsMe ? 3 : 0) + ($message->isMine ? 2 : 1);
            unset($chat);
        }

        uasort($chats, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $chats = array_slice($chats, 0, (int) config('work_context.limits.chats'));

        if ($chats === []) {
            return '';
        }

        $blocks = ['--- Slack, por chat (los más relevantes primero) ---'];

        foreach ($chats as $chat) {
            $names = array_values(array_unique(array_map(
                fn (ChatMessage $message): string => $message->isMine ? $user->name : $message->authorName,
                $chat['messages'],
            )));
            $blocks[] = "Chat {$chat['label']} (".count($chat['messages']).' mensajes; '.implode(', ', $names).')';

            foreach ($chat['messages'] as $message) {
                $blocks[] = "[{$when($message->sentAt)}] ".($message->isMine ? $user->name : $message->authorName).": {$line($message->text, 400)}";
            }
        }

        return implode("\n", $blocks);
    }

    /**
     * @param  callable(CarbonImmutable): string  $when
     * @param  callable(?string, int): string  $line
     * @param  callable(list<MailAddress>): string  $people
     */
    private function gmail(User $user, WorkActivity $activity, callable $when, callable $line, callable $people): string
    {
        $threads = [];

        foreach (array_reverse($activity->emails) as $email) {
            $key = $email->threadId ?: $email->ref;
            $thread = &$threads[$key];
            $thread['subject'] = $email->subject;
            $thread['messages'][] = $email;
            $thread['score'] = ($thread['score'] ?? 0) + ($this->sent($user, $email) ? 2 : 1);
            unset($thread);
        }

        uasort($threads, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $threads = array_slice($threads, 0, (int) config('work_context.limits.threads'));

        if ($threads === []) {
            return '';
        }

        $blocks = ['--- Gmail, por hilo (los más relevantes primero) ---'];

        foreach ($threads as $thread) {
            $blocks[] = 'Hilo «'.$line($thread['subject'], 150).'»';

            foreach ($thread['messages'] as $email) {
                $who = $this->sent($user, $email)
                    ? "{$user->name} → {$people($email->to)}"
                    : 'De '.($email->from?->label() ?? 'desconocido');
                $blocks[] = "[{$when($email->date)}] {$who} · {$line($email->snippet, 250)}";
            }
        }

        return implode("\n", $blocks);
    }

    /**
     * @param  callable(CarbonImmutable): string  $when
     * @param  callable(?string, int): string  $line
     * @param  callable(list<MailAddress>): string  $people
     */
    private function voice(User $user, WorkActivity $activity, callable $when, callable $line, callable $people): string
    {
        $slack = array_values(array_filter($activity->messages, fn (ChatMessage $message): bool => $message->isMine));
        $mail = array_values(array_filter($activity->emails, fn (MailMessage $email): bool => $this->sent($user, $email)));
        $blocks = ["--- Cómo escribe {$user->name} (solo lo suyo) ---"];

        foreach (array_slice($slack, 0, 12) as $message) {
            $blocks[] = "Slack {$message->conversationLabel} [{$when($message->sentAt)}]: {$line($message->text, 500)}";
        }

        foreach (array_slice($mail, 0, 12) as $email) {
            $blocks[] = "Gmail → {$people($email->to)} [{$when($email->date)}] «{$line($email->subject, 120)}»: {$line($email->snippet, 400)}";
        }

        return count($blocks) === 1 ? '' : implode("\n", $blocks);
    }

    /**
     * @param  callable(CarbonImmutable): string  $when
     * @param  callable(?string, int): string  $line
     */
    private function drive(WorkActivity $activity, callable $when, callable $line): string
    {
        if ($activity->documents === []) {
            return '';
        }

        $blocks = ['--- Drive, últimos 30 días (creados, editados o compartidos) ---'];

        foreach ($activity->documents as $item) {
            $document = $item['document'];
            $owner = $document->owner['email'] ?? '';
            $kind = $document->createdAt->greaterThanOrEqualTo(now()->subDays(30)) && strcasecmp($owner, $document->account) === 0
                ? 'creado'
                : (strcasecmp($owner, $document->account) === 0 ? 'editado' : 'compartido');
            $blocks[] = "[{$when($document->modifiedAt)}] «{$document->title}» · {$kind}"
                .' · propietario: '.($document->owner['name'] ?? $owner ?: 'desconocido')
                .($item['excerpt'] ? "\n  Empieza así: {$line($item['excerpt'], 400)}" : '');
        }

        return implode("\n", $blocks);
    }

    /**
     * @param  callable(CarbonImmutable): string  $when
     * @param  callable(?string, int): string  $line
     */
    private function notion(WorkActivity $activity, callable $when, callable $line): string
    {
        if ($activity->pages === []) {
            return '';
        }

        $blocks = ['--- Notion, de las páginas más altas hacia abajo (más sangría, más adentro) ---'];

        foreach ($activity->pages as $item) {
            $page = $item['page'];
            $indent = str_repeat('  ', $item['depth']);
            $blocks[] = $indent.($page->editedAt ? "[{$when($page->editedAt)}] " : '')."«{$page->title}»"
                .($page->parentTitle ? " (dentro de {$page->parentTitle})" : '')
                .($item['excerpt'] ? "\n{$indent}  Empieza así: {$line($item['excerpt'], 300)}" : '');
        }

        return implode("\n", $blocks);
    }

    private function jira(WorkActivity $activity, string $rules): string
    {
        if ($activity->issues === [] && trim($rules) === '') {
            return '';
        }

        $blocks = ['--- Jira reciente (ya filtrado con las reglas de cada sitio) ---'];

        foreach ($activity->issues as $issue) {
            $blocks[] = "{$issue->key} · {$issue->status} · {$issue->summary}"
                .($issue->assignee ? " · {$issue->assignee}" : '');
        }

        if (trim($rules) !== '') {
            $blocks[] = "Reglas de Jira que hay que seguir al resumirlo:\n".Str::limit(trim($rules), 2000);
        }

        return implode("\n", $blocks);
    }

    private function archive(WorkActivity $activity): string
    {
        if ($activity->archive === []) {
            return '';
        }

        $blocks = ['--- Archivo de trabajo, cambiado en el último día ---'];

        foreach ($activity->archive as $file) {
            $blocks[] = "«{$file['title']}»".($file['excerpt'] !== '' ? "\n  {$file['excerpt']}" : '');
        }

        return implode("\n", $blocks);
    }

    private function sent(User $user, MailMessage $email): bool
    {
        return strcasecmp((string) $email->from?->email, $email->account) === 0
            || strcasecmp((string) $email->from?->name, $user->name) === 0;
    }
}
