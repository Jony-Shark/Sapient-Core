<?php

namespace Tests\Unit\Workflow;

use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Workflow\AdvancedWorkflow;
use PHPUnit\Framework\TestCase;
use Sapient\Core\State;

class AdvancedWorkflowTest extends TestCase
{
    public function testFluentBuilderAddsSteps()
    {
        $mockStep = $this->createMock(StepInterface::class);
        $mockStep->method('getName')->willReturn('mock');

        $workflow = new class('test', 'desc') extends AdvancedWorkflow {
            public $stepAdded = false;
            protected function build(): void {
                // Not calling anything here, we'll test then() directly
            }
        };

        $workflow->then($mockStep);
        
        // Use reflection to check internal pipeline steps if necessary, 
        // or just verify process() calls it.
        $state = new State();
        $mockStep->expects($this->once())->method('process')->willReturn($state);
        
        $workflow->process($state);
    }

    public function testDecisionStepRouting()
    {
        $mockLlm = $this->createStub(LLMInterface::class);
        $mockLlm->method('chat')->willReturn('billing');

        $billingStep = $this->createMock(StepInterface::class);
        $billingStep->expects($this->once())->method('process')->willReturnArgument(0);

        $medicalStep = $this->createMock(StepInterface::class);
        $medicalStep->expects($this->never())->method('process');

        $workflow = new class($mockLlm, $billingStep, $medicalStep) extends AdvancedWorkflow {
            private $llm;
            private $billing;
            private $medical;
            public function __construct($llm, $billing, $medical) {
                $this->llm = $llm;
                $this->billing = $billing;
                $this->medical = $medical;
                parent::__construct('router', 'routes to billing or medical');
            }
            protected function build(): void {
                $this->decide($this->llm, "Where to go?", [
                    'billing' => $this->billing,
                    'medical' => $this->medical
                ]);
            }
        };

        $state = new State();
        $state->set('input', 'I want to pay my bill');
        $workflow->process($state);
    }

    public function testExtractionStep()
    {
        $mockLlm = $this->createStub(LLMInterface::class);
        $mockLlm->method('chat')->willReturn('{"name": "John Doe", "status": "active"}');

        $workflow = new class($mockLlm) extends AdvancedWorkflow {
            private $llm;
            public function __construct($llm) {
                $this->llm = $llm;
                parent::__construct('extractor', 'extracts json');
            }
            protected function build(): void {
                $this->extract($this->llm, "System", "Prompt {{ input }}", "data");
            }
        };

        $state = new State();
        $state->set('input', 'Some raw data');
        $workflow->process($state);

        $extracted = $state->get('data');
        $this->assertIsArray($extracted);
        $this->assertEquals('John Doe', $extracted['name']);
    }

    public function testExtractionStepMultiMapping()
    {
        $mockLlm = $this->createStub(LLMInterface::class);
        $mockLlm->method('chat')->willReturn('{"data": "some content", "next": "carry-over"}');

        $workflow = new class($mockLlm) extends AdvancedWorkflow {
            private $llm;
            public function __construct($llm) {
                $this->llm = $llm;
                parent::__construct('extractor', 'extracts json');
            }
            protected function build(): void {
                $this->extract($this->llm, "System", "Prompt", [
                    'data' => 'accumulated[]',
                    'next' => 'context'
                ]);
            }
        };

        $state = new State();
        
        // Iteration 1
        $workflow->process($state);
        $this->assertEquals(['some content'], $state->get('accumulated'));
        $this->assertEquals('carry-over', $state->get('context'));

        // Iteration 2
        $workflow->process($state);
        $this->assertEquals(['some content', 'some content'], $state->get('accumulated'));
    }
}
