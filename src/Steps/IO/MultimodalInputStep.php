<?php

namespace Sapient\Steps\IO;

use Sapient\Contracts\Core\InputProviderInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Core\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MultimodalInputStep implements StepInterface {
    /** @var InputProviderInterface[] */
    private $providers = [];
    private $logger;

    /**
     * @param InputProviderInterface[] $providers
     */
    public function __construct(array $providers, ?LoggerInterface $logger = null) {
        $this->providers = $providers;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string {
        return 'MultimodalInput';
    }

    public function process(StateInterface $state): StateInterface {
        $rawInput = $state->get('input');
        if (empty($rawInput)) {
            return $state; // Nothing to process
        }

        // Regex to find [IMAGE: path] or [FILE: path]
        // Supports detection anywhere in the string.
        $pattern = '/\[(IMAGE|FILE):\s*([^\]]+)\]/i';
        
        // If no image found, treat as simple text
        if (!preg_match($pattern, $rawInput)) {
            $this->appendToHistory($state, $rawInput);
            return $state;
        }

        // Break input into parts
        // Use preg_match_all with offset to build it robustly.
        $contentArray = [];
        $offset = 0;
        
        if (preg_match_all($pattern, $rawInput, $matches, PREG_OFFSET_CAPTURE)) {
            $lastEnd = 0;
            foreach ($matches[0] as $key => $fullMatch) {
                // $fullMatch is [string, offset]
                $matchType = strtoupper($matches[1][$key][0]);
                $matchStr = $fullMatch[0];
                $matchPos = $fullMatch[1];
                $filePath = trim($matches[2][$key][0]);

                // Text before this image?
                if ($matchPos > $lastEnd) {
                    $text = substr($rawInput, $lastEnd, $matchPos - $lastEnd);
                    if (trim($text) !== '') {
                        $contentArray[] = ['type' => 'text', 'text' => trim($text)];
                    }
                }

                if (strpos($filePath, 'gs://') === 0 && $matchType === 'IMAGE') {
                    $resolved = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $filePath]
                    ];
                } else {
                    // Resolve via Providers
                    foreach ($this->providers as $provider) {
                        $resolved = $provider->resolve($filePath);
                        if ($resolved) break;
                    }
                }

                if ($resolved) {
                    $this->logger->info("Found $matchType: '$filePath'", ['type' => $resolved['type'] ?? 'unknown']);
                    
                    // Keep the tag visible to the LLM for context
                    $contentArray[] = ['type' => 'text', 'text' => $matchStr];
                    $contentArray[] = $resolved;
                } else {
                    $this->logger->warning("$matchType not found/handled: '$filePath'. Treating as placeholder.");
                    $contentArray[] = ['type' => 'text', 'text' => "[MISSING $matchType: $filePath]"];
                }

                $lastEnd = $matchPos + strlen($matchStr);
            }

            // Remaining text after last image
            if ($lastEnd < strlen($rawInput)) {
                $text = substr($rawInput, $lastEnd);
                if (trim($text) !== '') {
                    $contentArray[] = ['type' => 'text', 'text' => trim($text)];
                }
            }
        } else {
            // Should be caught by initial check, but safety fallback
            $contentArray[] = ['type' => 'text', 'text' => $rawInput];
        }

        $this->appendToHistory($state, $contentArray);
        return $state;
    }

    private function appendToHistory(StateInterface $state, $content) {
        $state->appendHistory(Message::user($content));
    }
}
