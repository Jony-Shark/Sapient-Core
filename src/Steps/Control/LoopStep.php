<?php

namespace Sapient\Steps\Control;

use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;

class LoopStep implements StepInterface {
    private $iteratorKey;
    private $itemKey;
    private $step;

    /**
     * @param string $iteratorKey State key containing the array to iterate (e.g., 'items').
     * @param string $itemKey State key to set the current item into (e.g., 'current_item').
     * @param StepInterface $step The step to run for each item.
     */
    public function __construct(string $iteratorKey, string $itemKey, StepInterface $step) {
        $this->iteratorKey = $iteratorKey;
        $this->itemKey = $itemKey;
        $this->step = $step;
    }

    public function getName(): string {
        return "Loop (ForEach $this->iteratorKey)";
    }

    public function process(StateInterface $state): StateInterface {
        $list = $state->get($this->iteratorKey);
        
        if (!is_array($list)) {
            // Log warning or skip?
            return $state;
        }

        // We probably need to clone state or manage scope carefully?
        // Ideally, the step modifies the MAIN state accumulator.
        // But for 'current_item', we overwrite it each time.
        
        foreach ($list as $item) {
            $state->set($this->itemKey, $item);
            $state = $this->step->process($state);
        }
        
        // Cleanup?
        // $state->set($this->itemKey, null); 

        return $state;
    }
}
