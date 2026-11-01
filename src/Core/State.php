<?php

namespace Sapient\Core;

use Sapient\Contracts\Core\StateInterface;
use Sapient\Core\Utils\JsonParser;
use Sapient\Core\Message;

class State implements StateInterface {
    private $data = [];
    /** @var Message[] */
    private $history = [];
    private ?string $lastError = null;

    public function __construct(array $initialData = []) {
        $this->data = $initialData;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    public function setLastError(?string $error): void {
        $this->lastError = $error;
    }

    public function get(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool {
        return array_key_exists($key, $this->data);
    }

    public function all(): array {
        return $this->data;
    }

    public function getHistory(): array {
        return $this->history;
    }

    public function appendHistory(Message $message): void {
        $this->history[] = $message;
    }
    
    // Helper to bulk set history (e.g. loading from disk)
    public function setHistory(array $history): void {
        foreach ($history as $item) {
            if (!$item instanceof Message) {
                throw new \InvalidArgumentException('History items must be Message objects. Use Message::fromArray() to convert.');
            }
        }
        $this->history = $history;
    }

    public function getJson(string $key): ?array {
        $content = $this->get($key);
        if (!is_string($content)) {
            return null;
        }

        return JsonParser::parse($content);
    }

    /**
     * Persists the entire state (history and data) to a file.
     */
    public function saveToFile(string $path): bool {
        $export = [
            'history' => array_map(fn(Message $m) => $m->toArray(), $this->history),
            'data' => $this->data,
            'last_error' => $this->lastError
        ];

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;

        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        return file_put_contents($path, $json) !== false;
    }

    /**
     * Loads the state from a file.
     */
    public function loadFromFile(string $path): bool {
        if (!file_exists($path)) return false;

        $json = file_get_contents($path);
        $import = json_decode($json, true);
        if (!$import) return false;

        if (isset($import['history'])) {
            $this->history = array_map(fn(array $m) => Message::fromArray($m), $import['history']);
        }

        if (isset($import['data'])) {
            $this->data = $import['data'];
        }

        if (isset($import['last_error'])) {
            $this->lastError = $import['last_error'];
        }

        return true;
    }
}
