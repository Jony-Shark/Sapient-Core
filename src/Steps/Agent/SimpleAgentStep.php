<?php

namespace Sapient\Steps\Agent;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Core\Message;

class SimpleAgentStep implements StepInterface {
    private $llm;

    public function __construct(LLMInterface $llm) {
        $this->llm = $llm;
    }

    public function getName(): string {
        return 'StandardAgent';
    }

    public function process(StateInterface $state): StateInterface {
        // 1. Retrieve history
        $messages = $state->getHistory();
        
        // 2. Chat
        // In a real scenario, we'd inject System Prompts here if not already in history.
        $response = $this->llm->chat($messages);
        
        // 3. Store raw response in State (so next steps like OutputParser can handle it)
        $state->set('last_response', $response);
        
        // 4. Append to History immediately? Or let OutputParser do it? 
        // Let's do it here to ensure continuity, but cleaned version might replace it later.
        $state->appendHistory(Message::assistant($response));
        
        return $state;
    }
}
