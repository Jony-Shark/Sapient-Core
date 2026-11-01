<?php

namespace Sapient\Adapter\Embedding;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\EmbeddingInterface;

class OllamaEmbeddingAdapter implements EmbeddingInterface {
    private $client;
    private $baseUrl;
    private $timeout;
    protected string $model;

    public function __construct(string $model = 'nomic-embed-text', string $baseUrl = 'http://localhost:11434', int $timeout = 180, array $guzzleOptions = []) {
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        
        $guzzleConfig = array_merge([
            'timeout' => $this->timeout,
        ], $guzzleOptions);

        $this->client = new Client($guzzleConfig);
    }

    public function embed(string $text): array {
        return $this->embedBatch([$text])[0] ?? [];
    }

    public function embedBatch(array $texts): array {
        // Uses the newer /api/embed endpoint which supports batching
        $url = "{$this->baseUrl}/api/embed";
        
        $body = [
            'model' => $this->model,
            'input' => $texts
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $this->timeout
            ]);

            $data = json_decode($response->getBody(), true);
            
            if (isset($data['embeddings']) && is_array($data['embeddings'])) {
                return $data['embeddings'];
            }

            throw new \RuntimeException("Unexpected response format from Ollama Embed API.");
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Ollama API Request failed: " . $e->getMessage(), 0, $e);
        }
    }
}
