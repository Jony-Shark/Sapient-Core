<?php

namespace Sapient\Tools\Std;

use Sapient\Contracts\Tools\ToolInterface;

class CalculatorTool implements ToolInterface {
    public function getName(): string {
        return 'calculator';
    }
    
        public function getDescription(): string {
            return 'Use this tool for ANY arithmetic tasks to ensure accuracy.
            **Tool name:** `calculator`
            **Function:** Perform mathematical calculations.
            **Supported operators:** + - * / % ^ and parentheses.
            **Input:** A mathematical expression string (e.g. "12 * (5 + 3)").

            **Usage examples (syntax):**
            <calculator>12 * (5 + 3)</calculator>';
        }

    public function getInstruction(): string {
        return 'Pattern: <calculator>math_expression</calculator>, Instruction: Perform mathematical calculations.';
    }

    public function execute(array $args): string {
        $expression = $args['value'] ?? $args['content'] ?? '';

        // If simple list provided, try first element
        if (empty($expression) && !empty($args) && isset($args[0]) && is_string($args[0])) {
            $expression = $args[0];
        }

        if (empty($expression)) {
            return "Error: No expression";
        }

        // Sanitize Input: Allow only digits, whitespace, and specific operators
        // Strict whitelist approach for security.
        if (preg_match('/[^0-9\s\+\-\*\/\.\(\)\%\^]/', $expression)) {
            return "Error: Invalid characters";
        }

        // Evaluate
        try {
            // Allow power operator ^ -> **
             $expression = str_replace('^', '**', $expression);

            $result = @eval("return $expression;");

            if ($result === false && $expression !== "return false;") {
                 return "Error: Eval failed";
            }
            
            return (string)$result;

        } catch (\Throwable $e) {
            return "Error: " . $e->getMessage();
        }
    }
}
