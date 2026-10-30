<?php

namespace App\Http\Controllers;

use App\Voice\OpenUi\Data\Datasets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * The rows a composed view asks for with Query(), while it is on screen.
 * Only the datasets on the list, only reads, only the signed-in person's.
 */
class CanvasQueryController extends Controller
{
    public function __construct(private Datasets $datasets) {}

    public function __invoke(Request $request, string $dataset): JsonResponse
    {
        $source = $this->datasets->find($dataset);

        abort_if($source === null, 404);

        $arguments = (array) $request->input('arguments', []);
        $validator = Validator::make($arguments, $source->rules());

        if ($validator->fails()) {
            return response()->json(['error' => implode(' ', $validator->errors()->all())], 422);
        }

        $timezone = $request->string('timezone', config('agenda.timezone'))->toString();

        try {
            $rows = $source->rows($request->user(), $validator->validated(), in_array($timezone, timezone_identifiers_list(), true) ? $timezone : config('agenda.timezone'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'rows' => array_slice($rows, 0, Datasets::MAX_ROWS),
            'fields' => $source->fields(),
        ]);
    }
}
