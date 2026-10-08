<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunVoiceToolRequest;
use App\Voice\Tools\Toolbox;
use App\Voice\Tools\ToolContext;
use Illuminate\Http\JsonResponse;

class VoiceToolController extends Controller
{
    /**
     * Run a tool the voice assistant called. The browser relays the call here
     * and sends `output` back to the model; `canvas`, if any, goes on screen.
     */
    public function __invoke(RunVoiceToolRequest $request, Toolbox $toolbox, string $tool): JsonResponse
    {
        $voiceTool = $toolbox->find($tool);

        abort_if($voiceTool === null, 404);

        $result = $toolbox->run($voiceTool, $request->user(), $request->arguments(), new ToolContext($request->now()));

        return response()->json($result->toArray());
    }
}
