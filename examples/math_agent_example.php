<?php
/**
 * Example: Using AgentLoopStep within a Workflow
 * 
 * This example demonstrates embedding an Autonomous Agent (which can use tools like a Calculator)
 * inside a static, linear Workflow. The Workflow provides the prompt, hands over control to the 
 * AgentLoop, and then retrieves the final result.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Sapient\Core\Config;
use Sapient\Core\ConsoleLogger;
use Sapient\Core\Message;
use Sapient\Core\Pipeline;
use Sapient\Core\State;
use Sapient\Core\AgentLoop;
use Sapient\Adapter\GeminiAdapter;
use Sapient\Tools\Std\CalculatorTool;
use Sapient\Steps\Tools\ToolExecutionStep;
use Sapient\Steps\Agent\SimpleAgentStep;
use Sapient\Steps\IO\OutputParsingStep;
use Sapient\Parsing\XmlOutputParser;
use Sapient\Steps\System\PromptBuilderStep;
use Sapient\Steps\Agent\LoopAgentStep;
use Sapient\Core\StaticContextProvider;

Config::load(__DIR__ . '/../');

$logger = new ConsoleLogger();
$logger->info("Initializing Math Agent Example...");

// 1. Setup LLM (Gemini recommended for this example)
$apiKey = Config::get('GEMINI_API_KEY');
if (empty($apiKey)) {
    die("\033[1;31mError:\033[0m Please set GEMINI_API_KEY in .env to run this example.\n");
}
$llm = new GeminiAdapter(Config::get('GEMINI_MODEL', 'gemini-2.0-flash'), $apiKey);

// 2. Setup the Inner Agent Loop (The "Brain" that can use tools)
$mathTools = [
    new CalculatorTool()
];
$toolStep = new ToolExecutionStep($mathTools, $logger);
$parser = new XmlOutputParser($toolStep->getToolNames());

// The Prompt Builder for the Agent Loop instructs it to solve the math puzzle and use tools if needed.
$rules = "You are an expert mathematician. Calculate the exact result. You MUST use the <calculator> tool for math operations. Do not guess. Just return the final number once you know it.";
$staticContext = new StaticContextProvider($rules, 'Math_Rules');

$innerPipeline = new Pipeline();
$innerPipeline->addStep(new PromptBuilderStep([$staticContext, $toolStep], "{{input}}"))
              ->addStep(new SimpleAgentStep($llm))
              ->addStep(new OutputParsingStep($parser, $logger))
              ->addStep($toolStep);

// The autonomous loop will run up to 3 times
$agentLoop = new AgentLoop($innerPipeline, 3, $logger);

// 3. Setup the Outer Workflow (The "Factory Line")
// In a real scenario, this workflow might have steps to load a PDF, extract text, and THEN run the AgentLoop.
// Here, we just inject the AgentLoopStep directly.
$workflowPipeline = new Pipeline();

// We inject the AgentLoop into the workflow
$workflowPipeline->addStep(new LoopAgentStep($agentLoop, $logger));

// 4. Run the Workflow!
$state = new State();
$mathProblem = "Please calculate the amount of this with the Calculator: (12345 * 7.5) + (9876 / 3.14)";
$state->set('input', $mathProblem);
$state->appendHistory(Message::user($mathProblem));

echo "\n\033[1;33m[Workflow Input]\033[0m $mathProblem\n\n";

// Execute pipeline
$state = $workflowPipeline->run($state);

// 5. Check the result
$finalAnswer = $state->get('last_response');
echo "\n\033[1;32m[Workflow Final Output]\033[0m\n$finalAnswer\n\n";
