<?php

namespace Sapient\Core;

class Message {
    public readonly string $role;
    public readonly string|array $content;

    private function __construct(string $role, string|array $content) {
        $this->role = $role;
        $this->content = $content;
    }

    public static function system(string $content): self {
        return new self('system', $content);
    }

    public static function user(string|array $content): self {
        return new self('user', $content);
    }

    public static function assistant(string $content): self {
        return new self('assistant', $content);
    }

    public static function fromArray(array $data): self {
        return new self(
            $data['role'] ?? 'user',
            $data['content'] ?? ''
        );
    }

    public function toArray(): array {
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }

    public function isSystem(): bool {
        return $this->role === 'system';
    }

    public function isUser(): bool {
        return $this->role === 'user';
    }

    public function isAssistant(): bool {
        return $this->role === 'assistant';
    }

    /**
     * Create a new Message with updated content (immutable pattern).
     */
    public function withContent(string|array $content): self {
        return new self($this->role, $content);
    }
}
