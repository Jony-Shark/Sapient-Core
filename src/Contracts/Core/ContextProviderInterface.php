<?php

namespace Sapient\Contracts\Core;

interface ContextProviderInterface {
    /**
     * Returns a unique key for the context type (e.g., 'memory', 'weather').
     * Used for debugging or optional ordering.
     */
    public function getContextName(): string;

    /**
     * Returns the markdown-formatted context snippet to be injected.
     * Return null or empty string if no context is relevant/available for this turn.
     * 
     * @param StateInterface $state The current state (to check input, history, etc.)
     */
    public function provideContext(StateInterface $state): ?string;
}
