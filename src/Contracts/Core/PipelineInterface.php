<?php

namespace Sapient\Contracts\Core;

interface PipelineInterface {
    /**
     * Add a step to the pipeline.
     * @param StepInterface $step
     * @return self
     */
    public function addStep(StepInterface $step): self;

    /**
     * Run the pipeline with an initial state.
     * @param StateInterface $state
     * @return StateInterface
     */
    public function run(StateInterface $state): StateInterface;
}
