<?php

namespace Tests\Unit\Workflow;

use Sapient\Examples\ExampleWorkflow;
use Sapient\Core\State;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../examples/ExampleWorkflow.php';

class WorkflowTest extends TestCase
{
    public function testExampleWorkflowBananaLogic()
    {
        $workflow = new ExampleWorkflow('test', 'Test Workflow');
        $state = new State();
        
        // The workflow echoes output, so we capture it
        $this->expectOutputRegex('/Workflow Started/');
        $this->expectOutputRegex('/Processing item: Apple/');
        $this->expectOutputRegex('/Found a Banana! Keeping it special/');
        $this->expectOutputRegex('/Processing item: Cherry/');
        $this->expectOutputRegex('/Workflow Finished/');

        $finalState = $workflow->process($state);
        
        // Verify state side-effects
        $this->assertEquals(['Apple', 'Banana', 'Cherry'], $finalState->get('items'));
        // The loop finishes with the last item
        $this->assertEquals('Cherry', $finalState->get('current_item'));
    }
}
