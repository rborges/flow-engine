<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\GitRevisionReader;
use PHPUnit\Framework\TestCase;

final class GitRevisionReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-git-revision-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                chmod($item->getPathname(), 0666);
                unlink($item->getPathname());
            }
        }
        rmdir($this->tmpDir);
    }

    public function test_reads_head_and_origin_without_credentials_from_a_subdirectory(): void
    {
        $this->git('init', '--quiet');
        mkdir($this->tmpDir . '/services/web', 0777, true);
        file_put_contents($this->tmpDir . '/services/web/a.php', "<?php\n");
        $this->git('add', '.');
        $this->commit();
        $this->git('remote', 'add', 'origin', 'https://someone:secret-token@git.example.invalid/team/web.git');

        $revision = (new GitRevisionReader())->read($this->tmpDir . '/services/web');

        self::assertSame('https://git.example.invalid/team/web.git', $revision['repository']);
        self::assertSame($this->git('rev-parse', 'HEAD'), $revision['commitSha']);
    }

    public function test_repository_without_origin_has_commit_only(): void
    {
        $this->git('init', '--quiet');
        file_put_contents($this->tmpDir . '/a.php', "<?php\n");
        $this->git('add', '.');
        $this->commit();

        $revision = (new GitRevisionReader())->read($this->tmpDir);

        self::assertNull($revision['repository']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $revision['commitSha']);
    }

    public function test_repository_without_commits_is_unknown(): void
    {
        $this->git('init', '--quiet');
        $this->git('remote', 'add', 'origin', 'https://git.example.invalid/team/web.git');

        self::assertSame(['repository' => null, 'commitSha' => null], (new GitRevisionReader())->read($this->tmpDir));
    }

    public function test_directory_outside_git_and_missing_directory_are_unknown(): void
    {
        $unknown = ['repository' => null, 'commitSha' => null];

        self::assertSame($unknown, (new GitRevisionReader())->read($this->tmpDir));
        self::assertSame($unknown, (new GitRevisionReader())->read($this->tmpDir . '/missing'));
    }

    public function test_strips_only_user_info_of_urls_with_scheme(): void
    {
        self::assertSame('ssh://bitbucket.org/ws/repo.git', GitRevisionReader::withoutCredentials('ssh://git@bitbucket.org/ws/repo.git'));
        self::assertSame('https://host.invalid:8443/r.git', GitRevisionReader::withoutCredentials('https://u:p@host.invalid:8443/r.git'));
        self::assertSame('git@bitbucket.org:ws/repo.git', GitRevisionReader::withoutCredentials('git@bitbucket.org:ws/repo.git'));
        self::assertSame('https://host.invalid/a@b/r.git', GitRevisionReader::withoutCredentials('https://host.invalid/a@b/r.git'));
        self::assertSame('https://host.invalid/r.git', GitRevisionReader::withoutCredentials('https://u:p@ss@host.invalid/r.git'));
    }

    public function test_disabled_reader_does_not_touch_git(): void
    {
        $this->git('init', '--quiet');
        file_put_contents($this->tmpDir . '/a.php', "<?php\n");
        $this->git('add', '.');
        $this->commit();

        self::assertSame(['repository' => null, 'commitSha' => null], GitRevisionReader::disabled()->read($this->tmpDir));
    }

    private function commit(): void
    {
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=', 'commit', '--quiet', '-m', 'init');
    }

    private function git(string ...$args): string
    {
        $command = 'git -C ' . escapeshellarg($this->tmpDir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($command, $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
