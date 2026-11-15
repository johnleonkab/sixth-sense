<?php

namespace App\Avisos;

use App\Alerts\AlertSource;
use App\Connections\Provider;
use App\Jobs\ScoreAvisosCandidate;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A signed webhook becomes aviso candidates. The signature is checked
 * before the body is trusted.
 */
class WebhookIntake
{
    public function accept(Request $request, string $source): void
    {
        $alertSource = $this->source($source);
        $this->verify($request, $source);

        $data = $request->json()->all();
        $email = strtolower(trim((string) ($data['account'] ?? '')));
        $externalId = trim((string) ($data['external_id'] ?? ''));

        if ($email === '' || $externalId === '') {
            return;
        }

        $candidate = new Candidate(
            source: $alertSource,
            externalId: $externalId,
            actorName: trim((string) ($data['actor_name'] ?? 'Alguien')) ?: 'Alguien',
            actorEmail: isset($data['actor_email']) ? strtolower((string) $data['actor_email']) : null,
            title: (string) ($data['title'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            url: isset($data['url']) ? (string) $data['url'] : null,
            receivedAt: isset($data['received_at']) ? CarbonImmutable::parse($data['received_at']) : CarbonImmutable::now(),
            threadKey: isset($data['thread_key']) ? (string) $data['thread_key'] : null,
            directMessage: (bool) ($data['direct_message'] ?? false),
            mention: (bool) ($data['mention'] ?? false),
            importantSender: (bool) ($data['important_sender'] ?? false),
            replyToOwnThread: (bool) ($data['reply_to_own_thread'] ?? false),
            bulk: (bool) ($data['bulk'] ?? false),
            fromSelf: (bool) ($data['from_self'] ?? false),
            ownedPage: (bool) ($data['owned_page'] ?? false),
            assignedTask: (bool) ($data['assigned_task'] ?? false),
            inInbox: (bool) ($data['in_inbox'] ?? true),
            accountEmail: $email,
        );

        $provider = match ($alertSource) {
            AlertSource::Slack => Provider::Slack,
            AlertSource::Gmail => Provider::Google,
            AlertSource::Jira => Provider::Atlassian,
            AlertSource::Notion => Provider::Notion,
            default => null,
        };

        if ($provider === null) {
            return;
        }

        ConnectedAccount::query()
            ->where('provider', $provider)
            ->whereRaw('lower(email) = ?', [$email])
            ->with('user')
            ->get()
            ->each(function (ConnectedAccount $account) use ($alertSource, $candidate): void {
                if ($account->user->wantsAlertsFrom($alertSource)) {
                    ScoreAvisosCandidate::dispatch($account->user_id, $candidate->toArray());
                }
            });
    }

    private function source(string $source): AlertSource
    {
        return AlertSource::tryFrom($source) ?? throw new HttpException(404);
    }

    private function verify(Request $request, string $source): void
    {
        $secret = config("avisos.webhooks.{$source}");
        $given = (string) $request->header('X-Avisos-Signature', '');
        $expected = is_string($secret) && $secret !== ''
            ? hash_hmac('sha256', $request->getContent(), $secret)
            : '';

        if ($expected === '' || ! hash_equals($expected, $given)) {
            throw new HttpException(401);
        }
    }
}
