<?php

namespace Tests\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Infrastructure\Analyzer\GoParser;
use PHPUnit\Framework\TestCase;

final class GoParserTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/go-parser-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTempFile(string $name, string $content): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function makeParser(): GoParser
    {
        return new GoParser(new DefaultNodeFactory(), $this->tempDir);
    }

    // -------------------------------------------------------------------------

    public function test_detects_exported_package_function(): void
    {
        $file = $this->createTempFile('handlers.go', <<<'GO'
package main

func Hello(w http.ResponseWriter, r *http.Request) {
    fmt.Fprintln(w, "Hello")
}
GO);

        $result = $this->makeParser()->parse($file);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        self::assertContains('go:main::Hello', $ids);
    }

    public function test_detects_struct_method(): void
    {
        $file = $this->createTempFile('service.go', <<<'GO'
package users

type UserService struct{}

func (s *UserService) GetUser(id string) *User {
    return nil
}
GO);

        $result = $this->makeParser()->parse($file);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        self::assertContains('go:users.UserService::GetUser', $ids);
    }

    public function test_package_name_in_node_id(): void
    {
        $file = $this->createTempFile('api.go', <<<'GO'
package api

func HandleRequest(w http.ResponseWriter, r *http.Request) {
    return
}
GO);

        $result = $this->makeParser()->parse($file);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        self::assertContains('go:api::HandleRequest', $ids);
    }

    public function test_nested_directories_disambiguate_equal_package_and_function_names(): void
    {
        $firstDir = $this->tempDir . '/services/automerge';
        $secondDir = $this->tempDir . '/services/shared/automerge';
        mkdir($firstDir, 0777, true);
        mkdir($secondDir, 0777, true);
        $source = "package automerge\n\nfunc TestMain() {}\n";
        file_put_contents($firstDir . '/main_test.go', $source);
        file_put_contents($secondDir . '/main_test.go', $source);

        $parser = $this->makeParser();
        $firstIds = array_map(static fn($node): string => $node->id(), $parser->parse($firstDir . '/main_test.go')['nodes']);
        $secondIds = array_map(static fn($node): string => $node->id(), $parser->parse($secondDir . '/main_test.go')['nodes']);

        self::assertContains('go:~path~.services.automerge::TestMain', $firstIds);
        self::assertContains('go:~path~.services.shared.automerge::TestMain', $secondIds);
        self::assertNotSame($firstIds, $secondIds);
    }

    public function test_directory_encoding_is_collision_free(): void
    {
        $hyphenDirectory = $this->tempDir . '/foo-bar';
        $underscoreDirectory = $this->tempDir . '/foo_bar';
        mkdir($hyphenDirectory, 0777, true);
        mkdir($underscoreDirectory, 0777, true);
        file_put_contents($hyphenDirectory . '/main.go', "package first\n\nfunc Run() {}\n");
        file_put_contents($underscoreDirectory . '/main.go', "package second\n\nfunc Run() {}\n");

        $parser = $this->makeParser();
        $hyphenId = $parser->parse($hyphenDirectory . '/main.go')['nodes'][0]->id();
        $underscoreId = $parser->parse($underscoreDirectory . '/main.go')['nodes'][0]->id();

        self::assertSame('go:~path~.foo_2Dbar@first::Run', $hyphenId);
        self::assertSame('go:~path~.foo_5Fbar@second::Run', $underscoreId);
        self::assertNotSame($hyphenId, $underscoreId);
    }

    public function test_external_test_package_in_same_directory_has_distinct_id(): void
    {
        $directory = $this->tempDir . '/pkg/auth';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/auth.go', "package auth\n\nfunc New() {}\n");
        file_put_contents($directory . '/auth_test.go', "package auth_test\n\nfunc New() {}\n");

        $parser = $this->makeParser();
        $productionId = $parser->parse($directory . '/auth.go')['nodes'][0]->id();
        $externalTestId = $parser->parse($directory . '/auth_test.go')['nodes'][0]->id();

        self::assertSame('go:~path~.pkg.auth::New', $productionId);
        self::assertSame('go:~path~.pkg.auth@auth_5Ftest::New', $externalTestId);
        self::assertNotSame($productionId, $externalTestId);
    }

    public function test_root_package_and_same_named_directory_have_distinct_ids(): void
    {
        $rootFile = $this->tempDir . '/root.go';
        $nestedDirectory = $this->tempDir . '/cmd';
        mkdir($nestedDirectory, 0777, true);
        file_put_contents($rootFile, "package cmd\n\nfunc Run() {}\n");
        file_put_contents($nestedDirectory . '/file.go', "package cmd\n\nfunc Run() {}\n");

        $parser = $this->makeParser();
        $rootId = $parser->parse($rootFile)['nodes'][0]->id();
        $nestedId = $parser->parse($nestedDirectory . '/file.go')['nodes'][0]->id();

        self::assertSame('go:cmd::Run', $rootId);
        self::assertSame('go:~path~.cmd::Run', $nestedId);
        self::assertNotSame($rootId, $nestedId);
    }

    public function test_constructor_remains_compatible_without_project_root(): void
    {
        $file = $this->createTempFile('compat.go', "package compat\n\nfunc Run() {}\n");
        $parser = new GoParser(new DefaultNodeFactory());

        self::assertSame('go:compat::Run', $parser->parse($file)['nodes'][0]->id());
    }

    public function test_stores_http_handler_metadata(): void
    {
        $file = $this->createTempFile('router.go', <<<'GO'
package main

func main() {
    http.HandleFunc("/api/users", GetUsers)
}

func GetUsers(w http.ResponseWriter, r *http.Request) {
    fmt.Fprintln(w, "[]")
}
GO);

        $result = $this->makeParser()->parse($file);

        $nodeMap = [];
        foreach ($result['nodes'] as $node) {
            $nodeMap[$node->id()] = $node;
        }

        $node = $nodeMap['go:main::GetUsers'] ?? null;
        self::assertNotNull($node, 'Node GetUsers not found');
        self::assertNotNull($node->metadata());
        self::assertSame('/api/users', $node->metadata()['http_path']);
    }

    public function test_stores_gin_route_metadata(): void
    {
        $file = $this->createTempFile('gin_router.go', <<<'GO'
package main

func SetupRouter(r *gin.Engine) {
    r.GET("/products", ListProducts)
}

func ListProducts(c *gin.Context) {
    c.JSON(200, gin.H{})
}
GO);

        $result = $this->makeParser()->parse($file);

        $nodeMap = [];
        foreach ($result['nodes'] as $node) {
            $nodeMap[$node->id()] = $node;
        }

        $node = $nodeMap['go:main::ListProducts'] ?? null;
        self::assertNotNull($node, 'Node ListProducts not found');
        self::assertNotNull($node->metadata());
        self::assertSame('GET', $node->metadata()['http_method']);
        self::assertSame('/products', $node->metadata()['http_path']);
    }

    public function test_unexported_function_not_detected(): void
    {
        $file = $this->createTempFile('private.go', <<<'GO'
package main

func helper() string {
    return "private"
}

func anotherHelper(x int) int {
    return x * 2
}
GO);

        $result = $this->makeParser()->parse($file);

        self::assertSame([], $result['nodes'], 'Unexported functions should not become nodes');
    }

    public function test_handles_empty_file(): void
    {
        $file = $this->createTempFile('empty.go', '');

        $result = $this->makeParser()->parse($file);

        self::assertSame([], $result['nodes']);
        self::assertSame([], $result['edges']);
    }
}
