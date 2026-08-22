<?php

namespace Tests\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Infrastructure\Analyzer\TypeScriptParser;
use PHPUnit\Framework\TestCase;

final class TypeScriptParserTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ts-parser-test-' . uniqid();
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
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function makeParser(string $language = 'typescript'): TypeScriptParser
    {
        return new TypeScriptParser(new DefaultNodeFactory(), $this->tempDir, $language);
    }

    // -------------------------------------------------------------------------

    public function test_detects_class_with_methods(): void
    {
        $file = $this->createTempFile('user.service.ts', <<<'TS'
export class UserService {
    getUser(id: string): User {
        return this.repo.find(id);
    }

    createUser(data: CreateUserDto): User {
        return this.repo.save(data);
    }
}
TS);

        $result = $this->makeParser()->parse($file);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        self::assertContains('typescript:user%2Eservice.UserService::getUser', $ids);
        self::assertContains('typescript:user%2Eservice.UserService::createUser', $ids);
    }

    public function test_ignores_classes_declared_inside_callbacks(): void
    {
        $file = $this->createTempFile('scope.test.ts', <<<'TS'
describe('scope', () => {
    class MockAudioContext {
        resume(): void {}
    }
});

export class RealService {
    run(): void {}
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);

        self::assertContains('typescript:scope%2Etest.RealService::run', $ids);
        self::assertNotContains('typescript:scope%2Etest.MockAudioContext::resume', $ids);
    }

    public function test_does_not_attribute_local_class_edges_to_the_enclosing_method(): void
    {
        $file = $this->createTempFile('local-class.ts', <<<'TS'
export class RealService {
    run(): void {
        class FakeClient {
            request(): void {
                fetch('/fake-only');
            }
        }
        fetch('/real');
    }
}
TS);

        $result = $this->makeParser()->parse($file);
        $targets = array_map(static fn($edge): string => $edge->to(), $result['edges']);

        self::assertNotContains('http:GET:/fake-only', $targets);
        self::assertContains('http:GET:/real', $targets);
    }

    public function test_lexical_trivia_does_not_change_class_scope_and_class_brace_may_follow(): void
    {
        $file = $this->createTempFile('lexical.ts', <<<'TS'
const stringMarker = "{";
const templateMarker = `}`;
const regexMarker = /[{}]/;
const concatenatedRegex = '' + /\{/;
false && ((...args: unknown[]) => args)(.../\}/);
/* { unmatched comment brace */
export class Service
{
    run(): void {
        if ((true)) /\}/.test('value');
    }
    afterElse(): void {
        if (true) useValue(); else /\}/.test('value');
    }
    afterDo(): void {
        do /\}/.test('value'); while (condition);
    }
    async afterForAwait(): Promise<void> {
        for await (const value of values) /\}/.test(value);
    }
    second(): void {}
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);

        self::assertContains('typescript:lexical.Service::run', $ids);
        self::assertContains('typescript:lexical.Service::afterElse', $ids);
        self::assertContains('typescript:lexical.Service::afterDo', $ids);
        self::assertContains('typescript:lexical.Service::afterForAwait', $ids);
        self::assertContains('typescript:lexical.Service::second', $ids);
    }

    public function test_division_after_object_literal_does_not_hide_following_methods(): void
    {
        $file = $this->createTempFile('object-division.ts', <<<'TS'
export class Service {
    inline(): void { const ratio = <number><unknown>{value: 2} / 2; }
    functionExpression(): void { const ratio = function () {} / 2; }
    complexFunctionExpression(): void { const ratio = function ({value = makeDefault()}: {value?: number}) {} / 2; }
    typedFunctionExpression(): void { const ratio = function (value: {run(): void;}) {} / 2; }
    genericFunctionExpression(): void { const ratio = function <T>(value: T) {} / 2; }
    classExpression(): void { const ratio = class {} / 2; }
    genericClassExpression(): void { const ratio = class Box<T> {} / 2; }
    arrowExpression(): void { const ratio = (() => {}) / 2; }
    unaryObjectExpression(): void { const ratio = void {} / 2; }
    multilineFunctionExpression(): void {
        const ratio = function
        ({value = makeDefault()}: {value?: number}) {} / 2; }
    multiline(): void {
        const ratio = {
            value: 2,
        }
        / 2;
    }
    after(): void {}
}
TS);

        $methods = array_map(
            static fn($node): string => $node->method(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertContains('inline', $methods);
        self::assertContains('functionExpression', $methods);
        self::assertContains('complexFunctionExpression', $methods);
        self::assertContains('typedFunctionExpression', $methods);
        self::assertContains('genericFunctionExpression', $methods);
        self::assertContains('classExpression', $methods);
        self::assertContains('genericClassExpression', $methods);
        self::assertContains('arrowExpression', $methods);
        self::assertContains('unaryObjectExpression', $methods);
        self::assertContains('multilineFunctionExpression', $methods);
        self::assertContains('multiline', $methods);
        self::assertContains('after', $methods);
    }

    public function test_regex_after_closed_block_does_not_change_class_scope(): void
    {
        $file = $this->createTempFile('block-regex.ts', <<<'TS'
export class Service {
    run(): void {
        if (condition) {}
        /\}/.test(value);
    }
    after(): void {}
}
TS);

        $methods = array_map(
            static fn($node): string => $node->method(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertContains('run', $methods);
        self::assertContains('after', $methods);
    }

    public function test_nested_constructs_do_not_contaminate_regex_after_a_block(): void
    {
        $file = $this->createTempFile('nested-construct-regex.ts', <<<'TS'
export class Service {
    run(): void {
        function inner(callback = function () {}) {}
        const previous = function () {}
        if (condition) {}
        /\}/.test(value);
    }
    after(): void {}
}
TS);

        $methods = array_map(
            static fn($node): string => $node->method(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertContains('run', $methods);
        self::assertContains('after', $methods);
    }

    public function test_asi_control_keywords_allow_a_regex_statement(): void
    {
        $file = $this->createTempFile('asi-regex.js', <<<'JS'
export class Service {
    run() {
        debugger
        /}/.test(value)
        loop: while (condition) {
            break loop
            /}/.test(value)
        }
    }
    after() {}
}
JS);

        $methods = array_map(
            static fn($node): string => $node->method(),
            $this->makeParser('javascript')->parse($file)['nodes'],
        );

        self::assertContains('run', $methods);
        self::assertContains('after', $methods);
    }

    public function test_quotes_in_jsx_text_do_not_change_following_scope(): void
    {
        $file = $this->createTempFile('jsx-text.tsx', <<<'TSX'
export function Component() {
    return <div>`'"{format("}")}<Widget title="}" /><span>`'"</span>{format('}')}</div>;
}
export class Service {
    run(): void {}
}
TSX);

        $ids = array_map(
            static fn($node): string => $node->id(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertContains('typescript:jsx-text::Component', $ids);
        self::assertContains('typescript:jsx-text.Service::run', $ids);
    }

    public function test_nested_template_literals_do_not_change_following_scope(): void
    {
        $file = $this->createTempFile('nested-template.ts', <<<'TS'
export function first() {
    const value = `${true ? `}` : "ok"}`;
    const opening = `${true ? `{` : "ok"}`;
    const multiline = `${
        true ? `{` : "ok"
    }`;
    return value + opening + multiline;
}
export class Next {
    run(): void {}
}
TS);

        $ids = array_map(
            static fn($node): string => $node->id(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertContains('typescript:nested-template::first', $ids);
        self::assertContains('typescript:nested-template.Next::run', $ids);
    }

    public function test_multiline_generic_method_is_not_dropped(): void
    {
        $file = $this->createTempFile('generic-method.ts', <<<'TS'
export class Service {
    map<
        T extends Record<string, unknown>
    >(value: T): T {
        return value;
    }

    after(): void {}
}
TS);

        $methods = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $methods[$node->method()] = $node;
        }

        self::assertArrayHasKey('map', $methods);
        self::assertSame(2, $methods['map']->line());
        self::assertSame(6, $methods['map']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $methods);
    }

    public function test_relational_comparison_does_not_leave_generic_depth_open(): void
    {
        $file = $this->createTempFile('relational-comparison.ts', <<<'TS'
export class Service {
    first() {
        value < limit;
        if (ok) { value++; }
        /}/.test(value);
    }

    after() {}
}
TS);

        $methods = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $methods[$node->method()] = $node;
        }

        self::assertSame(6, $methods['first']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $methods);
    }

    public function test_asi_relational_comparison_does_not_leave_generic_depth_open(): void
    {
        $file = $this->createTempFile('asi-relational-comparison.ts', <<<'TS'
export class Service {
    first() {
        value < limit
        if (ok) { value++; }
        /}/.test(value);
    }

    after() {}
}
TS);

        $methods = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $methods[$node->method()] = $node;
        }

        self::assertSame(6, $methods['first']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $methods);
    }

    public function test_multiline_logical_comparison_does_not_leave_generic_depth_open(): void
    {
        $file = $this->createTempFile('logical-relational-comparison.ts', <<<'TS'
export class Service {
    first() {
        value < limit
            && active
        other < cap
            || fallback
        flags < allowed
            & mask
        options < defaults
            | override
        if (ok) { value++; }
        /}/.test(value);
    }

    after() {}
}
TS);

        $methods = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $methods[$node->method()] = $node;
        }

        self::assertSame(13, $methods['first']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $methods);
    }

    public function test_generic_call_is_not_treated_as_jsx(): void
    {
        $file = $this->createTempFile('generic-call.tsx', <<<'TS'
export function first() {
    const value = parse<Result>("}");
    return value;
}
export class Next {
    run(): void {}
}
TS);

        $nodes = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertSame(4, $nodes['first']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('run', $nodes);
    }

    public function test_type_assertion_in_ts_is_not_treated_as_jsx(): void
    {
        $file = $this->createTempFile('type-assertion.ts', <<<'TS'
export class Service {
    first(): void { const value = <string>"}"; }
    after(): void {}
}
TS);

        $methods = array_map(
            static fn($node): string => $node->method(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertSame(['first', 'after'], $methods);
    }

    public function test_exported_generic_function_is_not_dropped(): void
    {
        $file = $this->createTempFile('generic-function.ts', <<<'TS'
export function identity<T>(value: T): T {
    return value;
}
export function after(): void {}
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertSame(3, $nodes['identity']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
        self::assertContains('identity', array_map(
            static fn($symbol): string => $symbol->name,
            $result['symbols'],
        ));
    }

    public function test_type_literal_in_generic_constraint_is_not_a_method_body(): void
    {
        $file = $this->createTempFile('generic-constraint.ts', <<<'TS'
export class Service {
    map<
        T extends { key: string }
    >(value: T): T {
        return value;
    }
    after(): void {}
}
TS);

        $nodes = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertSame(6, $nodes['map']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
    }

    public function test_multiline_generic_method_keeps_union_and_intersection_constraints(): void
    {
        $file = $this->createTempFile('generic-union-intersection.ts', <<<'TS'
export class Service {
    map<
        T extends { key: string }
            & ({ id: number } | { slug: string })
    >(value: T): T {
        return value;
    }
    after(): void {}
}
TS);

        $nodes = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertSame(7, $nodes['map']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
    }

    public function test_non_exported_generic_function_keeps_symbol_and_call_edge(): void
    {
        $file = $this->createTempFile('generic-helper.ts', <<<'TS'
function helper<T extends { id: string }>(value: T): T {
    return value;
}
export function caller() {
    return helper({ id: "x" });
}
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertSame(3, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('caller', $nodes);
        self::assertContains('helper', array_map(
            static fn($symbol): string => $symbol->name,
            $result['symbols'],
        ));
        self::assertContains($nodes['helper']->id(), array_map(
            static fn($edge): string => $edge->to(),
            $result['edges'],
        ));
    }

    public function test_generic_arrow_functions_become_nodes_and_keep_edges(): void
    {
        $file = $this->createTempFile('generic-arrows.tsx', <<<'TSX'
export const identity = <T,>(value: T): T => value;
const helper = <T extends { id: string }>(value: T): T => value;
export function caller() {
    return identity(helper({ id: "x" }));
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $targets = array_map(static fn($edge): string => $edge->to(), $result['edges']);

        self::assertSame(1, $nodes['identity']->metadata()['endLine'] ?? null);
        self::assertSame(2, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('caller', $nodes);
        self::assertContains($nodes['identity']->id(), $targets);
        self::assertContains($nodes['helper']->id(), $targets);
    }

    public function test_multiline_generic_arrows_in_tsx_keep_scope_and_edges(): void
    {
        $file = $this->createTempFile('multiline-generic-arrows.tsx', <<<'TSX'
function helper(): void {}
export const load = <T extends
    { id: string }
>(value: T): T => {
    helper();
    fetch('/top-real');
    return value;
};
export class Service {
    save = <T extends
        { id: string }
    >(value: T): T => {
        helper();
        fetch('/class-real');
        return value;
    };
    after(): void { helper(); }
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertSame(8, $nodes['load']->metadata()['endLine'] ?? null);
        self::assertSame(16, $nodes['save']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
        self::assertContains([$nodes['load']->id(), $nodes['helper']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['save']->id(), $nodes['helper']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['load']->id(), 'http:GET:/top-real', 'http_call'], $edges);
        self::assertContains([$nodes['save']->id(), 'http:GET:/class-real', 'http_call'], $edges);
    }

    public function test_nested_and_modified_multiline_generic_arrows_in_tsx_keep_scope_and_edges(): void
    {
        $file = $this->createTempFile('nested-modified-generic-arrows.tsx', <<<'TSX'
function helper(): void {}
export function outer() {
    let inner = <T extends
        { id: string }
    >(value: T): T => value;
    helper();
    fetch('/outer-real');
    return inner;
}
export class Service {
    protected static override save = async <T extends
        { id: string }
    >(value: T): Promise<T> => {
        helper();
        fetch('/class-real');
        return value;
    };
    after(): void { helper(); }
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertSame(9, $nodes['outer']->metadata()['endLine'] ?? null);
        self::assertSame(17, $nodes['save']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
        self::assertContains([$nodes['outer']->id(), $nodes['helper']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['outer']->id(), 'http:GET:/outer-real', 'http_call'], $edges);
        self::assertContains([$nodes['save']->id(), $nodes['helper']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['save']->id(), 'http:GET:/class-real', 'http_call'], $edges);
    }

    public function test_generic_class_arrow_property_keeps_its_body_scope(): void
    {
        $file = $this->createTempFile('generic-property.tsx', <<<'TSX'
export class Service {
    load = <T,>(value: T) => {
        return fetch("/items");
    }
    after(): void {}
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertSame(4, $nodes['load']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
        self::assertContains('http:GET:/items', array_map(
            static fn($edge): string => $edge->to(),
            $result['edges'],
        ));
    }

    public function test_multiline_class_arrow_property_keeps_exact_scope_and_edges(): void
    {
        $file = $this->createTempFile('class-expression-property.ts', <<<'TS'
function inside(): void {}
function after(): void {}
class Service {
    task = () =>
        inside()
}
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(5, $nodes['task']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['task']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['task']->id(), $nodes['after']->id()], $edges);
        self::assertNotContains(
            'typescript:class-expression-property.Service::inside',
            array_map(static fn($edge): string => $edge->from(), $result['edges']),
        );
    }

    public function test_multiline_non_arrow_assignments_do_not_become_nodes(): void
    {
        $file = $this->createTempFile('non-arrows.ts', <<<'TS'
export const computed = (
    left + right
);
const asserted = <
    Result
>value;
const functionalAssertion = <
    (value: string) => number
>callback;
export const mapped = (
    items.map(item => item.id)
);
export function realNode(): void {}
TS);

        $methods = array_map(
            static fn($node): string => $node->method(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertSame(['realNode'], $methods);
    }

    public function test_later_declarator_arrow_is_not_assigned_to_the_first_binding(): void
    {
        $file = $this->createTempFile('multiple-declarators.ts', <<<'TS'
function target(): void {}
const first = (source), second = () => {
    target();
    fetch('/wrong');
};
function after(): void {
    target();
    fetch('/after');
}
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertArrayNotHasKey('first', $nodes);
        self::assertNotContains('http:GET:/wrong', array_column($edges, 1));
        self::assertContains([$nodes['after']->id(), $nodes['target']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['after']->id(), 'http:GET:/after', 'http_call'], $edges);
    }

    public function test_generic_class_arrow_properties_accept_modifiers(): void
    {
        $file = $this->createTempFile('generic-modifiers.ts', <<<'TS'
export class Service {
    public load = async <T,>(value: T): Promise<T> => Promise.resolve(value);
    private readonly save = <T,>(value: T): T => value;
    protected static override replace = <T,>(value: T): T => value;
}
TS);

        $methods = array_map(
            static fn($node): string => $node->method(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertSame(['load', 'save', 'replace'], $methods);
    }

    public function test_auto_accessor_arrow_property_becomes_a_node_with_edges(): void
    {
        $file = $this->createTempFile('accessor-arrow.ts', <<<'TS'
function inner(): void {}
export class Service {
    public accessor run = () => inner();
}
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertArrayHasKey('run', $nodes);
        self::assertContains(
            [$nodes['run']->id(), $nodes['inner']->id()],
            array_map(static fn($edge): array => [$edge->from(), $edge->to()], $result['edges']),
        );
    }

    public function test_function_type_in_arrow_signature_does_not_open_the_body(): void
    {
        $file = $this->createTempFile('function-type-parameter.ts', <<<'TS'
function first(): void {}
function second(): void {}
const helper = (
    callback: (value: string) => string,
) => {
    first();
    second();
};
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(8, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['helper']->id(), $nodes['first']->id()], $edges);
        self::assertContains([$nodes['helper']->id(), $nodes['second']->id()], $edges);
    }

    public function test_multiline_arrow_body_with_asi_does_not_leak_edges(): void
    {
        $file = $this->createTempFile('asi-expression-arrow.ts', <<<'TS'
function inside(): void {}
function after(): void {}
const helper = () =>
    inside()
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(4, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['helper']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['helper']->id(), $nodes['after']->id()], $edges);
    }

    public function test_arrow_started_on_declaration_tracks_multiline_call_and_return_type_literal(): void
    {
        $file = $this->createTempFile('declaration-expression-arrow.ts', <<<'TS'
function inside(): object { return {}; }
function after(): void {}
function invoke(value: object): object { return value; }
const multiline = () => invoke(
    inside()
)
const typed = (): { value: number } => inside()
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(6, $nodes['multiline']->metadata()['endLine'] ?? null);
        self::assertSame(7, $nodes['typed']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['multiline']->id(), $nodes['inside']->id()], $edges);
        self::assertContains([$nodes['typed']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['typed']->id(), $nodes['after']->id()], $edges);
    }

    public function test_asi_lookahead_skips_trivia_and_keeps_delimiter_continuation(): void
    {
        $file = $this->createTempFile('asi-delimiter-continuation.ts', <<<'TS'
function inside(): number { return 1; }
function after(): void {}
function invoke(callback: () => number): number { return callback(); }
const helper = () =>
    invoke
    // continuation trivia
    (() => inside())
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(7, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['helper']->id(), $nodes['invoke']->id()], $edges);
        self::assertContains([$nodes['helper']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['helper']->id(), $nodes['after']->id()], $edges);
    }

    public function test_template_literal_expression_body_keeps_exact_scope_and_edges(): void
    {
        $file = $this->createTempFile('template-expression-arrow.ts', <<<'TS'
function inside(): number { return 1; }
function after(): void {}
const literal = () =>
    `literal`
const tagged = () =>
    String.raw
    `value ${inside()}`
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(4, $nodes['literal']->metadata()['endLine'] ?? null);
        self::assertSame(7, $nodes['tagged']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['tagged']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['literal']->id(), $nodes['after']->id()], $edges);
        self::assertNotContains([$nodes['tagged']->id(), $nodes['after']->id()], $edges);
    }

    public function test_multiline_template_class_arrow_keeps_exact_scope_and_edges(): void
    {
        $file = $this->createTempFile('class-template-expression.ts', <<<'TS'
function inside(): number { return 1; }
function after(): void {}
class Service {
    task = () => `hello
plain ${inside()}
world`
}
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(6, $nodes['task']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['task']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['task']->id(), $nodes['after']->id()], $edges);
    }

    public function test_block_comment_lookahead_does_not_extend_arrow_expression(): void
    {
        $file = $this->createTempFile('block-comment-expression.ts', <<<'TS'
function inside(): void {}
function after(): void {}
const source = () =>
    inside() /*
    ( comment text
    */
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(4, $nodes['source']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['source']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['source']->id(), $nodes['after']->id()], $edges);
    }

    public function test_backtick_in_comment_does_not_end_arrow_expression(): void
    {
        $file = $this->createTempFile('comment-backtick-arrow.ts', <<<'TS'
function target(): void {}
function after(): void {}
const source = () =>
    // Use `target` here.
    target()
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(5, $nodes['source']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['source']->id(), $nodes['target']->id()], $edges);
        self::assertNotContains([$nodes['source']->id(), $nodes['after']->id()], $edges);
    }

    public function test_await_on_its_own_line_continues_arrow_expression(): void
    {
        $file = $this->createTempFile('await-expression-arrow.ts', <<<'TS'
function inside(): Promise<number> { return Promise.resolve(1); }
function after(): void {}
const helper = async () =>
    await
    inside()
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(5, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['helper']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['helper']->id(), $nodes['after']->id()], $edges);
    }

    public function test_optional_multiline_call_emits_callee_edge(): void
    {
        $file = $this->createTempFile('optional-multiline-call.ts', <<<'TS'
function invoke(callback: () => number): number { return callback(); }
function inside(): number { return 1; }
function after(): void {}
const helper = () =>
    invoke
    ?.(() => inside())
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(6, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['helper']->id(), $nodes['invoke']->id()], $edges);
        self::assertContains([$nodes['helper']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['helper']->id(), $nodes['after']->id()], $edges);
    }

    public function test_non_null_assertion_continues_arrow_expression(): void
    {
        $file = $this->createTempFile('non-null-expression-arrow.ts', <<<'TS'
function inside(): object { return {}; }
function after(): void {}
const helper = () =>
    inside()
    !.toString()
after()
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(5, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['helper']->id(), $nodes['inside']->id()], $edges);
        self::assertNotContains([$nodes['helper']->id(), $nodes['after']->id()], $edges);
    }

    public function test_multiline_arrow_body_has_exact_scope_and_edges(): void
    {
        $file = $this->createTempFile('multiline-expression-arrow.ts', <<<'TS'
function transform<T>(value: T): T { return value; }
function consume(value: unknown): void {}
const helper = <T>(
    value: T,
) =>
    transform(value);
consume(helper(1));
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to()],
            $result['edges'],
        );

        self::assertSame(6, $nodes['helper']->metadata()['endLine'] ?? null);
        self::assertContains([$nodes['helper']->id(), $nodes['transform']->id()], $edges);
        self::assertNotContains([$nodes['helper']->id(), $nodes['consume']->id()], $edges);
    }

    public function test_single_line_arrow_body_emits_its_call_edge(): void
    {
        $file = $this->createTempFile('single-line-expression-arrow.ts', <<<'TS'
function transform<T>(value: T): T { return value; }
const helper = <T>(value: T): T => transform(value);
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertContains(
            [$nodes['helper']->id(), $nodes['transform']->id()],
            array_map(static fn($edge): array => [$edge->from(), $edge->to()], $result['edges']),
        );
    }

    public function test_multiline_arrow_return_type_literal_gets_real_endline(): void
    {
        $file = $this->createTempFile('arrow-return-type.ts', <<<'TS'
export const build = (
    value: string
): {
    value: string;
} => ({
    value,
});
export function after(): void {}
TS);

        $nodes = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertSame(7, $nodes['build']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
    }

    public function test_jsx_closing_tags_do_not_hide_following_nodes(): void
    {
        $file = $this->createTempFile('component.tsx', <<<'TSX'
export function Component() {
    return (
        <section>
            <div>content</div>
        </section>
    );
}

export class Service {
    run(): void {}
}
TSX);

        $ids = array_map(
            static fn($node): string => $node->id(),
            $this->makeParser()->parse($file)['nodes'],
        );

        self::assertContains('typescript:component::Component', $ids);
        self::assertContains('typescript:component.Service::run', $ids);
    }

    public function test_declarations_inside_comments_do_not_become_nodes_or_symbols(): void
    {
        $file = $this->createTempFile('commented.ts', <<<'TS'
/*
export function ghostFunction(): void {}
*/
export class Service {
    /*
    ghostMethod(): void {}
    */
    realMethod(): void {}
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);
        $symbolNames = array_map(static fn($symbol): string => $symbol->name, $result['symbols']);

        self::assertSame(['typescript:commented.Service::realMethod'], $ids);
        self::assertNotContains('ghostFunction', $symbolNames);
    }

    public function test_does_not_treat_indented_function_calls_as_class_methods(): void
    {
        $file = $this->createTempFile('calls.ts', <<<'TS'
class ApiService {
    request(): void {
        if (true) {
            reportClientFailure('first')
            reportClientFailure('second')
        }
    }
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);

        self::assertSame(['typescript:calls.ApiService::request'], $ids);
    }


    public function test_keeps_classes_declared_in_namespaces(): void
    {
        $file = $this->createTempFile('namespace.ts', <<<'TS'
export namespace Api {
    export class Client {
        fetch(): void {}
    }
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);

        self::assertContains('typescript:namespace.~namespace~.Api.Client::fetch', $ids);
    }

    public function test_namespace_identity_disambiguates_siblings_and_nested_namespaces(): void
    {
        $file = $this->createTempFile('namespaces.ts', <<<'TS'
namespace Api {
    export class Client {
        fetch(): void {}
    }
    namespace Internal {
        export class Client {
            fetch(): void {}
        }
    }
}
namespace Admin {
    export class Client {
        fetch(): void {}
    }
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);

        self::assertContains('typescript:namespaces.~namespace~.Api.Client::fetch', $ids);
        self::assertContains('typescript:namespaces.~namespace~.Api.Internal.Client::fetch', $ids);
        self::assertContains('typescript:namespaces.~namespace~.Admin.Client::fetch', $ids);
        self::assertCount(3, array_unique($ids));
    }

    public function test_supports_ambient_and_dotted_namespaces(): void
    {
        $file = $this->createTempFile('ambient.d.ts', <<<'TS'
declare namespace API {
    export class Client {
        fetch(): void;
    }
}
namespace Company.Internal {
    export class Client {
        fetch(): void {}
    }
}
declare module "pkg/sub" {
    export class Client {
        run(): void {}
    }
}
module Legacy {
    export class Client {
        run(): void {}
    }
}
declare global {
    class AmbientClient {
        boot(): void;
    }
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);

        self::assertContains('typescript:ambient%2Ed.~namespace~.API.Client::fetch', $ids);
        self::assertContains('typescript:ambient%2Ed.~namespace~.Company.Internal.Client::fetch', $ids);
        self::assertContains('typescript:ambient%2Ed.~namespace~.pkg%2Fsub.Client::run', $ids);
        self::assertContains('typescript:ambient%2Ed.~namespace~.Legacy.Client::run', $ids);
        self::assertContains('typescript:ambient%2Ed.AmbientClient::boot', $ids);
    }

    public function test_namespace_identity_survives_a_module_block_on_the_next_line(): void
    {
        $file = $this->createTempFile('next-line.ts', <<<'TS'
export function run(): void {}

namespace A
/* comment between declaration and block */
{
    export function run(): void {}
}

namespace B
/* multi-line
 * comment
 */ { /* trailing comment after the namespace block opener
    export class Ghost {
        leaked(): void {}
    }
 */
    export function run(): void {}
}

namespace C { /* same-line namespace comment starts
    export class Ghost {
        leaked(): void {}
    }
 */
    export function run(): void {}
}

namespace Empty { /* comment opens and closes here */ }
export function afterEmptyNamespace(): void {}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);

        self::assertContains('typescript:next-line::run', $ids);
        self::assertContains('typescript:next-line.~namespace~.A::run', $ids);
        self::assertContains('typescript:next-line.~namespace~.B::run', $ids);
        self::assertNotContains('typescript:next-line.~namespace~.B.Ghost::leaked', $ids);
        self::assertContains('typescript:next-line.~namespace~.C::run', $ids);
        self::assertNotContains('typescript:next-line.~namespace~.C.Ghost::leaked', $ids);
        self::assertContains('typescript:next-line::afterEmptyNamespace', $ids);
        self::assertNotContains('typescript:next-line.~namespace~.Empty::afterEmptyNamespace', $ids);
        self::assertCount(5, array_unique($ids));
    }

    public function test_namespace_identity_disambiguates_exported_functions_and_local_calls(): void
    {
        $file = $this->createTempFile('namespace-functions.ts', <<<'TS'
namespace Api {
    export function helper(): void {}
    export function run(): void {
        helper();
    }
}
namespace Admin {
    export function helper(): void {}
    export function run(): void {
        helper();
    }
}
TS);

        $result = $this->makeParser()->parse($file);
        $ids = array_map(static fn($node): string => $node->id(), $result['nodes']);
        $callTargets = array_map(
            static fn($edge): string => $edge->to(),
            array_filter($result['edges'], static fn($edge): bool => $edge->type() === 'ts_call')
        );

        self::assertContains('typescript:namespace-functions.~namespace~.Api::helper', $ids);
        self::assertContains('typescript:namespace-functions.~namespace~.Api::run', $ids);
        self::assertContains('typescript:namespace-functions.~namespace~.Admin::helper', $ids);
        self::assertContains('typescript:namespace-functions.~namespace~.Admin::run', $ids);
        self::assertCount(4, array_unique($ids));
        self::assertContains('typescript:namespace-functions.~namespace~.Api::helper', $callTargets);
        self::assertContains('typescript:namespace-functions.~namespace~.Admin::helper', $callTargets);
    }

    public function test_namespace_boundary_cannot_collide_with_a_module_path(): void
    {
        $namespacedFile = $this->createTempFile('src/client.ts', <<<'TS'
namespace Api {
    export class Client {
        fetch(): void {}
    }
}
TS);
        $pathFile = $this->createTempFile('src/client/~namespace~/Api.ts', <<<'TS'
export class Client {
    fetch(): void {}
}
TS);

        $namespacedId = $this->makeParser()->parse($namespacedFile)['nodes'][0]->id();
        $pathId = $this->makeParser()->parse($pathFile)['nodes'][0]->id();

        self::assertSame('typescript:src.client.~namespace~.Api.Client::fetch', $namespacedId);
        self::assertSame('typescript:src.client.%7Enamespace%7E.Api.Client::fetch', $pathId);
        self::assertNotSame($namespacedId, $pathId);
    }

    public function test_module_path_segments_with_dots_have_distinct_identities(): void
    {
        $left = $this->createTempFile('a.b/c.ts', <<<'TS'
export class Service {
    run(): void {}
}
TS);
        $right = $this->createTempFile('a/b.c.ts', <<<'TS'
export class Service {
    run(): void {}
}
TS);

        $leftId = $this->makeParser()->parse($left)['nodes'][0]->id();
        $rightId = $this->makeParser()->parse($right)['nodes'][0]->id();

        self::assertSame('typescript:a%2Eb.c.Service::run', $leftId);
        self::assertSame('typescript:a.b%2Ec.Service::run', $rightId);
        self::assertNotSame($leftId, $rightId);
    }

    public function test_nested_namespace_calls_resolve_through_lexical_ancestors(): void
    {
        $file = $this->createTempFile('lexical.ts', <<<'TS'
export function helper(): void {}
namespace A {
    export function helper(): void {}
    namespace B {
        export function run(): void {
            helper();
        }
    }
}
TS);

        $result = $this->makeParser()->parse($file);
        $runId = 'typescript:lexical.~namespace~.A.B::run';
        $targets = array_map(
            static fn($edge): string => $edge->to(),
            array_filter(
                $result['edges'],
                static fn($edge): bool => $edge->from() === $runId && $edge->type() === 'ts_call',
            ),
        );

        self::assertContains('typescript:lexical.~namespace~.A::helper', $targets);
        self::assertNotContains('typescript:lexical::helper', $targets);
    }


    public function test_detects_exported_function(): void
    {
        $file = $this->createTempFile('helpers.ts', <<<'TS'
export function formatDate(date: Date): string {
    return date.toISOString();
}

export async function fetchData(url: string): Promise<any> {
    return fetch(url);
}
TS);

        $result = $this->makeParser()->parse($file);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        self::assertContains('typescript:helpers::formatDate', $ids);
        self::assertContains('typescript:helpers::fetchData', $ids);
    }

    public function test_detects_non_exported_main_function_as_cli_entrypoint(): void
    {
        $file = $this->createTempFile('server.ts', <<<'TS'
async function main(): Promise<void> {
    await startServer();
}
TS);

        $result = $this->makeParser()->parse($file);

        $node = $result['nodes'][0] ?? null;
        self::assertNotNull($node);
        self::assertSame('typescript:server::main', $node->id());
        self::assertSame('cli', $node->metadata()['entrypoint_type'] ?? null);
    }

    public function test_detects_route_get_export_as_http_entrypoint(): void
    {
        $file = $this->createTempFile('route.ts', <<<'TS'
export async function GET(): Promise<Response> {
    return new Response('ok');
}
TS);

        $result = $this->makeParser()->parse($file);

        $node = $result['nodes'][0] ?? null;
        self::assertNotNull($node);
        self::assertSame('typescript:route::GET', $node->id());
        self::assertSame('http', $node->metadata()['entrypoint_type'] ?? null);
        self::assertSame('GET', $node->metadata()['http_method'] ?? null);
    }

    public function test_dotted_route_filename_keeps_handler_http_semantics(): void
    {
        $file = $this->createTempFile('orders.route.ts', <<<'TS'
export function handler(): void {}
TS);

        $result = $this->makeParser()->parse($file);
        $node = $result['nodes'][0] ?? null;

        self::assertNotNull($node);
        self::assertSame('typescript:orders%2Eroute::handler', $node->id());
        self::assertSame('http', $node->metadata()['entrypoint_type'] ?? null);
    }

    public function test_nested_non_exported_function_does_not_clear_outer_edge_context(): void
    {
        $file = $this->createTempFile('server.ts', <<<'TS'
function main(): void {
    function inner(): void {}
    afterInner();
}

function afterInner(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $edgeTos = array_map(fn($e) => $e->to(), $result['edges']);
        self::assertContains('typescript:server::afterInner', $edgeTos);
    }

    public function test_node_id_includes_module_path(): void
    {
        $subDir = $this->tempDir . '/src/services';
        mkdir($subDir, 0777, true);
        $file = $subDir . '/auth.service.ts';
        file_put_contents($file, <<<'TS'
export class AuthService {
    login(username: string): void {}
}
TS);

        $result = $this->makeParser()->parse($file);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        self::assertContains('typescript:src.services.auth%2Eservice.AuthService::login', $ids);
    }

    public function test_stores_nestjs_controller_decorator_metadata(): void
    {
        $file = $this->createTempFile('users.controller.ts', <<<'TS'
@Controller('/users')
export class UsersController {
    getAll(): User[] {
        return [];
    }
}
TS);

        $result = $this->makeParser()->parse($file);

        $nodeMap = [];
        foreach ($result['nodes'] as $node) {
            $nodeMap[$node->id()] = $node;
        }

        $node = $nodeMap['typescript:users%2Econtroller.UsersController::getAll'] ?? null;
        self::assertNotNull($node, 'Node UsersController::getAll not found');
        self::assertNotNull($node->metadata());
        self::assertSame('nestjs', $node->metadata()['framework']);
    }

    public function test_stores_route_method_decorator_metadata(): void
    {
        $file = $this->createTempFile('api.controller.ts', <<<'TS'
@Controller('/api')
export class ApiController {
    @Get('/users')
    listUsers(): User[] {
        return [];
    }
}
TS);

        $result = $this->makeParser()->parse($file);

        $nodeMap = [];
        foreach ($result['nodes'] as $node) {
            $nodeMap[$node->id()] = $node;
        }

        $node = $nodeMap['typescript:api%2Econtroller.ApiController::listUsers'] ?? null;
        self::assertNotNull($node, 'Node ApiController::listUsers not found');
        self::assertNotNull($node->metadata());
        self::assertSame('GET', $node->metadata()['http_method']);
        self::assertSame('/api/users', $node->metadata()['http_path']);
    }

    public function test_detects_fetch_as_http_call_edge(): void
    {
        $file = $this->createTempFile('api.client.ts', <<<'TS'
export async function getUsers(): Promise<any> {
    return fetch('/api/users');
}
TS);

        $result = $this->makeParser()->parse($file);

        $edgeTos = array_map(fn($e) => $e->to(), $result['edges']);
        self::assertContains('http:GET:/api/users', $edgeTos);

        $httpEdges = array_filter($result['edges'], fn($e) => $e->type() === 'http_call');
        self::assertNotEmpty($httpEdges);
    }

    public function test_detects_axios_get_as_http_call_edge(): void
    {
        $file = $this->createTempFile('data.service.ts', <<<'TS'
export async function loadProducts(): Promise<any> {
    return axios.get('/api/products');
}
TS);

        $result = $this->makeParser()->parse($file);

        $edgeTos = array_map(fn($e) => $e->to(), $result['edges']);
        self::assertContains('http:GET:/api/products', $edgeTos);
    }

    public function test_edges_ignore_lexical_literals_and_keep_executable_calls(): void
    {
        $file = $this->createTempFile('lexical-call-edges.ts', <<<'TS'
function target(): void {}
export function source(): void {
    // target(); fetch('/comment'); axios.get('/comment');
    const text = "target(); fetch('/string'); axios.get('/string')";
    const pattern = /target\(\).*fetch\('\/regex'\)/;
    const template = `target(); fetch('/template'); ${target()}`;
    fetch('/real');
    axios.get('/real');
}
TS);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertSame(1, count(array_filter(
            $edges,
            static fn(array $edge): bool => $edge === [$nodes['source']->id(), $nodes['target']->id(), 'ts_call'],
        )));
        self::assertContains([$nodes['source']->id(), 'http:GET:/real', 'http_call'], $edges);
        self::assertNotContains([$nodes['source']->id(), 'http:GET:/comment', 'http_call'], $edges);
        self::assertNotContains([$nodes['source']->id(), 'http:GET:/string', 'http_call'], $edges);
        self::assertNotContains([$nodes['source']->id(), 'http:GET:/template', 'http_call'], $edges);
        self::assertNotContains([$nodes['source']->id(), 'http:GET:/regex', 'http_call'], $edges);
    }

    public function test_edges_ignore_jsx_text_and_keep_interpolation_calls(): void
    {
        $file = $this->createTempFile('jsx-call-edges.tsx', <<<'TSX'
function target(): void {}
export function Component() {
    return <>
        <div>ação target() fetch('/phantom') axios.get('/also-phantom')</div>
        <span>{target()} {fetch('/real')} {axios.get('/also-real')}</span>
        <Button onClick={() => target()} load={() => fetch('/prop-real')} />
        <Comp render={() => <span>target() {target()}</span>} />
    </>;
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertSame(3, count(array_filter(
            $edges,
            static fn(array $edge): bool => $edge === [$nodes['Component']->id(), $nodes['target']->id(), 'ts_call'],
        )));
        self::assertContains([$nodes['Component']->id(), 'http:GET:/real', 'http_call'], $edges);
        self::assertContains([$nodes['Component']->id(), 'http:GET:/also-real', 'http_call'], $edges);
        self::assertContains([$nodes['Component']->id(), 'http:GET:/prop-real', 'http_call'], $edges);
        self::assertNotContains([$nodes['Component']->id(), 'http:GET:/phantom', 'http_call'], $edges);
        self::assertNotContains([$nodes['Component']->id(), 'http:GET:/also-phantom', 'http_call'], $edges);
    }

    public function test_assigned_jsx_is_not_misclassified_as_a_generic_arrow(): void
    {
        $file = $this->createTempFile('assigned-jsx-call-edges.tsx', <<<'TSX'
function target(): void {}
const topView = <div>A => B target() fetch('/top-phantom')</div>;
export function Component() {
    const view = <div>A => B target() fetch('/phantom')</div>;
    const widget = <Widget<Item> label="target() fetch('/also-phantom')" />;
    target();
    fetch('/real');
    return <>{view}{widget}</>;
}
export class Screen {
    view = <div>target() fetch('/property-phantom')</div>;
    after(): void { target(); }
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertArrayNotHasKey('topView', $nodes);
        self::assertArrayNotHasKey('view', $nodes);
        self::assertSame(1, count(array_filter(
            $edges,
            static fn(array $edge): bool => $edge === [$nodes['Component']->id(), $nodes['target']->id(), 'ts_call'],
        )));
        self::assertContains([$nodes['Component']->id(), 'http:GET:/real', 'http_call'], $edges);
        self::assertNotContains([$nodes['Component']->id(), 'http:GET:/phantom', 'http_call'], $edges);
        self::assertNotContains([$nodes['Component']->id(), 'http:GET:/also-phantom', 'http_call'], $edges);
        self::assertNotContains([$nodes['after']->id(), 'http:GET:/property-phantom', 'http_call'], $edges);
        self::assertNotContains('http:GET:/top-phantom', array_column($edges, 1));
    }

    public function test_function_type_arrow_does_not_close_jsx_type_arguments(): void
    {
        $file = $this->createTempFile('jsx-function-type-argument.tsx', <<<'TSX'
function target(): void {}
export function Component() {
    const view = <Widget<(value: string) => string> />;
    target();
    fetch('/inside-real');
    return view;
}
export function after() {
    target();
    fetch('/after-real');
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertContains([$nodes['Component']->id(), $nodes['target']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['Component']->id(), 'http:GET:/inside-real', 'http_call'], $edges);
        self::assertContains([$nodes['after']->id(), $nodes['target']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['after']->id(), 'http:GET:/after-real', 'http_call'], $edges);
    }

    public function test_jsx_extends_attribute_is_not_a_generic_arrow_constraint(): void
    {
        $file = $this->createTempFile('jsx-extends-attribute.tsx', <<<'TSX'
function target(): void {}
const assignedView = <Snippet extends="Base">
    (value) => target() fetch('/assigned-phantom')
</Snippet>;
const booleanView = <Snippet extends>
    (value) => target() fetch('/phantom')
</Snippet>;
const inlineSpreadView = <Snippet extends {...props}>
    (value) => target() fetch('/inline-spread-phantom')
</Snippet>;
const multilineSpreadView = <Snippet extends
    {...props}>
    (value) => target() fetch('/multiline-spread-phantom')
</Snippet>;
function after(): void {
    target();
    fetch('/after');
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertArrayNotHasKey('assignedView', $nodes);
        self::assertArrayNotHasKey('booleanView', $nodes);
        self::assertArrayNotHasKey('inlineSpreadView', $nodes);
        self::assertArrayNotHasKey('multilineSpreadView', $nodes);
        self::assertNotContains('http:GET:/assigned-phantom', array_column($edges, 1));
        self::assertNotContains('http:GET:/phantom', array_column($edges, 1));
        self::assertNotContains('http:GET:/inline-spread-phantom', array_column($edges, 1));
        self::assertNotContains('http:GET:/multiline-spread-phantom', array_column($edges, 1));
        self::assertContains([$nodes['after']->id(), $nodes['target']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['after']->id(), 'http:GET:/after', 'http_call'], $edges);
    }

    public function test_relational_comparison_in_jsx_interpolation_keeps_real_call(): void
    {
        $file = $this->createTempFile('jsx-relational-call.tsx', <<<'TSX'
function target(): void {}
export function Component(count: number, limit: number) {
    return <div>{count<limit ? target() : null}</div>;
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertContains([$nodes['Component']->id(), $nodes['target']->id(), 'ts_call'], $edges);
    }

    public function test_multiline_jsx_attribute_keeps_tag_state_and_real_calls(): void
    {
        $file = $this->createTempFile('multiline-jsx-attribute.tsx', <<<'TSX'
function target(): void {}
export function Component() {
    return <Panel title="hello
world">
        {target()}
    </Panel>;
}
export function after() {
    target();
}
TSX);

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertSame(7, $nodes['Component']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
        self::assertContains([$nodes['Component']->id(), $nodes['target']->id(), 'ts_call'], $edges);
        self::assertContains([$nodes['after']->id(), $nodes['target']->id(), 'ts_call'], $edges);
    }

    public function test_jsx_text_state_survives_long_multiline_content(): void
    {
        $longText = str_repeat('x', 5000);
        $file = $this->createTempFile(
            'long-jsx-call-edges.tsx',
            "function target(): void {}\n"
            . "export function Component() {\n"
            . "    return <div>\n"
            . "        {$longText}\n"
            . "        target() fetch('/phantom')\n"
            . "    </div>;\n"
            . "}\n",
        );

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertNotContains([$nodes['Component']->id(), $nodes['target']->id(), 'ts_call'], $edges);
        self::assertNotContains([$nodes['Component']->id(), 'http:GET:/phantom', 'http_call'], $edges);
    }

    public function test_jsx_text_state_keeps_interpolation_after_long_text_with_apostrophe(): void
    {
        $longLines = implode("\n", array_fill(0, 5, str_repeat('x', 1000)));
        $file = $this->createTempFile(
            'long-jsx-interpolation.tsx',
            "function target(): void {}\n"
            . "export function Component() {\n"
            . "    return <div>\n"
            . "{$longLines}\n"
            . "don't {target()}\n"
            . "    </div>;\n"
            . "}\n",
        );

        $result = $this->makeParser()->parse($file);
        $nodes = [];
        foreach ($result['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }
        $edges = array_map(
            static fn($edge): array => [$edge->from(), $edge->to(), $edge->type()],
            $result['edges'],
        );

        self::assertContains([$nodes['Component']->id(), $nodes['target']->id(), 'ts_call'], $edges);
    }

    public function test_javascript_file_gets_javascript_language_tag(): void
    {
        $file = $this->createTempFile('utils.js', <<<'JS'
export function helper() {
    return true;
}
JS);

        $result = $this->makeParser('javascript')->parse($file);

        self::assertNotEmpty($result['nodes']);
        self::assertSame('javascript', $result['nodes'][0]->language());
    }

    public function test_handles_empty_file(): void
    {
        $file = $this->createTempFile('empty.ts', '');

        $result = $this->makeParser()->parse($file);

        self::assertSame([], $result['nodes']);
        self::assertSame([], $result['edges']);
    }

    // ── Import tracking ──────────────────────────────────────────────────────

    public function test_detects_export_default_function(): void
    {
        $file = $this->createTempFile('page.tsx', <<<'TS'
export default function Page() {
    return null;
}
TS);

        $result = $this->makeParser()->parse($file);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        self::assertContains('typescript:page::Page', $ids);
    }

    public function test_emits_virtual_import_edge_for_named_import(): void
    {
        // Create the target file so we have a real module path reference
        $subDir = $this->tempDir . '/lib';
        mkdir($subDir, 0777, true);
        $file = $this->createTempFile('page.ts', <<<'TS'
import { formatDate } from './lib/utils';

export function render(): void {
    formatDate(new Date());
}
TS);

        $result = $this->makeParser()->parse($file);

        $importEdges = array_filter(
            $result['edges'],
            fn($e) => $e->type() === 'import_call'
        );
        self::assertNotEmpty($importEdges, 'Expected at least one import_call edge');

        $tos = array_map(fn($e) => $e->to(), array_values($importEdges));
        self::assertContains('ts_import:lib.utils::formatDate', $tos);
    }

    public function test_emits_virtual_import_edge_for_default_import(): void
    {
        $file = $this->createTempFile('app.ts', <<<'TS'
import Button from './components/Button';

export function render(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $importEdges = array_filter($result['edges'], fn($e) => $e->type() === 'import_call');
        $tos = array_map(fn($e) => $e->to(), array_values($importEdges));
        self::assertContains('ts_import:components.Button::Button', $tos);
    }

    public function test_skips_npm_package_imports(): void
    {
        $file = $this->createTempFile('widget.ts', <<<'TS'
import { useState } from 'react';
import { QueryClient } from '@tanstack/react-query';

export function Widget(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $importEdges = array_filter($result['edges'], fn($e) => $e->type() === 'import_call');
        self::assertEmpty($importEdges, 'npm package imports must not produce import_call edges');
    }

    public function test_skips_type_only_imports(): void
    {
        $file = $this->createTempFile('typed.ts', <<<'TS'
import type { User } from './types';
import { getUser } from './api';

export function load(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $importEdges = array_filter($result['edges'], fn($e) => $e->type() === 'import_call');
        $tos = array_map(fn($e) => $e->to(), array_values($importEdges));

        // type-only import must not appear
        self::assertNotContains('ts_import:types::User', $tos);
        // value import must appear
        self::assertContains('ts_import:api::getUser', $tos);
    }

    public function test_resolves_dotdot_relative_import(): void
    {
        // File is at tempDir/src/app/page.ts; imports from '../lib/utils'
        $srcDir = $this->tempDir . '/src/app';
        mkdir($srcDir, 0777, true);
        $file = $srcDir . '/page.ts';
        file_put_contents($file, <<<'TS'
import { helper } from '../lib/utils';

export function Page(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $importEdges = array_filter($result['edges'], fn($e) => $e->type() === 'import_call');
        $tos = array_map(fn($e) => $e->to(), array_values($importEdges));
        // ../lib/utils from src/app/ resolves to src/lib/utils
        self::assertContains('ts_import:src.lib.utils::helper', $tos);
    }

    public function test_resolves_at_slash_alias_import(): void
    {
        // @/ maps to {projectRoot}/src/
        $file = $this->createTempFile('admin.ts', <<<'TS'
import { apiClient } from '@/shared/lib/api-client';

export function Admin(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $importEdges = array_filter($result['edges'], fn($e) => $e->type() === 'import_call');
        $tos = array_map(fn($e) => $e->to(), array_values($importEdges));
        self::assertContains('ts_import:src.shared.lib.api-client::apiClient', $tos);
    }

    public function test_no_import_edges_when_file_has_no_nodes(): void
    {
        // Type-declaration-only file: has imports but no exported functions
        $file = $this->createTempFile('types.ts', <<<'TS'
import type { Config } from './config';

export type UserType = { id: string };
TS);

        $result = $this->makeParser()->parse($file);

        $importEdges = array_filter($result['edges'], fn($e) => $e->type() === 'import_call');
        self::assertEmpty($importEdges, 'No import edges without a from-node in the file');
    }

    public function test_method_endline_multiline_body(): void
    {
        $file = $this->createTempFile('multi.ts', <<<'TS'
export class Foo {
    bar(): void {
        const x = 1;
        return;
    }
}
TS);

        $result = $this->makeParser()->parse($file);

        $bar = null;
        foreach ($result['nodes'] as $node) {
            if ($node->method() === 'bar') {
                $bar = $node;
                break;
            }
        }

        self::assertNotNull($bar);
        $meta = $bar->metadata();
        self::assertIsArray($meta);
        self::assertArrayHasKey('endLine', $meta);
        self::assertSame(5, $meta['endLine']);
    }

    public function test_method_endline_inline_body(): void
    {
        $file = $this->createTempFile('inline.ts', <<<'TS'
export class Foo {
    bar(): number { return 1; }
}
TS);

        $result = $this->makeParser()->parse($file);

        $bar = null;
        foreach ($result['nodes'] as $node) {
            if ($node->method() === 'bar') {
                $bar = $node;
                break;
            }
        }

        self::assertNotNull($bar);
        $meta = $bar->metadata();
        self::assertIsArray($meta);
        self::assertArrayHasKey('endLine', $meta);
        self::assertSame(2, $meta['endLine']);
    }

    public function test_exported_function_endline(): void
    {
        $file = $this->createTempFile('export.ts', <<<'TS'
export function compute(x: number): number {
    const a = x + 1;
    return a;
}
TS);

        $result = $this->makeParser()->parse($file);

        $fn = null;
        foreach ($result['nodes'] as $node) {
            if ($node->method() === 'compute') {
                $fn = $node;
                break;
            }
        }

        self::assertNotNull($fn);
        $meta = $fn->metadata();
        self::assertIsArray($meta);
        self::assertArrayHasKey('endLine', $meta);
        self::assertSame(4, $meta['endLine']);
    }

    public function test_method_endline_multiline_signature(): void
    {
        $file = $this->createTempFile('multisig.ts', <<<'TS'
export class Foo {
    bar(
        arg: string,
        other: number,
    ): void {
        return;
    }
}
TS);

        $result = $this->makeParser()->parse($file);

        $bar = null;
        foreach ($result['nodes'] as $node) {
            if ($node->method() === 'bar') {
                $bar = $node;
                break;
            }
        }

        self::assertNotNull($bar);
        $meta = $bar->metadata();
        self::assertIsArray($meta);
        self::assertArrayHasKey('endLine', $meta);
        self::assertSame(7, $meta['endLine']);
    }

    public function test_function_endline_with_type_literal_in_signature(): void
    {
        $file = $this->createTempFile('typelit.ts', <<<'TS'
export function process(arg: { id: string },
                        other: number): void {
    return;
}
TS);

        $result = $this->makeParser()->parse($file);

        $fn = null;
        foreach ($result['nodes'] as $node) {
            if ($node->method() === 'process') {
                $fn = $node;
                break;
            }
        }

        self::assertNotNull($fn);
        $meta = $fn->metadata();
        self::assertIsArray($meta);
        self::assertArrayHasKey('endLine', $meta);
        self::assertSame(4, $meta['endLine']);
    }

    public function test_function_endline_with_multiline_return_type_literal(): void
    {
        $file = $this->createTempFile('return-type-literal.ts', <<<'TS'
export function build():
{
    value: number;
}
{
    return {value: 1};
}
TS);

        $node = $this->makeParser()->parse($file)['nodes'][0] ?? null;

        self::assertNotNull($node);
        self::assertSame('build', $node->method());
        self::assertSame(7, $node->metadata()['endLine'] ?? null);
    }

    public function test_method_endline_with_multiline_return_type_literal(): void
    {
        $file = $this->createTempFile('method-return-type-literal.ts', <<<'TS'
export class Service {
    build():
    {
        value: number;
    }
    {
        return {value: 1};
    }
    after(): void {}
}
TS);

        $nodes = [];
        foreach ($this->makeParser()->parse($file)['nodes'] as $node) {
            $nodes[$node->method()] = $node;
        }

        self::assertArrayHasKey('build', $nodes);
        self::assertSame(8, $nodes['build']->metadata()['endLine'] ?? null);
        self::assertArrayHasKey('after', $nodes);
    }

    public function test_expression_bodied_arrow_endline(): void
    {
        $file = $this->createTempFile('arrow.ts', <<<'TS'
export const compute = (x: number) => x + 1;
TS);

        $result = $this->makeParser()->parse($file);

        $fn = null;
        foreach ($result['nodes'] as $node) {
            if ($node->method() === 'compute') {
                $fn = $node;
                break;
            }
        }

        self::assertNotNull($fn);
        $meta = $fn->metadata();
        self::assertIsArray($meta);
        self::assertArrayHasKey('endLine', $meta);
        self::assertSame(1, $meta['endLine']);
    }

    // ── Symbol collection ─────────────────────────────────────────────────────

    public function test_symbols_includes_npm_import(): void
    {
        $file = $this->createTempFile('widget.ts', <<<'TS'
import { TriangleAlertIcon, AlertCircle } from 'lucide-react';
import { useState } from 'react';

export function Widget(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $names = array_map(fn($s) => $s->name, $result['symbols']);
        self::assertContains('TriangleAlertIcon', $names);
        self::assertContains('AlertCircle', $names);
        self::assertContains('useState', $names);
    }

    public function test_symbols_npm_import_sets_source_module(): void
    {
        $file = $this->createTempFile('alert.ts', <<<'TS'
import { TriangleAlertIcon } from 'lucide-react';

export function Alert(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $sym = null;
        foreach ($result['symbols'] as $s) {
            if ($s->name === 'TriangleAlertIcon') {
                $sym = $s;
                break;
            }
        }

        self::assertNotNull($sym);
        self::assertSame('import', $sym->kind);
        self::assertSame('lucide-react', $sym->sourceModule);
    }

    public function test_symbols_import_uses_local_alias_name(): void
    {
        $file = $this->createTempFile('aliased.ts', <<<'TS'
import { Foo as Bar } from 'some-lib';

export function use(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $names = array_map(fn($s) => $s->name, $result['symbols']);
        self::assertContains('Bar', $names);
        self::assertNotContains('Foo', $names);
    }

    public function test_symbols_export_function_and_export_const(): void
    {
        $file = $this->createTempFile('exports.ts', <<<'TS'
export function formatDate(date: Date): string {
    return date.toISOString();
}

export const MAX_RETRY = 3;
TS);

        $result = $this->makeParser()->parse($file);

        $byName = [];
        foreach ($result['symbols'] as $s) {
            $byName[$s->name] = $s;
        }

        self::assertArrayHasKey('formatDate', $byName);
        self::assertSame('export_function', $byName['formatDate']->kind);
        self::assertArrayHasKey('MAX_RETRY', $byName);
        self::assertSame('export_const', $byName['MAX_RETRY']->kind);
    }

    public function test_symbols_type_only_imports_are_excluded(): void
    {
        $file = $this->createTempFile('typed.ts', <<<'TS'
import type { User } from './types';
import { getUser } from './api';

export function load(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $names = array_map(fn($s) => $s->name, $result['symbols']);
        self::assertNotContains('User', $names);
        self::assertContains('getUser', $names);
    }

    public function test_symbols_mixed_default_and_named_import(): void
    {
        $file = $this->createTempFile('mixed.ts', <<<'TS'
import React, { useState, useEffect } from 'react';
import Button, { ButtonProps } from './Button';

export function App(): void {}
TS);

        $result = $this->makeParser()->parse($file);

        $names = array_map(fn($s) => $s->name, $result['symbols']);
        self::assertContains('React', $names, 'default name from mixed import must be indexed');
        self::assertContains('useState', $names, 'named import must be indexed');
        self::assertContains('useEffect', $names, 'second named import must be indexed');
        self::assertContains('Button', $names, 'default name from local mixed import must be indexed');
        self::assertContains('ButtonProps', $names, 'named import from local mixed must be indexed');
    }
}
