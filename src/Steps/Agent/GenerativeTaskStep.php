<?php

namespace Sapient\Steps\Agent;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Core\Utils\TemplateRenderer;
use Sapient\Core\Message;

class GenerativeTaskStep implements StepInterface {
    private $llm;
    private $systemPrompt;
    private $userPromptTemplate;
    private $outputKey;

    /**
     * @param LLMInterface $llm The LLM provider.
     * @param string $systemPrompt Fixed system instruction (e.g., "You are a summarizer.").
     * @param string $userPromptTemplate Template with {{ placeholders }} for state values.
     * @param string $outputKey Where to store the result in the State.
     */
    public function __construct(LLMInterface $llm, string $systemPrompt, string $userPromptTemplate, string $outputKey) {
        $this->llm = $llm;
        $this->systemPrompt = $systemPrompt;
        $this->userPromptTemplate = $userPromptTemplate;
        $this->outputKey = $outputKey;
    }

    public function getName(): string {
        return "Generative Task -> {$this->outputKey}";
    }

    public function process(StateInterface $state): StateInterface {
        // 1. Render User Prompt
        $prompt = TemplateRenderer::render($this->userPromptTemplate, $state);

        // 2. Prepare Messages (Single Turn)
        $messages = [
            Message::system($this->systemPrompt),
            Message::user($prompt)
        ];

        // 3. Call LLM
        // Note: We might want structured output forcing later, but for now raw text.
        $response = $this->llm->chat($messages);

        // 4. Store Result
        $state->set($this->outputKey, $response);

        return $state;
    }
}
