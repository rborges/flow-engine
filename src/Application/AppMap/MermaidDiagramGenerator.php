<?php

namespace FlowEngine\Application\AppMap;

final class MermaidDiagramGenerator
{
    /**
     * @param array<string, mixed> $appmap
     */
    public function dependencyGraph(array $appmap): string
    {
        $lines = [];
        $lines[] = 'graph LR';

        $services = $appmap['services'] ?? [];
        if (is_array($services)) {
            foreach ($services as $svc) {
                if (!is_array($svc)) {
                    continue;
                }

                $name = (string) ($svc['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $id = $this->id($name);
                $label = $this->escape($name);
                $langs = $svc['languages'] ?? [];
                $langText = ($svc['status'] ?? 'ok') === 'failed'
                    ? 'analysis failed'
                    : (is_array($langs) ? implode(',', $langs) : '');

                $lines[] = "  {$id}[\"{$label}\\n{$langText}\"]";
            }
        }

        $edges = $appmap['serviceEdges'] ?? [];
        if (is_array($edges)) {
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

                $label = $this->escape(trim($type . ' x' . $count));
                $lines[] = "  {$this->id($from)} -->|\"{$label}\"| {$this->id($to)}";
            }
        }

        $incons = $appmap['inconsistencies'] ?? [];
        if (is_array($incons) && count($incons) > 0) {
            $lines[] = '  classDef warn fill:#fff3cd,stroke:#856404,color:#856404;';

            $warnServices = [];
            foreach ($incons as $i) {
                if (!is_array($i)) {
                    continue;
                }

                $fromSvc = (string) ($i['fromService'] ?? '');
                if ($fromSvc !== '') {
                    $warnServices[$fromSvc] = true;
                }
            }

            foreach (array_keys($warnServices) as $svcName) {
                $lines[] = '  class ' . $this->id($svcName) . ' warn';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $appmap
     */
    public function sequenceDiagram(array $appmap): string
    {
        $lines = [];
        $lines[] = 'sequenceDiagram';

        $services = $appmap['services'] ?? [];
        if (is_array($services)) {
            foreach ($services as $svc) {
                if (!is_array($svc)) {
                    continue;
                }

                $name = (string) ($svc['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $lines[] = '  participant ' . $this->id($name) . ' as ' . $this->escape($name);
            }
        }

        $edges = $appmap['integrationEdges'] ?? [];
        if (!is_array($edges) || $edges === []) {
            $lines[] = '  Note over A,B: No integration edges detected';
            return implode("\n", $lines);
        }

        $addedExternal = false;
        foreach ($edges as $e) {
            if (!is_array($e)) {
                continue;
            }

            $from = (string) ($e['fromService'] ?? '');
            if ($from === '') {
                continue;
            }

            $to = (string) ($e['toService'] ?? '');
            $type = (string) ($e['type'] ?? '');
            $target = (string) ($e['target'] ?? '');

            $fromId = $this->id($from);
            $label = $this->escape(trim(($type !== '' ? $type . ': ' : '') . $target));

            if ($to !== '') {
                $toId = $this->id($to);
                $lines[] = "  {$fromId}->>{$toId}: {$label}";
                continue;
            }

            if (!$addedExternal) {
                $lines[] = '  participant ext as External';
                $addedExternal = true;
            }

            $lines[] = "  {$fromId}->>ext: {$label}";
        }

        return implode("\n", $lines);
    }

    /**
     * Generates a C4 Container diagram (Level 2) for a multi-project appmap.
     *
     * Each service becomes a Container element. Resolved inter-service edges
     * become Rel arrows. Unresolved HTTP calls to external hosts become
     * System_Ext elements.
     *
     * @param array<string, mixed> $appmap Output from ApplicationMapBuilder::build()
     */
    public function c4Container(array $appmap): string
    {
        $services        = (array) ($appmap['services'] ?? []);
        $serviceEdges    = (array) ($appmap['serviceEdges'] ?? []);
        $integrationEdges = (array) ($appmap['integrationEdges'] ?? []);

        // Collect external hosts from unresolved integration edges
        $externalHosts = []; // host => true
        foreach ($integrationEdges as $e) {
            if (!is_array($e) || ($e['toService'] ?? null) !== null) {
                continue;
            }
            if (($e['type'] ?? '') !== 'http') {
                continue;
            }
            $parsed = parse_url((string) ($e['target'] ?? ''));
            $host   = (string) ($parsed['host'] ?? '');
            if ($host !== '') {
                $externalHosts[$host] = true;
            }
        }

        $lines   = [];
        $lines[] = 'C4Container';
        $lines[] = '  title Container Diagram';
        $lines[] = '';

        // Service containers
        foreach ($services as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            $name = (string) ($svc['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $langs     = implode(', ', (array) ($svc['languages'] ?? []));
            $nodeCount = (int) ($svc['stats']['nodeCount'] ?? 0);
            $endpoints = count((array) ($svc['endpoints'] ?? []));
            $desc      = ($svc['status'] ?? 'ok') === 'failed'
                ? 'analysis failed'
                : $nodeCount . ' nodes' . ($endpoints > 0 ? " · {$endpoints} endpoints" : '');

            $id     = $this->id($name);
            $lines[] = sprintf(
                '  Container(%s, "%s", "%s", "%s")',
                $id,
                $this->escape($name),
                $this->escape($langs),
                $this->escape($desc)
            );
        }

        // External systems
        foreach (array_keys($externalHosts) as $host) {
            $extId  = 'ext_' . $this->c4Id($host);
            $lines[] = sprintf(
                '  System_Ext(%s, "%s", "External service")',
                $extId,
                $this->escape($host)
            );
        }

        $lines[] = '';

        // Inter-service relationships (deduped at service-pair level)
        $seenPairs = [];
        foreach ($serviceEdges as $e) {
            if (!is_array($e)) {
                continue;
            }
            $from = (string) ($e['from'] ?? '');
            $to   = (string) ($e['to'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }
            $key = $from . '->' . $to;
            if (isset($seenPairs[$key])) {
                continue;
            }
            $seenPairs[$key] = true;

            $type  = (string) ($e['type'] ?? '');
            $count = (int) ($e['count'] ?? 0);
            $label = $this->escape(trim($type . ($count > 1 ? " ×{$count}" : '')));
            $lines[] = "  Rel({$this->id($from)}, {$this->id($to)}, \"{$label}\")";
        }

        // Relationships to external (deduped)
        $seenExt = [];
        foreach ($integrationEdges as $e) {
            if (!is_array($e) || ($e['toService'] ?? null) !== null) {
                continue;
            }
            if (($e['type'] ?? '') !== 'http') {
                continue;
            }
            $from   = (string) ($e['fromService'] ?? '');
            $parsed = parse_url((string) ($e['target'] ?? ''));
            $host   = (string) ($parsed['host'] ?? '');
            if ($from === '' || $host === '') {
                continue;
            }
            $key = $from . '->' . $host;
            if (isset($seenExt[$key])) {
                continue;
            }
            $seenExt[$key] = true;
            $extId = 'ext_' . $this->c4Id($host);
            $lines[] = "  Rel({$this->id($from)}, {$extId}, \"HTTP\")";
        }

        return implode("\n", $lines);
    }

    private function id(string $name): string
    {
        return 'svc_' . substr(sha1($name), 0, 10);
    }

    /** Safe identifier for C4 element IDs. */
    private function c4Id(string $s): string
    {
        return substr((string) (preg_replace('/[^A-Za-z0-9_]/', '_', $s) ?? 'x'), 0, 32);
    }

    private function escape(string $s): string
    {
        return str_replace(['"', "\n", "\r"], ["'", ' ', ' '], $s);
    }
}
