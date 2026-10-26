<?php

namespace FlowEngine\Application\AppMap;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Rotas de um projeto Laravel lidas por AST, com prefixo, namespace e controller de grupo.
 *
 * Os arquivos de rota ficam fora do `scan.include` na maioria dos projetos, por isso são lidos direto da raiz.
 * Fora do alcance: rotas em `$router->get()` (Lumen) e rotas registradas por pacote.
 */
final class LaravelRouteCollector
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];
    private const MAX_DEPTH = 10;

    private Parser $parser;
    private NodeFinder $finder;
    /** `$this->namespace` do provider enquanto ele é lido; null fora dele. */
    private ?string $providerNamespace = null;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    /**
     * @return array<int, array{method: string, path: string, handler: string}>
     */
    public function collect(string $root): array
    {
        $root = rtrim($root, '/\\');
        if (!is_file($root . '/artisan')) {
            return [];
        }

        $routes = [];
        if (!$this->fromProvider($root, $routes)) {
            foreach ($this->bootstrapOrDefaultSources($root) as [$file, $group]) {
                $this->parseFile($file, $group, $root, $routes, []);
            }
        }

        // Mesmo método e caminho definidos duas vezes: no Laravel vale a última.
        $unique = [];
        foreach ($routes as $route) {
            $unique[$route['method'] . ' ' . $route['path']] = $route;
        }

        return array_values($unique);
    }

    /**
     * Percorre as cadeias `Route::...` mais externas do `RouteServiceProvider`. Devolve false sem provider ou sem cadeia.
     *
     * @param array<int, array{method: string, path: string, handler: string}> $routes
     */
    private function fromProvider(string $root, array &$routes): bool
    {
        $file = $root . '/app/Providers/RouteServiceProvider.php';
        $stmts = $this->parse($file);
        if ($stmts === null) {
            return false;
        }

        $namespace = '';
        foreach ($this->finder->findInstanceOf($stmts, Node\PropertyItem::class) as $prop) {
            if ($prop->name->toString() === 'namespace' && $prop->default instanceof String_) {
                $namespace = $prop->default->value;
            }
        }

        $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);
        $visitor = new class (fn (Expr $e): bool => $this->chain($e) !== null) extends NodeVisitorAbstract {
            /** @var Expr[] */
            public array $outer = [];

            public function __construct(private \Closure $isRoute)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Expression && ($this->isRoute)($node->expr)) {
                    $this->outer[] = $node->expr;
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($stmts);
        $outer = $visitor->outer;

        if ($outer === []) {
            return false;
        }

        $this->providerNamespace = $namespace;
        try {
            $context = ['file' => $file, 'root' => $root, 'uses' => $this->uses($stmts), 'stack' => []];
            foreach ($outer as $expr) {
                $this->expression($expr, self::group(''), $context, $routes);
            }
        } finally {
            $this->providerNamespace = null;
        }

        return true;
    }

    /**
     * Laravel 11+: `->withRouting(web: ..., api: ..., apiPrefix: ...)`; sem ele, `routes/web.php` e `routes/api.php`.
     *
     * @return array<int, array{0: string, 1: array{prefix: string, namespace: string, controller: ?string}}>
     */
    private function bootstrapOrDefaultSources(string $root): array
    {
        $sources = [];
        $file = $root . '/bootstrap/app.php';
        foreach ($this->finder->findInstanceOf($this->parse($file) ?? [], MethodCall::class) as $call) {
            if (!$this->isName($call->name, 'withRouting')) {
                continue;
            }
            $named = [];
            foreach ($this->args($call->args) as $arg) {
                if ($arg->name !== null) {
                    $named[$arg->name->toString()] = $arg->value;
                }
            }
            $apiPrefix = isset($named['apiPrefix']) && $named['apiPrefix'] instanceof String_ ? $named['apiPrefix']->value : 'api';
            foreach (['web' => '', 'api' => $apiPrefix] as $key => $prefix) {
                $path = isset($named[$key]) ? $this->evalPath($named[$key], $root, dirname($file)) : null;
                if ($path !== null) {
                    $sources[] = [$path, self::group($prefix)];
                }
            }
        }
        if ($sources !== []) {
            return $sources;
        }

        foreach (['web.php' => '', 'api.php' => 'api'] as $name => $prefix) {
            if (is_file($root . '/routes/' . $name)) {
                $sources[] = [$root . '/routes/' . $name, self::group($prefix)];
            }
        }

        return $sources;
    }

    /**
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<int, array{method: string, path: string, handler: string}> $routes
     * @param array<string, true> $stack
     */
    private function parseFile(string $file, array $group, string $root, array &$routes, array $stack): void
    {
        $real = realpath($file) ?: $file;
        if (isset($stack[$real]) || count($stack) >= self::MAX_DEPTH) {
            return;
        }
        $stmts = $this->parse($real);
        if ($stmts === null) {
            return;
        }
        $stack[$real] = true;

        $uses = $this->uses($stmts);
        $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);

        $this->walk($stmts, $group, ['file' => $real, 'root' => $root, 'uses' => $uses, 'stack' => $stack], $routes);
    }

    /**
     * Desce em `if`, `foreach`, `try` e afins: rota registrada sob condição também existe.
     *
     * @param Node[] $stmts
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array{file: string, root: string, uses: array<string, string>, stack: array<string, true>} $context
     * @param array<int, array{method: string, path: string, handler: string}> $routes
     */
    private function walk(array $stmts, array $group, array $context, array &$routes): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Expression) {
                $this->expression($stmt->expr, $group, $context, $routes);
                continue;
            }
            if (!$stmt instanceof Stmt || $stmt instanceof Stmt\ClassLike || $stmt instanceof Stmt\Function_) {
                continue;
            }
            foreach ($stmt->getSubNodeNames() as $name) {
                $sub = $stmt->$name;
                if ($sub instanceof Stmt) {
                    $sub = [$sub];
                }
                if (is_array($sub) && $sub !== [] && $sub[array_key_first($sub)] instanceof Stmt) {
                    $this->walk($sub, $group, $context, $routes);
                }
            }
        }
    }

    /**
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array{file: string, root: string, uses: array<string, string>, stack: array<string, true>} $context
     * @param array<int, array{method: string, path: string, handler: string}> $routes
     */
    private function expression(Expr $expr, array $group, array $context, array &$routes): void
    {
        if ($expr instanceof Expr\Include_) {
            $path = $this->evalPath($expr->expr, $context['root'], dirname($context['file']));
            if ($path !== null) {
                $this->parseFile($path, $group, $context['root'], $routes, $context['stack']);
            }
            return;
        }

        $chain = $this->chain($expr);
        if ($chain === null) {
            return;
        }

        foreach ($chain as $i => [$name, $args]) {
            if ($name === 'group') {
                $inner = $this->applyChain($group, array_slice($chain, 0, $i), $context['uses']);
                $body = null;
                foreach ($args as $arg) {
                    if ($arg->value instanceof Expr\Array_) {
                        $inner = $this->applyArray($inner, $arg->value, $context['uses']);
                    } elseif ($arg->value instanceof Closure) {
                        $body = $arg->value;
                    } elseif (($path = $this->evalPath($arg->value, $context['root'], dirname($context['file']))) !== null) {
                        $this->parseFile($path, $inner, $context['root'], $routes, $context['stack']);
                    }
                }
                if ($body !== null) {
                    $this->walk($body->stmts, $inner, $context, $routes);
                }
                return;
            }

            if (in_array($name, self::VERBS, true) || $name === 'match' || $name === 'resource' || $name === 'apiResource') {
                $inner = $this->applyChain($group, array_slice($chain, 0, $i), $context['uses']);
                $this->route($name, $args, array_slice($chain, $i + 1), $inner, $context['uses'], $routes);
                return;
            }
        }
    }

    /**
     * @param Arg[] $args
     * @param array<int, array{0: string, 1: Arg[]}> $after chamadas encadeadas depois do verbo (`->only()`, `->name()`)
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<string, string> $uses
     * @param array<int, array{method: string, path: string, handler: string}> $routes
     */
    private function route(string $verb, array $args, array $after, array $group, array $uses, array &$routes): void
    {
        if ($verb === 'resource' || $verb === 'apiResource') {
            $this->resource($verb, $args, $after, $group, $uses, $routes);
            return;
        }

        $methods = [strtoupper($verb)];
        if ($verb === 'match') {
            $methods = array_map('strtoupper', $this->strings($args[0] ?? null));
            $args = array_slice($args, 1);
        }

        $uri = $this->string($args[0] ?? null);
        $handler = $this->handler($args[1]->value ?? null, $group, $uses);
        if ($uri === null || $handler === null) {
            return;
        }
        foreach ($methods as $method) {
            $routes[] = $this->entry($method, $group['prefix'], $uri, $handler);
        }
    }

    /**
     * @param Arg[] $args
     * @param array<int, array{0: string, 1: Arg[]}> $after
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<string, string> $uses
     * @param array<int, array{method: string, path: string, handler: string}> $routes
     */
    private function resource(string $verb, array $args, array $after, array $group, array $uses, array &$routes): void
    {
        $name = $this->string($args[0] ?? null);
        $class = isset($args[1]) ? $this->className($args[1]->value, $group, $uses) : null;
        if ($name === null || $class === null) {
            return;
        }

        // `photos.comments` é aninhado: photos/{photo}/comments.
        $parts = explode('.', trim($name, '/'));
        $last = array_pop($parts);
        $base = '';
        foreach ($parts as $parent) {
            $base .= $parent . '/{' . self::singular($parent) . '}/';
        }
        $base .= $last;
        $param = '{' . self::singular((string) preg_replace('/.*\//', '', $last)) . '}';

        $actions = [['GET', '', 'index'], ['POST', '', 'store'], ['GET', '/' . $param, 'show'],
            ['PUT', '/' . $param, 'update'], ['PATCH', '/' . $param, 'update'], ['DELETE', '/' . $param, 'destroy']];
        if ($verb === 'resource') {
            $actions[] = ['GET', '/create', 'create'];
            $actions[] = ['GET', '/' . $param . '/edit', 'edit'];
        }

        $only = null;
        $except = [];
        foreach ($after as [$call, $callArgs]) {
            if ($call === 'only') {
                $only = $this->strings($callArgs[0] ?? null);
            } elseif ($call === 'except') {
                $except = $this->strings($callArgs[0] ?? null);
            }
        }

        foreach ($actions as [$method, $suffix, $action]) {
            if (($only !== null && !in_array($action, $only, true)) || in_array($action, $except, true)) {
                continue;
            }
            $routes[] = $this->entry($method, $group['prefix'], $base . $suffix, $class . '::' . $action);
        }
    }

    /**
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<string, string> $uses
     */
    private function handler(?Expr $value, array $group, array $uses): ?string
    {
        if ($value === null || $value instanceof Closure || $value instanceof ArrowFunction) {
            return null;
        }
        if ($value instanceof Expr\Array_) {
            $items = array_values(array_filter($value->items));
            $keyed = array_filter($items, static fn ($item): bool => $item->key !== null);
            if ($keyed !== []) {
                // Forma antiga: ['as' => 'nome', 'uses' => 'X@m'] ou ['middleware' => ..., function () {}].
                foreach ($keyed as $item) {
                    if ($item->key instanceof String_ && $item->key->value === 'uses') {
                        return $this->handler($item->value, $group, $uses);
                    }
                }
                return null;
            }
            $class = isset($items[0]) ? $this->className($items[0]->value, $group, $uses) : null;
            $method = isset($items[1]) && $items[1]->value instanceof String_ ? $items[1]->value->value : '__invoke';
            return $class !== null ? $class . '::' . $method : null;
        }
        if ($value instanceof Expr\ClassConstFetch) {
            $class = $this->className($value, $group, $uses);
            return $class !== null ? $class . '::__invoke' : null;
        }
        if ($value instanceof String_) {
            $action = $value->value;
            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action, 2);
                return $this->qualify($class, $group, $uses) . '::' . $method;
            }
            if ($group['controller'] !== null) {
                return $group['controller'] . '::' . $action;
            }
            return $this->qualify($action, $group, $uses) . '::__invoke';
        }

        return null;
    }

    /**
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<string, string> $uses
     */
    private function className(Expr $expr, array $group, array $uses): ?string
    {
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $this->isName($expr->name, 'class')) {
            return ltrim($expr->class->toString(), '\\');
        }
        if ($expr instanceof String_) {
            return $this->qualify($expr->value, $group, $uses);
        }

        return null;
    }

    /**
     * Classe em string: absoluta, pelo namespace do grupo (como o Laravel faz) ou pelo `use` do arquivo.
     *
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<string, string> $uses
     */
    private function qualify(string $class, array $group, array $uses): string
    {
        if (str_starts_with($class, '\\')) {
            return ltrim($class, '\\');
        }
        if ($group['namespace'] !== '') {
            return trim($group['namespace'], '\\') . '\\' . $class;
        }

        return $uses[$class] ?? $class;
    }

    /**
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<int, array{0: string, 1: Arg[]}> $calls
     * @param array<string, string> $uses
     * @return array{prefix: string, namespace: string, controller: ?string}
     */
    private function applyChain(array $group, array $calls, array $uses): array
    {
        foreach ($calls as [$name, $args]) {
            $value = $args[0]->value ?? null;
            if ($value === null) {
                continue;
            }
            if ($name === 'prefix' && ($prefix = $this->stringValue($value)) !== null) {
                $group['prefix'] = self::join($group['prefix'], $prefix);
            } elseif ($name === 'namespace' && ($namespace = $this->stringValue($value)) !== null) {
                $group['namespace'] = self::joinNamespace($group['namespace'], $namespace);
            } elseif ($name === 'controller') {
                $group['controller'] = $this->className($value, $group, $uses);
            }
        }

        return $group;
    }

    /**
     * @param array{prefix: string, namespace: string, controller: ?string} $group
     * @param array<string, string> $uses
     * @return array{prefix: string, namespace: string, controller: ?string}
     */
    private function applyArray(array $group, Expr\Array_ $array, array $uses): array
    {
        $calls = [];
        foreach ($array->items as $item) {
            if ($item !== null && $item->key instanceof String_) {
                $calls[] = [$item->key->value, [new Arg($item->value)]];
            }
        }

        return $this->applyChain($group, $calls, $uses);
    }

    /**
     * Cadeia `Route::a(...)->b(...)` como lista `[nome, args]` a partir da chamada estática.
     *
     * @return array<int, array{0: string, 1: Arg[]}>|null
     */
    private function chain(Expr $expr): ?array
    {
        $calls = [];
        while ($expr instanceof MethodCall) {
            if (!$expr->name instanceof Node\Identifier) {
                return null;
            }
            array_unshift($calls, [$expr->name->toString(), $this->args($expr->args)]);
            $expr = $expr->var;
        }
        if (!$expr instanceof StaticCall || !$expr->class instanceof Name || !$expr->name instanceof Node\Identifier) {
            return null;
        }
        if ($expr->class->getLast() !== 'Route') {
            return null;
        }
        array_unshift($calls, [$expr->name->toString(), $this->args($expr->args)]);

        return $calls;
    }

    /**
     * @param array<int, Arg|Node\VariadicPlaceholder> $args
     * @return Arg[]
     */
    private function args(array $args): array
    {
        return array_values(array_filter($args, static fn ($a): bool => $a instanceof Arg));
    }

    /**
     * @param Node[] $stmts
     * @return array<string, string>
     */
    private function uses(array $stmts): array
    {
        $uses = [];
        foreach ($this->finder->findInstanceOf($stmts, Node\UseItem::class) as $use) {
            $uses[$use->getAlias()->toString()] = $use->name->toString();
        }

        return $uses;
    }

    private function evalPath(Expr $expr, string $root, string $dir): ?string
    {
        $value = $this->evalString($expr, $root, $dir);
        if ($value === null || !str_ends_with(strtolower($value), '.php')) {
            return null;
        }

        return is_file($value) ? $value : null;
    }

    private function evalString(Expr $expr, string $root, string $dir): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }
        if ($expr instanceof Dir) {
            return $dir;
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->evalString($expr->left, $root, $dir);
            $right = $this->evalString($expr->right, $root, $dir);
            return $left !== null && $right !== null ? $left . $right : null;
        }
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && $expr->name->getLast() === 'base_path') {
            $arg = $expr->args[0] ?? null;
            $relative = $arg instanceof Arg ? $this->evalString($arg->value, $root, $dir) : '';
            return $relative === null ? null : $root . '/' . ltrim($relative, '/\\');
        }

        return null;
    }

    /**
     * Texto literal, ou `$this->namespace` enquanto o provider é lido.
     */
    private function stringValue(Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }
        if ($this->providerNamespace !== null && $expr instanceof Expr\PropertyFetch && $this->isName($expr->name, 'namespace')) {
            return $this->providerNamespace;
        }

        return null;
    }

    private function string(?Arg $arg): ?string
    {
        return $arg !== null && $arg->value instanceof String_ ? $arg->value->value : null;
    }

    /**
     * @return string[]
     */
    private function strings(?Arg $arg): array
    {
        $value = $arg?->value;
        if ($value instanceof String_) {
            return [$value->value];
        }
        $strings = [];
        foreach ($value instanceof Expr\Array_ ? $value->items : [] as $item) {
            if ($item !== null && $item->value instanceof String_) {
                $strings[] = $item->value->value;
            }
        }

        return $strings;
    }

    private function isName(Node $name, string $expected): bool
    {
        return $name instanceof Node\Identifier && strcasecmp($name->toString(), $expected) === 0;
    }

    /**
     * @return Node[]|null
     */
    private function parse(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $code = @file_get_contents($file);
        if ($code === false) {
            return null;
        }
        try {
            return $this->parser->parse($code);
        } catch (\PhpParser\Error) {
            return null;
        }
    }

    /**
     * @return array{method: string, path: string, handler: string}
     */
    private function entry(string $method, string $prefix, string $uri, string $handler): array
    {
        return ['method' => $method, 'path' => '/' . trim(self::join($prefix, $uri), '/'), 'handler' => $handler];
    }

    /**
     * Singular do nome do recurso, para o parâmetro (`photos` → `photo`); cobre os plurais regulares do inglês.
     */
    private static function singular(string $word): string
    {
        return match (true) {
            (bool) preg_match('/[^aeiou]ies$/i', $word) => substr($word, 0, -3) . 'y',
            (bool) preg_match('/(s|x|z|ch|sh)es$/i', $word) => substr($word, 0, -2),
            (bool) preg_match('/(ss|us)$/i', $word) => $word,
            str_ends_with(strtolower($word), 's') => substr($word, 0, -1),
            default => $word,
        };
    }

    private static function join(string $prefix, string $path): string
    {
        return implode('/', array_filter([trim($prefix, '/'), trim($path, '/')], static fn (string $p): bool => $p !== ''));
    }

    private static function joinNamespace(string $base, string $namespace): string
    {
        if ($namespace === '') {
            return $base;
        }
        if (str_starts_with($namespace, '\\') || $base === '') {
            return trim($namespace, '\\');
        }

        return trim($base, '\\') . '\\' . trim($namespace, '\\');
    }

    /**
     * @return array{prefix: string, namespace: string, controller: ?string}
     */
    private static function group(string $prefix): array
    {
        return ['prefix' => $prefix, 'namespace' => '', 'controller' => null];
    }
}
