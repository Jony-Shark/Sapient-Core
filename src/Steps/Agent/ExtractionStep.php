<?php

namespace Sapient\Steps\Agent;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Core\Utils\TemplateRenderer;
use Sapient\Core\Utils\JsonParser;
use Sapient\Core\Message;

/**
 * ExtractionStep is specialized for obtaining JSON data from an LLM.
 */
class ExtractionStep implements StepInterface {
    private $llm;
    private $systemPrompt;
    private $userPromptTemplate;
    private $mappings;
    private $fileKey;

    /**
     * @param LLMInterface $llm
     * @param string $systemPrompt
     * @param string $userPromptTemplate
     * @param string|array $mappings Either a single state_key string, or ['json_field' => 'state_key']
     * @param string|null $fileKey Optional state key containing a file path to attach.
     */
    public function __construct(LLMInterface $llm, string $systemPrompt, string $userPromptTemplate, $mappings, ?string $fileKey = null) {
        $this->llm = $llm;
        $this->systemPrompt = $systemPrompt . "\nYou MUST respond with valid JSON only.";
        $this->userPromptTemplate = $userPromptTemplate;
        $this->fileKey = $fileKey;
        
        // Normalize mappings: string 'key' -> ['*' => 'key'] (meaning the whole object)
        $this->mappings = is_array($mappings) ? $mappings : ['*' => $mappings];
    }

    public function getName(): string {
        $keys = implode(", ", array_values($this->mappings));
        return "Extraction -> [{$keys}]";
    }

    public function process(StateInterface $state): StateInterface {
        // Render Template
        $prompt = TemplateRenderer::render($this->userPromptTemplate, $state);

        $messages = [
            Message::system($this->systemPrompt),
        ];

        $filePath = null;
        if ($this->fileKey && $state->has($this->fileKey)) {
            $filePath = $state->get($this->fileKey);
        }

        // Fix: Allow GCS/URI paths by skipping file_exists() for paths containing ://
        if ($filePath && (strpos($filePath, '://') !== false || file_exists($filePath))) {
            $messages[] = Message::user([
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $filePath]] // Handles any file type supported by adapter
            ]);
        } else {
            $messages[] = Message::user($prompt);
        }

        $response = $this->llm->chat($messages);
        
        $decoded = JsonParser::parse($response);
        
        if ($decoded !== null) {
            foreach ($this->mappings as $jsonField => $stateKey) {
                // Determine source value: '*' means the whole decoded object
                $val = ($jsonField === '*') ? $decoded : ($decoded[$jsonField] ?? null);
                
                if ($val === null) continue;

                // Support array accumulation (e.g. key[])
                if (str_ends_with($stateKey, '[]')) {
                    $baseKey = substr($stateKey, 0, -2);
                    $existing = $state->get($baseKey, []);
                    if (!is_array($existing)) $existing = [$existing];
                    $existing[] = $val;
                    $state->set($baseKey, $existing);
                } else {
                    $state->set($stateKey, $val);
                }
            }
        } else {
            // Log error or store raw in a generic field
            $state->set('last_extraction_error', $response);
        }

        return $state;
    }
}
