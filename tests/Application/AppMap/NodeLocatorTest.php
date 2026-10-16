<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\NodeLocator;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use PHPUnit\Framework\TestCase;

final class NodeLocatorTest extends TestCase
{
    private const FILE = 'C:\\project\\src\\Client.php';

    public function test_picks_innermost_node_whose_range_covers_the_line(): void
    {
        $flow = new Flow([
            $this->node('Outer', 'run', 3, 20),
            $this->node('Outer', 'inner', 6, 9, Node::TYPE_FUNCTION),
        ], []);

        self::assertSame('func:Outer\\inner', (new NodeLocator())->locate($flow, self::FILE, 7));
        self::assertSame('Outer::run', (new NodeLocator())->locate($flow, self::FILE, 12));
    }

    public function test_line_after_the_last_method_is_not_attributed_to_it(): void
    {
        $flow = new Flow([$this->node('Client', 'orders', 3, 6)], []);

        self::assertNull((new NodeLocator())->locate($flow, self::FILE, 8));
    }

    public function test_procedural_line_falls_back_to_the_file_node(): void
    {
        $flow = new Flow([
            new Node('src/Client.php', '__file', self::FILE, null, 'php', null, Node::TYPE_FILE),
            $this->node('Client', 'orders', 10, 14),
        ], []);

        self::assertSame('file:src/Client.php', (new NodeLocator())->locate($flow, self::FILE, 2));
        self::assertSame('Client::orders', (new NodeLocator())->locate($flow, self::FILE, 12));
        self::assertSame('file:src/Client.php', (new NodeLocator())->locate($flow, self::FILE, 20));
    }

    public function test_nodes_without_end_line_keep_the_nearest_preceding_rule(): void
    {
        $flow = new Flow([
            new Node('app', 'first', self::FILE, 1, 'python'),
            new Node('app', 'second', self::FILE, 5, 'python'),
        ], []);

        self::assertSame('python:app::second', (new NodeLocator())->locate($flow, self::FILE, 30));
    }

    public function test_paths_are_compared_ignoring_separator_and_drive_case(): void
    {
        $flow = new Flow([$this->node('Client', 'orders', 3, 6)], []);

        self::assertSame('Client::orders', (new NodeLocator())->locate($flow, 'c:/project/src/Client.php', 4));
    }

    private function node(string $class, string $method, int $line, int $endLine, string $type = Node::TYPE_METHOD): Node
    {
        return new Node($class, $method, self::FILE, $line, 'php', ['endLine' => $endLine], $type);
    }
}
