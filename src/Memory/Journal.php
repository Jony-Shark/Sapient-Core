<?php

namespace Sapient\Memory;

use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Contracts\Core\StateInterface;

class Journal implements ContextProviderInterface {
    private $journalFile;
    private $contextLimit;

    public function __construct($dataDir, $persona = 'helix', int $contextLimit = 3) {
        $this->journalFile = $dataDir . '/' . $persona . '/journal.md';
        $this->contextLimit = $contextLimit;

        if (!is_dir(dirname($this->journalFile))) mkdir(dirname($this->journalFile), 0777, true);
    }

    public function append($entry) {
        $timestamp = date('Y-m-d H:i');
        $line = "[$timestamp] $entry\n";
        
        file_put_contents($this->journalFile, $line, FILE_APPEND);
        return "Logged entry: \"$entry\"";
    }

    // --- ContextProviderInterface ---

    public function getContextName(): string {
        return 'journal_history';
    }

    public function provideContext(StateInterface $state): ?string {
        $history = $this->getRecent($this->contextLimit);
        $time = $this->getTimePerception();
        
        return $time . PHP_EOL . $history; 
    }

    public function getRecent($limit = 3) {
        if (!file_exists($this->journalFile)) return "";
        
        $lines = file($this->journalFile);
        $total = count($lines);
        $start = max(0, $total - $limit);
        $recent = array_slice($lines, $start);
        
        if (empty($recent)) return "";
        
        $output = "[EPISODIC MEMORY (Recent Journal Entries)]\n";
        foreach ($recent as $line) {
            $output .= trim($line) . PHP_EOL;
        }
        return $output;
    }
    
    private function getLastLogTime() {
        if (!file_exists($this->journalFile)) return time();
        
        // Efficiently read last line? 
        // For simple text files, reading into array (file()) is robust for small files.
        // If huge, we might use seek, but let's stick to file() for now as we used it in getRecent.
        $lines = file($this->journalFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) return time();
        
        $lastLine = end($lines);
        
        // Parse [YYYY-MM-DD HH:MM]
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]/', $lastLine, $matches)) {
            return strtotime($matches[1]);
        }
        
        return time();
    }

    public function getTimePerception() {
        $lastUpdate = $this->getLastLogTime();
        $now = time();
        $diff = $now - $lastUpdate;
        
        if ($diff < 60) $delta = "just now";
        elseif ($diff < 3600) $delta = floor($diff / 60) . " minutes ago";
        elseif ($diff < 86400) $delta = floor($diff / 3600) . " hours ago";
        else $delta = floor($diff / 86400) . " days ago";
        
        return "[TIME PERCEPTION]\nCurrent Time: " . date('Y-m-d H:i') . "\nLast Interaction: $delta\n";
    }
}
