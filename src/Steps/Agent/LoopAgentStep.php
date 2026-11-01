<?php

namespace Sapient\Steps\Agent;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Core\AgentLoop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LoopAgentStep implements StepInterface {
    private $loop;
    private $logger;

    public function __construct(AgentLoop $loop, ?LoggerInterface $logger = null) {
        $this->loop = $loop;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string {
        return 'LoopAgentStep';
    }

    public function process(StateInterface $state): StateInterface {
        $this->logger->info("Entering Autonomous Agent Loop within Workflow...");
        
        // Clear any previous loop status in the state
        $state->set('loop_index', 0);
        
        // Execute the autonomous loop
        $state = $this->loop->run($state);
        
        $this->logger->info("Autonomous Agent Loop completed. Returning to Workflow.");

        return $state;
    }
}
