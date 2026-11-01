<?php

namespace Sapient\Core;

use Sapient\Contracts\Core\PipelineInterface;
use Sapient\Contracts\Core\StateInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AgentLoop {
    private $pipeline;
    private $maxIterations;
    private $logger;

    public function __construct(PipelineInterface $pipeline, int $maxIterations = 5, ?LoggerInterface $logger = null) {
        $this->pipeline = $pipeline;
        $this->maxIterations = $maxIterations;
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(StateInterface $state): StateInterface {
        $iter = 0;
        $accumulatedResponse = '';
        
        while ($iter < $this->maxIterations) {
            $iter++;

            $this->logger->info("Starting Turn $iter / $this->maxIterations", ['component' => 'LOOP']);

            // 1. Inject Loop Context for PromptBuilder
            $state->set('loop_index', $iter);
            $state->set('loop_max', $this->maxIterations);
            
            // 2. Handle Last Turn Logic (Safety Mechanism)
            if ($iter === $this->maxIterations) {
                $this->logger->warning("CRITICAL: Last Turn Reached. Disabling Tools.", ['component' => 'LOOP']);
                $state->set('system_warning', "CRITICAL WARNING: This is your LAST TURN. You are out of iterations. DO NOT use any more tools. Summarize your progress, explain why you couldn't finish, and stop immediately.");
            } else {
                $state->set('system_warning', null);
            }

            // 3. Execute Pipeline
            $state = $this->pipeline->run($state);
            
            // Capture response for this turn
            $turnResponse = $state->get('last_response', '');
            if (is_string($turnResponse) && trim($turnResponse) !== '') {
                $accumulatedResponse .= trim($turnResponse) . "\n\n";
            }

            // 4. Analyze: Should we stop?
            $pendingActions = $state->get('pending_actions', []);
            
            if (empty($pendingActions)) {
                $this->logger->info("No tools used. Assuming task complete.", ['component' => 'LOOP']);
                break;
            }
            
            $this->logger->info("Tools used. Continuing to next turn for feedback/refinement...", ['component' => 'LOOP']);
            
            // 5. Preparation for Next Turn
            $state->set('input', null); 
            $state->set('pending_actions', []);
            $state->set('tool_results', []); 
        }
        
        // Finalize: Set the accumulated response as the final response
        $state->set('last_response', trim($accumulatedResponse));
        
        return $state;
    }
}
