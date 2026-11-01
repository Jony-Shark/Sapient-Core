<?php

namespace Sapient\Tools\Std;

use Sapient\Contracts\Tools\ToolInterface;

class TurnControlTool implements ToolInterface {
    
    public function getName(): string {
        return 'continue';
    }

    public function getDescription(): string {
        return 'Control mechanism for handling long responses or token limits. Use ONLY when necessary.
        **Tool name:** `continue`
        **Function:** System control. But you can use it to continue your response if it is incomplete or token limit reached.
        This tool isn`t have any parameter, but a reason of why is needed into content.
        This only works within the predefined number of answer rounds.
        
        **Usage examples (syntax):**
        <continue>The entire document content is too long</continue>';
    }

    public function getInstruction(): string {
        return 'Pattern: <continue>reason</continue>, Instruction: System control. Use ONLY if response is incomplete or token limit reached.';
    }

    public function execute(array $args): string {
        // The value contains the reason.
        $reason = $args['value'] ?? $args['content'] ?? 'No reason provided';
        return "System: Continuation acknowledged. Control returned to agent. (Reason: $reason)";
    }
}
