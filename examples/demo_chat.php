<?php

require 'vendor/autoload.php';

// 1. Setup Services
use Sapient\Core\Config;
use Sapient\Core\State;
use Sapient\Core\Pipeline;
use Sapient\Core\AgentLoop;
use Sapient\Core\ConsoleLogger;
use Sapient\Adapter\OllamaAdapter;
use Sapient\Adapter\GeminiAdapter;
use Sapient\Adapter\VertexAIAdapter;
use Sapient\Service\OllamaProvisioner;
use Sapient\Memory\Identity;
use Sapient\Memory\Context;
use Sapient\Memory\Journal;
use Sapient\Workflow\Registry\WorkflowRegistry;
use Sapient\Examples\ExampleWorkflow;
// Optional Plugins
use Sapient\Hippocampus\HippocampusClient;
use Sapient\Hippocampus\Tools\MemorizeTool;
use Sapient\Hippocampus\Tools\RememberTool;
use Sapient\Hippocampus\Config\HippocampusConfig;
use Sapient\Sandbox\Tools\PythonSandboxTool;

// 2. Load Tools
use Sapient\Tools\Std\IdentityTool;
use Sapient\Tools\Std\ContextTool;
use Sapient\Tools\Std\JournalTool;
use Sapient\Tools\Std\WorkflowTool;
use Sapient\Tools\Std\TurnControlTool;
use Sapient\Tools\Std\HelpTool;
use Sapient\Tools\Std\ConsultationTool;
use Sapient\Tools\Std\CalculatorTool;

// 2. Setup Pipeline Steps
use Sapient\Input\LocalFileProvider;
use Sapient\Steps\IO\OutputParsingStep;
use Sapient\Parsing\XmlOutputParser;
use Sapient\Core\StaticContextProvider;
use Sapient\Steps\IO\MultimodalInputStep;
use Sapient\Steps\System\PromptBuilderStep;
use Sapient\Steps\Agent\SimpleAgentStep;
use Sapient\Steps\Tools\ToolExecutionStep;
use Sapient\Steps\Memory\ContextCompressionStep;

Config::load(__DIR__);

// Load Examples 
require_once __DIR__ . '/ExampleWorkflow.php';

$dataDir = Config::get('DATA_DIR', __DIR__ . '/data');
$persona = Config::get('PERSONA', 'helix');
$personaName = ucfirst($persona);
$memories = 3;
$logLimit = 3;
$historyMaxTurn = 15;
$enableThoughtLoop = true;
$defaultPrompt = "";

$logger = new ConsoleLogger();
$identityService = new Identity($dataDir, $persona);
$contextService = new Context($dataDir, $persona);
$journalService = new Journal($dataDir, $persona, $logLimit);

// Optional Memory Service (Hippocampus Plugin)
$hippocampus = null;
if (class_exists(HippocampusClient::class)) {
    $hippocampusConfig = new HippocampusConfig(
        Config::get('HIPPOCAMPUS_URL', 'http://127.0.0.1:6366'),
        $persona,
        $memories,
        (bool)Config::get('HIPPOCAMPUS_AUTOSTART', true)
    );
    $hippocampus = new HippocampusClient($hippocampusConfig);
    
    register_shutdown_function(function() use ($hippocampus) {
        $hippocampus->shutdown();
    });
}

$driver = Config::get('LLM_DRIVER', 'ollama');
$provisioner = null;

if ($driver === 'gemini') {
    $apiKey = Config::get('GEMINI_API_KEY');
    $geminiModel = Config::get('GEMINI_MODEL', 'gemini-2.0-flash');
    $llm = new GeminiAdapter($geminiModel, $apiKey);
    $logger->info("Using LLM Driver: Gemini ($geminiModel)");
} elseif ($driver === 'vertex') {
    $vertexModel = Config::get('GEMINI_MODEL', 'gemini-2.0-flash');
    $llm = new VertexAIAdapter($vertexModel);
    $logger->info("Using LLM Driver: Vertex AI ($vertexModel)");
} else {
    $modelName = Config::get('OLLAMA_MODEL', 'llama3');
    $ollamaUrl = Config::get('OLLAMA_URL', 'http://localhost:11434');
    $llm = new OllamaAdapter($modelName, $ollamaUrl);
    $provisioner = new OllamaProvisioner($persona, $modelName, 0.7, 8192, $logger);
    $logger->info("Using LLM Driver: Ollama ($modelName)");
}

