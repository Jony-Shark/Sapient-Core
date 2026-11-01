<?php

namespace Sapient\Adapter\Embedding;

use Google\Cloud\AIPlatform\V1\Client\PredictionServiceClient;
use Google\Cloud\AIPlatform\V1\EmbedContentRequest;
use Google\Cloud\AIPlatform\V1\PredictRequest;
use Google\Cloud\AIPlatform\V1\Content;
use Google\Cloud\AIPlatform\V1\Part;
use Google\ApiCore\ApiException;
use Google\Protobuf\Value;
use Google\Protobuf\Struct;
use Sapient\Contracts\Core\EmbeddingInterface;
use Sapient\Traits\GaxRetryWorkaroundTrait;

class VertexAIEmbeddingAdapter implements EmbeddingInterface {
    use GaxRetryWorkaroundTrait;

    private $projectId;
    private $location;
    private $timeout;
    private $callOptions;
    private $client;
    protected string $model;
    protected bool $useLegacyPredict;

    public function __construct(string $model = 'text-embedding-004', string $projectId = '', string $location = 'us-central1', int $timeout = 60, array $adapterOptions = [], array $callOptions = []) {
        $this->model = $model;
        $this->projectId = $projectId;
        $this->location = $location;
        $this->timeout = $timeout * 1000; // Store as ms
        $this->callOptions = $callOptions;

        // WORKAROUND: EmbedContentRequest has a protobuf oneof conflict bug in older/current GAX versions.
        // We default to the older, stable predict() API, but allow opting into embedContent().
        $this->useLegacyPredict = $adapterOptions['use_legacy_predict'] ?? true;

        // Toggle to bypass the GAX retry workaround
        $this->useNativeRetry = $adapterOptions['use_native_retry'] ?? false;

        $this->client = $this->createClient($adapterOptions);
    }

    protected function createClient(array $options = []): PredictionServiceClient {
        $apiEndpoint = sprintf('%s-aiplatform.googleapis.com', $this->location);

        $config = array_merge([
            'apiEndpoint' => $apiEndpoint
        ], $options);

        return new PredictionServiceClient($config);
    }

    // executeWithRealRetry has been moved to GaxRetryWorkaroundTrait

    public function embed(string $text): array {
        if ($this->useLegacyPredict) {
            return $this->embedWithLegacyPredict($text);
        }
        return $this->embedWithEmbedContent($text);
    }

    private function embedWithLegacyPredict(string $text): array {
        if (empty($this->projectId)) {
            throw new \RuntimeException("Vertex AI Project ID is not set.");
        }

        $endpoint = sprintf(
            'projects/%s/locations/%s/publishers/google/models/%s',
            $this->projectId,
            $this->location,
            $this->model
        );

        $instanceValue = new Value();
        $struct = new Struct();
        $struct->setFields([
            'content' => (new Value())->setStringValue($text)
        ]);
        $instanceValue->setStructValue($struct);

        $request = (new PredictRequest())
            ->setEndpoint($endpoint)
            ->setInstances([$instanceValue]);

        $optionalArgs = array_merge(
            ['timeoutMillis' => $this->timeout],
            $this->callOptions
        );

        try {
            $response = $this->executeWithRealRetry(function($args) use ($request) {
                return $this->client->predict($request, $args);
            }, $optionalArgs);

            $predictions = $response->getPredictions();

            if (count($predictions) > 0) {
                $fields = $predictions[0]->getStructValue()->getFields();
                if (isset($fields['embeddings'])) {
                    $embeds = $fields['embeddings']->getStructValue()->getFields()['values']->getListValue()->getValues();
                    $result = [];
                    foreach ($embeds as $val) {
                        $result[] = $val->getNumberValue();
                    }
                    return $result;
                }
            }

            throw new \RuntimeException("Unexpected response format from Vertex AI Predict API.");
        } catch (ApiException $e) {
            throw new \RuntimeException("Vertex AI API Request failed: " . $e->getMessage(), 0, $e);
        }
    }

    private function embedWithEmbedContent(string $text): array {
        if (empty($this->projectId)) {
            throw new \RuntimeException("Vertex AI Project ID is not set.");
        }

        $modelName = sprintf(
            'projects/%s/locations/%s/publishers/google/models/%s',
            $this->projectId,
            $this->location,
            $this->model
        );

        $part = new Part();
        $part->setText($text);

        $content = new Content();
        $content->setParts([$part]);

        $request = (new EmbedContentRequest())
            ->setModel($modelName)
            ->setContent($content);

        $optionalArgs = array_merge(
            ['timeoutMillis' => $this->timeout],
            $this->callOptions
        );

        try {
            $response = $this->executeWithRealRetry(function($args) use ($request) {
                return $this->client->embedContent($request, $args);
            }, $optionalArgs);

            $embedding = $response->getEmbedding();
            
            if ($embedding) {
                // Iterator to array
                $values = iterator_to_array($embedding->getValues());
                return $values;
            }

            throw new \RuntimeException("Unexpected response format from Vertex AI Embed API.");
        } catch (ApiException $e) {
            throw new \RuntimeException("Vertex AI API Request failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function embedBatch(array $texts): array {
        // Vertex AI PHP SDK doesn't natively expose batchEmbedContents yet.
        // We iterate and embed sequentially. 
        // For production scale, it's recommended to implement concurrent async calls or fallback to the REST API.
        $results = [];
        foreach ($texts as $text) {
            $results[] = $this->embed($text);
        }
        return $results;
    }
}
