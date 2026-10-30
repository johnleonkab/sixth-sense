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
        $properties = $this->analytics->properties($user);

        if ($properties === []) {
            return ToolResult::error('No hay ninguna cuenta de Google con permiso para ver Analytics. Se da en Configuración, Conexiones («Ver Google Analytics»).');
        }

        $wanted = trim((string) ($arguments['property'] ?? ''));
        $matching = $wanted === '' ? $properties : array_values(array_filter(
            $properties,
            fn (array $property): bool => Str::contains(Str::lower($property['name'].' '.$property['property']), Str::lower($wanted)),
        ));

        if (count($matching) !== 1) {
            return new ToolResult([
                'choose_property' => array_map(fn (array $property): string => "{$property['name']} ({$property['account']})", $matching === [] ? $properties : $matching),
                'next_step' => 'Pregunta de qué propiedad quiere el informe y vuelve a llamar con property.',
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

    private function range(string $from, string $to): string
    {
        if (preg_match('/^(\d+)daysAgo$/', $from, $match) === 1 && $to === 'today') {
            return "últimos {$match[1]} días";
        }

        return "{$from} – {$to}";
    }
}
