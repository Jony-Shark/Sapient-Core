<?php

namespace Sapient\Steps\IO;

use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Core\Message;

class SimpleInputStep implements StepInterface {
    public function getName(): string {
        return 'InputProcessing';
    }

    public function process(StateInterface $state): StateInterface {
        // Assume 'input' key is populated by the runner, or we handle it here.
        // For the Pipeline, it expects the 'input' to be in the state at start,
        // or this step might format it (e.g. wrapping in XML or JSON if needed).
        
        $rawInput = $state->get('input');
        
        if (empty($rawInput)) {
            return $state;
        }

        // Just append to history for now, this mimics the "Engine::history[]" logic
        $state->appendHistory(Message::user($rawInput));
        
        return $state;
    }
}
