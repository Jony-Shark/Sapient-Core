<?php

namespace Sapient\Adapter\Embedding;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\EmbeddingInterface;

class OpenAIEmbeddingAdapter implements EmbeddingInterface {
    private $client;
    private $apiKey;
    private $baseUrl;
    private $timeout;
    protected string $model;

    public function __construct(string $model = 'text-embedding-3-small', string $apiKey = '', string $baseUrl = 'https://api.openai.com/v1', int $timeout = 60, array $guzzleOptions = []) {
        $this->model = $model;
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        
        $guzzleConfig = array_merge([
            'timeout' => $this->timeout,
        ], $guzzleOptions);

        $this->client = new Client($guzzleConfig);
    }

    public function embed(string $text): array {
        if (empty($this->apiKey)) {
            throw new \RuntimeException("OPENAI_API_KEY is not set.");
        }

        $body = [
            'model' => $this->model,
            'input' => $text
        ];

        return $this->sendRequest($body)[0] ?? [];
    }

    public function embedBatch(array $texts): array {
        if (empty($this->apiKey)) {
            throw new \RuntimeException("OPENAI_API_KEY is not set.");
        }

        $body = [
            'model' => $this->model,
            'input' => $texts
        ];

        return $this->sendRequest($body);
    }

    private function sendRequest(array $body): array {
        try {
            $response = $this->client->post("{$this->baseUrl}/embeddings", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
                'timeout' => $this->timeout
            ]);

            $data = json_decode($response->getBody(), true);
            
            if (isset($data['data']) && is_array($data['data'])) {
                $results = [];
                // Ensure they are ordered correctly by index, though API usually guarantees order
                usort($data['data'], fn($a, $b) => $a['index'] <=> $b['index']);
                foreach ($data['data'] as $item) {
                    $results[] = $item['embedding'];
                }
                return $results;
            }

            throw new \RuntimeException("Unexpected response format from OpenAI Embed API.");
        } catch (GuzzleException $e) {
            throw new \RuntimeException("OpenAI API Request failed: " . $e->getMessage(), 0, $e);
        }
    }
}
