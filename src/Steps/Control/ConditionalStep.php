<?php

namespace Sapient\Steps\Control;

use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;

class ConditionalStep implements StepInterface {
    private $condition;
    private $thenStep;
    private $elseStep;

    /**
     * @param callable $condition Function(StateInterface): bool
     * @param StepInterface $thenStep Code to run if true.
     * @param StepInterface|null $elseStep Code to run if false.
     */
    public function __construct(callable $condition, StepInterface $thenStep, ?StepInterface $elseStep = null) {
        $this->condition = $condition;
        $this->thenStep = $thenStep;
        $this->elseStep = $elseStep;
    }

    public function getName(): string {
        return 'Conditional (If/Else)';
    }

    public function process(StateInterface $state): StateInterface {
        // Execute the condition function
        $result = call_user_func($this->condition, $state);

        if ($result) {
            return $this->thenStep->process($state);
        } elseif ($this->elseStep) {
            return $this->elseStep->process($state);
        }

        return $state;
    }
}
