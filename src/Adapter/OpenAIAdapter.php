<?php

namespace Sapient\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\LLMInterface;

class OpenAIAdapter implements LLMInterface {
    private $client;
    private $apiKey;
    private $baseUrl;
    private $timeout;
    private $adapterOptions;

    protected string $model;

    public function __construct(string $model, string $apiKey = '', string $baseUrl = 'https://api.openai.com/v1', int $timeout = 180, array $adapterOptions = [], array $guzzleOptions = []) {
        $this->model = $model;
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $defaultGenConfig = $this->normalizeKeys($adapterOptions['generationConfig'] ?? []);
        unset($adapterOptions['generationConfig']);
        $this->adapterOptions = array_merge($this->normalizeKeys($adapterOptions), $defaultGenConfig);
        
        $guzzleConfig = array_merge([
            'timeout' => $this->timeout,
        ], $guzzleOptions);

        $this->client = new Client($guzzleConfig);
    }

    public function chat(array $messages, array $options = []): string {
        if (empty($this->apiKey)) {
            return "Error: OPENAI_API_KEY is not set in configuration.";
        }

        $generationConfig = $this->normalizeKeys($options);
        $prepared = $this->prepareMessages($messages);

        // Call OpenAI API
        $body = array_merge($this->adapterOptions, $generationConfig);
        $body['model'] = $this->model;
        $body['messages'] = $prepared->messages;

        $response = $this->client->post("{$this->baseUrl}/chat/completions", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => $body,
            'timeout' => $this->timeout
        ]);

        $data = json_decode($response->getBody(), true);
        
        // Extract text response
        return $data['choices'][0]['message']['content'] ?? "Error: Unexpected empty response from OpenAI.";
    }

    /**
     * Transform Sapient Message objects into OpenAI chat completions message format.
     *
     * @param array $messages
     * @return object{messages: array, system: null}
     */
    protected function prepareMessages(array $messages): object {
        $transformedMessages = [];
        foreach ($messages as $msg) {
            $role = $msg->role;
            $content = $msg->content;

            // OpenAI format strongly matches Sapient's core array structure
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $partData) {
                    if (($partData['type'] ?? '') === 'text') {
                        $parts[] = ['type' => 'text', 'text' => $partData['text']];
                    } elseif (in_array($partData['type'] ?? '', ['image_url', 'file_url', 'audio_url'], true)) {
                        $url = $partData['image_url']['url'] ?? $partData['file_url']['url'] ?? $partData['audio_url']['url'] ?? '';
                        
                        if (strpos($url, 'data:') === 0) {
                            $mime = substr($url, 5, strpos($url, ';') - 5);
                            $base64 = substr($url, strpos($url, ',') + 1);
                            $parts[] = $this->formatMediaPart($mime, $base64, 'document.pdf', $url);
                        } elseif (strpos($url, 'http') === 0) {
                            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                        } elseif (file_exists($url)) {
                            $mime = mime_content_type($url) ?: 'image/jpeg';
                            $base64 = base64_encode(file_get_contents($url));
                            $parts[] = $this->formatMediaPart($mime, $base64, basename($url));
                        } else {
                            $parts[] = ['type' => 'text', 'text' => "[IMAGE NOT FOUND: {$url}]"];
                        }
                    }
                }
                $content = $parts;
            }

            $transformedMessages[] = [
                'role' => $role, // 'system', 'user', 'assistant' map perfectly to OpenAI
                'content' => $content
            ];
        }

        return (object) [
            'messages' => $transformedMessages,
            'system'   => null,
        ];
    }

    /**
     * Format a media part for OpenAI based on its MIME type (input_audio, file, or image_url).
     */
    private function formatMediaPart(string $mime, string $base64, string $filename = 'document.pdf', ?string $rawDataUri = null): array {
        if (str_starts_with($mime, 'audio/')) {
            return [
                'type' => 'input_audio',
                'input_audio' => [
                    'data'   => $base64,
                    'format' => str_contains($mime, 'wav') ? 'wav' : 'mp3',
                ],
            ];
        }

        if ($mime === 'application/pdf') {
            return [
                'type' => 'file',
                'file' => [
                    'filename'  => $filename,
                    'file_data' => $rawDataUri ?? "data:application/pdf;base64,{$base64}",
                ],
            ];
        }

        return [
            'type' => 'image_url',
            'image_url' => ['url' => $rawDataUri ?? "data:{$mime};base64,{$base64}"],
        ];
    }

    /**
     * Normalize camelCase keys to snake_case (topP -> top_p)
     */
    private function normalizeKeys(array $options): array {
        $normalized = [];
        foreach ($options as $key => $value) {
            $normalized[strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key))] = $value;
        }
        return $normalized;
    }
}
