<?php

namespace Sapient\Tests\Unit\Adapter\Embedding;

use PHPUnit\Framework\TestCase;
use Sapient\Adapter\Embedding\OpenAIEmbeddingAdapter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class OpenAIEmbeddingAdapterTest extends TestCase {
    private function createAdapterWithMockResponse(int $statusCode, array $responseBody): OpenAIEmbeddingAdapter {
        $mock = new MockHandler([
            new Response($statusCode, [], json_encode($responseBody))
        ]);

        $handlerStack = HandlerStack::create($mock);

        return new OpenAIEmbeddingAdapter('test-model', 'fake-key', 'http://fake', 60, ['handler' => $handlerStack]);
    }

    public function testEmbedSingleSuccessfullyReturnsVector() {
        $adapter = $this->createAdapterWithMockResponse(200, [
            'data' => [
                [
                    'index' => 0,
                    'embedding' => [0.1, 0.2, 0.3]
                ]
            ]
        ]);

        $vector = $adapter->embed("Hello world");
        $this->assertEquals([0.1, 0.2, 0.3], $vector);
    }

    public function testEmbedThrowsExceptionOnMissingApiKey() {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("OPENAI_API_KEY is not set.");
        
        $adapter = new OpenAIEmbeddingAdapter('test-model', '');
        $adapter->embed("Fail");
    }

    public function testEmbedBatchSuccessfullyReturnsVectors() {
        $adapter = $this->createAdapterWithMockResponse(200, [
            'data' => [
                ['index' => 1, 'embedding' => [0.3, 0.4]], // Unordered to test sorting
                ['index' => 0, 'embedding' => [0.1, 0.2]]
            ]
        ]);

        $vectors = $adapter->embedBatch(["Text 1", "Text 2"]);
        $this->assertCount(2, $vectors);
        // Should be sorted by index 0 then 1
        $this->assertEquals([0.1, 0.2], $vectors[0]);
        $this->assertEquals([0.3, 0.4], $vectors[1]);
    }
}
