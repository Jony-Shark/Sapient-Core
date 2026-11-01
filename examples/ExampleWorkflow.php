<?php

namespace Sapient\Examples;

use Sapient\Workflow\Workflow;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Steps\Control\LoopStep;
use Sapient\Steps\Control\ConditionalStep;
use Sapient\Contracts\Core\StepInterface;

// A simple step to log processing
class ExampleWorkflow extends Workflow {
    protected function build(): void {
        // Step 1: Log Start
        $this->addStep(new class implements StepInterface {
            public function getName(): string { return 'Start'; }
            public function process(StateInterface $state): StateInterface {
                echo "Workflow Started.\n";
                // Seed data if not present
                if (!$state->has('items')) {
                    $state->set('items', ['Apple', 'Banana', 'Cherry']);
                }
                return $state;
            }
        });

        // Step 2: Loop through items
        $this->addStep(new LoopStep(
            'items',
            'current_item',
            new ConditionalStep(
                function (StateInterface $state) {
                    return $state->get('current_item') === 'Banana';
                },
                new class implements StepInterface { // Then: Special handling
                    public function getName(): string { return 'SpecialHandle'; }
                    public function process(StateInterface $state): StateInterface {
                        echo "  -> Found a Banana! Keeping it special.\n";
                        return $state;
                    }
                },
                new class implements StepInterface { // Else: Normal logging
                    public function getName(): string { return 'LogItem'; }
                    public function process(StateInterface $state): StateInterface {
                        $item = $state->get('current_item');
                        echo "Processing item: $item\n";
                        return $state;
                    }
                }
            )
        ));

        // Step 3: Log End
        $this->addStep(new class implements StepInterface {
            public function getName(): string { return 'End'; }
            public function process(StateInterface $state): StateInterface {
                echo "Workflow Finished.\n";
                return $state;
            }
        });
    }
}
