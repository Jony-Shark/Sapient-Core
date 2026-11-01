<?php

namespace Sapient\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class ConsoleLogger implements LoggerInterface {
    use LoggerTrait;
    
    // Fallback dictionary for stringifying log level values
    private $levelColors = [
        'emergency' => "\033[1;41m[EMERGENCY]\033[0m",
        'alert'     => "\033[1;41m[ALERT]\033[0m",
        'critical'  => "\033[1;41m[CRITICAL]\033[0m",
        'error'     => "\033[1;31m[ERROR]\033[0m",
        'warning'   => "\033[1;33m[WARN]\033[0m",
        'notice'    => "\033[1;36m[NOTICE]\033[0m",
        'info'      => "\033[1;36m[INFO]\033[0m",
        'debug'     => "\033[1;30m[DEBUG]\033[0m",
    ];

    public function log($level, \Stringable|string $message, array $context = []): void {
        $colorTag = $this->levelColors[$level] ?? "[$level]";
        
        $contextStr = !empty($context) ? " " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : "";
        echo "$colorTag $message$contextStr\n";
    }
}
