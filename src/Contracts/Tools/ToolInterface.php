<?php

namespace Sapient\Contracts\Tools;

interface ToolInterface {
    /**
     * Get the name of the tool (e.g., 'memorize').
     * @return string
     */
    public function getName(): string;

    /**
     * Get the description of the tool for the system prompt.
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get concise instruction/usage pattern for the tool.
     * @return string
     */
    public function getInstruction(): string;

    /**
     * Execute the tool with the provided arguments.
     * @param array $args Key-value pair of arguments extracted from the command.
     * @return string Result of the execution to be fed back to the LLM or User.
     */
    public function execute(array $args): string;
}
