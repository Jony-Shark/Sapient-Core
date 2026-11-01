<?php

namespace Sapient\Tools\Std;

use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Memory\Context;

class ContextTool implements ToolInterface {
    private $contextService;

    public function __construct(Context $contextService) {
        $this->contextService = $contextService;
    }

    public function getName(): string {
        return 'set_state';
    }

    public function getDescription(): string {
        return 'Context Extension & State Management. Use this to store persistent variables, notes, or progress markers that you want to keep in your active "working memory" across turns.
        It acts as your own whiteboard for long-term tasks or just keep your current state.
        **Tool name:** `set_state`
        **Function:** Manages persistent state variables that are carried across conversation turns. Useful for tracking current status or long-running tasks.

        **Parameters:**
        *   `key` (required): The key of the state variable.
        *   `value` (required): The value of the state variable.
        *   `remove` (optional): Use an empty string to remove a key from the board.

        **Usage examples (syntax):**
        <set_state key="mood">Happy</set_state>
        <set_state key="task_progress">Summarized the document`s first 50 pages</set_state>
        <set_state key="task_progress"></set_state> # Removes the task_progress key';
    }

    public function getInstruction(): string {
        return 'Pattern: <set_state key="any_key">any_value</set_state>, Instruction: Update persistent state or note.';
    }

    public function execute(array $args): string {
        if (!isset($args['key']) || !isset($args['value'])) {
            return "Error: Missing 'key' or 'value' for set_state.";
        }

        $data = $this->contextService->load();
        $result = $this->contextService->update($data, $args['key'], $args['value']);

        if ($result) {
            $this->contextService->save($data);
            return "State Updated: " . $result;
        }

        return "Error: Failed to update state.";
    }
}
