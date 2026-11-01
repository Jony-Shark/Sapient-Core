<?php

namespace Sapient\Steps\IO;

use Sapient\Contracts\Core\OutputParserInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class OutputParsingStep implements StepInterface {
    private $parser;
    private $logger;

    public function __construct(OutputParserInterface $parser, ?LoggerInterface $logger = null) {
        $this->parser = $parser;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string {
        return 'OutputParser';
    }

    public function process(StateInterface $state): StateInterface {
        $response = $state->get('last_response');
        if (empty($response)) {
            return $state;
        }

        // Delegate parsing
        $result = $this->parser->parse($response);
        $actions = $result['actions'];
        $thoughts = $result['thoughts'] ?? [];
        $cleanedResponse = $result['response'];

        // Output thoughts immediately so the user can see the reasoning process
        foreach ($thoughts as $thought) {
            $this->logger->info("[THOUGHT] " . $thought);
        }
        
        // Save thoughts to state for the loop to append to history
        $state->set('thoughts', $thoughts);

        // Update cleaned response in State
        $state->set('last_response', trim($cleanedResponse));
        
        // Save pending actions to state
        if (!empty($actions)) {
            $state->set('pending_actions', $actions);
            $this->logger->debug("Parser found " . count($actions) . " actions.");
        }
        
        return $state;
    }
}
