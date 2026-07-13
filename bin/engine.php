#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

// Sem isto, um projeto grande demais para o memory_limit morre com um fatal error
// cru do PHP, apontando para dentro do php-parser — sem dizer o que aconteceu nem
// o que fazer. Vale para o CLI e para o servidor MCP.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || $error['type'] !== E_ERROR) {
        return;
    }

    if (!str_contains($error['message'], 'Allowed memory size')) {
        return;
    }

    fwrite(STDERR, sprintf(
        "\nFlow Engine ran out of memory (memory_limit = %s).\n\n"
        . "The project is larger than the current limit allows. Either:\n"
        . "  - raise the limit:  php -d memory_limit=1G bin/engine.php <command> ...\n"
        . "  - or shrink the graph by excluding third-party and build directories in\n"
        . "    flow-engine.json (run `init` to detect them automatically).\n",
        ini_get('memory_limit')
    ));
});

use FlowEngine\Application\CLI\CommandRouter;
use FlowEngine\Console\ConsoleIO;
use FlowEngine\Infrastructure\Config\BundledLibraryDetector;
use FlowEngine\AI\LLM\LLMException;

// Commands
use FlowEngine\Application\CLI\Command\AnalyzeCommand;
use FlowEngine\Application\CLI\Command\NodesCommand;
use FlowEngine\Application\CLI\Command\InputsCommand;
use FlowEngine\Application\CLI\Command\CompareCommand;
use FlowEngine\Application\CLI\Command\WatchCommand;
use FlowEngine\Application\CLI\Command\InitCommand;
use FlowEngine\Application\CLI\Command\DoctorCommand;
use FlowEngine\Application\CLI\Command\InteractiveCommand;
use FlowEngine\Application\CLI\Command\ComplexityCommand;
use FlowEngine\Application\CLI\Command\CyclesCommand;
use FlowEngine\Application\CLI\Command\ArchitectureCommand;
use FlowEngine\Application\CLI\Command\MetricsCommand;
use FlowEngine\Application\CLI\Command\OrphansCommand;
// use FlowEngine\Application\CLI\Command\RunNodeCommand;
// use FlowEngine\Application\CLI\Command\RunGuidedCommand;
use FlowEngine\Application\CLI\Command\ExplainCommand;
use FlowEngine\Application\CLI\Command\ImpactCommand;
use FlowEngine\Application\CLI\Command\ContextCommand;
use FlowEngine\Application\CLI\Command\AskCommand;
use FlowEngine\Application\CLI\Command\InterpretCommand;
use FlowEngine\Application\CLI\Command\ImpactReportCommand;
use FlowEngine\Application\CLI\Command\SnapshotCommand;
use FlowEngine\Application\CLI\Command\FlowCommand;
use FlowEngine\Application\CLI\Command\TraceCommand;
use FlowEngine\Application\CLI\Command\ChangeRiskCommand;
use FlowEngine\Application\CLI\Command\RefactorSafetyCommand;
use FlowEngine\Application\CLI\Command\RefactorPlanCommand;
use FlowEngine\Application\CLI\Command\RefactorExecuteCommand;
use FlowEngine\Application\CLI\Command\RefactorValidateCommand;
use FlowEngine\Application\CLI\Command\AppMapCommand;
use FlowEngine\Application\CLI\Command\DiagramCommand;
use FlowEngine\Application\CLI\Command\AppMapDiffCommand;
use FlowEngine\Application\CLI\Command\ApiCommand;
use FlowEngine\Application\CLI\Command\DriftCommand;
use FlowEngine\Application\CLI\Command\CleanupCommand;
use FlowEngine\Application\CLI\Command\BugsCommand;
use FlowEngine\Application\CLI\Command\EntrypointsCommand;
use FlowEngine\Application\CLI\Command\ArchitectureGateCommand;
use FlowEngine\Application\CLI\Command\McpCommand;
use FlowEngine\Application\CLI\Command\RefactorPrCommand;
use FlowEngine\Application\CLI\Command\SimulateCommand;
use FlowEngine\Application\CLI\Command\RemediationProposalsCommand;
use FlowEngine\Application\CLI\Command\RemediationApproveCommand;
use FlowEngine\Application\CLI\Command\RemediationStatusCommand;
use FlowEngine\Application\CLI\Command\SolidCommand;
use FlowEngine\Application\CLI\Command\PatternsCommand;
use FlowEngine\Application\CLI\Command\HelpCommand;

