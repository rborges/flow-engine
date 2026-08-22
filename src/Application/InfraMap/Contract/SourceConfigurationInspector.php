<?php

namespace FlowEngine\Application\InfraMap\Contract;

interface SourceConfigurationInspector
{
    /** @return string[] */
    public function warningsFor(string $projectRoot): array;
}
