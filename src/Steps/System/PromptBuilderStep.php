<?php

namespace Sapient\Steps\System;

use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Core\Message;

class PromptBuilderStep implements StepInterface {
    /** @var ContextProviderInterface[] */
    private $providers = [];
    private $baseInstruction;
    private $enableThoughtLoop;

    /**
     * @param ContextProviderInterface[] $providers
     * @param string $baseInstruction
     * @param bool $enableThoughtLoop
     */
    public function __construct(
        array $providers,
        string $baseInstruction = "",
        bool $enableThoughtLoop = true
    ) {
        $this->providers = $providers;
        $this->baseInstruction = $baseInstruction;
        $this->enableThoughtLoop = $enableThoughtLoop;
    }

    public function getName(): string {
        return 'PromptBuilder';
    }

    public function process(StateInterface $state): StateInterface {
        // 1. Start with Base Instruction
        $systemPrompt = $this->baseInstruction . "\n\n";

        // Toggleable Chain of Thought
        if ($this->enableThoughtLoop) {
            $systemPrompt .= "### Chain of Thought\n";
            $systemPrompt .= "If you face a complex problem, need to analyze user intent, or plan a multi-step execution, you can \"think aloud\" by wrapping your reasoning in <thought>...</thought> tags. This will remain private and won't be shown to the user.\n\n";
        }

        // 2. Iterate Context Providers
        foreach ($this->providers as $provider) {
            $content = $provider->provideContext($state);
            if (!empty($content)) {
                // Formatting: Improve visual separation
                $systemPrompt .= trim($content) . "\n\n";
            }
        }

        // 3. Kernel Context (Internal Loop State)
        // These are intrinsic to the Agent's operation cycle, not external "context".
        
        // Flow Status
        $loopIndex = $state->get('loop_index');
        $loopMax = $state->get('loop_max');
        
        // Only show turn info if we are deeper in the loop (index > 1)
        // This prevents confusion during the first turn or single-turn conversations.
        if ($loopIndex !== null && $loopIndex > 1) {
            $systemPrompt .= "=== FLOW STATUS ===\n";
            $systemPrompt .= "Current Turn: $loopIndex / $loopMax\n\n";
        }

        // Critical Warnings (e.g. Last Turn)
        $warning = $state->get('system_warning');
        if ($warning) {
            $systemPrompt .= "=== ! SYSTEM WARNING ! ===\n";
            $systemPrompt .= "$warning\n\n";
        }

        // 4. Inject into History
        $history = $state->getHistory();
        
        // Prevent stacking System Prompts during loops (replace logic)
        // Check if the first message is a system prompt and replace it
        if (!empty($history) && $history[0]->isSystem()) {
             $history[0] = $history[0]->withContent(trim($systemPrompt));
        } else {
             // Otherwise prepend it
             array_unshift($history, Message::system(trim($systemPrompt)));
        }
        
        // Update State
        $state->setHistory($history);
        
        return $state;
    }
}
