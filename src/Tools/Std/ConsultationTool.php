<?php

namespace Sapient\Tools\Std;

use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Adapter\VertexAIAdapter;
use Sapient\Adapter\GeminiAdapter;
use Sapient\Core\Message;


class ConsultationTool implements ToolInterface {
    private $model;
    private $systemPrompt;
    private $apiKey;
    private $useGoogleAuth;

    public function __construct(
        string $apiKey = '',
        bool $useGoogleAuth = false,
        string $model = 'gemini-2.0-flash', 
        string $systemPrompt = ''
    ) {
        $this->apiKey = $apiKey;
        $this->useGoogleAuth = $useGoogleAuth;
        $this->model = $model;
        $this->systemPrompt = $systemPrompt;
    }
    
    public function getName(): string {
        return 'consult';
    }

    public function getDescription(): string {
        return 'Pass your question as content.
        **Tool name:** `consult`
        **Function:** Call an expert AI colleague for advice on a complex question, specialized knowledge, or to verify logic.
        The response will be their detailed advice.

        **Usage examples (syntax):**
        <consult>What is the best way to solve this problem?</consult>';
    }   

    public function getInstruction(): string {
        return 'Pattern: <consult>your question</consult>, Instruction: Call your AI colleague for help.';
    }

    public function getParameters(): array {
        return [
            'content' => [
                'type' => 'string',
                'description' => 'The question or problem you want to ask your colleague.'
            ]
        ];
    }

    public function execute(array $args): string {
        $question = $args['content'] ?? null;
        if (!$question) {
            return "ERROR: No question provided.";
        }

        // Fallback to Vertex if API Key is empty
        if (empty($this->apiKey) && $this->useGoogleAuth) {
            $consultant = new VertexAIAdapter($this->model, '', 'us-central1'); // Caller is responsible for auth
        } elseif (!empty($this->apiKey)) {
            $consultant = new GeminiAdapter($this->model, $this->apiKey);
        } else {
            return "ERROR: Consultation failed. No colleague available (GEMINI_API_KEY or GOOGLE_APPLICATION_CREDENTIALS missing).";
        }
        
        if (empty($this->systemPrompt)) {
            $defaultPromptFile = dirname(__DIR__, 3) . '/resources/consultant_prompt.md';
            $this->systemPrompt = file_exists($defaultPromptFile) 
            ? file_get_contents($defaultPromptFile) 
            : "You are a Senior AI Consultant assisting your colleague. Provide expert advice.";
        }
        
        $messages = [
            Message::system($this->systemPrompt),
            Message::user($question)
        ];

        try {
            $response = $consultant->chat($messages);
            return "Response from Colleague (Gemini):\n" . $response;
        } catch (\Exception $e) {
            return "ERROR: Failed to contact colleague: " . $e->getMessage();
        }
    }
}
