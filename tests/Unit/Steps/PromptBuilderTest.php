<?php

namespace Tests\Unit\Steps;

use Sapient\Steps\System\PromptBuilderStep;
use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Core\Message;
use Sapient\Core\State;
use PHPUnit\Framework\TestCase;

class PromptBuilderTest extends TestCase
{
    public function testBaseInstructionInjection()
    {
        $step = new PromptBuilderStep([], "You are a test bot.");
        $state = new State();
        
        $newState = $step->process($state);
        $history = $newState->getHistory();
        
        $this->assertNotEmpty($history);
        $this->assertEquals('system', $history[0]->role);
        $this->assertStringContainsString("You are a test bot.", $history[0]->content);
    }

    public function testContextProviderInjection()
    {
        // Mock Provider
        $provider = new class implements ContextProviderInterface {
            public function getContextName(): string { return 'mock'; }
            public function provideContext(StateInterface $state): ?string {
                return "Mock Context Content";
            }
        };

        $step = new PromptBuilderStep([$provider], "Base.");
        $state = new State();
        
        $newState = $step->process($state);
        $history = $newState->getHistory();
        
        $this->assertStringContainsString("Mock Context Content", $history[0]->content);
    }

    public function testSystemRewriteOnLoop()
    {
        $step = new PromptBuilderStep([], "New System Prompt.");
        $state = new State();
        
        // Simulate existing history with old system prompt
        $state->setHistory([
            Message::system('Old Prompt'),
            Message::user('Hi')
        ]);
        
        $newState = $step->process($state);
        $history = $newState->getHistory();
        
        // Should replace the FIRST element, not append
        $this->assertCount(2, $history);
        $this->assertEquals('system', $history[0]->role);
        $this->assertStringContainsString("New System Prompt", $history[0]->content);
        $this->assertEquals('Hi', $history[1]->content);
    }
}
