<?php

namespace Sapient\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\LLMInterface;

class OllamaAdapter implements LLMInterface {
    private $client;
    private $apiUrl;
    private $baseUrl;
    private $timeout;
    private $adapterOptions;
    private array $generationConfig;

    protected string $model;

    public function __construct(string $model, string $baseUrl = 'http://localhost:11434', int $timeout = 300, array $adapterOptions = [], array $guzzleOptions = []) {
        $this->model = $model;
        $this->baseUrl = $baseUrl;
        $this->apiUrl = rtrim($this->baseUrl, '/') . '/api/chat';
        $this->timeout = $timeout;
        $this->generationConfig = $this->normalizeKeys($adapterOptions['generationConfig'] ?? $adapterOptions['options'] ?? []);
        unset($adapterOptions['generationConfig'], $adapterOptions['options']);
        $this->adapterOptions = array_merge([
            'stream' => false,
            'keep_alive' => '30m',
        ], $this->normalizeKeys($adapterOptions));
        
        $guzzleConfig = array_merge([
            'timeout' => $this->timeout,
        ], $guzzleOptions);

        $this->client = new Client($guzzleConfig);
    }

    public function chat(array $messages, array $options = []): string {
        $generationConfig = array_merge($this->generationConfig, $this->normalizeKeys($options));
        $transformedMessages = $this->prepareMessages($messages);
        
        $body = array_merge($this->adapterOptions, [
            'model'      => $this->model,
            'messages'   => $transformedMessages,
        ]);

        if (!empty($generationConfig)) {
            $body['options'] = $generationConfig;
        }

        $response = $this->client->post($this->apiUrl, [
            'json' => $body,
            'timeout' => $this->timeout
        ]);

        $data = json_decode($response->getBody(), true);
        $content = $data['message']['content'] ?? '';
        
        // If the model provided thinking data, wrap it in <thought> tags and prepend it
        if (!empty($data['message']['thinking'])) {
            $thinkingStr = trim($data['message']['thinking']);
            $content = "<thought>\n" . $thinkingStr . "\n</thought>\n\n" . $content;
        }
        
        return $content;
    }

    /**
     * Transform Sapient Message objects into Ollama message format (with multimodal support).
     */
    protected function prepareMessages(array $messages): array {
        $transformedMessages = [];
        foreach ($messages as $msg) {
            $content = $msg->content;
            $images = [];
            
            if (is_array($content)) {
                $textParts = [];
                foreach ($content as $part) {
                    if (isset($part['type'])) {
                        if ($part['type'] === 'text') {
                            $textParts[] = $part['text'];
                        } elseif ($part['type'] === 'image_url') {
                            // Extract base64 or path
                            // Assuming format: "data:image/jpeg;base64,..." OR "/path/to/file"
                            $url = $part['image_url']['url'] ?? '';
                            if (strpos($url, 'data:') === 0) {
                                // Extract pure base64
                                $base64 = substr($url, strpos($url, ',') + 1);
                                $images[] = $base64;
                            } elseif (file_exists($url)) {
                                // Read file and convert to base64
                                $fileData = file_get_contents($url);
                                if ($fileData !== false) {
                                    $images[] = base64_encode($fileData);
                                }
                            }
                        }
                    }
                }
                $content = implode("\n", $textParts);
            }
            
            $newMessage = [
                'role' => $msg->role,
                'content' => $content
            ];
            if (!empty($images)) {
                $newMessage['images'] = $images;
            }
            $transformedMessages[] = $newMessage;
        }

        return $transformedMessages;
    }

    /**
     * Normalize camelCase keys to snake_case (topK -> top_k)
     */
    private function normalizeKeys(array $options): array {
        $normalized = [];
        foreach ($options as $key => $value) {
            $normalized[strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key))] = $value;
        }
        return $normalized;
    }
}
