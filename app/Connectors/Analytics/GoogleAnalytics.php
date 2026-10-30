<?php

namespace App\Connectors\Analytics;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Analytics 4, read only: the properties each Google account can see
 * (Admin API) and reports on one of them (Data API).
 *
 * @see https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport
 */
class GoogleAnalytics
{
    private const ADMIN_URL = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries';

    private const DATA_URL = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(private AccessTokens $tokens) {}

    /**
     * Every GA4 property the person's Google accounts can read.
     *
     * @return list<array{property: string, name: string, account: string, account_id: int}>
     */
    public function properties(User $user): array
    {
        $properties = [];

        foreach ($user->accountsWith(Capability::AnalyticsRead) as $account) {
            $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(15)->get(self::ADMIN_URL, ['pageSize' => 200]);

            if ($response->failed()) {
                report(new RuntimeException("Analytics Admin API failed with HTTP {$response->status()}."));

                continue;
            }

            foreach ($response->json('accountSummaries') ?? [] as $summary) {
                foreach ($summary['propertySummaries'] ?? [] as $property) {
                    $properties[] = [
                        'property' => (string) $property['property'],
                        'name' => (string) ($property['displayName'] ?? $property['property']),
                        'account' => (string) ($summary['displayName'] ?? ''),
                        'account_id' => $account->id,
                    ];
                }
            }
        }

        return $properties;
    }

    /**
     * One report on a property: metrics broken down by dimensions over a
     * date range ("7daysAgo", "today", "2026-10-01").
     *
     * @param  array{property: string, name: string, account_id: int}  $property
     * @param  list<string>  $metrics
     * @param  list<string>  $dimensions
     *
     * @throws RuntimeException when Google refuses the report.
     */
    public function report(User $user, array $property, array $metrics, array $dimensions, string $from, string $to, int $limit = 50): AnalyticsReport
    {
        $account = $user->accountsWith(Capability::AnalyticsRead)->first(fn (ConnectedAccount $account): bool => $account->id === $property['account_id']);

        if ($account === null) {
            throw new RuntimeException('Esa cuenta de Google ya no puede leer Analytics.');
        }

        $byDate = $dimensions === ['date'];
        $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(20)->post(self::DATA_URL."/{$property['property']}:runReport", array_filter([
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'metrics' => array_map(fn (string $metric): array => ['name' => $metric], $metrics),
            'dimensions' => array_map(fn (string $dimension): array => ['name' => $dimension], $dimensions),
            'metricAggregations' => ['TOTAL'],
            'limit' => $limit,
            'orderBys' => $byDate
                ? [['dimension' => ['dimensionName' => 'date']]]
                : ($dimensions === [] ? null : [['metric' => ['metricName' => $metrics[0]], 'desc' => true]]),
        ]));

        if ($response->failed()) {
            throw new RuntimeException('Google Analytics no ha dado el informe: '.($response->json('error.message') ?? "HTTP {$response->status()}"));
        }

        $rows = array_map(fn (array $row): array => [
            'dimensions' => array_map(fn (array $value): string => (string) ($value['value'] ?? ''), $row['dimensionValues'] ?? []),
            'metrics' => array_map(fn (array $value): float => (float) ($value['value'] ?? 0), $row['metricValues'] ?? []),
        ], $response->json('rows') ?? []);

        $totals = [];
        $total = $response->json('totals.0.metricValues') ?? [];

        foreach ($metrics as $position => $metric) {
            $totals[$metric] = isset($total[$position]['value'])
                ? (float) $total[$position]['value']
                : array_sum(array_map(fn (array $row): float => $row['metrics'][$position] ?? 0, $rows));
        }

        return new AnalyticsReport($property['property'], $property['name'], $dimensions, $metrics, $rows, $totals);
    }
}
