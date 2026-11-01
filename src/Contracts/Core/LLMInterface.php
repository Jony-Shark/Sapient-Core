<?php

namespace Sapient\Contracts\Core;

interface LLMInterface {
    /**
     * Send a chat request to the LLM.
     * @param Message[] $messages Array of messages
     * @param array $options Optional parameters (temperature, stop tokens, etc.)
     * @return string The LLM's response text
     */
    public function chat(array $messages, array $options = []): string;
    
}
