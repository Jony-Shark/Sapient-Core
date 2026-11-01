<?php

namespace Tests\Integration;

use Sapient\Contracts\Core\LLMInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;
use Sapient\Core\Pipeline;
use Sapient\Core\State;
use Sapient\Steps\Agent\SimpleAgentStep;
use Sapient\Steps\IO\OutputParsingStep;
use Sapient\Steps\Tools\ToolExecutionStep;
use Sapient\Parsing\XmlOutputParser;
use Sapient\Hippocampus\Tools\MemorizeTool;
use Sapient\Hippocampus\HippocampusClient;
use Sapient\Tools\Std\HelpTool;

class ToolIntegrationTest extends TestCase
{
    public function testToolExecutionFlow()
    {
        // 1. Mock LLM to return a Tool Call
        $mockLlm = $this->createStub(LLMInterface::class);
        $mockLlm->method('chat')
            ->willReturn("I will remember that.\n<memorize>The sky is blue.</memorize>");

        // 2. Setup Tool (use Hippocampus MemorizeTool if plugin is present, otherwise mock ToolInterface)
        if (class_exists(HippocampusClient::class) && class_exists(MemorizeTool::class)) {
            $mockHippocampus = $this->createMock(HippocampusClient::class);
            $mockHippocampus->method('isAvailable')->willReturn(true);
            $mockHippocampus->expects($this->once())
                ->method('remember')
                ->with($this->stringContains('The sky is blue.'))
                ->willReturn(['status' => 'stored', 'id' => 123]);

            $memorizeTool = new MemorizeTool($mockHippocampus);
        } else {
            $memorizeTool = $this->createMock(ToolInterface::class);
            $memorizeTool->method('getName')->willReturn('memorize');
            $memorizeTool->expects($this->once())
                ->method('execute')
                ->with($this->callback(fn(array $args) => ($args['content'] ?? '') === 'The sky is blue.'))
                ->willReturn('Memory stored (ID: 123).');
        }

        // 3. Setup Tools
        $toolStep = new ToolExecutionStep([
            $memorizeTool
        ]);

        // 4. Setup Pipeline
        $pipeline = new Pipeline();
        
        // Mock Input Step via anonymous class
        $pipeline->addStep(new class implements StepInterface {
            public function getName(): string { return 'InputSim'; }
            public function process(StateInterface $state): StateInterface {
                return $state; // Input not strictly needed for this test phase, just LLM output
            }
        });

        // Agent Step (Generates response)
        $pipeline->addStep(new SimpleAgentStep($mockLlm));

        // Output Parser (Extracts XML)
        $parser = new XmlOutputParser($toolStep->getToolNames());
        $pipeline->addStep(new OutputParsingStep($parser));

        // Tool Execution Step (Runs the tool)
        $pipeline->addStep($toolStep);

        // 5. Execution
        $state = new State();
        $state->set('input', 'Please remember the sky is blue.');
        
        $finalState = $pipeline->run($state);

        // 6. Verification
        
        // Check if actions were parsed
        $actions = $finalState->get('pending_actions');
        $this->assertNotEmpty($actions);
        $this->assertEquals('memorize', $actions[0]['tool']);
        $this->assertEquals('The sky is blue.', $actions[0]['args']['content']);

        // Check if tool results are in state
        $results = $finalState->get('tool_results');
        $this->assertNotEmpty($results);
        $this->assertEquals('memorize', $results[0]['tool']);
        
        // The mock verification ($mockHippocampus->expects($this->once())) handles the actual logic check.
    }

    public function testHelpToolIntrospection()
    {
        // 1. Setup Mock Tools
        $mockTool1 = $this->createStub(ToolInterface::class);
        $mockTool1->method('getName')->willReturn('mock_tool_1');
        $mockTool1->method('getDescription')->willReturn('Description 1');
        $mockTool1->method('getInstruction')->willReturn('<tool>1</tool>');

        $mockTool2 = $this->createStub(ToolInterface::class);
        $mockTool2->method('getName')->willReturn('mock_tool_2');
        $mockTool2->method('getDescription')->willReturn('Description 2');
        $mockTool2->method('getInstruction')->willReturn('<tool>2</tool>');

        // 2. Setup HelpTool
        $helpTool = new HelpTool();
        
        // 3. Register Tools
        $helpTool->setRegisteredTools([$mockTool1, $mockTool2, $helpTool]);

        // 4. Case A: List all tools
        $output = $helpTool->execute(['value' => 'list']);
        $this->assertStringContainsString('Available Tools:', $output);
        $this->assertStringContainsString('- mock_tool_1:', $output);
        $this->assertStringContainsString('  Usage: <tool>1</tool>', $output);
        $this->assertStringNotContainsString('  Description: Description 1', $output); // List is now compact
        
        // 5. Case B: Specific Tool details (Man page)
        $output = $helpTool->execute(['value' => 'mock_tool_1']);
        $this->assertStringContainsString('Tool: mock_tool_1', $output);
        $this->assertStringContainsString('Description: Description 1', $output);
        $this->assertStringContainsString('Usage: <tool>1</tool>', $output);
        
        $this->assertStringContainsString('- help:', $helpTool->execute(['value' => 'list']));
    }
}
