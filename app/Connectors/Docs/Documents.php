<?php

namespace App\Connectors\Docs;

use App\Connections\Capability;
use App\Models\User;

interface Documents
{
    /**
     * Search documents in every account allowed to read them.
     */
    public function search(User $user, DocumentSearch $search): DocumentSearchResult;

    /**
     * Open a document by its ref, its URL or its id. For a URL or id, every
     * account of the user is tried until one can open it.
     */
    public function find(User $user, string $reference): ?Document;

    /**
     * Whether the account behind this document may use the capability.
     */
    public function allows(User $user, string $ref, Capability $capability): bool;

    /**
     * Apply a confirmed edit plan. Fails if the document changed since the plan.
     *
     * @throws EditNotPossible
     */
    public function applyEdits(User $user, EditPlan $plan): void;

    /**
     * Comment on the document as the user, optionally quoting a fragment.
     * Returns the comment's id.
     */
    public function addComment(User $user, string $ref, string $content, ?string $quote = null): string;
}
