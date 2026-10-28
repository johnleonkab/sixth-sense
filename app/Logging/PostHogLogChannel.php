<?php

namespace App\Logging;

use LogicException;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OpenTelemetryHandler;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;

class PostHogLogChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('posthog');
        $token = config('posthog.api_key');
        $host = config('posthog.host');

        if (config('posthog.disabled')) {
            $logger->pushHandler(new NullHandler);

            return $logger;
        }

        if (! filled($token) || ! filled($host)) {
            if (config('app.debug')) {
                $variable = ! filled($token) ? 'POSTHOG_PROJECT_TOKEN' : 'POSTHOG_HOST';

                throw new LogicException("{$variable} variable required by PostHog is missing or un-configured, this causes events to be silently missed. This error stops appearing once {$variable} is configured");
            }

            $logger->pushHandler(new NullHandler);

            return $logger;
        }

        $transport = (new OtlpHttpTransportFactory)->create(
            rtrim((string) $host, '/').'/i/v1/logs',
            'application/x-protobuf',
            ['Authorization' => 'Bearer '.$token],
        );
        $provider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new LogsExporter($transport)))
            ->build();

        $logger->pushHandler(new OpenTelemetryHandler($provider, Level::Info));

        return $logger;
    }
}
