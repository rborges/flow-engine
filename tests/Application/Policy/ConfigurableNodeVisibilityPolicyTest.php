<?php

namespace Tests\Application\Policy;

use PHPUnit\Framework\TestCase;
use FlowEngine\Application\Policy\ConfigurableNodeVisibilityPolicy;
use FlowEngine\Application\Policy\DefaultNodeVisibilityPolicy;
use FlowEngine\Domain\Contracts\ProjectConfig;
use FlowEngine\Domain\Flow\Node;

final class ConfigurableNodeVisibilityPolicyTest extends TestCase
{
    public function test_it_stores_and_exposes_project_config(): void
    {
        $config = $this->createStub(ProjectConfig::class);
        $config->method('rootPath')->willReturn('/project');

        $policy = new ConfigurableNodeVisibilityPolicy($config, [
            new DefaultNodeVisibilityPolicy(),
        ]);

        $this->assertSame($config, $policy->config());
        $this->assertSame('/project', $policy->config()->rootPath());
    }

    public function test_it_delegates_visibility_to_inner_policies(): void
    {
        $config = $this->createStub(ProjectConfig::class);

        $policy = new ConfigurableNodeVisibilityPolicy($config, [
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
}