$io = new ConsoleIO();
$router = new CommandRouter($io);

// Registro explícito dos comandos (FASE 5)
$router->register(new HelpCommand($io));
$router->register(new AnalyzeCommand($io));
$router->register(new NodesCommand($io));
$router->register(new InputsCommand($io));
$router->register(new CompareCommand($io));
$router->register(new WatchCommand($io));
$libraryScanner = new BundledLibraryDetector();
$router->register(new InitCommand($io, $libraryScanner));
$router->register(new DoctorCommand($io));
$router->register(new InteractiveCommand($io, $libraryScanner));
$router->register(new ComplexityCommand($io));
$router->register(new CyclesCommand($io));
$router->register(new ArchitectureCommand($io));
$router->register(new MetricsCommand($io));
$router->register(new OrphansCommand($io));
// $router->register(new RunNodeCommand($io));
// $router->register(new RunGuidedCommand($io));
$router->register(new ExplainCommand($io));
$router->register(new ImpactCommand($io));
$router->register(new ContextCommand($io));
$router->register(new AskCommand($io));
$router->register(new InterpretCommand($io));
$router->register(new ImpactReportCommand($io));
$router->register(new SnapshotCommand($io));
$router->register(new FlowCommand($io));
$router->register(new TraceCommand($io));
$router->register(new ChangeRiskCommand($io));
$router->register(new RefactorSafetyCommand($io));
$router->register(new RefactorPlanCommand($io));
$router->register(new RefactorExecuteCommand($io));
$router->register(new RefactorValidateCommand($io));
$router->register(new RefactorPrCommand($io));
$router->register(new ArchitectureGateCommand($io));
$router->register(new AppMapCommand($io));
$router->register(new DiagramCommand($io));
$router->register(new AppMapDiffCommand($io));
$router->register(new ApiCommand($io));
$router->register(new DriftCommand($io));
$router->register(new CleanupCommand($io));
$router->register(new EntrypointsCommand($io));
$router->register(new BugsCommand($io));
$router->register(new McpCommand($io));
$router->register(new SimulateCommand($io));
$router->register(new RemediationProposalsCommand($io));
$router->register(new RemediationApproveCommand($io));
$router->register(new RemediationStatusCommand($io));
$router->register(new SolidCommand($io));
$router->register(new PatternsCommand($io));

$jsonCommands = [
    'ask',
    'interpret',
    'impact-report',
    'metrics',
    'complexity',
    'cycles',
    'architecture',
    'orphans',
    'nodes',
    'flow',
    'trace',
    'bugs',
    'entrypoints',
];

try {
    $router->handle($argv);
    if ($io->hasErrors()) {
        exit(1);
    }
} catch (LLMException $e) {
    $command = $argv[1] ?? '';
    if (in_array($command, $jsonCommands, true)) {
        echo json_encode($e->toArray(), JSON_PRETTY_PRINT) . "\n";
    } else {
        $io->error($e->getMessage());
    }
    exit(1);
} catch (\Throwable $e) {
    $command = $argv[1] ?? '';
    if (in_array($command, $jsonCommands, true)) {
        echo json_encode([
            'error' => $e->getMessage(),
            'errorCode' => 'CLI_UNHANDLED_ERROR',
            'hints' => ['Verifique configuracao do comando e dependencias do ambiente.'],
            'retryable' => false,
            'provider' => null,
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        $io->error($e->getMessage());
    }
    exit(1);
}
