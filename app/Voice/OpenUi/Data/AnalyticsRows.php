<?php

namespace App\Voice\OpenUi\Data;

use App\Connectors\Analytics\GoogleAnalytics;
use App\Models\User;
use App\Voice\Tools\Analytics\ShowAnalyticsReport;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A Google Analytics 4 report as rows: one per combination of dimensions,
 * with each dimension and metric as a field. Days come as YYYY-MM-DD.
 */
class AnalyticsRows implements Dataset
{
    private const DATE = '/^(\d{4}-\d{2}-\d{2}|today|yesterday|\d+daysAgo)$/';

    public function __construct(private GoogleAnalytics $analytics) {}

    public function name(): string
    {
        return 'analytics_rows';
    }

    public function description(): string
    {
        return 'Un informe de Google Analytics 4 como filas: un campo por dimensión y por métrica (mismos nombres que analytics_report). property por nombre, cuenta o id.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'property' => ['type' => 'string'],
                'metrics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'dimensions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
            ],
            'required' => ['property', 'metrics'],
        ];
    }

    public function rules(): array
    {
        return [
            'property' => ['required', 'string', 'max:200'],
            'metrics' => ['required', 'array', 'min:1', 'max:6'],
            'metrics.*' => ['string', 'regex:/^[A-Za-z0-9_:]+$/'],
            'dimensions' => ['nullable', 'array', 'max:3'],
            'dimensions.*' => ['string', 'regex:/^[A-Za-z0-9_:]+$/'],
            'start_date' => ['nullable', 'string', 'regex:'.self::DATE],
            'end_date' => ['nullable', 'string', 'regex:'.self::DATE],
        ];
    }

    /**
     * Its fields depend on the metrics and dimensions asked for.
     */
    public function fields(): array
    {
        return [];
    }

    public function rows(User $user, array $arguments, string $timezone): array
    {
        $matching = ShowAnalyticsReport::closest($this->analytics->properties($user), $arguments['property']);

        if (count($matching) !== 1) {
            throw new RuntimeException("No encuentro una sola propiedad de Analytics que se llame «{$arguments['property']}».");
        }

        $dimensions = array_values($arguments['dimensions'] ?? []);
        $metrics = array_values($arguments['metrics']);
        $report = $this->analytics->report($user, $matching[0], $metrics, $dimensions, $arguments['start_date'] ?? '28daysAgo', $arguments['end_date'] ?? 'today', Datasets::MAX_ROWS);

        return array_map(function (array $row) use ($dimensions, $metrics): array {
            $values = [];

            foreach ($dimensions as $position => $dimension) {
                $value = $row['dimensions'][$position] ?? '';
                $values[$dimension] = $dimension === 'date' && preg_match('/^\d{8}$/', $value) === 1 ? CarbonImmutable::createFromFormat('Ymd', $value)?->toDateString() : $value;
            }

            foreach ($metrics as $position => $metric) {
                $values[$metric] = $row['metrics'][$position] ?? 0;
            }

            return $values;
        }, $report->rows);
    }
}
