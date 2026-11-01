<?php

namespace Sapient\Tests\Unit\Adapter\Embedding;

use PHPUnit\Framework\TestCase;
use Sapient\Adapter\Embedding\OllamaEmbeddingAdapter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class OllamaEmbeddingAdapterTest extends TestCase {
    private function createAdapterWithMockResponse(int $statusCode, array $responseBody): OllamaEmbeddingAdapter {
        $mock = new MockHandler([
            new Response($statusCode, [], json_encode($responseBody))
        ]);

        $handlerStack = HandlerStack::create($mock);

        return new OllamaEmbeddingAdapter('test-model', 'http://fake', 60, ['handler' => $handlerStack]);
    }

    public function testEmbedSingleSuccessfullyReturnsVector() {
        $adapter = $this->createAdapterWithMockResponse(200, [
            'embeddings' => [
                [0.1, 0.2, 0.3]
            ]
        ]);

        $vector = $adapter->embed("Hello world");
        $this->assertEquals([0.1, 0.2, 0.3], $vector);
    }

    public function testEmbedBatchSuccessfullyReturnsVectors() {
        $adapter = $this->createAdapterWithMockResponse(200, [
            'embeddings' => [
                [0.1, 0.2],
                [0.3, 0.4]
            ]
        ]);

        $vectors = $adapter->embedBatch(["Text 1", "Text 2"]);
        $this->assertCount(2, $vectors);
        $this->assertEquals([0.1, 0.2], $vectors[0]);
        $this->assertEquals([0.3, 0.4], $vectors[1]);
    }

    public function testEmbedThrowsExceptionOnInvalidResponse() {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unexpected response format");

        $adapter = $this->createAdapterWithMockResponse(200, ['wrong_data' => 'yes']);
        $adapter->embed("Hello world");
    }
}
