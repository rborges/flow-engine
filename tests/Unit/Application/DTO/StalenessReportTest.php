<?php

namespace Tests\Unit\Application\DTO;

use FlowEngine\Application\DTO\StalenessReport;
use PHPUnit\Framework\TestCase;

class StalenessReportTest extends TestCase
{
    public function testNotStaleReportHasCorrectShape(): void
    {
        $report = new StalenessReport(false, [], [], [], 0);

        $this->assertFalse($report->stale);
        $this->assertSame([], $report->changedFiles);
        $this->assertSame([], $report->newFiles);
        $this->assertSame([], $report->deletedFiles);
        $this->assertSame(0, $report->totalChanged);
    }

    public function testSummaryWarningReturnsEmptyStringWhenNotStale(): void
    {
        $report = new StalenessReport(false, [], [], [], 0);

        $this->assertSame('', $report->summaryWarning());
    }

    public function testSummaryWarningSingleFile(): void
    {
        $report = new StalenessReport(true, ['/app/src/Foo.php'], [], [], 1);

        $warning = $report->summaryWarning();

        $this->assertStringContainsString('1 project input changed since the previous cache', $warning);
        $this->assertStringContainsString('Foo.php', $warning);
        $this->assertStringContainsString('Cache refreshed automatically before producing these results.', $warning);
        $this->assertStringNotContainsString('and', $warning);
    }

    public function testSummaryWarningPluralFiles(): void
    {
        $report = new StalenessReport(true, ['/a/B.php', '/a/C.php'], ['/a/D.php'], [], 3);

        $warning = $report->summaryWarning();

        $this->assertStringStartsWith('3 project inputs changed', $warning);
        $this->assertStringContainsString('B.php', $warning);
        $this->assertStringContainsString('C.php', $warning);
        $this->assertStringContainsString('D.php', $warning);
    }

    public function testSummaryWarningTruncatesAfterFiveFiles(): void
    {
        $changed = [
            '/a/A.php',
            '/a/B.php',
            '/a/C.php',
            '/a/D.php',
            '/a/E.php',
        ];
        $new = ['/a/F.php', '/a/G.php'];
        $report = new StalenessReport(true, $changed, $new, [], 7);

        $warning = $report->summaryWarning();

        $this->assertStringStartsWith('7 project inputs changed', $warning);
        $this->assertStringContainsString('(and 2 more)', $warning);
        // Only first 5 basenames should appear explicitly
        $this->assertStringContainsString('A.php', $warning);
        $this->assertStringContainsString('E.php', $warning);
        $this->assertStringNotContainsString('F.php', $warning);
        $this->assertStringNotContainsString('G.php', $warning);
    }

    public function testSummaryWarningExactlyFiveFilesNoSuffix(): void
    {
        $changed = ['/a/A.php', '/a/B.php', '/a/C.php', '/a/D.php', '/a/E.php'];
        $report = new StalenessReport(true, $changed, [], [], 5);

        $warning = $report->summaryWarning();

        $this->assertStringNotContainsString('and', $warning);
        $this->assertStringContainsString('E.php', $warning);
    }

    public function testToArrayShape(): void
    {
        $report = new StalenessReport(true, ['/a/Foo.php'], [], ['/a/Bar.php'], 2);

        $arr = $report->toArray();

        $this->assertTrue($arr['stale']);
        $this->assertSame(['/a/Foo.php'], $arr['changedFiles']);
        $this->assertSame([], $arr['newFiles']);
        $this->assertSame(['/a/Bar.php'], $arr['deletedFiles']);
        $this->assertSame(2, $arr['totalChanged']);
        $this->assertSame(['changed' => 1, 'new' => 0, 'deleted' => 1, 'configuration' => 0], $arr['counts']);
        $this->assertFalse($arr['configChanged']);
        $this->assertFalse($arr['truncated']);
        $this->assertSame([['directory' => '/a', 'count' => 2]], $arr['directories']);
    }

    public function testToArrayTruncatesLargeFileListsAndKeepsCounts(): void
    {
        $changed = [];
        for ($i = 1; $i <= 25; $i++) {
            $changed[] = "/repo/src/File{$i}.php";
        }

        $report = new StalenessReport(true, $changed, [], [], 25);

        $arr = $report->toArray();

        $this->assertCount(20, $arr['changedFiles']);
        $this->assertSame(25, $arr['counts']['changed']);
        $this->assertTrue($arr['truncated']);
        $this->assertSame([['directory' => '/repo/src', 'count' => 25]], $arr['directories']);
    }

    public function testToJsonIsValidJson(): void
    {
        $report = new StalenessReport(true, ['/a/Foo.php'], [], [], 1);

        $json = $report->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['stale']);
        $this->assertSame(1, $decoded['totalChanged']);
    }

    public function testUsesBasenameInWarning(): void
    {
        $report = new StalenessReport(true, ['/very/long/path/to/MyFile.php'], [], [], 1);

        $warning = $report->summaryWarning();

        $this->assertStringContainsString('MyFile.php', $warning);
        $this->assertStringNotContainsString('/very/long/path/to/', $warning);
    }

    public function testConfigurationChangeIsVisibleWithoutSourceFiles(): void
    {
        $report = new StalenessReport(true, [], [], [], 1, true);

        $array = $report->toArray();

        $this->assertTrue($array['configChanged']);
        $this->assertSame(1, $array['counts']['configuration']);
        $this->assertFalse($array['truncated']);
        $this->assertStringContainsString('flow-engine.json', $report->summaryWarning());
    }
}
