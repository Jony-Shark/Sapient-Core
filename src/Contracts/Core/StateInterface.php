<?php

namespace Sapient\Contracts\Core;

use Sapient\Core\Message;

interface StateInterface {
    /**
     * Get a value from the state.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Set a value in the state.
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void;

    /**
     * Check if a key exists.
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Get the conversation history.
     * @return Message[]
     */
    public function getHistory(): array;

    /**
     * Append a message to the history.
     * @param Message $message
     * @return void
     */
    public function appendHistory(Message $message): void;

    /**
     * Get all data from the state.
     * @return array
     */
    public function all(): array;

    /**
     * Helper to retrieve a value and decode it as JSON.
     * Handles Markdown code blocks (```json ... ```) automatically.
     * 
     * @param string $key
     * @return array|null Returns null if decoding fails or key missing.
     */
    public function getJson(string $key): ?array;
}
