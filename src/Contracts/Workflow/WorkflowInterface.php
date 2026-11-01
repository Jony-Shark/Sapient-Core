<?php

namespace Sapient\Contracts\Workflow;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;

/**
 * A Workflow is a specialized Step that orchestrates a sequence of other Steps.
 * It follows the "Fractal Architecture" pattern: A Workflow IS A Step.
 */
interface WorkflowInterface extends StepInterface {
    /**
     * Configure the workflow with runtime parameters.
     * @param array $config
     * @return self
     */
    public function configure(array $config): self;

    /**
     * Get the description of what this workflow does (for AI discovery).
     * @return string
     */
    public function getDescription(): string;
}
