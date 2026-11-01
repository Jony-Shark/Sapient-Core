<?php

namespace Sapient\Core;

use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Contracts\Core\StateInterface;

class StaticContextProvider implements ContextProviderInterface {
    private $content;
    private $name;

    public function __construct(string $content, string $name = 'static_context') {
        $this->content = $content;
        $this->name = $name;
    }

    public function getContextName(): string {
        return $this->name;
    }

    public function provideContext(StateInterface $state): ?string {
        return $this->content;
    }
}
