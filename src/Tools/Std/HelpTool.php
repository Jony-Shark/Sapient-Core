<?php

namespace Sapient\Tools\Std;

use Sapient\Contracts\Tools\ToolInterface;

class HelpTool implements ToolInterface {
    private $tools = [];

    public function getName(): string {
        return 'help';
    }

    public function getDescription(): string {
        return 'System Documentation Service. Use it to explore available tools and their detailed specifications.';
    }

    public function getInstruction(): string {
        return 'Pattern: <help>list</help> for a short inventory of tools. Pattern: <help>tool_name</help> for a detailed manual (man-page) of a specific tool.';
    }

    public function setRegisteredTools(array $tools) {
        $this->tools = $tools;
    }

    public function execute(array $args): string {
        $output = "Available Tools:\n";

        if (!isset($args['value'])) {
            return "Error: Missing 'value' for help.";
        }

        foreach ($this->tools as $tool) {
            if ($tool instanceof ToolInterface) {
                if ($args['value'] == $tool->getName()) {
                    return "Tool: " . $tool->getName() . "\nUsage: " . $tool->getInstruction() . "\nDescription: " . $tool->getDescription() . PHP_EOL;
                }

                $output .= "- " . $tool->getName() . ":\n";

                if (method_exists($tool, 'getInstruction')) {
                    $output .= "  Usage: " . $tool->getInstruction() . PHP_EOL;
                }

                // Optionally verify getInstruction existence if interface update delayed (but we did it first)
                $output .= PHP_EOL;
            }
        }
        
        return $output;
    }
}
