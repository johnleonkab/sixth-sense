<?php

namespace App\Voice\Tools\Analytics;

use App\Connectors\Analytics\AnalyticsReport;
use App\Voice\OpenUi\ViewBuilder;
use Carbon\CarbonImmutable;

/**
 * A Google Analytics report as an OpenUI view: the totals as metrics, a
 * line by day or bars by category, and the detail as a table.
 */
class AnalyticsView
{
    /** @var array<string, string> */
    private const METRICS = [
        'activeUsers' => 'Usuarios activos',
        'totalUsers' => 'Usuarios',
        'newUsers' => 'Usuarios nuevos',
        'sessions' => 'Sesiones',
        'screenPageViews' => 'Vistas',
        'engagedSessions' => 'Sesiones con interacción',
        'engagementRate' => 'Tasa de interacción',
        'bounceRate' => 'Tasa de rebote',
        'averageSessionDuration' => 'Duración media',
        'keyEvents' => 'Eventos clave',
        'conversions' => 'Conversiones',
        'eventCount' => 'Eventos',
        'totalRevenue' => 'Ingresos',
    ];

    /** @var array<string, string> */
    private const DIMENSIONS = [
        'date' => 'Día',
        'pagePath' => 'Página',
        'pageTitle' => 'Título',
        'sessionDefaultChannelGroup' => 'Canal',
        'sessionSource' => 'Fuente',
        'sessionSourceMedium' => 'Fuente / medio',
        'country' => 'País',
        'city' => 'Ciudad',
        'deviceCategory' => 'Dispositivo',
        'landingPage' => 'Página de entrada',
        'eventName' => 'Evento',
    ];

    /** Rows shown in the table and the bars, at most. */
    private const SHOWN = 15;

    public static function metric(string $name): string
    {
        return self::METRICS[$name] ?? $name;
    }

    public static function dimension(string $name): string
    {
        return self::DIMENSIONS[$name] ?? $name;
    }

    public static function format(string $metric, float $value): string
    {
        return match (true) {
            in_array($metric, ['engagementRate', 'bounceRate'], true) => number_format($value * 100, 1, ',', '.').' %',
            $metric === 'averageSessionDuration' => intdiv((int) round($value), 60).' min '.((int) round($value) % 60).' s',
            $metric === 'totalRevenue' => number_format($value, 2, ',', '.').' €',
            default => number_format($value, 0, ',', '.'),
        };
    }

    public static function build(AnalyticsReport $report, string $title): string
    {
        $view = new ViewBuilder($title);
        $totals = [];

        foreach (array_slice($report->metrics, 0, 4) as $metric) {
            $totals[] = $view->add('Metric', [self::metric($metric), self::format($metric, $report->totals[$metric] ?? 0)], top: false);
        }

        $view->add('Row', [$totals]);

        $rows = $report->rows;
        $single = count($report->dimensions) === 1;

        if ($single && $report->dimensions[0] === 'date' && count($rows) > 1) {
            $view->add('LineChart', [
                self::metric($report->metrics[0]).' por día',
                array_map(fn (array $row): string => self::day($row['dimensions'][0]), $rows),
                array_map(fn (array $row): float => $row['metrics'][0], $rows),
            ]);
        } elseif ($single && $rows !== []) {
            $top = array_slice($rows, 0, 10);
            $view->add('BarChart', [
                self::metric($report->metrics[0]).' por '.mb_strtolower(self::dimension($report->dimensions[0])),
                array_map(fn (array $row): string => $row['dimensions'][0] ?: '(sin valor)', $top),
                array_map(fn (array $row): float => $row['metrics'][0], $top),
            ]);
        }

        if ($rows !== [] && $report->dimensions !== []) {
            $view->add('Table', [
                [...array_map(self::dimension(...), $report->dimensions), ...array_map(self::metric(...), $report->metrics)],
                array_map(fn (array $row): array => [
                    ...array_map(fn (string $value, string $dimension): string => $dimension === 'date' ? self::day($value) : ($value ?: '(sin valor)'), $row['dimensions'], $report->dimensions),
                    ...array_map(fn (float $value, string $metric): string => self::format($metric, $value), $row['metrics'], $report->metrics),
                ], array_slice($rows, 0, self::SHOWN)),
            ]);
        }

        $view->add('Text', ["{$report->propertyName} · Google Analytics", 'muted']);

        return $view->toString();
    }

    /**
     * GA writes days as 20261028.
     */
    private static function day(string $value): string
    {
        $date = CarbonImmutable::createFromFormat('Ymd', $value);

        return $date === null ? $value : $date->locale('es')->isoFormat('D MMM');
    }
}
