<?php

namespace Sapient\Tools\Std;

use Sapient\Workflow\Registry\WorkflowRegistry;
use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Core\State;

class WorkflowTool implements ToolInterface {
    private $registry;

    public function __construct(WorkflowRegistry $registry) {
        $this->registry = $registry;
    }

    public function getName(): string {
        return 'run_workflow';
    }

    public function getDescription(): string {
        $list = "";
        foreach ($this->registry->getAll() as $wf) {
             // More detailed description for HelpTool
            $list .= "- " . $wf->getName() . ": " . $wf->getDescription() . PHP_EOL;
        }
        return "Executes predefined business workflows. Useful for complex, multi-step processes.\nAvailable Workflows:\n$list";
    }

    public function getInstruction(): string {
        // Concise list for Context
        $names = [];
        foreach ($this->registry->getAll() as $wf) {
            $names[] = $wf->getName();
        }
        $list = implode(", ", $names);
        
        return "Pattern: <run_workflow name=\"demo\">Global Context</run_workflow>, Instruction: Execute a business process. Available: [$list]";
    }

    public function execute(array $args): string {
        $name = $args['name'] ?? $args['value'] ?? null;
        $context = $args['context'] ?? $args['input'] ?? '';

        if (!$name) {
            return "Error: Missing 'name' argument.";
        }

        $workflow = $this->registry->get($name);
        if (!$workflow) {
            return "Error: Workflow '$name' not found.";
        }

        // Setup State for the Workflow
        // We might want to pass the MAIN state? Or a sub-state?
        // For simplicity and safety, let's create a sub-state but maybe inject some context.
        $wfState = new State();
        if ($context) {
            $wfState->set('input', $context);
        }

        // Run
        try {
            $workflow->process($wfState);
            // What to return? Result? Logs?
            // Maybe the workflow sets a 'result' key?
            return "Workflow '$name' completed."; 
        } catch (\Exception $e) {
            return "Error executing workflow '$name': " . $e->getMessage();
        }
    }
}
