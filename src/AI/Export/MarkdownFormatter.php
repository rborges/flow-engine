<?php

namespace FlowEngine\AI\Export;

use FlowEngine\Application\DTO\MetricsReportDTO;
use FlowEngine\Application\DTO\ComplexityReportDTO;
use FlowEngine\Application\DTO\CycleReportDTO;
use FlowEngine\Application\DTO\ArchitectureReportDTO;
use FlowEngine\Application\DTO\OrphanReportDTO;
use FlowEngine\Application\DTO\RefactorGuidanceDTO;
use FlowEngine\Application\DTO\RefactorPlanDTO;
use FlowEngine\Application\DTO\RefactorValidationDTO;
use FlowEngine\Application\DTO\LargeScaleSimulationDTO;

final class MarkdownFormatter
{
    /**
     * @param array<string, string>|null $signatures nodeId => formatted signature string
     */
    public function formatMetrics(MetricsReportDTO $report, ?array $signatures = null): string
    {
        $md = "## Metrics\n\n";
        $md .= "| Metric | Value |\n|---|---|\n";
        $md .= "| Total nodes | {$report->totalNodes} |\n";
        $md .= "| Total edges | {$report->totalEdges} |\n";
        $md .= "| Avg fan-in | " . round($report->avgFanIn, 2) . " |\n";
        $md .= "| Avg fan-out | " . round($report->avgFanOut, 2) . " |\n";
        $md .= "| Max fan-in | {$report->maxFanIn} |\n";
        $md .= "| Max fan-out | {$report->maxFanOut} |\n";
        $md .= "| Hotspot count | {$report->hotspotCount} |\n";

        if ($report->hotspots !== []) {
            $md .= "\n### Hotspots\n\n";
            foreach ($report->hotspots as $h) {
                $id = $h['nodeId'] ?? $h['id'] ?? 'unknown';
                $fanIn = $h['fanIn'] ?? '?';
                $fanOut = $h['fanOut'] ?? '?';
                $label = $signatures[$id] ?? $id;
                $md .= "- {$label} (fan-in: {$fanIn}, fan-out: {$fanOut})\n";
            }
        }

        if ($report->topCoupled !== []) {
            $md .= "\n### Top Coupled\n\n";
            foreach ($report->topCoupled as $c) {
                $id = $c['nodeId'] ?? $c['id'] ?? 'unknown';
                $coupling = $c['coupling'] ?? $c['totalCoupling'] ?? '?';
                $md .= "- {$id} (coupling: {$coupling})\n";
            }
        }

        return $md;
    }

    public function formatComplexity(ComplexityReportDTO $report): string
    {
        $md = "## Complexity\n\n";
        $md .= "| Metric | Value |\n|---|---|\n";
        $md .= "| Total methods | {$report->totalMethods} |\n";
        $md .= "| Avg complexity | " . round($report->avgComplexity, 2) . " |\n";
        $md .= "| Max complexity | {$report->maxComplexity} |\n";
        $md .= "| Min complexity | {$report->minComplexity} |\n";

        if ($report->byLevel !== []) {
            $md .= "\n### By Level\n\n";
            foreach ($report->byLevel as $level => $count) {
                $md .= "- {$level}: {$count}\n";
            }
        }

        if ($report->complexMethods !== []) {
            $md .= "\n### Complex Methods\n\n";
            foreach ($report->complexMethods as $m) {
                $id = $m['nodeId'] ?? 'unknown';
                $complexity = $m['complexity'] ?? '?';
                $level = $m['level'] ?? '?';
                $md .= "- {$id} (complexity: {$complexity}, level: {$level})\n";
            }
        }

        return $md;
    }

