<?php

namespace Sapient\Workflow\Registry;

use Sapient\Contracts\Workflow\WorkflowInterface;

class WorkflowRegistry {
    /** @var WorkflowInterface[] */
    private $workflows = [];

    public function register(WorkflowInterface $workflow): void {
        $this->workflows[$workflow->getName()] = $workflow;
    }

    public function get(string $name): ?WorkflowInterface {
        return $this->workflows[$name] ?? null;
    }

    public function getAll(): array {
        return $this->workflows;
    }

    public function has(string $name): bool {
        return isset($this->workflows[$name]);
    }
}
