<?php

namespace Tests\Infrastructure\Config;

use FlowEngine\Infrastructure\Config\JsonProjectConfig;
use FlowEngine\Infrastructure\Config\SchemaValidator;
use FlowEngine\Infrastructure\Context\DefaultProjectContextFactory;
use FlowEngine\Infrastructure\Context\StaticProjectContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FlowEngine\Infrastructure\Config\JsonProjectConfig
 */
final class JsonProjectConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flow-engine-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $json = $this->tmpDir . '/flow-engine.json';
        if (file_exists($json)) {
            unlink($json);
        }
        rmdir($this->tmpDir);
    }

    private function validator(): SchemaValidator
    {
        return new SchemaValidator(
            __DIR__ . '/../../../schema/flow-engine.v1.json'
        );
    }

    public function test_sem_arquivo_instancia_sem_excecao(): void
    {
        $config = new JsonProjectConfig($this->tmpDir, $this->validator());

        $this->assertInstanceOf(JsonProjectConfig::class, $config);
    }

    public function test_sem_arquivo_retorna_defaults(): void
    {
        $config = new JsonProjectConfig($this->tmpDir, $this->validator());

        $this->assertSame('1.0', $config->version());
        $this->assertSame('composer', $config->contextType());
        $this->assertSame(['src'], $config->scanInclude());
        $this->assertSame([], $config->scanExclude());
        $this->assertSame(['php'], $config->scanExtensions());
        $this->assertNull($config->autoloadPath());
        $this->assertSame([], $config->nodeVisibilityRules());
        $this->assertSame('public', $config->defaultNodeVisibility());
        $this->assertSame([], $config->architectureLayers());
        $this->assertSame([], $config->entrypointPatterns());
        $this->assertNull($config->snapshotRetention());
        $this->assertSame([], $config->flutterConfig());
        $this->assertSame($this->tmpDir, $config->rootPath());
    }

    public function test_com_arquivo_le_dados_do_arquivo(): void
    {
        $data = [
            'version' => '1.0',
            'context' => ['type' => 'composer'],
            'scan'    => [
                'include'    => ['app', 'tests'],
                'exclude'    => ['vendor'],
                'extensions' => ['php'],
            ],
        ];

        file_put_contents(
            $this->tmpDir . '/flow-engine.json',
            json_encode($data)
        );

        $config = new JsonProjectConfig($this->tmpDir, $this->validator());

        $this->assertSame('1.0', $config->version());
        $this->assertSame('composer', $config->contextType());
        $this->assertSame(['app', 'tests'], $config->scanInclude());
        $this->assertSame(['vendor'], $config->scanExclude());
    }

    public function test_com_arquivo_invalido_lanca_excecao(): void
    {
        file_put_contents($this->tmpDir . '/flow-engine.json', 'not-json');

        $this->expectException(\RuntimeException::class);

        new JsonProjectConfig($this->tmpDir, $this->validator());
    }

    /**
     * @return array<string, array{string, string[]}>
     */
    public static function staticTypes(): array
    {
        return [
            'python' => ['python', ['py']],
            'typescript' => ['typescript', ['ts', 'tsx', 'js', 'jsx']],
        ];
    }

    /**
     * @param string[] $extensions
     */
    #[DataProvider('staticTypes')]
    public function test_tipos_estaticos_tem_extensoes_default_e_contexto_sem_boot(string $type, array $extensions): void
    {
        file_put_contents($this->tmpDir . '/flow-engine.json', (string) json_encode([
            'version' => '1.0',
            'context' => ['type' => $type],
            'scan' => ['include' => ['src']],
        ]));

        $config = new JsonProjectConfig($this->tmpDir, $this->validator());
        $context = (new DefaultProjectContextFactory())->create($config);

        $this->assertSame($extensions, $config->scanExtensions());
        $this->assertInstanceOf(StaticProjectContext::class, $context);
        $this->assertSame($extensions, $context->extensions());
        $context->boot();
    }
}
