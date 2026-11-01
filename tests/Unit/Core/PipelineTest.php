<?php

namespace Tests\Unit\Core;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use PHPUnit\Framework\TestCase;
use Sapient\Core\Pipeline;
use Sapient\Core\State;

class PipelineTest extends TestCase
{
    public function testPipelineExecutionOrder()
    {
        $step1 = new class implements StepInterface {
            public function getName(): string { return 'Step1'; }
            public function process(StateInterface $state): StateInterface {
                $state->set('scratched', ($state->get('scratched') ?? '') . 'A');
                return $state;
            }
        };

        $step2 = new class implements StepInterface {
            public function getName(): string { return 'Step2'; }
            public function process(StateInterface $state): StateInterface {
                $state->set('scratched', $state->get('scratched') . 'B');
                return $state;
            }
        };

        $pipeline = new Pipeline();
        $pipeline->addStep($step1)->addStep($step2);

        $state = new State();
        $finalState = $pipeline->run($state);

        $this->assertEquals('AB', $finalState->get('scratched'));
    }

    public function testPipelineHandlesExceptions()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Boom');

        $brokenStep = new class implements StepInterface {
            public function getName(): string { return 'Broken'; }
            public function process(StateInterface $state): StateInterface {
                throw new \RuntimeException('Boom');
            }
        };

        $pipeline = new Pipeline();
        $pipeline->addStep($brokenStep);
        $pipeline->run(new State());
    }
}
