<?php

namespace Sapient\Steps\Control;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Core\Message;

/**
 * DecisionStep uses an LLM to choose a branch to execute.
 */
class DecisionStep implements StepInterface {
    private $llm;
    private $prompt;
    private $branches;

    /**
     * @param LLMInterface $llm
     * @param string $prompt Question for the AI.
     * @param array $branches Map of expected output -> StepInterface
     */
    public function __construct(LLMInterface $llm, string $prompt, array $branches) {
        $this->llm = $llm;
        $this->prompt = $prompt;
        $this->branches = $branches;
    }

    public function getName(): string {
        return "Decision Point";
    }

    public function process(StateInterface $state): StateInterface {
        $optionsList = implode(", ", array_keys($this->branches));
        
        $systemMsg = "You are a routing agent. Your task is to select exactly one of the available options based on the user's input. 
        Available options: [{$optionsList}]. 
        Respond with ONLY the name of the selected option, nothing else.";

        $messages = [
            Message::system($systemMsg),
            Message::user($this->prompt . "\n\nInput to analyze: " . ($state->get('input') ?? 'No input provided'))
        ];

        $decision = trim($this->llm->chat($messages));
        
        // Match branch (case-insensitive for robustness)
        foreach ($this->branches as $name => $step) {
            if (strtolower($name) === strtolower($decision)) {
                return $step->process($state);
            }
        }

        // Fallback: If no match, we might want a default or just stop.
        return $state;
    }
}
