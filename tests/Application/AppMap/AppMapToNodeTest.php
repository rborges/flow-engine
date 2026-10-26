<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Application\AppMap\ServiceInfo;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use PHPUnit\Framework\TestCase;

final class AppMapToNodeTest extends TestCase
{
    public function test_http_edge_points_to_the_laravel_route_or_legacy_file_that_serves_it(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-to-node-' . uniqid();
        $callerRoot = $base . '/caller';
        $laravelRoot = $base . '/laravel';
        $legacyRoot = $base . '/legado';
        foreach ([$callerRoot, $laravelRoot . '/routes', $legacyRoot . '/www'] as $dir) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($laravelRoot . '/artisan', '');
        file_put_contents($laravelRoot . '/routes/api.php', <<<'PHP'
            <?php
            use App\Http\Controllers\Propostas;
            Route::controller(Propostas::class)->prefix('propostas')->group(function () {
                Route::get('{id}', 'mostrar');
                Route::get('pendentes', 'pendentes');
                Route::delete('{id}/itens/{item?}', 'apagar');
            });
            Route::get('/', [Propostas::class, 'raiz']);
            PHP);
        $legacyFile = $legacyRoot . '/www/documentosApi.php';
        file_put_contents($legacyFile, "<?php\necho 1;\n");

        $callerFile = $callerRoot . '/Cliente.php';
        file_put_contents($callerFile, <<<'PHP'
            <?php
            class Cliente {
                public function chamar() {
                    $a = 'http://laravel/api/propostas/pendentes';
                    $b = 'http://laravel/api/propostas/42';
                    $c = 'http://laravel/api/propostas/42/itens';
                    $d = 'http://laravel/';
                    $e = 'http://laravel/api/nada/aqui';
                    $f = 'http://legado/documentosApi.php?ctn=1';
                    $g = 'http://legado';
                }
            }
            PHP);

        $caller = new ServiceInfo('caller', $callerRoot, new Flow([new Node('Cliente', 'chamar', $callerFile, 3, 'php')], []), [$callerFile]);
        $laravel = new ServiceInfo('laravel', $laravelRoot, new Flow([], []), [], ['laravel']);
        $legacy = new ServiceInfo('legado', $legacyRoot, new Flow([
            new Node('www/documentosApi.php', '', $legacyFile, null, 'php', null, Node::TYPE_FILE),
        ], []), [$legacyFile], ['legado']);

        $map = (new ApplicationMapBuilder())->build([$caller, $laravel, $legacy]);

        $toNode = [];
        foreach ($map['integrationEdges'] as $edge) {
            $toNode[$edge['target']] = $edge['toNode'];
        }

        self::assertSame('App\Http\Controllers\Propostas::pendentes', $toNode['http://laravel/api/propostas/pendentes'], 'literal vence parâmetro');
        self::assertSame('App\Http\Controllers\Propostas::mostrar', $toNode['http://laravel/api/propostas/42']);
        self::assertSame('App\Http\Controllers\Propostas::apagar', $toNode['http://laravel/api/propostas/42/itens'], 'parâmetro opcional no fim');
        self::assertNull($toNode['http://laravel/'], 'URL base não casa com a rota raiz');
        self::assertNull($toNode['http://laravel/api/nada/aqui']);
        self::assertSame('file:www/documentosApi.php', $toNode['http://legado/documentosApi.php?ctn=1']);
        self::assertNull($toNode['http://legado']);

        $laravelEntry = array_values(array_filter($map['services'], fn (array $s) => $s['id'] === 'laravel'))[0];
        self::assertCount(4, $laravelEntry['endpoints']);
    }

    public function test_http_method_must_match_when_the_call_names_it(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-to-node-method-' . uniqid();
        mkdir($base . '/laravel/routes', 0777, true);
        file_put_contents($base . '/laravel/artisan', '');
        file_put_contents($base . '/laravel/routes/api.php', "<?php\nRoute::post('itens', [App\\Itens::class, 'criar']);\nRoute::any('livre', [App\\Itens::class, 'livre']);\n");

        $builder = new ApplicationMapBuilder();
        $match = new \ReflectionMethod($builder, 'matchEndpoint');
        $endpoints = (new \FlowEngine\Application\AppMap\LaravelRouteCollector())->collect($base . '/laravel');

        self::assertNull($match->invoke($builder, ['path' => '/api/itens', 'httpMethod' => 'get'], $endpoints));
        self::assertSame('App\Itens::criar', $match->invoke($builder, ['path' => '/api/itens', 'httpMethod' => 'post'], $endpoints));
        self::assertSame('App\Itens::criar', $match->invoke($builder, ['path' => '/api/itens'], $endpoints));
        self::assertSame('App\Itens::livre', $match->invoke($builder, ['path' => '/api/livre', 'httpMethod' => 'DELETE'], $endpoints));
        self::assertSame('App\Itens::criar', $match->invoke($builder, ['path' => '/API/Itens/'], $endpoints));
        self::assertNull($match->invoke($builder, ['path' => '/api/itens/extra'], $endpoints));
    }

    public function test_trailing_slash_stands_for_a_segment_concatenated_at_runtime(): void
    {
        $endpoints = [
            ['method' => 'GET', 'path' => '/propostas', 'handler' => 'App\P::listar'],
            ['method' => 'DELETE', 'path' => '/propostas/{id}', 'handler' => 'App\P::apagar'],
        ];
        $builder = new ApplicationMapBuilder();
        $match = new \ReflectionMethod($builder, 'matchEndpoint');

        self::assertSame('App\P::apagar', $match->invoke($builder, ['path' => '/propostas/'], $endpoints));
        self::assertSame('App\P::listar', $match->invoke($builder, ['path' => '/propostas'], $endpoints));
    }

    public function test_runtime_segment_prefers_parameter_and_base_url_does_not_hit_literal_route(): void
    {
        $endpoints = [
            ['method' => 'GET', 'path' => '/users/export', 'handler' => 'App\U::export'],
            ['method' => 'GET', 'path' => '/users/{id}', 'handler' => 'App\U::show'],
            ['method' => 'GET', 'path' => '/api/users', 'handler' => 'App\U::index'],
        ];
        $builder = new ApplicationMapBuilder();
        $match = new \ReflectionMethod($builder, 'matchEndpoint');

        self::assertSame('App\U::show', $match->invoke($builder, ['path' => '/users/${id}'], $endpoints));
        self::assertSame('App\U::export', $match->invoke($builder, ['path' => '/users/export'], $endpoints));
        self::assertNull($match->invoke($builder, ['path' => '/api/'], $endpoints));
    }
}
