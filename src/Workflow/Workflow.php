<?php

namespace Sapient\Workflow;

use Sapient\Contracts\Workflow\WorkflowInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Core\Pipeline;

abstract class Workflow implements WorkflowInterface {
    protected $pipeline;
    protected $config = [];
    protected $name;
    protected $description;

    public function __construct(string $name, string $description) {
        $this->name = $name;
        $this->description = $description;
        $this->pipeline = new Pipeline();
        $this->build();
    }

    /**
     * Define the steps of the workflow here.
     * Use $this->addStep(new MyStep());
     */
    abstract protected function build(): void;

    protected function addStep(StepInterface $step): self {
        $this->pipeline->addStep($step);
        return $this;
    }

    public function configure(array $config): self {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function process(StateInterface $state): StateInterface {
        // Create a sub-state or isolated context if needed?
        // For now, share state but maybe scope input/output?
        // Let's pass state through directly for maximum flexibility (Fractal).
        
        // Log start?
        // echo "[Workflow: {$this->name}] Starting...\n";

        return $this->pipeline->run($state);
    }
}
