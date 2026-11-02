<?php

namespace Sapient\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\LLMInterface;

class AnthropicAdapter implements LLMInterface {
    private $client;
    private $apiKey;
    private $timeout;
    private $adapterOptions;

    protected string $model;

    public function __construct(string $model, string $apiKey = '', int $timeout = 180, array $adapterOptions = [], array $guzzleOptions = []) {
        $this->model = $model;
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $defaultGenConfig = $this->normalizeKeys($adapterOptions['generationConfig'] ?? []);
        unset($adapterOptions['generationConfig']);
        $this->adapterOptions = array_merge([
            'max_tokens' => 4096, // Required by Anthropic API
        ], $this->normalizeKeys($adapterOptions), $defaultGenConfig);
        
        $guzzleConfig = array_merge([
            'timeout' => $this->timeout,
        ], $guzzleOptions);

        $this->client = new Client($guzzleConfig);
    }

    public function chat(array $messages, array $options = []): string {
        if (empty($this->apiKey)) {
            return "Error: ANTHROPIC_API_KEY is not set in configuration.";
        }

        $generationConfig = $this->normalizeKeys($options);
        [$transformedMessages, $systemPrompt] = $this->prepareMessages($messages);

        // Build request body
        $body = array_merge($this->adapterOptions, $generationConfig);
        $body['model'] = $this->model;
        $body['messages'] = $transformedMessages;

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }

        // Call Anthropic Messages API
        $response = $this->client->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
            'timeout' => $this->timeout
        ]);

        $data = json_decode($response->getBody(), true);
        
        // Extract text response
        // Anthropic returns: { content: [{ type: "text", text: "..." }] }
        if (isset($data['content'])) {
            $textParts = [];
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $textParts[] = $block['text'];
                }
                // Future: handle tool_use blocks if needed
            }
            return implode('', $textParts) ?: "Error: Empty response from Anthropic.";
        }

        // Check for error
        if (isset($data['error'])) {
            return "Error: " . ($data['error']['message'] ?? 'Unknown Anthropic API error.');
        }

        return "Error: Unexpected response format from Anthropic.";
    }

    /**
     * Transform Sapient Message objects into Anthropic messages and an optional top-level system prompt.
     *
     * @param array $messages
     * @return array{0: array, 1: ?string}
     */
    protected function prepareMessages(array $messages): array {
        // Anthropic separates system prompt from messages (like Gemini)
        $systemPrompt = null;
        $transformedMessages = [];

        foreach ($messages as $msg) {
            $role = $msg->role;
            $content = $msg->content;

            if ($role === 'system') {
                // Anthropic uses a top-level 'system' field
                $text = is_array($content) ? json_encode($content) : $content;
                // Accumulate system messages
                $systemPrompt = $systemPrompt ? $systemPrompt . "\n\n" . $text : $text;
                continue;
            }

            // Anthropic only supports 'user' and 'assistant' roles
            $anthropicRole = ($role === 'assistant') ? 'assistant' : 'user';

            // Format content parts (Handle Multimodal)
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $partData) {
                    if (($partData['type'] ?? '') === 'text') {
                        $parts[] = ['type' => 'text', 'text' => $partData['text']];
                    } elseif (($partData['type'] ?? '') === 'image_url') {
                        $url = $partData['image_url']['url'] ?? '';
                        
                        if (strpos($url, 'data:') === 0) {
                            // Parse data URI: data:image/jpeg;base64,/9j/4AAQ...
                            $mimeType = substr($url, 5, strpos($url, ';') - 5);
                            $base64 = substr($url, strpos($url, ',') + 1);
                            $parts[] = [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => $mimeType,
                                    'data' => $base64
                                ]
                            ];
                        } elseif (strpos($url, 'http') === 0) {
                            // Anthropic supports URL images
                            $parts[] = [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'url',
                                    'url' => $url
                                ]
                            ];
                        } elseif (file_exists($url)) {
                            // Local file: read and convert to base64
                            $fileData = file_get_contents($url);
                            if ($fileData !== false) {
                                $mimeType = mime_content_type($url) ?: 'image/jpeg';
                                $parts[] = [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $mimeType,
                                        'data' => base64_encode($fileData)
                                    ]
                                ];
                            }
                        } else {
                            $parts[] = ['type' => 'text', 'text' => "[IMAGE NOT FOUND: {$url}]"];
                        }
                    }
                }
                $content = $parts;
            }

            $transformedMessages[] = [
                'role' => $anthropicRole,
                'content' => $content
            ];
        }

        return [$transformedMessages, $systemPrompt];
    }

    /**
     * Normalize camelCase keys to snake_case (maxTokens -> max_tokens)
     */
    private function normalizeKeys(array $options): array {
        $normalized = [];
        foreach ($options as $key => $value) {
            $normalized[strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key))] = $value;
        }
        return $normalized;
    }
}