$localFileProvider = new LocalFileProvider();

// Load Rules
$rulesContent = @file_get_contents(dirname(__DIR__) . '/resources/sapient_rules.md') ?: "";
$rulesProvider = new StaticContextProvider($rulesContent, 'sapient_rules');

// Workflow Registry
$workflowRegistry = new WorkflowRegistry();
$workflowRegistry->register(new ExampleWorkflow('demo', 'A demonstration workflow (Banana special)'));

// Workflow Tool
$workflowTool = new WorkflowTool($workflowRegistry);

// Help Tool (Introspection)
$helpTool = new HelpTool();

$tools = [
    new IdentityTool($identityService, $provisioner), 
    new ContextTool($contextService),
    new JournalTool($journalService),
    new TurnControlTool(),
    new CalculatorTool(),
    new ConsultationTool(
        Config::get('GEMINI_API_KEY', ''),
        (bool)Config::get('GOOGLE_APPLICATION_CREDENTIALS')
    ),
    $workflowTool,
    $helpTool
];

if (class_exists(PythonSandboxTool::class)) {
    $tools[] = new PythonSandboxTool();
}

if ($hippocampus
    && class_exists(MemorizeTool::class)
    && class_exists(RememberTool::class)
) {
    $tools[] = new MemorizeTool($hippocampus);
    $tools[] = new RememberTool($hippocampus);
}

$toolStep = new ToolExecutionStep($tools, $logger);
$helpTool->setRegisteredTools($toolStep->getTools());

$pipeline = new Pipeline();
$parser = new XmlOutputParser($toolStep->getToolNames());

// Memory Optimization Step
$compressionStep = new ContextCompressionStep(
    $llm, 
    $historyMaxTurn,
    $logger
);

$contextProviders = [
    $identityService,
    $rulesProvider,
    $contextService,
    $journalService,
    $toolStep
];

if ($hippocampus) {
    $contextProviders[] = $hippocampus;
}

$pipeline->addStep(new MultimodalInputStep([$localFileProvider]))
         ->addStep($compressionStep)
         ->addStep(new PromptBuilderStep(
             $contextProviders, 
             $defaultPrompt,
             $enableThoughtLoop
         ))
         ->addStep(new SimpleAgentStep($llm))
         ->addStep(new OutputParsingStep($parser, $logger))
         ->addStep($toolStep);

// 3. Setup Loop
$loop = new AgentLoop($pipeline, 5, $logger);

// 4. Initialize State
$stateFile = $dataDir . '/' . $persona . '/session_state.json';
$state = new State();

if ($state->loadFromFile($stateFile)) {
    $logger->info("Restored previous session state.", ['persona' => $personaName]);
} else {
    $state->set('session_start', date('Y-m-d H:i:s'));
    $logger->info("Starting fresh session.", ['persona' => $personaName]);
}

$logger->info("Sapient Demo System Online", ['persona' => $personaName]);
$logger->info("Type 'exit' to quit.");

// 5. REPL Loop
while (true) {
    echo "\033[1;32mUser > \033[0m";
    $input = "";
    
    while (true) {
        $line = fgets(STDIN);
        if ($line === false) break 2;
        
        $trimmed = rtrim($line, "\r\n");
        if (str_ends_with($trimmed, "\\") || str_contains($trimmed, "\eOM") || str_contains($trimmed, "\x1bOM")) {
            $cleaned = str_replace(["\eOM", "\x1bOM"], "", $trimmed);
            if (str_ends_with($cleaned, "\\")) {
                $cleaned = substr($cleaned, 0, -1);
            }
            $input .= $cleaned . "\n";
            echo "      \033[1;32m| \033[0m";
        } else {
            $input .= $trimmed;
            break;
        }
    }

    $input = trim($input);

    if (strtolower($input) === 'exit') {
        echo "Goodbye.\n";
        break;
    }

    if (empty($input)) continue;

    $state->set('input', $input);
    echo "$personaName is thinking...\n";
    
    try {
        $state = $loop->run($state);
        $response = $state->get('last_response');
        echo "\033[1;35m$personaName > \033[0m" . $response . "\n\n";

        // Persist State after successful turn
        $state->saveToFile($stateFile);

    } catch (Exception $e) {
        $state->setLastError($e->getMessage());
        $state->saveToFile($stateFile);
        echo "\033[1;31mError > \033[0m" . $e->getMessage() . PHP_EOL;
    }
}
