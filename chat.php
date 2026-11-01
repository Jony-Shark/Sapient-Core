<?php
/**
 * Sapient - Minimal Chat Entry Point
 * 
 * This file demonstrates the simplest possible usage of the Sapient Framework.
 * It provides a "zero-dependency" chat loop using either Ollama (local) or Gemini (cloud).
 * 
 * For a full-featured demonstration (Workflows, Vector Memory, Tools), see: examples/demo_chat.php
 */

require 'vendor/autoload.php';

use Sapient\Core\Config;
use Sapient\Core\Message;
use Sapient\Core\ConsoleLogger;
use Sapient\Adapter\OllamaAdapter;
use Sapient\Adapter\GeminiAdapter;

Config::load(__DIR__);

echo "\n\033[1;36m=== Sapient AI (Minimal) ===\033[0m\n";

// 1. Setup LLM
$driver = Config::get('LLM_DRIVER', 'ollama');
$apiKey = Config::get('GEMINI_API_KEY');

if ($driver === 'gemini' && $apiKey) {
    $model = Config::get('GEMINI_MODEL', 'gemini-2.0-flash');
    $llm = new GeminiAdapter($model, $apiKey);
    echo "Driver: Google Gemini ($model)\n";
} else {
    // Default to Ollama
    $model = Config::get('OLLAMA_MODEL', 'llama3');
    $url = Config::get('OLLAMA_URL', 'http://localhost:11434');
    $llm = new OllamaAdapter($model, $url);
    echo "Driver: Ollama ($model) @ $url\n";
}

// 2. Simple Chat Loop
// We maintain a local history array for context.
$chatHistory = [];
$logger = new ConsoleLogger();

echo "Type 'exit' to quit.\n\n";

while (true) {
    echo "\033[1;32mUser > \033[0m";
    $input = "";
    
    while (true) {
        $line = fgets(STDIN);
        if ($line === false) break 2; // Handle Ctrl+D
        
        $trimmed = rtrim($line, "\r\n");
        // Check for backslash or the escape sequence ^[OM (\eOM / \x1bOM) which represents Shift+Enter in some terminals
        if (str_ends_with($trimmed, "\\") || str_contains($trimmed, "\eOM") || str_contains($trimmed, "\x1bOM")) {
            $cleaned = str_replace(["\eOM", "\x1bOM"], "", $trimmed);
            if (str_ends_with($cleaned, "\\")) {
                $cleaned = substr($cleaned, 0, -1);
            }
            $input .= $cleaned . "\n";
            echo "      \033[1;32m| \033[0m"; // Continuation prompt
        } else {
            $input .= $trimmed;
            break;
        }
    }

    $input = trim($input);

    if (strtolower($input) === 'exit') {
        echo "Goodbye.\n";
        break;
    }
    
    if (empty($input)) continue;

    // Add User Message to History
    $chatHistory[] = Message::user($input);

    echo "\033[1;33mAI is thinking...\033[0m\n";

    try {
        // Direct Adapter Call (No Pipeline overhead)
        $response = $llm->chat($chatHistory);
        
        // Add AI Response to History
        $chatHistory[] = Message::assistant($response);

        echo "\033[1;35mAI > \033[0m" . $response . "\n\n";

    } catch (Exception $e) {
        echo "\033[1;31mError: \033[0m" . $e->getMessage() . "\n";
    }
}
