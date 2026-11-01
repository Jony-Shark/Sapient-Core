<?php

namespace Sapient\Steps\Memory;

use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Core\Message;
use Psr\Log\LoggerInterface;

class ContextCompressionStep implements StepInterface {
    private $llm;
    private $logger;
    private $maxTurns;

    public function __construct(LLMInterface $llm, int $maxTurns = 15, ?LoggerInterface $logger = null) {
        $this->llm = $llm;
        $this->logger = $logger;
        $this->maxTurns = $maxTurns;
    }

    public function getName(): string {
        return 'ContextCompression';
    }

    public function process(StateInterface $state): StateInterface {
        $history = $state->getHistory();
        
        // 1 user + 1 assistant = 2 messages per turn roughly
        // If history is just array of messages, we check count.
        // Threshold: maxTurns * 2 (messages)
        $threshold = $this->maxTurns * 2;
        
        if (count($history) <= $threshold) {
             return $state;
        }

        if ($this->logger) $this->logger->info("[Memory] History limit exceeded ($threshold). Compressing...", ['count' => count($history)]);

        // Strategy:
        // 1. Keep System Prompt (index 0 usually)
        // 2. Keep last N messages (Active Context), e.g. last 5 turns (10 msgs)
        // 3. Summarize the middle chunk.
        
        $systemMsg = null;
        if (!empty($history) && $history[0]->isSystem()) {
            $systemMsg = array_shift($history);
        }
        
        // Active context to preserve (e.g. last 6 messages = 3 turns)
        $preserveCount = 6; 
        
        if (count($history) <= $preserveCount) {
             // Should not happen if threshold > preserveCount, but safety check.
             if ($systemMsg) array_unshift($history, $systemMsg);
             $state->setHistory($history);
             return $state;
        }
        
        $recentMessages = array_splice($history, -$preserveCount);
        $toSummarize = $history; // The remaining middle chunk
        
        // Perform Summarization
        $summaryText = $this->summarize($toSummarize);
        
        // Reconstruct History
        $newHistory = [];
        if ($systemMsg) $newHistory[] = $systemMsg;
        
        // Add Summary as a System Note or a specific role?
        // System role is best for "implanted memory".
        $newHistory[] = Message::system("[PREVIOUS CONVERSATION SUMMARY]\n" . $summaryText);
        
        // Append Recent Messages
        $newHistory = array_merge($newHistory, $recentMessages);
        
        if ($this->logger) $this->logger->info("[Memory] Compression complete.", ['old_count' => count($state->getHistory()), 'new_count' => count($newHistory)]);
        
        $state->setHistory($newHistory);
        
        return $state;
    }
    
    private function summarize(array $messages): string {
        // Simple string concatenation for the prompt
        $transcript = "";
        foreach ($messages as $msg) {
            $role = strtoupper($msg->role);
            $content = $msg->content;
            if (is_array($content)) {
                $content = json_encode($content); // Fallback for multimodal in summary transcript
            }
            $transcript .= "$role: $content\n";
        }
        
        $prompt = "Summarize the following conversation transcript into a concise paragraph, capturing key decisions, user intent, and assistant actions. Ignore trivial greetings. \n\nTranscript:\n$transcript";
        
        // Call LLM
        // We use a separate context-less call.
        // Note: This relies on the LLM being stateless or handling this ad-hoc request.
        // Adapter interface assumes chat history. We'll pass a single user message.
        
        try {
            $response = $this->llm->chat([
                Message::user($prompt)
            ]);
            return $response;
        } catch (\Exception $e) {
            if ($this->logger) $this->logger->error("[Memory] Summarization failed: " . $e->getMessage());
            return "Earlier conversation (summary unavailable).";
        }
    }
}
