<?php

namespace FlowEngine\Infrastructure\Analyzer;

interface ScanDiagnosticsProvider
{
    /**
     * @return string[]
     */
    public function scanWarnings(): array;
}
