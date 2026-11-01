<?php

namespace Sapient\Core;

use Sapient\Contracts\Core\PipelineInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;

class Pipeline implements PipelineInterface, StepInterface {
    /** @var StepInterface[] */
    private $steps = [];

    public function getName(): string {
        return "Pipeline (" . count($this->steps) . " steps)";
    }

    public function process(StateInterface $state): StateInterface {
        return $this->run($state);
    }

    public function addStep(StepInterface $step): self {
        $this->steps[] = $step;
        return $this;
    }

    public function run(StateInterface $state): StateInterface {
        foreach ($this->steps as $step) {
            // Check if state signals a stop? (Not yet implemented, but good for future)
            
            // Execute the step
            $state = $step->process($state);
        }
        
        return $state;
    }
}
