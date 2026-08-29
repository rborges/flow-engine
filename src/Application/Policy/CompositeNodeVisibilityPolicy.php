<?php

namespace FlowEngine\Application\Policy;

use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Node\NodeVisibility;

class CompositeNodeVisibilityPolicy implements NodeVisibilityPolicy
{
    /** @var NodeVisibilityPolicy[] */
    private array $policies;

    private VisibilityResolutionReport $report;

    private VisibilityCompositionStrategy $strategy;

    /**
     * @param NodeVisibilityPolicy[] $policies Ordered list
     */
    public function __construct(
        array $policies,
        ?VisibilityCompositionStrategy $strategy = null
    ) {
        $this->policies = $policies;
        $this->report   = new VisibilityResolutionReport();
        $this->strategy = $strategy ?? new StrictVisibilityStrategy();
    }

    public function visibility(Node $node): ?NodeVisibility
    {
        // Report is scoped to a single visibility() resolution: report() is meant
        // to explain *this* node's decision (see NodeVisibilityResolver::explain()).
        // Without this reset, every prior node's decisions would stay in the
        // report forever: compose() below would re-walk an ever-growing list on
        // every call -- quadratic over the whole node set -- and report() would
        // return stale results.
        $this->report = new VisibilityResolutionReport();

        foreach ($this->policies as $policy) {
            $visibility = $policy->visibility($node);

            $source = ($policy instanceof PolicyWithSource)
                ? $policy->source()
                : VisibilityPolicySource::DEFAULT;

            $policyClass = ($policy instanceof PolicyWithSource)
                ? $policy->policyClass()
                : $policy::class;

            if ($visibility === null) {
                $this->report->add(new VisibilityDecisionResult(
                    $policyClass,
                    $source,
                    VisibilityDecision::ABSTAIN,
                    null
                ));
                continue;
            }

            $decision = $visibility->isPublic()
                ? VisibilityDecision::ALLOW
                : VisibilityDecision::DENY;

            $this->report->add(new VisibilityDecisionResult(
                $policyClass,
                $source,
                $decision,
                $visibility
            ));
        }

        return $this->strategy->compose($this->report->results());
    }

    /**
     * Scoped to the most recent visibility() call; empty before the first one.
     */
    public function report(): VisibilityResolutionReport
    {
        return $this->report;
    }
}
