<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\ConfigUrlDetector;
use FlowEngine\Application\AppMap\IntegrationDetector;
use FlowEngine\Domain\Flow\Flow;
use PHPUnit\Framework\TestCase;

final class ConfigUrlDetectorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-config-urls-' . uniqid();
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function test_reads_env_example_with_key_and_without_path_or_query(): void
    {
        $this->write('.env.example', "APP_NAME=x\nAPI_PARCEIRO_URL=https://api.parceiro.example.com:8443/v1/clientes?token=abc\n");

        $calls = $this->detect();

        self::assertCount(1, $calls);
        $edge = $calls[0]->toArray();
        self::assertSame('http', $edge['type']);
        self::assertSame('file:.env.example', $edge['fromNode']);
        self::assertSame(2, $edge['fromLine']);
        self::assertSame('https://api.parceiro.example.com:8443', $edge['target']);
        self::assertSame('api.parceiro.example.com', $edge['metadata']['host']);
        self::assertSame(8443, $edge['metadata']['port']);
        self::assertSame('', $edge['metadata']['path']);
        self::assertSame('', $edge['metadata']['query']);
        self::assertSame('config', $edge['metadata']['source']);
        self::assertSame('API_PARCEIRO_URL', $edge['metadata']['configKey']);
        self::assertArrayNotHasKey('commented', $edge['metadata']);
    }

    public function test_never_reads_real_env_files(): void
    {
        $this->write('.env', "API_URL=https://real.example.com\n");
        $this->write('.env.production', "API_URL=https://prod.example.com\n");
        $this->write('.env.local', "API_URL=https://local.example.com\n");

        self::assertSame([], $this->detect());
    }

    public function test_reads_every_example_suffix_in_nested_folders(): void
    {
        $this->write('.env.dist', "A_URL=https://a.example.com\n");
        $this->write('robo/.env.sample', "B_URL=https://b.example.com\n");
        $this->write('robo/.env.homolog.example', "C_URL=https://c.example.com\n");

        self::assertSame(['a.example.com', 'c.example.com', 'b.example.com'], $this->hosts($this->detect()));
    }

    public function test_commented_env_line_is_kept_and_flagged(): void
    {
        $this->write('.env.example', "# Producao: https://prod.parceiro.example.com/oauth2/token\n# OLD_API_URL=https://old.example.com\n");

        $calls = $this->detect();

        self::assertSame(['prod.parceiro.example.com', 'old.example.com'], $this->hosts($calls));
        self::assertTrue($calls[0]->metadata['commented']);
        self::assertArrayNotHasKey('configKey', $calls[0]->metadata);
        self::assertSame('OLD_API_URL', $calls[1]->metadata['configKey']);
    }

    public function test_skips_secret_keys_but_not_url_of_a_token_endpoint(): void
    {
        $this->write('.env.example', implode("\n", [
            'CLIENT_SECRET=https://leak.example.com',
            'API_KEY=https://leak2.example.com',
            'DB_PASSWORD=https://leak3.example.com',
            'DATAPREV_URL_TOKEN=https://token.example.com/oauth2/token',
            'AWS_ACCESS_KEY_ID=https://leak4.example.com',
            'stripeApiKey=https://leak5.example.com',
            'PASSPORT_SERVER=https://auth.example.com',
            'KEYCLOAK_BASE=https://sso.example.com',
        ]) . "\n");

        self::assertSame(['token.example.com', 'auth.example.com', 'sso.example.com'], $this->hosts($this->detect()));
    }

    public function test_splits_url_lists_and_skips_interpolated_hosts(): void
    {
        $this->write('.env.example', "CORS_ORIGINS=https://a.example.com,https://b.example.com\nAPP_URL=https://\${APP_HOST}/x\n");

        self::assertSame(['a.example.com', 'b.example.com'], $this->hosts($this->detect()));
    }

    public function test_kind_uses_the_path_before_it_is_dropped(): void
    {
        $this->write('.env.example', "LOGO_URL=https://static.example.com/img/logo.png\n");

        $calls = $this->detect();

        self::assertSame('asset', $calls[0]->metadata['kind']);
        self::assertSame('', $calls[0]->metadata['path']);
    }

    public function test_reads_laravel_config_using_env_name_as_key_and_ignoring_comments(): void
    {
        $this->write('artisan', "#!/usr/bin/env php\n");
        $this->write('config/queue.php', <<<'PHP'
<?php
return [
    // 'old' => 'https://comentado.example.com',
    'sqs' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
        'secret' => env('AWS_SECRET', 'https://never.example.com'),
    ],
    'docs' => 'https://docs.example.com',
];
PHP);

        $calls = $this->detect();

        self::assertSame(['sqs.us-east-1.amazonaws.com', 'docs.example.com'], $this->hosts($calls));
        self::assertSame('SQS_PREFIX', $calls[0]->metadata['configKey']);
        self::assertSame('docs', $calls[1]->metadata['configKey']);
        self::assertSame('file:config/queue.php', $calls[0]->fromNodeId);
    }

    public function test_config_php_outside_a_laravel_app_is_not_read(): void
    {
        $this->write('config/app.php', "<?php return ['url' => 'https://x.example.com'];\n");

        self::assertSame([], $this->detect());
    }

    public function test_reads_ini_skipping_comments_and_dependency_folders(): void
    {
        $this->write('src/config.ini', "[api]\n; antigo = https://comentado.example.com\napi_novo = https://api.interno.example.com/averbar/?x=1\n");
        $this->write('vendor/pkg/config.ini', "url = https://vendor.example.com\n");
        $this->write('node_modules/pkg/.env.example', "URL=https://npm.example.com\n");
        $this->write('.claude/worktrees/x/.env.example', "URL=https://worktree.example.com\n");

        $calls = $this->detect();

        self::assertSame(['api.interno.example.com'], $this->hosts($calls));
        self::assertSame('api_novo', $calls[0]->metadata['configKey']);
        self::assertSame(3, $calls[0]->fromLine);
    }

    public function test_file_already_in_scan_is_not_read_twice(): void
    {
        $this->write('artisan', '');
        $file = $this->write('config/services.php', "<?php return ['url' => 'https://x.example.com'];\n");

        self::assertSame([], (new ConfigUrlDetector())->detect($this->base, [$file]));
        self::assertCount(1, (new IntegrationDetector())->detect(new Flow([], []), $this->base, [$file]));
    }

    public function test_scanned_file_that_is_not_source_is_still_read(): void
    {
        $file = $this->write('src/config.ini', "api = https://x.example.com\n");

        $calls = (new IntegrationDetector())->detect(new Flow([], []), $this->base, [$file]);

        self::assertSame(['x.example.com'], $this->hosts($calls));
    }

    public function test_integration_detector_includes_config_edges(): void
    {
        $this->write('.env.example', "PARCEIRO_URL=https://parceiro.example.com\n");

        $calls = (new IntegrationDetector())->detect(new Flow([], []), $this->base, []);

        self::assertSame(['parceiro.example.com'], $this->hosts($calls));
    }

    /**
     * @return \FlowEngine\Application\AppMap\IntegrationCall[]
     */
    private function detect(): array
    {
        return (new ConfigUrlDetector())->detect($this->base, []);
    }

    /**
     * @param \FlowEngine\Application\AppMap\IntegrationCall[] $calls
     * @return string[]
     */
    private function hosts(array $calls): array
    {
        return array_map(fn($c) => $c->metadata['host'], $calls);
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}