    public function formatCycles(CycleReportDTO $report): string
    {
        $md = "## Dependency Cycles\n\n";
        $md .= "| Metric | Value |\n|---|---|\n";
        $md .= "| Total cycles | {$report->totalCycles} |\n";
        $md .= "| Nodes in cycles | {$report->totalNodesInCycles} |\n";
        $md .= "| Largest cycle | {$report->largestCycle} |\n";

        if ($report->bySeverity !== []) {
            $md .= "\n### By Severity\n\n";
            foreach ($report->bySeverity as $severity => $count) {
                $md .= "- {$severity}: {$count}\n";
            }
        }

        if ($report->cycles !== []) {
            $md .= "\n### Cycles\n\n";
            foreach ($report->cycles as $i => $cycle) {
                $nodes = $cycle['nodes'] ?? $cycle['path'] ?? [];
                $nodeList = is_array($nodes) ? implode(' -> ', $nodes) : (string) $nodes;
                $md .= ($i + 1) . ". {$nodeList}\n";
            }
        }

        return $md;
    }

    public function formatArchitecture(ArchitectureReportDTO $report): string
    {
        $md = "## Architecture\n\n";
        $md .= "- Status: " . ($report->isClean ? 'Clean' : 'Violations found') . "\n";
        $md .= "- Total violations: {$report->totalViolations}\n";

        if ($report->layerDistribution !== []) {
            $md .= "\n### Layer Distribution\n\n";
            $md .= "| Layer | Count |\n|---|---|\n";
            foreach ($report->layerDistribution as $layer => $count) {
                $md .= "| {$layer} | {$count} |\n";
            }
        }

        if ($report->bySeverity !== []) {
            $md .= "\n### Violations by Severity\n\n";
            foreach ($report->bySeverity as $severity => $count) {
                $md .= "- {$severity}: {$count}\n";
            }
        }

        if ($report->violations !== []) {
            $md .= "\n### Violations\n\n";
            foreach ($report->violations as $v) {
                $from = $v['from'] ?? $v['source'] ?? 'unknown';
                $to = $v['to'] ?? $v['target'] ?? 'unknown';
                $type = $v['type'] ?? $v['rule'] ?? 'unknown';
                $md .= "- {$from} -> {$to} ({$type})\n";
            }
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $appmap Output from ApplicationMapBuilder::build()
     */
    public function formatServiceMap(array $appmap): string
    {
        $md = "## Service Map\n\n";

        $services = $appmap['services'] ?? [];
        $integrationEdges = $appmap['integrationEdges'] ?? [];
        $serviceEdges = $appmap['serviceEdges'] ?? [];

        // Services table
        $md .= "### Services (" . count($services) . ")\n\n";
        $md .= "| Service | Language | Methods | Dependencies |\n";
        $md .= "|---------|----------|---------|-------------|\n";

        foreach ($services as $svc) {
            $name = $svc['name'] ?? 'unknown';
            $langs = implode(', ', $svc['languages'] ?? []);
            $nodes = $svc['stats']['nodeCount'] ?? 0;
            $edges = $svc['stats']['edgeCount'] ?? 0;
            if (($svc['status'] ?? 'ok') === 'failed') {
                $error = str_replace(['|', "\n"], ['\\|', ' '], (string) ($svc['error'] ?? ''));
                $md .= "| {$name} | analysis failed: {$error} | - | - |\n";
                continue;
            }
            $md .= "| {$name} | {$langs} | {$nodes} | {$edges} |\n";
        }

        // Endpoints per service
        foreach ($services as $svc) {
            $endpoints = $svc['endpoints'] ?? [];
            if ($endpoints === []) {
                continue;
            }
            $name = $svc['name'] ?? 'unknown';
            $md .= "\n### Endpoints ({$name})\n\n";
            $md .= "| Method | Path | Handler |\n|--------|------|---------|\n";
            foreach ($endpoints as $ep) {
                $md .= "| {$ep['method']} | {$ep['path']} | {$ep['handler']} |\n";
            }
        }

        // Integration edges table
        $httpEdges = array_values(array_filter(
            $integrationEdges,
            fn(array $e) => ($e['type'] ?? '') === 'http'
        ));

        if ($httpEdges !== []) {
            $md .= "\n### Integration Edges (" . count($httpEdges) . " HTTP calls)\n\n";
            $md .= "| From (Service) | To (Service) | Method | Endpoint |\n";
            $md .= "|----------------|-------------|--------|----------|\n";

            foreach ($httpEdges as $edge) {
                $from = $edge['fromService'] ?? '?';
                $to = $edge['toService'] ?? '[unresolved]';
                $method = $edge['fromNode'] ?? '?';
                $target = $edge['target'] ?? '?';
                $md .= "| {$from} | {$to} | {$method} | {$target} |\n";
            }
        }

        // Service dependency graph
        if ($serviceEdges !== []) {
            $md .= "\n### Service Dependency Graph\n\n";

            foreach ($serviceEdges as $se) {
                $from = $se['from'] ?? '?';
                $to = $se['to'] ?? '?';
                $count = $se['count'] ?? 0;
                $type = $se['type'] ?? '';
                $label = $count === 1 ? "1 {$type} call" : "{$count} {$type} calls";
                $md .= "{$from} -> {$to} ({$label})\n";
            }
        }

        return $md;
    }

    public function formatOrphans(OrphanReportDTO $report): string
    {
        $md = "## Orphan Code\n\n";
        $md .= "| Metric | Value |\n|---|---|\n";
        $md .= "| Total orphans | {$report->totalOrphans} |\n";
        $md .= "| High confidence | {$report->highConfidenceOrphans} |\n";
        $md .= "| Suspicious leaf nodes | {$report->suspiciousLeafNodes} |\n";
        $md .= "| Percentage orphans | " . round($report->percentageOrphans, 2) . "% |\n";

        if ($report->orphans !== []) {
            $md .= "\n### Orphans\n\n";
            foreach ($report->orphans as $o) {
                $id = $o['nodeId'] ?? $o['id'] ?? 'unknown';
                $confidence = $o['confidence'] ?? '?';
                $md .= "- {$id} (confidence: {$confidence})\n";
            }
        }

        if ($report->suspiciousLeaves !== []) {
            $md .= "\n### Suspicious Leaf Nodes\n\n";
            foreach ($report->suspiciousLeaves as $l) {
                $id = $l['nodeId'] ?? $l['id'] ?? 'unknown';
                $md .= "- {$id}\n";
            }
        }

        return $md;
    }

    public function formatRefactorPlan(RefactorPlanDTO $plan): string
    {
        $md = "# Refactoring Plan: {$plan->nodeId}\n\n";

        // Why Refactor?
        $md .= "## Why Refactor?\n\n";
        $md .= "{$plan->detectionReason}\n\n";

        // Risk Assessment
        $md .= "## Risk Assessment\n\n";
        $md .= "| Metric | Value |\n|---|---|\n";
        $md .= "| Risk Level | {$plan->overallRisk} |\n";
        $md .= "| Risk Score | {$plan->riskScore} / 100 |\n";
        $md .= "| Estimated Complexity | {$plan->estimatedComplexity} / 10 |\n";

        // Risk Factors
        if ($plan->riskFactors !== []) {
            $md .= "\n### Risk Factors\n\n";
            $md .= "| Factor | Value | Contribution |\n|---|---|---|\n";
            foreach ($plan->riskFactors as $factor) {
                $name = $factor['name'] ?? 'unknown';
                $value = round($factor['value'] ?? 0, 2);
                $contribution = round($factor['contribution'] ?? 0, 2);
                $md .= "| {$name} | {$value} | {$contribution}% |\n";
            }
        }

        // Prerequisites
        if ($plan->prerequisites !== []) {
            $md .= "\n## Prerequisites\n\n";
            $md .= "These issues must be resolved before refactoring:\n\n";
            foreach ($plan->prerequisites as $i => $prereq) {
                $num = $i + 1;
                $md .= "### {$num}. {$prereq->type} ({$prereq->severity})\n\n";
                $md .= "{$prereq->description}\n\n";
                $md .= "**Affected nodes:**\n";
                foreach ($prereq->affectedNodes as $node) {
                    $md .= "- {$node}\n";
                }
                $md .= "\n**Recommendation:** {$prereq->recommendation}\n\n";
            }
        } else {
            $md .= "\n## Prerequisites\n\n";
            $md .= "No blocking issues detected. Safe to proceed with refactoring.\n\n";
        }

        // Refactoring Steps
        if ($plan->steps !== []) {
            $md .= "## Refactoring Steps\n\n";
            foreach ($plan->steps as $step) {
                $md .= "### Step {$step->order}: {$step->action}\n\n";
                $md .= "**Target:** `{$step->target}`\n\n";
                $md .= "**Priority:** {$step->priority}\n\n";
                $md .= "**Rationale:** {$step->rationale}\n\n";

                if ($step->affectedNodes !== []) {
                    $md .= "**Affected nodes:**\n";
                    foreach ($step->affectedNodes as $node) {
                        $md .= "- {$node}\n";
                    }
                    $md .= "\n";
                }

                if ($step->tests !== []) {
                    $md .= "**Tests:**\n";
                    foreach ($step->tests as $test) {
                        $md .= "- {$test}\n";
                    }
                    $md .= "\n";
                }
            }
        } else {
            $md .= "## Refactoring Steps\n\n";
            $md .= "No specific steps required. This node is simple enough for direct refactoring.\n\n";
        }

        // Testing Guidance
        if ($plan->testingGuidance !== []) {
            $md .= "## Testing Guidance\n\n";
            foreach ($plan->testingGuidance as $guidance) {
                $md .= "- {$guidance}\n";
            }
            $md .= "\n";
        }

        // Metadata footer
        $tokensUsed = $plan->metadata['tokensUsed'] ?? 0;
        $trivial = $plan->metadata['trivial'] ?? false;
        $md .= "---\n\n";
        $md .= "*Tokens used: {$tokensUsed}";
        if ($trivial) {
            $md .= " (trivial node, no LLM call)*\n";
        } else {
            $md .= "*\n";
        }

        return $md;
    }

    public function formatRefactorGuidance(RefactorGuidanceDTO $guidance): string
    {
        $md = "# Step {$guidance->stepOrder} Guidance: {$guidance->stepAction}\n\n";
        $md .= "**Node:** `{$guidance->nodeId}`\n";
        $md .= "**Target:** `{$guidance->stepTarget}`\n";
        $md .= "**Estimated Effort:** {$guidance->estimatedEffort}\n\n";

        if ($guidance->actionableInstructions !== []) {
            $md .= "## Actionable Instructions\n\n";
            foreach ($guidance->actionableInstructions as $i => $instruction) {
                $num = $i + 1;
                $md .= "{$num}. {$instruction}\n";
            }
            $md .= "\n";
        }

        if ($guidance->codePatterns !== []) {
            $md .= "## Code Patterns\n\n";
            foreach ($guidance->codePatterns as $pattern) {
                $md .= "```\n{$pattern}\n```\n\n";
            }
        }

        if ($guidance->warningsToAvoid !== []) {
            $md .= "## Warnings to Avoid\n\n";
            foreach ($guidance->warningsToAvoid as $warning) {
                $md .= "- {$warning}\n";
            }
            $md .= "\n";
        }

        if ($guidance->verificationChecklist !== []) {
            $md .= "## Verification Checklist\n\n";
            foreach ($guidance->verificationChecklist as $item) {
                $md .= "- [ ] {$item}\n";
            }
            $md .= "\n";
        }

        $tokensUsed = $guidance->metadata['tokensUsed'] ?? 0;
        $llmConfigured = $guidance->metadata['llmConfigured'] ?? false;
        $md .= "---\n\n";
        $md .= "*Tokens used: {$tokensUsed}";
        if (!$llmConfigured) {
            $md .= " (LLM not configured — basic guidance only)*\n";
        } else {
            $md .= "*\n";
        }

        return $md;
    }

    public function formatSimulation(LargeScaleSimulationDTO $simulation): string
    {
        $md  = "# Large-Scale Refactoring Simulation\n\n";
        $md .= "**Scope:** `{$simulation->scope}`  \n";
        $md .= "**Total nodes:** {$simulation->totalNodes}  \n";
        $md .= "**Total risk score:** {$simulation->totalRiskScore}  \n";
        $md .= "**Phases:** " . count($simulation->phases) . "  \n";

        $conflictCount = $simulation->metadata['conflictCount'] ?? 0;
        if ($conflictCount > 0) {
            $md .= "**Conflicting nodes:** {$conflictCount} (mutual dependencies — see last phase)  \n";
        }

        $md .= "\n---\n\n";

        foreach ($simulation->phases as $phase) {
            $md .= "## {$phase->label}\n\n";
            $md .= "{$phase->rationale}\n\n";
            $md .= "| Metric | Value |\n|---|---|\n";
            $md .= "| Nodes | {$phase->nodeCount} |\n";
            $md .= "| Phase risk score | {$phase->totalRiskScore} |\n\n";

            if ($phase->nodes !== []) {
                $md .= "| Node | Risk | Score | Fan-in | Fan-out | Cycles | Violations |\n";
                $md .= "|---|---|---|---|---|---|---|\n";
                foreach ($phase->nodes as $node) {
                    $deps = count($node->dependsOn) > 0
                        ? implode(', ', $node->dependsOn)
                        : '—';
                    $md .= "| `{$node->nodeId}` | {$node->riskLevel} | {$node->riskScore} ";
                    $md .= "| {$node->fanIn} | {$node->fanOut} | {$node->cyclesCount} | {$node->violationsCount} |\n";
                    if (count($node->dependsOn) > 0) {
                        $md .= "> Depends on: {$deps}\n\n";
                    }
                }
                $md .= "\n";
            }
        }

        if ($simulation->conflictingPairs !== []) {
            $md .= "## Conflicting Pairs\n\n";
            $md .= "These node pairs have direct mutual dependencies and must be refactored together:\n\n";
            foreach ($simulation->conflictingPairs as $pair) {
                $md .= "- `{$pair['nodeA']}` ↔ `{$pair['nodeB']}`\n";
            }
            $md .= "\n";
        }

        $generatedAt = $simulation->metadata['generatedAt'] ?? 'unknown';
        $md .= "---\n\n*Generated: {$generatedAt}*\n";

        return $md;
    }

    public function formatRefactorValidation(RefactorValidationDTO $validation): string
    {
        $status = $validation->passed ? 'PASSED' : 'FAILED';
        $md = "# Step {$validation->stepOrder} Validation: {$status}\n\n";
        $md .= "**Node:** `{$validation->nodeId}`\n\n";

        if ($validation->findings !== []) {
            $md .= "## Findings\n\n";
            foreach ($validation->findings as $finding) {
                $md .= "- {$finding}\n";
            }
            $md .= "\n";
        }

        $md .= "## Metrics Comparison\n\n";
        $md .= "| Metric | Baseline | Current |\n|---|---|---|\n";
        $md .= "| Fan-in | {$validation->baselineMetrics['fanIn']} | {$validation->currentMetrics['fanIn']} |\n";
        $md .= "| Fan-out | {$validation->baselineMetrics['fanOut']} | {$validation->currentMetrics['fanOut']} |\n";
        $md .= "| Blast radius | {$validation->baselineMetrics['blastRadius']} | {$validation->currentMetrics['blastRadius']} |\n\n";

        $md .= "## Recommendation\n\n";
        $md .= "{$validation->recommendation}\n";

        return $md;
    }
}
