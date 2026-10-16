<?php

namespace FlowEngine\Application\AppMap;

final class AppMapDiffAnalyzer
{
    /**
     * Serviços que falharam na análise; diff contra eles acusaria arestas removidas que não foram.
     *
     * @param array<string, mixed> $appmap Envelope `{appmap}` ou o mapa em si.
     * @return string[]
     */
    public function failedServices(array $appmap): array
    {
        $map = isset($appmap['appmap']) && is_array($appmap['appmap']) ? $appmap['appmap'] : $appmap;

        $failed = [];
        foreach ($map['services'] ?? [] as $service) {
            if (is_array($service) && ($service['status'] ?? 'ok') === 'failed') {
                $failed[] = (string) ($service['name'] ?? '');
            }
        }

        return $failed;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    public function diff(array $before, array $after): array
    {
        $beforeMap = isset($before['appmap']) && is_array($before['appmap'])
            ? $before['appmap']
            : $before;
        $afterMap = isset($after['appmap']) && is_array($after['appmap'])
            ? $after['appmap']
            : $after;

        $beforeServices = $this->indexByName($beforeMap['services'] ?? []);
        $afterServices = $this->indexByName($afterMap['services'] ?? []);

        $serviceAdded = array_values(array_diff(array_keys($afterServices), array_keys($beforeServices)));
        $serviceRemoved = array_values(array_diff(array_keys($beforeServices), array_keys($afterServices)));

        $beforeEdges = $this->indexServiceEdges($beforeMap['serviceEdges'] ?? []);
        $afterEdges = $this->indexServiceEdges($afterMap['serviceEdges'] ?? []);

        $edgesAdded = array_values(array_diff(array_keys($afterEdges), array_keys($beforeEdges)));
        $edgesRemoved = array_values(array_diff(array_keys($beforeEdges), array_keys($afterEdges)));
        $edgesAddedDetails = array_values(array_intersect_key($afterEdges, array_flip($edgesAdded)));
        $edgesRemovedDetails = array_values(array_intersect_key($beforeEdges, array_flip($edgesRemoved)));

        $beforeInc = $this->indexInconsistencies($beforeMap['inconsistencies'] ?? []);
        $afterInc = $this->indexInconsistencies($afterMap['inconsistencies'] ?? []);

        $incAdded = array_values(array_diff(array_keys($afterInc), array_keys($beforeInc)));
        $incResolved = array_values(array_diff(array_keys($beforeInc), array_keys($afterInc)));
        $incAddedDetails = array_values(array_intersect_key($afterInc, array_flip($incAdded)));
        $incResolvedDetails = array_values(array_intersect_key($beforeInc, array_flip($incResolved)));
        $contractBreakingInconsistencies = array_values(array_filter(
            $incAddedDetails,
            fn(array $issue): bool => in_array((string) ($issue['type'] ?? ''), [
                'CONTRACT_ENDPOINT_NOT_IN_CODE',
                'CONTRACT_METHOD_SET_MISMATCH',
            ], true)
        ));

        $breakingTotal = count($serviceRemoved)
            + count($edgesRemovedDetails)
            + count($contractBreakingInconsistencies);

        return [
            'generatedAt' => date('c'),
            'summary' => [
                'servicesAdded' => count($serviceAdded),
                'servicesRemoved' => count($serviceRemoved),
                'serviceEdgesAdded' => count($edgesAdded),
                'serviceEdgesRemoved' => count($edgesRemoved),
                'inconsistenciesAdded' => count($incAdded),
                'inconsistenciesResolved' => count($incResolved),
                'breakingChanges' => $breakingTotal,
            ],
            'services' => [
                'added' => $serviceAdded,
                'removed' => $serviceRemoved,
            ],
            'serviceEdges' => [
                'added' => $edgesAdded,
                'removed' => $edgesRemoved,
                'addedDetails' => $edgesAddedDetails,
                'removedDetails' => $edgesRemovedDetails,
            ],
            'inconsistencies' => [
                'added' => $incAdded,
                'resolved' => $incResolved,
                'addedDetails' => $incAddedDetails,
                'resolvedDetails' => $incResolvedDetails,
            ],
            'breakingChanges' => [
                'total' => $breakingTotal,
                'servicesRemoved' => $serviceRemoved,
                'serviceEdgesRemoved' => $edgesRemovedDetails,
                'contractInconsistenciesAdded' => $contractBreakingInconsistencies,
            ],
        ];
    }

    /**
     * @param mixed $services
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(mixed $services): array
    {
        if (!is_array($services)) {
            return [];
        }

        $out = [];
        foreach ($services as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            $name = (string) ($svc['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = $svc;
        }

        return $out;
    }

    /**
     * @param mixed $edges
     * @return array<string, array<string, mixed>>
     */
    private function indexServiceEdges(mixed $edges): array
    {
        if (!is_array($edges)) {
            return [];
        }

        $out = [];
        foreach ($edges as $e) {
            if (!is_array($e)) {
                continue;
            }
            $from = (string) ($e['from'] ?? '');
            $to = (string) ($e['to'] ?? '');
            $type = (string) ($e['type'] ?? '');
            $count = (int) ($e['count'] ?? 0);
            if ($from === '' || $to === '') {
                continue;
            }

            $key = "{$from}|{$to}|{$type}|{$count}";
            $out[$key] = $e;
        }

        return $out;
    }

    /**
     * @param mixed $issues
     * @return array<string, array<string, mixed>>
     */
    private function indexInconsistencies(mixed $issues): array
    {
        if (!is_array($issues)) {
            return [];
        }

        $out = [];
        foreach ($issues as $i) {
            if (!is_array($i)) {
                continue;
            }
            $type = (string) ($i['type'] ?? '');
            $fromService = (string) ($i['fromService'] ?? '');
            $target = (string) ($i['target'] ?? '');
            $msg = (string) ($i['message'] ?? '');

            $key = sha1("{$type}|{$fromService}|{$target}|{$msg}");
            $out[$key] = $i;
        }

        return $out;
    }
}
