<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Cache;

use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Infrastructure\Cache\FlowSnapshotSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip dos tipos de nó pelo serializador (usado pelo FlowCache e pelos snapshots).
 *
 * Regressão: uma função global tem classe vazia, e string vazia é falsy em PHP.
 * O desserializador testava `if (!$class || !$method)` e, por isso, tratava toda
 * função global como payload legado, reconstruindo classe/método a partir do ID —
 * o que produzia nós corrompidos do tipo "func:func:nome\" e zerava o fan-in de
 * todas elas.
 */
final class FlowSnapshotSerializerNodeTypeTest extends TestCase
{
    private function roundTrip(Node $node): Node
    {
        $serializer = new FlowSnapshotSerializer();
        $data = $serializer->toArray(new Flow([$node], []));

        return $serializer->toFlow($data)->nodes()[0];
    }

    public function test_funcao_global_sobrevive_ao_round_trip(): void
    {
        $node = new Node('', 'formatavalor', __FILE__, 10, 'php', null, Node::TYPE_FUNCTION);
        $this->assertSame('func:formatavalor', $node->id());

        $restored = $this->roundTrip($node);

        $this->assertSame('func:formatavalor', $restored->id());
        $this->assertSame(Node::TYPE_FUNCTION, $restored->type());
        $this->assertSame('', $restored->class());
        $this->assertSame('php', $restored->language());
    }

    public function test_funcao_com_namespace_sobrevive_ao_round_trip(): void
    {
        $node = new Node('App', 'helper', __FILE__, 5, 'php', null, Node::TYPE_FUNCTION);

        $restored = $this->roundTrip($node);

        $this->assertSame('func:App\helper', $restored->id());
        $this->assertSame(Node::TYPE_FUNCTION, $restored->type());
    }

    public function test_no_de_arquivo_sobrevive_ao_round_trip(): void
    {
        $node = new Node('dashboard.php', '__file', __FILE__, null, 'php', null, Node::TYPE_FILE);

        $restored = $this->roundTrip($node);

        $this->assertSame('file:dashboard.php', $restored->id());
        $this->assertSame(Node::TYPE_FILE, $restored->type());
        $this->assertSame('php', $restored->language());
    }

    public function test_metodo_continua_inalterado(): void
    {
        $node = new Node('App\\UserService', 'getUser', __FILE__, 20);

        $restored = $this->roundTrip($node);

        $this->assertSame('App\UserService::getUser', $restored->id());
        $this->assertSame(Node::TYPE_METHOD, $restored->type());
    }
}
