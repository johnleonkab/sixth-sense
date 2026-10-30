<?php

namespace App\Http\Controllers;

use App\Voice\Tools\Toolbox;
use App\Voice\Tools\ToolContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One detail (an email, an issue, a page, a conversation, an alert) for a
 * composed view to place beside another, asked for while it is on screen.
 * Only the tools that open something: nothing that proposes or changes.
 */
class CanvasEmbedController extends Controller
{
    /** The tools a view may embed, all of them only showing something. */
    public const TOOLS = ['open_email', 'open_jira_issue', 'open_notion_page', 'show_slack_conversation', 'review_alert', 'show_calendar'];

    public function __invoke(Request $request, Toolbox $toolbox): JsonResponse
    {
        $validated = $request->validate([
            'tool' => ['required', 'in:'.implode(',', self::TOOLS)],
            'arguments' => ['nullable', 'array'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        $tool = $toolbox->find($validated['tool']);

        abort_if($tool === null, 404);

        $result = $toolbox->run($tool, $request->user(), $validated['arguments'] ?? [], new ToolContext(CarbonImmutable::now($validated['timezone'] ?? config('agenda.timezone'))));

        if (isset($result->output['error']) || $result->canvas === null) {
            return response()->json(['message' => $result->output['error'] ?? 'No hay nada que enseñar.'], 422);
        }

        return response()->json(['canvas' => $result->canvas->toArray()]);
    }
}
