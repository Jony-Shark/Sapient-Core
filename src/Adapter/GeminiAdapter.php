<?php

namespace Sapient\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\LLMInterface;

class GeminiAdapter implements LLMInterface {
    private $client;
    private $apiKey;
    private $timeout;
    private $adapterOptions;
    private array $generationConfig;

    protected string $model;

    public function __construct(string $model, string $apiKey = '', int $timeout = 180, array $adapterOptions = [], array $guzzleOptions = []) {
        $this->model = $model;
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->generationConfig = $adapterOptions['generationConfig'] ?? [];
        unset($adapterOptions['generationConfig']);
        $this->adapterOptions = $adapterOptions;
        
        $guzzleConfig = array_merge([
            'timeout' => $this->timeout,
        ], $guzzleOptions);

        $this->client = new Client($guzzleConfig);
    }

    public function chat(array $messages, array $options = []): string {
        if (empty($this->apiKey)) {
            return "Error: GEMINI_API_KEY is not set in configuration.";
        }

        // 1. Separate System Instruction from Chat History
        [$contents, $systemInstruction] = $this->prepareMessages($messages);

        // 2. Prepare API Payload
        // URL: https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=API_KEY
        // Use v1beta for now as it's common.
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $body = array_merge($this->adapterOptions, [
            'contents' => $contents,
        ]);

        $mergedOptions = array_merge($this->generationConfig, $options);
        if (!empty($mergedOptions)) {
            $body['generationConfig'] = $mergedOptions;
        }

        if ($systemInstruction) {
            $body['systemInstruction'] = $systemInstruction;
        }

        // 3. Send Request
        $response = $this->client->post($url, [
            'json' => $body,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => $this->timeout
        ]);

        $data = json_decode($response->getBody(), true);

        // 4. Extract Response
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return $data['candidates'][0]['content']['parts'][0]['text'];
        }

        // Check for safety blocks
        if (isset($data['promptFeedback']['blockReason'])) {
            return "Error: Blocked by safety filter (" . $data['promptFeedback']['blockReason'] . ")";
        }

        return "Error: Unexpected response format from Gemini.";
    }

    /**
     * Format Sapient Message objects into Gemini REST payload structure.
     *
     * @param array $messages
     * @return array{0: array, 1: ?array}
     */
    protected function prepareMessages(array $messages): array {
        $systemInstruction = null;
        $contents = [];
        
        foreach ($messages as $msg) {
            $role = $msg->role;
            $content = $msg->content;

            if ($role === 'system') {
                // Gemini uses 'system_instruction' field for system prompts.
                // We only use the FIRST system message as the base instruction.
                // Subsequent system messages (like tool results) are injected into the conversation
                // as 'user' turns with a prefix to trigger the Model response turn.
                $text = is_array($content) ? json_encode($content) : $content;
                
                if ($systemInstruction === null) {
                    $systemInstruction = ['parts' => [['text' => $text]]];
                    continue;
                }
            }

            // Map roles: user/system -> user, assistant -> model
            $geminiRole = ($role === 'assistant') ? 'model' : 'user';

            // Format content parts
            $parts = [];
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (isset($part['type'])) {
                        if ($part['type'] === 'text') {
                            $parts[] = ['text' => $part['text']];
                        } elseif ($part['type'] === 'image_url') {
                            // Extract base64
                            $url = $part['image_url']['url'] ?? '';
                            if (strpos($url, 'data:') === 0) {
                                $base64 = substr($url, strpos($url, ',') + 1);
                                $mimeType = substr($url, 5, strpos($url, ';') - 5);
                                $parts[] = [
                                    'inlineData' => [
                                        'mimeType' => $mimeType,
                                        'data' => $base64
                                    ]
                                ];
                            } elseif (file_exists($url)) {
                                // Handle local file paths
                                $fileData = file_get_contents($url);
                                if ($fileData !== false) {
                                    $mimeType = mime_content_type($url);
                                    $parts[] = [
                                        'inlineData' => [
                                            'mimeType' => $mimeType ?: 'image/jpeg',
                                            'data' => base64_encode($fileData)
                                        ]
                                    ];
                                }
                            }
                        }
                    }
                }
            } else {
                $parts[] = ['text' => (string) $content];
            }

            $contents[] = [
                'role' => $geminiRole,
                'parts' => $parts
            ];
        }

        return [$contents, $systemInstruction];
    }
}
