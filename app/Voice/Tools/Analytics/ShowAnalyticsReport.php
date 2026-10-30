<?php

namespace App\Voice\Tools\Analytics;

use App\Connectors\Analytics\GoogleAnalytics;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A Google Analytics 4 report on screen: totals, a trend or a ranking, and
 * the detail. Read only.
 */
class ShowAnalyticsReport implements VoiceTool
{
    public function __construct(private GoogleAnalytics $analytics) {}

    public function name(): string
    {
        return 'analytics_report';
    }

    public function description(): string
    {
        return 'Muestra un informe de Google Analytics 4 y devuelve sus cifras. Métricas habituales: activeUsers, newUsers, sessions, screenPageViews, engagementRate, averageSessionDuration, keyEvents. '
            .'Dimensiones: date (tendencia), pagePath, sessionDefaultChannelGroup, sessionSource, country, deviceCategory, landingPage. '
            .'Fechas como "7daysAgo", "yesterday", "today" o AAAA-MM-DD. Si hay varias propiedades y no dice cuál, te devuelve la lista para preguntar.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'property' => ['type' => 'string', 'description' => 'Nombre o id de la propiedad. Vacío si solo hay una.'],
                'metrics' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Por defecto activeUsers y sessions.'],
                'dimensions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Por defecto date.'],
                'start_date' => ['type' => 'string', 'description' => 'Por defecto 28daysAgo.'],
                'end_date' => ['type' => 'string', 'description' => 'Por defecto today.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'property' => ['nullable', 'string', 'max:200'],
            'metrics' => ['nullable', 'array', 'max:6'],
            'metrics.*' => ['string', 'regex:/^[A-Za-z0-9_:]+$/'],
            'dimensions' => ['nullable', 'array', 'max:3'],
            'dimensions.*' => ['string', 'regex:/^[A-Za-z0-9_:]+$/'],
            'start_date' => ['nullable', 'string', 'regex:/^(\d{4}-\d{2}-\d{2}|today|yesterday|\d+daysAgo)$/'],
            'end_date' => ['nullable', 'string', 'regex:/^(\d{4}-\d{2}-\d{2}|today|yesterday|\d+daysAgo)$/'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $properties = $this->analytics->properties($user);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        if ($properties === []) {
            return ToolResult::error('No hay ninguna cuenta de Google con permiso para ver Analytics. Se da en Configuración, Conexiones («Ver Google Analytics»).');
        }

        $wanted = trim((string) ($arguments['property'] ?? ''));
        $matching = $wanted === '' ? $properties : self::closest($properties, $wanted);

        if (count($matching) !== 1) {
            return new ToolResult([
                'choose_property' => array_map(fn (array $property): string => "{$property['name']} (cuenta {$property['account']})", $matching === [] ? $properties : $matching),
                'next_step' => $matching === []
                    ? "Ninguna propiedad se parece a «{$wanted}». No es un problema de acceso: lee las disponibles y pregunta cuál es."
                    : 'Varias propiedades encajan. No es un problema de acceso: pregunta cuál quiere y vuelve a llamar con su nombre exacto.',
            ]);
        }

        $property = $matching[0];
        $metrics = array_values($arguments['metrics'] ?? []) ?: ['activeUsers', 'sessions'];
        $dimensions = array_values($arguments['dimensions'] ?? ['date']);
        $from = $arguments['start_date'] ?? '28daysAgo';
        $to = $arguments['end_date'] ?? 'today';

        try {
            $report = $this->analytics->report($user, $property, $metrics, $dimensions, $from, $to);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $title = implode(' y ', array_map(AnalyticsView::metric(...), array_slice($metrics, 0, 2))).' · '.$this->range($from, $to);

        return new ToolResult(
            output: [
                'property' => $property['name'],
                'totals' => array_map(fn (string $metric): string => AnalyticsView::metric($metric).': '.AnalyticsView::format($metric, $report->totals[$metric] ?? 0), $metrics),
                'rows' => array_slice(array_map(fn (array $row): array => [...$row['dimensions'], ...$row['metrics']], $report->rows), 0, 30),
                'shown_on_screen' => true,
            ],
            canvas: new Canvas('openui.view', [
                'title' => $title,
                'source' => 'Google Analytics',
                'ui' => AnalyticsView::build($report, $title),
            ], 'wide'),
        );
    }

    /**
     * The properties that best match how the person named one: by its id,
     * its name, its account's name or both, in any order and ignoring
     * accents, slashes and brackets. Several only when they tie.
     *
     * @param  list<array{property: string, name: string, account: string, account_id: int}>  $properties
     * @return list<array{property: string, name: string, account: string, account_id: int}>
     */
    public static function closest(array $properties, string $wanted): array
    {
        $words = self::words($wanted);
        $id = preg_match('/(\d{6,})/', $wanted, $match) === 1 ? $match[1] : null;
        $scored = [];

        foreach ($properties as $property) {
            if ($id !== null && $property['property'] === "properties/{$id}") {
                return [$property];
            }

            $name = self::words($property['name']);
            $both = [...$name, ...self::words($property['account'])];
            $shared = $words === [] ? 0 : count(array_intersect(array_unique($words), $both)) / count(array_unique($words));
            $exact = $name !== [] && array_diff($name, $words) === [] ? 0.5 : 0;

            $scored[] = ['property' => $property, 'score' => $shared + $exact];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $scored[0]['score'] ?? 0;

        if ($best < 0.6) {
            return [];
        }

        return array_values(array_map(
            fn (array $item): array => $item['property'],
            array_filter($scored, fn (array $item): bool => $item['score'] >= $best - 0.001),
        ));
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[a-z0-9]+/', Str::lower(Str::ascii($text)), $matches);

        return $matches[0];
    }

    private function range(string $from, string $to): string
    {
        if (preg_match('/^(\d+)daysAgo$/', $from, $match) === 1 && $to === 'today') {
            return "últimos {$match[1]} días";
        }

        return "{$from} – {$to}";
    }
}
