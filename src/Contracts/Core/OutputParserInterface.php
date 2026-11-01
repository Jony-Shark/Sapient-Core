<?php

namespace Sapient\Contracts\Core;

interface OutputParserInterface {
    /**
     * Parse the LLM response string into a list of actions and a cleaned response.
     * 
     * @param string $response The raw LLM output.
     * @return array{actions: array, response: string}
     *         actions: [['tool' => 'name', 'args' => [...]]]
     *         response: The cleaned text (without tool tags/json)
     */
    public function parse(string $response): array;
}
