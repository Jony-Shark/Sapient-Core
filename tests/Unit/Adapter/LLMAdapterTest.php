<?php

namespace Tests\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Sapient\Adapter\AnthropicAdapter;
use Sapient\Adapter\GeminiAdapter;
use Sapient\Adapter\OllamaAdapter;
use Sapient\Adapter\OpenAIAdapter;
use Sapient\Adapter\VertexAIAdapter;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Core\Message;
use Sapient\Core\State;
use Sapient\Steps\Agent\ExtractionStep;
use Sapient\Steps\Agent\GenerativeTaskStep;
use Sapient\Steps\Agent\SimpleAgentStep;
use Sapient\Steps\Control\DecisionStep;
use Sapient\Steps\Memory\ContextCompressionStep;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Google\Cloud\AIPlatform\V1\Client\PredictionServiceClient;

class LLMAdapterTest extends TestCase
{
    /**
     * Helper to invoke protected prepareMessages() on any adapter without network calls.
     */
    private function invokePrepareMessages(object $adapter, array $messages): array
    {
        $ref = new \ReflectionMethod($adapter, 'prepareMessages');
        return $ref->invoke($adapter, $messages);
    }

    /**
     * Helper to inject a Mock Guzzle Client with history container into Guzzle-based adapters.
     */
    private function injectGuzzleHistory(object $adapter, array $responses, array &$history): void
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        $prop = new \ReflectionProperty($adapter, 'client');
        $prop->setValue($adapter, $client);
    }

    private function createImageMessage(string $text, string $url): Message
    {
        return Message::user([
            ['type' => 'text', 'text' => $text],
            ['type' => 'image_url', 'image_url' => ['url' => $url]],
        ]);
    }

    public function testOpenAIPrepareMessagesAndGenerationConfigMerge(): void
    {
        $adapter = new OpenAIAdapter('gpt-4o', 'test-key', 'https://api.openai.com/v1', 30, [
            'generationConfig' => [
                'temperature' => 0.2,
                'maxTokens'   => 500,
            ],
        ]);

        $messages = [
            Message::system('You are helpful.'),
            Message::user('Hello!'),
            Message::assistant('Hi there!'),
            $this->createImageMessage('What is this?', 'data:image/png;base64,iVBORw0KGgo='),
        ];

        $prepared = $this->invokePrepareMessages($adapter, $messages);

        $this->assertCount(4, $prepared);
        $this->assertSame('system', $prepared[0]['role']);
        $this->assertSame('You are helpful.', $prepared[0]['content']);
        $this->assertSame('assistant', $prepared[2]['role']);
        $this->assertIsArray($prepared[3]['content']);
        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $prepared[3]['content'][1]['image_url']['url']);

        // Verify generationConfig default + per-call override via MockHandler
        $history = [];
        $this->injectGuzzleHistory($adapter, [
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'Mocked OpenAI reply']]]
            ]))
        ], $history);

        $reply = $adapter->chat($messages, ['temperature' => 0.9]);
        $this->assertSame('Mocked OpenAI reply', $reply);
        $this->assertCount(1, $history);

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(0.9, $payload['temperature']); // Overridden per-call
        $this->assertSame(500, $payload['max_tokens']);  // Preserved from default generationConfig
    }

    public function testAnthropicPrepareMessagesAndGenerationConfigMerge(): void
    {
        $adapter = new AnthropicAdapter('claude-sonnet-4-5', 'test-key', 30, [
            'generationConfig' => [
                'temperature' => 0.3,
                'maxTokens'   => 2048,
            ],
        ]);

        $messages = [
            Message::system('System rule 1.'),
            Message::system('System rule 2.'),
            $this->createImageMessage('Describe image', 'data:image/jpeg;base64,/9j/4AAQ'),
        ];

        [$transformed, $systemPrompt] = $this->invokePrepareMessages($adapter, $messages);

        $this->assertSame("System rule 1.\n\nSystem rule 2.", $systemPrompt);
        $this->assertCount(1, $transformed);
        $this->assertSame('user', $transformed[0]['role']);
        $this->assertSame('image', $transformed[0]['content'][1]['type']);
        $this->assertSame('image/jpeg', $transformed[0]['content'][1]['source']['media_type']);
        $this->assertSame('/9j/4AAQ', $transformed[0]['content'][1]['source']['data']);

        $history = [];
        $this->injectGuzzleHistory($adapter, [
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'Mocked Anthropic reply']]
            ]))
        ], $history);

        $reply = $adapter->chat($messages, ['temperature' => 0.7]);
        $this->assertSame('Mocked Anthropic reply', $reply);

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame("System rule 1.\n\nSystem rule 2.", $payload['system']);
        $this->assertSame(0.7, $payload['temperature']);
        $this->assertSame(2048, $payload['max_tokens']);
    }

    public function testOllamaPrepareMessagesAndGenerationConfigMerge(): void
    {
        $adapter = new OllamaAdapter('qwen3', 'http://localhost:11434', 30, [
            'generationConfig' => [
                'temperature' => 0.1,
                'topK'        => 20,
            ],
        ]);

        $messages = [
            $this->createImageMessage('Look at this', 'data:image/png;base64,AAAA'),
        ];

        $transformed = $this->invokePrepareMessages($adapter, $messages);
        $this->assertCount(1, $transformed);
        $this->assertSame('Look at this', $transformed[0]['content']);
        $this->assertSame(['AAAA'], $transformed[0]['images']);

        $history = [];
        $this->injectGuzzleHistory($adapter, [
            new Response(200, [], json_encode([
                'message' => ['content' => 'Ollama response', 'thinking' => 'internal thought']
            ]))
        ], $history);

        $reply = $adapter->chat($messages, ['temperature' => 0.8]);
        $this->assertStringContainsString('<thought>', $reply);
        $this->assertStringContainsString('Ollama response', $reply);

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(0.8, $payload['options']['temperature']);
        $this->assertSame(20, $payload['options']['top_k']);
    }

    public function testGeminiPrepareMessagesAndGenerationConfigMerge(): void
    {
        $adapter = new GeminiAdapter('gemini-2.5-flash', 'fake-api-key', 30, [
            'generationConfig' => [
                'temperature' => 0.2,
                'topP'        => 0.9,
            ],
        ]);

        $messages = [
            Message::system('Be concise.'),
            Message::user('Hi'),
            Message::assistant('Hello!'),
        ];

        [$contents, $systemInstruction] = $this->invokePrepareMessages($adapter, $messages);

        $this->assertNotNull($systemInstruction);
        $this->assertSame('Be concise.', $systemInstruction['parts'][0]['text']);
        $this->assertCount(2, $contents);
        $this->assertSame('user', $contents[0]['role']);
        $this->assertSame('model', $contents[1]['role']);

        $history = [];
        $this->injectGuzzleHistory($adapter, [
            new Response(200, [], json_encode([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Gemini reply']]]]
                ]
            ]))
        ], $history);

        $reply = $adapter->chat($messages, ['temperature' => 0.6]);
        $this->assertSame('Gemini reply', $reply);

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(0.6, $payload['generationConfig']['temperature']);
        $this->assertSame(0.9, $payload['generationConfig']['topP']);
    }

    public function testVertexAIPrepareMessages(): void
    {
        if (!class_exists(PredictionServiceClient::class)) {
            $this->markTestSkipped('google/cloud-ai-platform is not installed.');
        }

        // Reflection instantiation without network credentials
        $refClass = new \ReflectionClass(VertexAIAdapter::class);
        /** @var VertexAIAdapter $adapter */
        $adapter = $refClass->newInstanceWithoutConstructor();

        $messages = [
            Message::system('System rule A'),
            Message::system('System rule B'),
            Message::user('Check [LOCAL_IMAGE:gs://my-bucket/doc.pdf] please'),
            Message::assistant('Sure!'),
        ];

        [$contents, $systemInstruction] = $this->invokePrepareMessages($adapter, $messages);

        $this->assertNotNull($systemInstruction);
        $this->assertCount(2, $systemInstruction->getParts());
        $this->assertSame('System rule A', $systemInstruction->getParts()[0]->getText());
        $this->assertSame('System rule B', $systemInstruction->getParts()[1]->getText());

        $this->assertCount(2, $contents);
        $this->assertSame('user', $contents[0]->getRole());
        $userParts = $contents[0]->getParts();
        $this->assertCount(3, $userParts);
        $this->assertSame('Check ', $userParts[0]->getText());
        $this->assertSame('gs://my-bucket/doc.pdf', $userParts[1]->getFileData()->getFileUri());
        $this->assertSame('application/pdf', $userParts[1]->getFileData()->getMimeType());
        $this->assertSame(' please', $userParts[2]->getText());

        $this->assertSame('model', $contents[1]->getRole());
    }

    public function testPipelineStepsForwardOptionsToLLMChat(): void
    {
        $customOptions = ['temperature' => 0.15, 'maxOutputTokens' => 1024];

        // 1. SimpleAgentStep
        $mockLlm1 = $this->createMock(LLMInterface::class);
        $mockLlm1->expects($this->once())
            ->method('chat')
            ->with($this->anything(), $this->equalTo($customOptions))
            ->willReturn('Agent reply');

        $state1 = new State();
        $state1->set('input', 'Hello');
        (new SimpleAgentStep($mockLlm1, $customOptions))->process($state1);

        // 2. ExtractionStep
        $mockLlm2 = $this->createMock(LLMInterface::class);
        $mockLlm2->expects($this->once())
            ->method('chat')
            ->with($this->anything(), $this->equalTo($customOptions))
            ->willReturn('{"name":"Test"}');

        $state2 = new State();
        $state2->set('raw_text', 'Name is Test');
        (new ExtractionStep($mockLlm2, 'Extract JSON:', '{{ raw_text }}', 'extracted', null, $customOptions))->process($state2);

        // 3. GenerativeTaskStep
        $mockLlm3 = $this->createMock(LLMInterface::class);
        $mockLlm3->expects($this->once())
            ->method('chat')
            ->with($this->anything(), $this->equalTo($customOptions))
            ->willReturn('Summary text');

        $state3 = new State();
        $state3->set('article', 'Long text');
        (new GenerativeTaskStep($mockLlm3, 'You are a summarizer.', '{{ article }}', 'summary', $customOptions))->process($state3);

        // 4. DecisionStep
        $mockLlm4 = $this->createMock(LLMInterface::class);
        $mockLlm4->expects($this->once())
            ->method('chat')
            ->with($this->anything(), $this->equalTo($customOptions))
            ->willReturn('approve');

        $state4 = new State();
        $state4->set('input', 'Valid item');
        (new DecisionStep($mockLlm4, 'Decide:', [], $customOptions))->process($state4);

        // 5. ContextCompressionStep
        $mockLlm5 = $this->createMock(LLMInterface::class);
        $mockLlm5->expects($this->once())
            ->method('chat')
            ->with($this->anything(), $this->equalTo($customOptions))
            ->willReturn('Compressed summary');

        $state5 = new State();
        $state5->setHistory([
            Message::user('1'), Message::assistant('2'),
            Message::user('3'), Message::assistant('4'),
            Message::user('5'), Message::assistant('6'),
            Message::user('7'), Message::assistant('8'),
            Message::user('9'), Message::assistant('10'),
        ]);
        (new ContextCompressionStep($mockLlm5, 2, null, $customOptions))->process($state5);
    }
}
