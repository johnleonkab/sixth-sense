<?php

namespace App\Connectors\Calendar;

use App\Connectors\Mail\MailAddress;
use App\Models\User;

/**
 * Changes to the user's calendars. Only called once the person confirmed.
 * Each change reads the event again first, so nobody else's edits are lost.
 */
interface CalendarWriter
{
    /**
     * Accounts whose calendar the user may change, the oldest first.
     *
     * @return list<CalendarAccount>
     */
    public function accounts(User $user): array;

    /**
     * Whether the account behind this event may be changed.
     */
    public function canChange(User $user, string $ref): bool;

    /**
     * Invite more people; they receive the invitation.
     *
     * @param  list<MailAddress>  $people
     */
    public function invite(User $user, string $ref, array $people): void;

    /**
     * Add a dated summary to the event's description, after what is there.
     */
    public function addSummary(User $user, string $ref, string $summary, string $heading): void;

    /**
     * Create the event and invite its attendees. Returns its link.
     */
    public function create(User $user, NewEvent $event): ?string;

    /**
     * Cancel an event the user organises; attendees are told.
     */
    public function cancel(User $user, string $ref): void;

    /**
     * Answer an invitation: accepted, declined or tentative.
     */
    public function respond(User $user, string $ref, string $response, ?string $comment = null): void;
}
