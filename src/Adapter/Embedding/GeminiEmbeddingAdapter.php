<?php

namespace Sapient\Adapter\Embedding;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\EmbeddingInterface;

class GeminiEmbeddingAdapter implements EmbeddingInterface {
    private $client;
    private $apiKey;
    private $timeout;
    protected string $model;

    public function __construct(string $model = 'text-embedding-004', string $apiKey = '', int $timeout = 60, array $guzzleOptions = []) {
        $this->model = $model;
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        
        $guzzleConfig = array_merge([
            'timeout' => $this->timeout,
        ], $guzzleOptions);

        $this->client = new Client($guzzleConfig);
    }

    public function embed(string $text): array {
        if (empty($this->apiKey)) {
            throw new \RuntimeException("GEMINI_API_KEY is not set.");
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:embedContent?key={$this->apiKey}";

        $body = [
            'model' => "models/{$this->model}",
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ]
        ];

        try {
            $response = $this->client->post($url, [
                'json' => $body,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => $this->timeout
            ]);

            $data = json_decode($response->getBody(), true);
            
            if (isset($data['embedding']['values'])) {
                return $data['embedding']['values'];
            }

            throw new \RuntimeException("Unexpected response format from Gemini Embed API.");
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Gemini API Request failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function embedBatch(array $texts): array {
        if (empty($this->apiKey)) {
            throw new \RuntimeException("GEMINI_API_KEY is not set.");
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:batchEmbedContents?key={$this->apiKey}";

        $requests = [];
        foreach ($texts as $text) {
            $requests[] = [
                'model' => "models/{$this->model}",
                'content' => [
                    'parts' => [
                        ['text' => $text]
                    ]
                ]
            ];
        }

        $body = [
            'requests' => $requests
        ];

        try {
            $response = $this->client->post($url, [
                'json' => $body,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => $this->timeout
            ]);

            $data = json_decode($response->getBody(), true);
            
            if (isset($data['embeddings']) && is_array($data['embeddings'])) {
                $results = [];
                foreach ($data['embeddings'] as $emb) {
                    $results[] = $emb['values'] ?? [];
                }
                return $results;
            }

            throw new \RuntimeException("Unexpected response format from Gemini Batch Embed API.");
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Gemini Batch API Request failed: " . $e->getMessage(), 0, $e);
        }
    }
}
