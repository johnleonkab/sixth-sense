<?php

namespace App\Connectors\Analytics;

/**
 * One Google Analytics report: its columns, rows and the total of each metric.
 */
final readonly class AnalyticsReport
{
    /**
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @param  list<array{dimensions: list<string>, metrics: list<float>}>  $rows
     * @param  array<string, float>  $totals  By metric.
     */
    public function __construct(
        public string $property,
        public string $propertyName,
        public array $dimensions,
        public array $metrics,
        public array $rows,
        public array $totals,
    ) {}
}
