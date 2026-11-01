<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sapient\Core\Pipeline;
use Sapient\Core\State;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Memory\Identity;
use Sapient\Core\StaticContextProvider;
use Sapient\Steps\System\PromptBuilderStep;
use Sapient\Steps\Agent\SimpleAgentStep;
use Sapient\Steps\IO\OutputParsingStep;
use Sapient\Parsing\XmlOutputParser;
use Sapient\Core\Message;

class EndToEndChatTest extends TestCase
{
    private $tempDir;
    private $identityService;
    private $rulesProvider;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sapient_integration_' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/data');
        mkdir($this->tempDir . '/resources');

        // Create base config files
        file_put_contents($this->tempDir . '/resources/identity_base.md', "You are IntegrationBot.");
        file_put_contents($this->tempDir . '/resources/sapient_rules.md', "You are in a Pipeline.");

        // Setup Services
        $this->identityService = new Identity($this->tempDir . '/data', 'test_bot');
        $this->rulesProvider = new StaticContextProvider(
            file_get_contents($this->tempDir . '/resources/sapient_rules.md'),
            'sapient_rules'
        );
    }

    protected function tearDown(): void
    {
        $this->recursiveRmdir($this->tempDir);
    }

    private function recursiveRmdir($dir)
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursiveRmdir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testChatLoopFlow()
    {
        // 1. Mock LLM
        $mockLlm = $this->createMock(LLMInterface::class);
        $mockLlm->expects($this->once())
            ->method('chat')
            ->with($this->callback(function ($messages) {
                // Assert System Prompts are present
                $hasIdentity = false;
                $hasUserMsg = false;
                $hasRules = false;

                foreach ($messages as $msg) {
                    if ($msg->role === 'system') {
                        if (str_contains($msg->content, 'IntegrationBot')) $hasIdentity = true;
                        if (str_contains($msg->content, 'Pipeline')) $hasRules = true;
                    }
                    if ($msg->role === 'user' && $msg->content === 'Hello') $hasUserMsg = true;
                }

                return $hasIdentity && $hasRules && $hasUserMsg;
            }))
            ->willReturn("Hello Human.");

        // 2. Setup Pipeline
        $pipeline = new Pipeline();
        
        // Input Injection (Simulate InputStep)
        $pipeline->addStep(new class implements StepInterface {
            public function getName(): string { return 'InputSim'; }
            public function process(StateInterface $state): StateInterface {
                $input = $state->get('input');
                $history = $state->getHistory();
                $history[] = Message::user($input);
                $state->setHistory($history);
                return $state; 
            }
        });

        // Prompt Builder
        $pipeline->addStep(new PromptBuilderStep(
            [$this->identityService, $this->rulesProvider],
            "" // Empty base, as Identity + Rules are providers
        ));

        // Agent
        $pipeline->addStep(new SimpleAgentStep($mockLlm));

        // Output Parser
        $pipeline->addStep(new OutputParsingStep(new XmlOutputParser([])));

        // 3. Execution
        $state = new State();
        $state->set('input', 'Hello');
        
        $finalState = $pipeline->run($state);

        // 4. Verification
        $this->assertEquals("Hello Human.", $finalState->get('last_response'));
        
        // Verify History
        $history = $finalState->getHistory();
        $this->assertCount(3, $history); // System + User + Assistant
        $this->assertEquals('system', $history[0]->role);
        $this->assertEquals('user', $history[1]->role);
        $this->assertEquals('Hello', $history[1]->content);
        $this->assertEquals('assistant', $history[2]->role);
        $this->assertEquals('Hello Human.', $history[2]->content);
    }
}
