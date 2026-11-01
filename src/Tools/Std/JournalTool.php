<?php

namespace Sapient\Tools\Std;

use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Memory\Journal;

class JournalTool implements ToolInterface {
    private $journal;

    public function __construct(Journal $journal) {
        $this->journal = $journal;
    }

    public function getName(): string {
        return 'log_event';
    }

    public function getDescription(): string {
        return 'Writes an entry to the journal. Useful for recording episodic memories or significant events.
        **Tool name:** `log_event`
        **Function:** Manages episodic memories that are carried across conversation turns.
        It simply saves the received content to a log file, the last elements of which appear in the conversation to maintain continuity.
        This tool isn`t have any parameter, but a reason of why is needed into content. 

        **Usage examples (syntax):**
        <log_event>Summarized the document`s first 50 pages...</log_event>';
    }

    public function getInstruction(): string {
        return 'Pattern: <log_event>Event description</log_event>, Instruction: Record episodic memory of major events. e.g: Summary of the conversation.';
    }

    public function execute(array $args): string {
        // Content might be in 'value' or 'content' depending on parser normalization. 
        // Let's assume 'value' for consistency with others, or check both.
        $content = $args['value'] ?? $args['content'] ?? null;

        if (!$content) {
            return "Error: Missing content for log_event.";
        }

        $result = $this->journal->append($content);
        return "Journal Entry Logged: " . $result;
    }
}
