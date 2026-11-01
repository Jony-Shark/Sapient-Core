<?php

namespace Tests\Unit\Adapter\Embedding;

use PHPUnit\Framework\TestCase;
use Sapient\Adapter\Embedding\VertexAIEmbeddingAdapter;
use Google\Cloud\AIPlatform\V1\Client\PredictionServiceClient;
use Google\ApiCore\Transport\RestTransport;
use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\RetrySettings;
use Google\ApiCore\ApiStatus;
use Google\Rpc\Code;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client;
use Google\Auth\FetchAuthTokenInterface;

class VertexAIEmbeddingAdapterTest extends TestCase {
    
    protected function setUp(): void
    {
        if (!class_exists(PredictionServiceClient::class)) {
            $this->markTestSkipped('The google/cloud-ai-platform package is not installed. Skipping Vertex AI tests.');
        }
    }

    private function createMockedAdapter(array $mockResponses, array $callOptions = [], array $adapterOptionsOverrides = []): VertexAIEmbeddingAdapter {
        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = HandlerStack::create($mockHandler);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $httpHandler = function ($request, $options) use ($guzzleClient) {
            return $guzzleClient->sendAsync($request, $options);
        };

        $dummyAuth = new class implements FetchAuthTokenInterface {
            public function fetchAuthToken(?callable $httpHandler = null) { return ['access_token' => 'fake']; }
            public function getCacheKey() { return 'fake'; }
            public function getLastReceivedToken() { return ['access_token' => 'fake']; }
        };

        $restConfigPath = __DIR__ . '/../../../../vendor/google/cloud-ai-platform/src/V1/resources/prediction_service_rest_client_config.php';
        
        $restTransport = RestTransport::build('us-central1-aiplatform.googleapis.com', 
            $restConfigPath,
            ['httpHandler' => $httpHandler]
        );

        $adapterOptions = array_merge([
            'credentials' => new CredentialsWrapper($dummyAuth),
            'transport' => $restTransport
        ], $adapterOptionsOverrides);

        return new VertexAIEmbeddingAdapter(
            'text-embedding-004', 
            'fake-project', 
            'us-central1', 
            10, 
            $adapterOptions, 
            $callOptions
        );
    }

    public function testLegacyPredictReturnsCorrectValues() {
        // Construct a valid Google API response JSON for PredictResponse
        $jsonResponse = json_encode([
            'predictions' => [
                [
                    'embeddings' => [
                        'values' => [0.8, -0.9, 1.0]
                    ]
                ]
            ]
        ]);

        $adapter = $this->createMockedAdapter([
            new Response(200, [], $jsonResponse)
        ], [], ['use_legacy_predict' => true]);

        $result = $adapter->embed("Hello world legacy");

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEqualsWithDelta(0.8, $result[0], 0.00001);
    }

    public function testEmbedContentReturnsCorrectValues() {
        // Construct a valid Google API response JSON for EmbedContentResponse
        $jsonResponse = json_encode([
            'embedding' => [
                'values' => [0.1, -0.2, 0.3]
            ]
        ]);

        $adapter = $this->createMockedAdapter([
            new Response(200, [], $jsonResponse)
        ], [], ['use_legacy_predict' => false]);

        $result = $adapter->embed("Hello world");

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEqualsWithDelta(0.1, $result[0], 0.00001);
    }

    public function testRetryLogicWorksWithUnavailableStatus() {
        // We will configure a very fast retry just to prove the mechanism triggers
        // without slowing down our test suite too much (e.g. 50ms).
        $retrySettings = new RetrySettings([
            'retriesEnabled' => true,
            'maxRetries' => 2,
            'initialRetryDelayMillis' => 50,
            'retryDelayMultiplier' => 1.0,
            'maxRetryDelayMillis' => 50,
            'initialRpcTimeoutMillis' => 5000,
            'rpcTimeoutMultiplier' => 1.0,
            'maxRpcTimeoutMillis' => 5000,
            'totalTimeoutMillis' => 10000,
            'retryableCodes' => [Code::UNAVAILABLE, ApiStatus::UNAVAILABLE],
        ]);

        // Testing with the legacy PredictResponse structure
        $jsonResponse = json_encode([
            'predictions' => [
                [
                    'embeddings' => [
                        'values' => [0.5, 0.6]
                    ]
                ]
            ]
        ]);

        $adapter = $this->createMockedAdapter([
            new Response(503, [], '{"error": {"message": "Unavailable", "status": "UNAVAILABLE", "code": 503}}'),
            new Response(503, [], '{"error": {"message": "Unavailable", "status": "UNAVAILABLE", "code": 503}}'),
            new Response(200, [], $jsonResponse)
        ], ['retrySettings' => $retrySettings], ['use_legacy_predict' => true]);

        $start = microtime(true);
        $result = $adapter->embed("Test retry");
        $duration = microtime(true) - $start;

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEqualsWithDelta(0.5, $result[0], 0.00001);

        // It should have slept for at least 2 * 50ms = 100ms (0.1s)
        $this->assertGreaterThanOrEqual(0.1, $duration, "Retry backoff didn't sleep properly");
    }

    public function testNativeGaxRetryWaitBehavior() {
        $retrySettings = new RetrySettings([
            'retriesEnabled' => true,
            'maxRetries' => 2,
            'initialRetryDelayMillis' => 50,
            'retryDelayMultiplier' => 1.0,
            'maxRetryDelayMillis' => 50,
            'initialRpcTimeoutMillis' => 5000,
            'rpcTimeoutMultiplier' => 1.0,
            'maxRpcTimeoutMillis' => 5000,
            'totalTimeoutMillis' => 10000,
            'retryableCodes' => [Code::UNAVAILABLE, ApiStatus::UNAVAILABLE],
        ]);

        $jsonResponse = json_encode([
            'predictions' => [
                [
                    'embeddings' => [
                        'values' => [0.5, 0.6]
                    ]
                ]
            ]
        ]);

        // We explicitly turn ON the native retry to test if the installed google/gax version has the sleep bug.
        $adapter = $this->createMockedAdapter([
            new Response(503, [], '{"error": {"message": "Unavailable", "status": "UNAVAILABLE", "code": 503}}'),
            new Response(503, [], '{"error": {"message": "Unavailable", "status": "UNAVAILABLE", "code": 503}}'),
            new Response(200, [], $jsonResponse)
        ], ['retrySettings' => $retrySettings], ['use_legacy_predict' => true, 'use_native_retry' => true]);

        $start = microtime(true);
        $result = $adapter->embed("Test native retry");
        $duration = microtime(true) - $start;

        $this->assertIsArray($result);
        
        // If the duration is less than 0.1s, it means the native GAX RetryMiddleware did not sleep.
        // This will explicitly FAIL the test, signaling that the environment has an outdated GAX dependency.
        $this->assertGreaterThanOrEqual(
            0.1, 
            $duration, 
            "Native GAX retry did not sleep! The google/gax dependency is likely older than 1.43.1 and has the sleep bug. Keep use_native_retry=false."
        );
    }

    public function testApiFailureThrowsRuntimeException() {
        $adapter = $this->createMockedAdapter([
            new Response(400, [], '{"error": {"message": "Bad Request", "status": "INVALID_ARGUMENT", "code": 400}}')
        ], [], ['use_legacy_predict' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Vertex AI API Request failed/i');

        $adapter->embed("Fail me");
    }
}
