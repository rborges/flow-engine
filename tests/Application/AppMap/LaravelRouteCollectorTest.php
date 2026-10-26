<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\LaravelRouteCollector;
use PHPUnit\Framework\TestCase;

final class LaravelRouteCollectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-laravel-' . uniqid();
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/artisan', "#!/usr/bin/env php\n");
    }

    public function test_without_artisan_returns_nothing(): void
    {
        unlink($this->root . '/artisan');
        $this->write('routes/api.php', "<?php\nRoute::get('x', [A::class, 'b']);\n");

        self::assertSame([], (new LaravelRouteCollector())->collect($this->root));
    }

    public function test_provider_groups_with_require_and_commented_prefix(): void
    {
        $this->write('app/Providers/RouteServiceProvider.php', <<<'PHP'
            <?php
            namespace App\Providers;
            use Illuminate\Support\Facades\Route;
            class RouteServiceProvider {
                public function boot() {
                    $this->routes(function () {
                        Route::middleware('api')
                            // ->prefix('api')
                            ->group(function () {
                                require base_path('routes/api.php');
                                require base_path('routes/Extra/api.extra.php');
                            });
                        Route::middleware('web')->group(base_path('routes/web.php'));
                        Route::prefix('ext-api')->group(function () {
                            require base_path('routes/api.externa.php');
                        });
                    });
                }
            }
            PHP);
        $this->write('routes/api.php', <<<'PHP'
            <?php
            use App\Http\Controllers\Pay\Emprestimo;
            use Illuminate\Support\Facades\Route;
            Route::controller(Emprestimo::class)->prefix('facilTech')->group(function () {
                Route::post('inserir', 'inserir');
                Route::delete('deletar/{proposta}', 'deletar')->name('x');
            });
            Route::get('closure', fn () => 1);
            PHP);
        $this->write('routes/Extra/api.extra.php', <<<'PHP'
            <?php
            use App\Http\Controllers\Geral;
            Route::middleware('auth')->prefix('simulador')->group(function () {
                Route::prefix('geral')->controller(Geral::class)->group(function () {
                    Route::get('produtos', 'getProdutos');
                });
            });
            PHP);
        $this->write('routes/web.php', "<?php\nRoute::get('/', [\\App\\Http\\Controllers\\Home::class, 'index']);\n");
        $this->write('routes/api.externa.php', <<<'PHP'
            <?php
            Route::group(['prefix' => 'v1', 'namespace' => 'App\Http\Controllers\Ext'], function () {
                Route::post('webhook', 'Hook@receber');
                Route::match(['get', 'post'], 'ping', 'Ping');
            });
            PHP);

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame('App\Http\Controllers\Pay\Emprestimo::inserir', $routes['POST /facilTech/inserir']);
        self::assertSame('App\Http\Controllers\Pay\Emprestimo::deletar', $routes['DELETE /facilTech/deletar/{proposta}']);
        self::assertSame('App\Http\Controllers\Geral::getProdutos', $routes['GET /simulador/geral/produtos']);
        self::assertSame('App\Http\Controllers\Home::index', $routes['GET /']);
        self::assertSame('App\Http\Controllers\Ext\Hook::receber', $routes['POST /ext-api/v1/webhook']);
        self::assertSame('App\Http\Controllers\Ext\Ping::__invoke', $routes['GET /ext-api/v1/ping']);
        self::assertSame('App\Http\Controllers\Ext\Ping::__invoke', $routes['POST /ext-api/v1/ping']);
        self::assertArrayNotHasKey('GET /closure', $routes);
    }

    public function test_provider_namespace_applies_only_through_the_chain(): void
    {
        $this->write('app/Providers/RouteServiceProvider.php', <<<'PHP'
            <?php
            class RouteServiceProvider {
                protected $namespace = 'App\Http\Controllers';
                public function map() {
                    Route::prefix('api')->namespace($this->namespace)->group(base_path('routes/api.php'));
                    Route::group([], base_path('routes/web.php'));
                }
            }
            PHP);
        $this->write('routes/api.php', "<?php\nRoute::get('users', 'UserController@index');\n");
        $this->write('routes/web.php', "<?php\nRoute::get('home', 'HomeController@show');\n");

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame('App\Http\Controllers\UserController::index', $routes['GET /api/users']);
        self::assertSame('HomeController::show', $routes['GET /home']);
    }

    public function test_resource_and_api_resource(): void
    {
        $this->write('routes/api.php', "<?php\nuse App\\Http\\Controllers\\PhotoController;\nRoute::apiResource('photos', PhotoController::class);\nRoute::resource('posts', PhotoController::class);\n");

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame('App\Http\Controllers\PhotoController::show', $routes['GET /api/photos/{photo}']);
        self::assertSame('App\Http\Controllers\PhotoController::destroy', $routes['DELETE /api/photos/{photo}']);
        self::assertArrayNotHasKey('GET /api/photos/create', $routes);
        self::assertSame('App\Http\Controllers\PhotoController::edit', $routes['GET /api/posts/{post}/edit']);
    }

    public function test_bootstrap_with_routing_and_api_prefix(): void
    {
        $this->write('bootstrap/app.php', <<<'PHP'
            <?php
            return Application::configure(basePath: dirname(__DIR__))
                ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', apiPrefix: 'v2')
                ->create();
            PHP);
        $this->write('routes/api.php', "<?php\nRoute::get('status', App\\Status::class);\n");
        $this->write('routes/web.php', "<?php\nRoute::get('sobre', [App\\Pagina::class, 'sobre']);\n");

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame('App\Status::__invoke', $routes['GET /v2/status']);
        self::assertSame('App\Pagina::sobre', $routes['GET /sobre']);
    }

    public function test_default_files_when_no_provider_or_bootstrap(): void
    {
        $this->write('routes/api.php', "<?php\nRoute::post('/pedidos/', [App\\Pedidos::class, 'criar']);\n");

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame(['POST /api/pedidos' => 'App\Pedidos::criar'], $routes);
    }

    public function test_include_cycle_and_syntax_error_do_not_break(): void
    {
        $this->write('routes/api.php', "<?php\nrequire __DIR__ . '/a.php';\nRoute::get('ok', [App\\A::class, 'ok']);\n");
        $this->write('routes/a.php', "<?php\nrequire __DIR__ . '/api.php';\nrequire __DIR__ . '/quebrado.php';\n");
        $this->write('routes/quebrado.php', "<?php\nRoute::get('x', \n");

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame(['GET /api/ok' => 'App\A::ok'], $routes);
    }

    public function test_legacy_array_action_conditional_blocks_and_last_definition_wins(): void
    {
        $this->write('routes/api.php', <<<'PHP'
            <?php
            Route::group(['namespace' => 'App\Http\Controllers'], function () {
                Route::get('home', ['as' => 'home', 'uses' => 'HomeController@index']);
                Route::get('fechado', ['middleware' => 'auth', function () {}]);
                if (app()->environment('local')) {
                    Route::get('debug', 'DebugController@show');
                }
                Route::get('status', 'StatusController@velho');
                Route::get('status', 'StatusController@novo');
            });
            PHP);

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame('App\Http\Controllers\HomeController::index', $routes['GET /api/home']);
        self::assertArrayNotHasKey('GET /api/fechado', $routes);
        self::assertSame('App\Http\Controllers\DebugController::show', $routes['GET /api/debug']);
        self::assertSame('App\Http\Controllers\StatusController::novo', $routes['GET /api/status']);
    }

    public function test_provider_array_namespace_and_nested_groups_compose(): void
    {
        $this->write('app/Providers/RouteServiceProvider.php', <<<'PHP'
            <?php
            class RouteServiceProvider {
                protected $namespace = 'App\Http\Controllers';
                public function map() {
                    Route::group(['middleware' => 'web', 'namespace' => $this->namespace], function ($router) {
                        require base_path('routes/web.php');
                    });
                    Route::prefix('api')->group(function () {
                        Route::prefix('v1')->namespace($this->namespace)->group(base_path('routes/v1.php'));
                    });
                }
            }
            PHP);
        $this->write('routes/web.php', "<?php\nRoute::get('users', 'UserController@index');\n");
        $this->write('routes/v1.php', "<?php\nRoute::get('itens', 'ItemController@index');\n");

        $routes = (new LaravelRouteCollector())->collect($this->root);

        self::assertSame([
            'GET /users' => 'App\Http\Controllers\UserController::index',
            'GET /api/v1/itens' => 'App\Http\Controllers\ItemController::index',
        ], $this->index($routes));
    }

    public function test_resource_only_except_nested_and_singular(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            use App\C;
            Route::resource('photos', C::class)->only(['index', 'show']);
            Route::apiResource('addresses', C::class)->except('destroy');
            Route::apiResource('photos.comments', C::class)->only('show');
            Route::apiResource('status', C::class)->only('show');
            Route::apiResource('categories', C::class)->only('show');
            PHP);

        $routes = $this->index((new LaravelRouteCollector())->collect($this->root));

        self::assertSame(['GET /photos', 'GET /photos/{photo}'], array_values(array_filter(array_keys($routes), fn ($k) => str_contains($k, ' /photos') && !str_contains($k, 'comments'))));
        self::assertArrayHasKey('PUT /addresses/{address}', $routes);
        self::assertArrayNotHasKey('DELETE /addresses/{address}', $routes);
        self::assertArrayHasKey('GET /photos/{photo}/comments/{comment}', $routes);
        self::assertArrayHasKey('GET /status/{status}', $routes);
        self::assertArrayHasKey('GET /categories/{category}', $routes);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * @param array<int, array{method: string, path: string, handler: string}> $routes
     * @return array<string, string>
     */
    private function index(array $routes): array
    {
        $index = [];
        foreach ($routes as $route) {
            $index[$route['method'] . ' ' . $route['path']] = $route['handler'];
        }

        return $index;
    }
}
