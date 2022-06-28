<?php

declare(strict_types=1);

namespace Lptn\PsalmStatisticsPlugin\Tests;

use Psalm\Codebase;
use Psalm\Config;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\Plugin\EventHandler\Event\AfterAnalysisEvent;
use Lptn\PsalmStatisticsPlugin\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testIt(): void
    {
        putenv('PSALM_STATISTICS_ENDPOINT=http://localhost');
        $event = $this->buildEvent();

        ob_start();
        Plugin::afterAnalysis($event);
        $output = ob_get_contents();
        ob_end_clean();

        $this->assertSame('', $output);
    }

    private function buildEvent(): AfterAnalysisEvent
    {
        $issue = new IssueData(
            Config::REPORT_ERROR,
            1,
            100,
            'type',
            'message',
            'file_name',
            'file_path',
            'file_snippet',
            'selected_text',
            0,
            0,
            0,
            0,
            0,
            0,
        );
        return new AfterAnalysisEvent(
            new Codebase($this->buildConfig(), new Providers(new FileProvider())),
            ['file.php' => [$issue]],
            [],
            null
        );
    }

    private function buildConfig(): Config
    {
        return \Psalm\Config::loadFromXML(
            dirname(__DIR__, 1),
            '<?xml version="1.0"?>
                <psalm
                    errorLevel="1"
                >
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                    <plugins>
                        <pluginClass class="\\Lptn\\PsalmStatisticsPlugin\\Plugin" />
                    </plugins>
                </psalm>'
        );
    }
}
