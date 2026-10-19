<?php

namespace Tests\Application\Policy;

use PHPUnit\Framework\TestCase;
use FlowEngine\Application\Policy\CompositeNodeVisibilityPolicy;
use FlowEngine\Application\Policy\DefaultNodeVisibilityPolicy;
use FlowEngine\Application\Policy\LaravelNodeVisibilityPolicy;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Application\Policy\NodeVisibilityPolicy;
use FlowEngine\Application\Policy\VisibilityDecision;
use FlowEngine\Domain\Node\NodeVisibility;
use LogicException;

final class CompositeNodeVisibilityPolicyTest extends TestCase
{
    public function test_order_is_respected(): void
    {
        $policy = new CompositeNodeVisibilityPolicy([
            new LaravelNodeVisibilityPolicy(), // ABSTAIN neste caso
            new DefaultNodeVisibilityPolicy(), // ALLOW
        ]);

        $node = new Node(
            'App\\Service\\MyService',
            'run',
            'MyService.php',
            20
        );

        $visibility = $policy->visibility($node);

        $this->assertTrue($visibility->isPublic());
    }

    public function test_default_applies_when_all_abstain(): void
    {
        $policy = new CompositeNodeVisibilityPolicy([
            new LaravelNodeVisibilityPolicy(),
            new DefaultNodeVisibilityPolicy(),
        ]);

        $node = new Node(
            'App\\Service\\MyService',
            'run',
            'MyService.php',
            20
        );

        $visibility = $policy->visibility($node);

        $this->assertTrue($visibility->isPublic());
    }

    public function test_conflicting_policies_are_detected(): void
    {
        $this->expectException(LogicException::class);

        $policy = new CompositeNodeVisibilityPolicy([
            new DefaultNodeVisibilityPolicy(),
            new LaravelNodeVisibilityPolicy(),
        ]);

        $node = new Node(
            'Illuminate\\Support\\Collection',
            'make',
            'Collection.php',
            10
        );

        $policy->visibility($node);
    }

    /**
     * Regression: report() must reflect only the most recently resolved node.
     * Before the fix, VisibilityResolutionReport was never reset between
     * calls, so it grew forever across the whole node set: report() leaked
     * every prior node's decisions, and StrictVisibilityStrategy::compose()
     * re-walked that ever-growing list on every single visibility() call --
     * quadratic over the project (observed as an analyze() over thousands
     * of nodes that never finished, pinning a CPU core with no filesystem
     * I/O).
     */
    public function test_report_is_scoped_to_the_last_resolved_node_only(): void
    {
        // A single policy that abstains for the first 49 nodes (App\*) and
        // decides only for the last one (Illuminate\*). On the pre-fix code
        // the report accumulates one entry per resolution, so the count
        // assertion below fails cleanly by assertion (51 entries instead of 1)
        // rather than through an unrelated strategy conflict; a wrong fix
        // that froze an earlier report would show ABSTAIN instead of DENY.
        $selective = new class implements NodeVisibilityPolicy {
            public function visibility(Node $node): ?NodeVisibility
            {
                if (str_starts_with($node->class(), 'Illuminate\\')) {
                    return new NodeVisibility(NodeVisibility::HIDDEN);
                }

                return null;
            }
        };
        $policy = new CompositeNodeVisibilityPolicy([$selective]);

        // Nothing resolved yet: the report starts empty.
        $this->assertCount(0, $policy->report()->results());

        $nodes = [];
        for ($i = 0; $i < 49; $i++) {
            $nodes[] = new Node(
                'App\\Service\\Service' . $i,
                'run',
                'Service' . $i . '.php',
                20
            );
        }
        $nodes[] = new Node('Illuminate\\Support\\Str', 'of', 'Str.php', 10);

        // After the first resolution the report holds that node's entry only.
        $policy->visibility($nodes[0]);
        $this->assertCount(1, $policy->report()->results());
        $this->assertSame(
            VisibilityDecision::ABSTAIN,
            $policy->report()->results()[0]->decision
        );

        foreach ($nodes as $node) {
            $policy->visibility($node);
        }

        // One decision per policy, for the LAST node only -- not one per
        // policy per node ever resolved (which would be 51 here).
        $results = $policy->report()->results();
        $this->assertCount(1, $results);
        $this->assertSame(VisibilityDecision::DENY, $results[0]->decision);
        $this->assertNotNull($results[0]->visibility);
        $this->assertFalse($results[0]->visibility->isPublic());
    }
}
