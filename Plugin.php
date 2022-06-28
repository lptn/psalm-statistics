<?php

declare(strict_types=1);

namespace Lptn\PsalmStatisticsPlugin;

use Psalm\Config;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Plugin\EventHandler\AfterAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterAnalysisEvent;

final class Plugin implements AfterAnalysisInterface
{
    /** @inheritDoc */
    public static function afterAnalysis(AfterAnalysisEvent $event): void
    {
        $url = getenv('PSALM_STATISTICS_ENDPOINT');
        if (! is_string($url)) {
            fwrite(\STDERR, 'Could not determinate endpoint to send Psalm statistics: ');
            fwrite(\STDERR, 'PSALM_STATISTICS_ENDPOINT environment variable is not set.' . PHP_EOL);
            return;
        }

        $payload = self::collectPayload($event);
        self::send($url, $payload);
    }

    /** @return array<string, mixed> */
    private static function collectPayload(AfterAnalysisEvent $event): array
    {
        $codebase = $event->getCodebase();

        /** @psalm-suppress PossiblyNullReference */
        $sourceControlInfo = $event->getSourceControlInfo() ? $event->getSourceControlInfo()->toArray() : [];

        /** @var list<\Psalm\Internal\Analyzer\IssueData> $issues */
        $issues = array_merge(...array_values($event->getIssues()));
        $errors = array_filter($issues, static fn (IssueData $i) => $i->severity === Config::REPORT_ERROR);
        $infos = array_filter($issues, static fn (IssueData $i) => $i->severity === Config::REPORT_INFO);
        $suppressions = array_filter($issues, static fn (IssueData $i) => $i->severity === Config::REPORT_SUPPRESS);
        $ciContext = $event->getBuildInfo();
        unset($ciContext['git']); // deduplicate git info available in $sourceControlInfo

        // Keep it compatible with \Psalm\Plugin\Shepherd
        return [
            // Shepherd data:
            'build' => $ciContext,
            'git' => $sourceControlInfo,
            'issues' => $errors,
            'coverage' => $codebase->analyzer->getTotalTypeCoverage($codebase),
            'level' => Config::getInstance()->level,

            // Custom data
            'issue_types' => [
                Config::REPORT_INFO => count($infos),
                Config::REPORT_SUPPRESS => count($suppressions),
                Config::REPORT_ERROR => count($errors),
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function send(string $url, array $payload): void
    {
        if (!function_exists('curl_init')) {
            fwrite(\STDERR, 'No curl found, cannot send data to ' . $url . PHP_EOL);

            return;
        }

        $payloadAsString = json_encode($payload, \JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payloadAsString),
        ];

        // Prepare new cURL resource
        $ch = curl_init($url);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, \CURLOPT_POST, true);
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $payloadAsString);
        curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);

        // Submit the POST request
        $curlExecResult = curl_exec($ch);
        curl_close($ch);

        $isSentFailed = $curlExecResult === false;
        if ($isSentFailed) {
            fwrite(\STDERR, 'Error with Psalm statistics: ' . PHP_EOL);
            fwrite(\STDERR, self::getCurlErrorMessage($ch) . PHP_EOL);
            return;
        }

        fwrite(\STDOUT, "Psalm statistics sent to $url" . PHP_EOL);
    }

    /**
     * @param resource|\CurlHandle $ch
     *
     * @psalm-pure
     */
    private static function getCurlErrorMessage($ch): string
    {
        return \Psalm\Plugin\Shepherd::getCurlErrorMessage($ch);
    }
}
