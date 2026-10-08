<?php

namespace App\Connectors\Chat;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Chat workspaces (Slack today) across every account of the user.
 */
interface Chats
{
    /**
     * People whose name, handle or email matches what was said, best match
     * first and, among equals, those the user talks to most.
     *
     * @return list<ChatPerson>
     */
    public function people(User $user, string $query, int $limit = 10): array;

    /**
     * The user's conversations whose name matches what was said, optionally of
     * one kind; best match first and, among equals, the most active.
     *
     * @return list<ChatConversation>
     */
    public function conversations(User $user, string $query, ?string $kind = null, int $limit = 10): array;

    /**
     * The people and conversations the user has dealt with most in the last
     * weeks, most active first, so a name they say can be recognised. Only
     * what is already known: it never calls the workspace, and is empty until
     * warm() has run.
     *
     * @return array{people: list<ChatPerson>, conversations: list<ChatConversation>}
     */
    public function familiar(User $user, int $people = 25, int $conversations = 20): array;

    /**
     * Refresh what is known about the user's workspaces (people, conversations
     * and recent activity) where it is getting old. Slow: run it in a job.
     */
    public function warm(User $user): void;

    /**
     * What the user wrote, and what was written to them or mentioning them,
     * since a moment, newest first. Read only.
     *
     * @return list<ChatMessage>
     */
    public function activity(User $user, CarbonImmutable $since, int $limit = 150): array;

    /**
     * Direct messages and mentions the user has not read, newest first.
     *
     * @return list<UnreadMessage>
     */
    public function unread(User $user): array;

    /**
     * A conversation's latest messages, a thread, or the context around a message.
     */
    public function view(User $user, string $conversationRef, ?string $aroundRef = null, ?string $threadRef = null, int $limit = 20): ?ChatView;

    /**
     * @return list<ChatMessage>
     */
    public function search(User $user, ChatSearch $search): array;

    public function file(User $user, string $fileRef): ?ChatFile;

    /**
     * The file's bytes, fetched with the user's credentials.
     */
    public function download(User $user, string $fileRef): ?string;

    /**
     * Whether the user wrote after the message, in its thread or in its
     * conversation (from here or from Slack), so it no longer waits for them.
     */
    public function repliedAfter(User $user, string $messageRef): bool;

    /**
     * Whether the user can send to this conversation or person.
     */
    public function canSend(User $user, string $to): bool;

    /**
     * Send as the user. Returns the sent message's ref.
     */
    public function send(User $user, OutgoingChatMessage $message): string;
}
