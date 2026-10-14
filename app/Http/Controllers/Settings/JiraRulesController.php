<?php

namespace App\Http\Controllers\Settings;

use App\Connections\Provider;
use App\Connectors\Jira\JiraRules;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JiraRulesController extends Controller
{
    /**
     * The rules of one Jira connection, to read and edit.
     */
    public function edit(Request $request, int $account): Response
    {
        $account = $this->jiraAccount($request, $account);
        $rules = new JiraRules((string) ($account->rules ?? JiraRules::template()));

        return Inertia::render('settings/jira-rules', [
            'account' => [
                'id' => $account->id,
                'email' => $account->email,
                'site' => $account->metadata['site_name'] ?? null,
                'site_url' => $account->metadata['site_url'] ?? null,
            ],
            'rules' => $rules->text,
            'saved' => filled($account->rules),
            'template' => JiraRules::template(),
            'understood' => [
                'projects' => $rules->projects(),
                'jql' => $rules->baseJql(),
                'link_type' => $rules->linkType(),
                'components' => $rules->components(),
                'sections' => $rules->sections(),
            ],
            'max_length' => JiraRules::MAX_LENGTH,
        ]);
    }

    /**
     * Save the rules as written.
     */
    public function update(Request $request, int $account): RedirectResponse
    {
        $account = $this->jiraAccount($request, $account);
        $validated = $request->validate(['rules' => ['nullable', 'string', 'max:'.JiraRules::MAX_LENGTH]]);

        $account->update(['rules' => filled($validated['rules'] ?? null) ? $validated['rules'] : null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reglas de Jira guardadas. El asistente las usará desde la próxima conversación.']);

        return back();
    }

    private function jiraAccount(Request $request, int $id): ConnectedAccount
    {
        return $request->user()->connectedAccounts()->where('provider', Provider::Atlassian)->findOrFail($id);
    }
}
