<?php

namespace Tests\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Infrastructure\Analyzer\PythonParser;
use PHPUnit\Framework\TestCase;

final class PythonParserTest extends TestCase
{
    public function test_ignores_nested_definitions_and_deduplicates_property_accessors(): void
    {
        $root = sys_get_temp_dir() . '/python-parser-scope-' . uniqid();
        mkdir($root, 0777, true);
        $file = $root . '/scope.py';
        file_put_contents($file, <<<'PY'
def outer():
    class FakeClient:
        def fetch(self):
            return None
    def helper():
        return None
    notify()

def notify():
    pass

class Service:
    @property
    def value(self):
        return self._value

    @value.setter
    def value(self, new_value):
        self._value = new_value

    async def run(self):
        def nested():
            return None
        notify()
        class Local:
            def work(self):
                return None
        notify()
PY);

        try {
            $result = (new PythonParser(new DefaultNodeFactory(), $root))->parse($file);
            $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);
            $edgePairs = array_map(static fn($edge): array => [$edge->from(), $edge->to()], $result['edges']);

            self::assertContains('python:scope::outer', $ids);
            self::assertContains('python:scope.Service::value', $ids);
            self::assertContains('python:scope.Service::run', $ids);
            self::assertSame(1, count(array_filter($ids, static fn(string $id): bool => $id === 'python:scope.Service::value')));
            self::assertNotContains('python:scope.FakeClient::fetch', $ids);
            self::assertNotContains('python:scope.Service::helper', $ids);
            self::assertNotContains('python:scope.Service::nested', $ids);
            self::assertNotContains('python:scope.Local::work', $ids);
            self::assertContains(['python:scope::outer', 'python:scope::notify'], $edgePairs);
            self::assertSame(2, count(array_filter(
                $edgePairs,
                static fn(array $edge): bool => $edge === ['python:scope.Service::run', 'python:scope::notify']
            )));
        } finally {
            unlink($file);
            rmdir($root);
        }
    }

    public function test_keeps_module_control_block_classes_and_setter_dependencies(): void
    {
        $root = sys_get_temp_dir() . '/python-parser-conditional-' . uniqid();
        mkdir($root, 0777, true);
        $file = $root . '/conditional.py';
        file_put_contents($file, <<<'PY'
def notify():
    pass

class First:
    def one(self):
        pass

if True:
    class Service:
        @property
        def value(self):
            return self._value

        @value.setter
        def value(self, new_value):
            notify()
            self._value = new_value
PY);

        try {
            $result = (new PythonParser(new DefaultNodeFactory(), $root))->parse($file);
            $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);
            $edgePairs = array_map(static fn($edge): array => [$edge->from(), $edge->to()], $result['edges']);

            self::assertContains('python:conditional.Service::value', $ids);
            self::assertContains('python:conditional.First::one', $ids);
            self::assertSame(1, count(array_filter(
                $ids,
                static fn(string $id): bool => $id === 'python:conditional.Service::value'
            )));
            self::assertContains(
                ['python:conditional.Service::value', 'python:conditional::notify'],
                $edgePairs
            );
        } finally {
            unlink($file);
            rmdir($root);
        }
    }

    public function test_it_extracts_nodes_and_simple_edges(): void
    {
        $root = realpath(__DIR__ . '/../Fixtures/ExampleProject');
        self::assertNotFalse($root);

        $file = $root . '/App/src/sample.py';
        self::assertFileExists($file);

        $parser = new PythonParser(new DefaultNodeFactory(), $root);
        $result = $parser->parse($file);

        self::assertArrayHasKey('nodes', $result);
        self::assertArrayHasKey('edges', $result);

        $nodeIds = array_map(fn($n) => $n->id(), $result['nodes']);

        self::assertContains('python:App.src.sample::a', $nodeIds);
        self::assertContains('python:App.src.sample::b', $nodeIds);
        self::assertContains('python:App.src.sample.C::m', $nodeIds);

        $edgePairs = array_map(fn($e) => [$e->from(), $e->to()], $result['edges']);

        self::assertContains(
            ['python:App.src.sample::a', 'python:App.src.sample::b'],
            $edgePairs
        );
    }

    public function test_it_extracts_fastapi_decorator_metadata(): void
    {
        $root = realpath(__DIR__ . '/../Fixtures/ExampleProject');
        self::assertNotFalse($root);

        $file = $root . '/App/src/fastapi_app.py';
        self::assertFileExists($file);

        $parser = new PythonParser(new DefaultNodeFactory(), $root);
        $result = $parser->parse($file);

        $nodeIds = array_map(fn($n) => $n->id(), $result['nodes']);

        self::assertContains('python:App.src.fastapi_app::list_clients', $nodeIds);
        self::assertContains('python:App.src.fastapi_app::run_backup', $nodeIds);
        self::assertContains('python:App.src.fastapi_app::internal_helper', $nodeIds);
        self::assertContains('python:App.src.fastapi_app.BackupService::delete_backup', $nodeIds);

        // Check FastAPI metadata on decorated functions
        $nodeMap = [];
        foreach ($result['nodes'] as $node) {
            $nodeMap[$node->id()] = $node;
        }

        $listClients = $nodeMap['python:App.src.fastapi_app::list_clients'];
        self::assertNotNull($listClients->metadata());
        self::assertSame('GET', $listClients->metadata()['http_method']);
        self::assertSame('/clients', $listClients->metadata()['http_path']);

        $runBackup = $nodeMap['python:App.src.fastapi_app::run_backup'];
        self::assertNotNull($runBackup->metadata());
        self::assertSame('POST', $runBackup->metadata()['http_method']);
        self::assertSame('/backup/run', $runBackup->metadata()['http_path']);

        // Non-decorated function should have no metadata
        $helper = $nodeMap['python:App.src.fastapi_app::internal_helper'];
        self::assertNull($helper->metadata());

        // Class method with decorator
        $deleteBackup = $nodeMap['python:App.src.fastapi_app.BackupService::delete_backup'];
        self::assertNotNull($deleteBackup->metadata());
        self::assertSame('DELETE', $deleteBackup->metadata()['http_method']);
        self::assertSame('/backup/{backup_id}', $deleteBackup->metadata()['http_path']);
    }

    // --- v4.10: Flask, Click, Celery, Django CBV, __main__ ---

    private function loadFrameworkFixture(): array
    {
        $root = realpath(__DIR__ . '/../Fixtures/ExampleProject');
        self::assertNotFalse($root);

        $file = $root . '/App/src/framework_entrypoints.py';
        self::assertFileExists($file);

        $parser = new PythonParser(new DefaultNodeFactory(), $root);
        $result = $parser->parse($file);

        $nodeMap = [];
        foreach ($result['nodes'] as $node) {
            $nodeMap[$node->id()] = $node;
        }

        return $nodeMap;
    }

    public function test_it_detects_flask_route_get_default(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $node = $nodeMap["{$prefix}::list_users"] ?? null;
        self::assertNotNull($node, 'list_users node must exist');
        self::assertSame('http', $node->metadata()['entrypoint_type']);
        self::assertSame('GET', $node->metadata()['http_method']);
        self::assertSame('/users', $node->metadata()['http_path']);
    }

    public function test_it_detects_flask_route_with_methods(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $node = $nodeMap["{$prefix}::user_detail"] ?? null;
        self::assertNotNull($node, 'user_detail node must exist');
        self::assertSame('http', $node->metadata()['entrypoint_type']);
        self::assertStringContainsString('GET', $node->metadata()['http_method']);
        self::assertStringContainsString('PUT', $node->metadata()['http_method']);
        self::assertSame('/users/<int:user_id>', $node->metadata()['http_path']);
    }

    public function test_it_detects_click_command(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $sync = $nodeMap["{$prefix}::sync"] ?? null;
        self::assertNotNull($sync, 'sync node must exist');
        self::assertSame('cli', $sync->metadata()['entrypoint_type']);
        self::assertSame('click', $sync->metadata()['framework']);
    }

    public function test_it_detects_click_group(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $cli = $nodeMap["{$prefix}::cli"] ?? null;
        self::assertNotNull($cli, 'cli node must exist');
        self::assertSame('cli', $cli->metadata()['entrypoint_type']);
    }

    public function test_it_detects_typer_command(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $deploy = $nodeMap["{$prefix}::deploy"] ?? null;
        self::assertNotNull($deploy, 'deploy node must exist');
        self::assertSame('cli', $deploy->metadata()['entrypoint_type']);
    }

    public function test_it_detects_celery_shared_task(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $node = $nodeMap["{$prefix}::send_email"] ?? null;
        self::assertNotNull($node, 'send_email node must exist');
        self::assertSame('async', $node->metadata()['entrypoint_type']);
        self::assertSame('celery', $node->metadata()['framework']);
    }

    public function test_it_detects_celery_app_task(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $node = $nodeMap["{$prefix}::process_data"] ?? null;
        self::assertNotNull($node, 'process_data node must exist');
        self::assertSame('async', $node->metadata()['entrypoint_type']);
    }

    public function test_it_detects_celery_shared_task_with_args(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $node = $nodeMap["{$prefix}::retry_task"] ?? null;
        self::assertNotNull($node, 'retry_task node must exist');
        self::assertSame('async', $node->metadata()['entrypoint_type']);
    }

    public function test_it_detects_django_cbv_http_methods_by_class_name(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $get = $nodeMap["{$prefix}.UserView::get"] ?? null;
        self::assertNotNull($get, 'UserView::get must exist');
        self::assertSame('http', $get->metadata()['entrypoint_type']);
        self::assertSame('GET', $get->metadata()['http_method']);

        $post = $nodeMap["{$prefix}.UserView::post"] ?? null;
        self::assertNotNull($post, 'UserView::post must exist');
        self::assertSame('http', $post->metadata()['entrypoint_type']);
        self::assertSame('POST', $post->metadata()['http_method']);
    }

    public function test_django_cbv_non_http_methods_have_no_entrypoint_metadata(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $helper = $nodeMap["{$prefix}.UserView::non_http_method"] ?? null;
        self::assertNotNull($helper, 'UserView::non_http_method must exist');
        // Should NOT be marked as an HTTP entrypoint
        self::assertNull($helper->metadata()['entrypoint_type'] ?? null);
    }

    public function test_it_detects_django_cbv_by_parent_class_name(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $get = $nodeMap["{$prefix}.OrderDetail::get"] ?? null;
        self::assertNotNull($get, 'OrderDetail::get must exist');
        self::assertSame('http', $get->metadata()['entrypoint_type']);
    }

    public function test_plain_class_http_method_names_are_not_marked_as_entrypoints(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        // DataProcessor has a method named 'get' but it's not a Django View
        $get = $nodeMap["{$prefix}.DataProcessor::get"] ?? null;
        self::assertNotNull($get, 'DataProcessor::get must exist');
        self::assertNull($get->metadata()['entrypoint_type'] ?? null);
    }

    public function test_it_detects_main_block_as_script_entrypoint(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $main = $nodeMap["{$prefix}::__main__"] ?? null;
        self::assertNotNull($main, '__main__ synthetic node must exist');
        self::assertSame('script', $main->metadata()['entrypoint_type']);
    }

    public function test_internal_helper_has_no_entrypoint_metadata(): void
    {
        $nodeMap = $this->loadFrameworkFixture();
        $prefix = 'python:App.src.framework_entrypoints';

        $helper = $nodeMap["{$prefix}::internal_helper"] ?? null;
        self::assertNotNull($helper, 'internal_helper must exist');
        self::assertNull($helper->metadata());
    }
}
