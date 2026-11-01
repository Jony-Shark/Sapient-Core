<?php

namespace Sapient\Contracts\Core;

interface StepInterface {
    /**
     * Process the state and return the updated state.
     * @param StateInterface $state
     * @return StateInterface
     */
    public function process(StateInterface $state): StateInterface;

    /**
     * Get the name of the step (for logging/debugging).
     * @return string
     */
    public function getName(): string;
}
