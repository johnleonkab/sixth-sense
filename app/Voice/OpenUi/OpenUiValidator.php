<?php

namespace App\Voice\OpenUi;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Checks a program with OpenUI's own parser and the canvas library, the same
 * check the tests run (`scripts/openui-check.mts`). Needs Node.
 */
class OpenUiValidator
{
    /**
     * The parser's errors, an empty list when the program is valid, or null
     * when it could not be checked here (no Node).
     *
     * @return list<string>|null
     */
    public function errors(string $program): ?array
    {
        $file = storage_path('app/private/openui-'.Str::random(12).'.ui');
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $program);

        try {
            $result = Process::path(base_path())->timeout(20)->run(['node', 'scripts/openui-check.mts', $file]);
        } finally {
            @unlink($file);
        }

        if ($result->successful()) {
            return [];
        }

        $report = json_decode(trim(Str::afterLast(trim($result->output()), "\n")) ?: trim($result->output()), true);

        if (! is_array($report)) {
            report(new \RuntimeException('OpenUI check could not run: '.Str::limit($result->errorOutput(), 300)));

            return null;
        }

        return array_values(array_map(
            fn (mixed $error): string => is_array($error) ? trim(($error['code'] ?? '').' '.($error['message'] ?? $error['name'] ?? '')) : (string) $error,
            $report['errors'] ?? [],
        )) ?: ['La vista no tiene raíz: empieza por root = View("título", [...]).'];
    }
}
