<?php

namespace Sapient\Steps\Tools;

use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Core\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ToolExecutionStep implements StepInterface, ContextProviderInterface {
    /** @var ToolInterface[] */
    private $tools = [];

    private $logger;

    /**
     * @param ToolInterface[] $tools
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $tools, ?LoggerInterface $logger = null) {
        $this->logger = $logger ?? new NullLogger();
        foreach ($tools as $tool) {
            $this->registerTool($tool);
        }
    }

    public function registerTool(ToolInterface $tool): void {
        $this->tools[$tool->getName()] = $tool;
    }

    public function getName(): string {
        return 'ToolExecution';
    }

    public function process(StateInterface $state): StateInterface {
        // Look for pre-parsed actions in the state
        $actions = $state->get('pending_actions', []);
        
        if (empty($actions)) {
            return $state;
        }

        $resultsBuffer = [];
        foreach ($actions as $action) {
            $toolName = $action['tool'];
            $args = $action['args'];
            
            if (isset($this->tools[$toolName])) {
                $tool = $this->tools[$toolName];
                
                $this->logger->info("Executing tool: $toolName", ['args' => $args]);

                $rawResult = $tool->execute($args);
                
                // Parse status from result (Error: prefix indicates failure)
                $status = (strpos($rawResult, 'Error:') === 0) ? 'error' : 'success';
                $cleanResult = $status === 'error' ? substr($rawResult, 6) : $rawResult;

                $resultsBuffer[$toolName] = [
                    'status' => $status,
                    'output' => trim($cleanResult)
                ];

                $this->logger->debug("Tool Result [{$status}]: " . substr($rawResult, 0, 100)); 

                
                // Append result to State (Structured data)
                $currentResults = $state->get('tool_results', []);
                $currentResults[] = [
                    'tool' => $toolName,
                    'args' => $args,
                    'result' => $rawResult
                ];
                $state->set('tool_results', $currentResults);
                
            } else {
                $resultsBuffer[$toolName] = [
                    'status' => 'error',
                    'output' => 'Unknown tool'
                ];
                $this->logger->warning("Unknown tool requested: $toolName");

            }
        }

        // FEEDBACK: Append ALL tool results as a single user message back to history (JSON Format)
        if (!empty($resultsBuffer)) {
            $jsonFeedback = json_encode($resultsBuffer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            $feedbackMessage = "<tool_results>\n" . $jsonFeedback . "\n</tool_results>\n
            <system_note>\n
            These are automated tool responses.\n
            The user does not see the response from the tools, only what you have written, so you do not have to repeat it, but you can supplement it.\n
            </system_note>";

            $state->appendHistory(Message::user($feedbackMessage));
        }
        
        return $state;
    }
    
    /**
     * Helper to get instructions for the System Prompt
     */
    public function getToolInstructions(): string {
        $output = "\n### Tools Usage\n
        You have access to a set of XML-based tools.\n
        To use a tool, please write the XML tags directly in your text.\n\n
        ### Constraint Awareness\n
        - Please write tool calls as plain text, avoiding Markdown code blocks limits their execution.\n
        - Tool results will be provided back in a <tool_results> block containing a JSON object.\n
        - Tool results calls you back in the next turn, so you can use them to formulate your final answer.\n
        [AVAILABLE TOOLS]\n";
        
        foreach ($this->tools as $tool) {
            // Refactoring: Use getInstruction() for system prompt concise usage.
            // But fall back to getDescription() if getInstruction doesn't exist (e.g. legacy/mock tools)
            // though Interface enforces it now.
            $instruction = method_exists($tool, 'getInstruction') ? $tool->getInstruction() : $tool->getDescription();
            
            $output .= "- " . $instruction . PHP_EOL;
        }
        return $output;
    }
    
    // Helper to get registered tag names for the Parser
    public function getToolNames(): array {
        return array_keys($this->tools);
    }
    
    /**
     * @return ToolInterface[]
     */
    public function getTools(): array {
        return array_values($this->tools);
    }

    // --- ContextProviderInterface ---

    public function getContextName(): string {
        return 'available_tools';
    }

    public function provideContext(StateInterface $state): ?string {
        return $this->getToolInstructions();
    }
}
