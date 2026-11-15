<?php

namespace App\Http\Controllers;

use App\Avisos\WebhookIntake;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AvisosWebhookController extends Controller
{
    /**
     * A signed notice from Slack, Gmail, Jira or Notion.
     */
    public function __invoke(Request $request, string $source, WebhookIntake $intake): Response
    {
        $intake->accept($request, $source);

        return response()->noContent();
    }
}
