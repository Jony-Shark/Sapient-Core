<?php

namespace Sapient\Steps\IO;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;

/**
 * DocumentSplitterStep splits large files (PDF or Text) into manageable chunks.
 * 
 * Mode 1 (Chunk Size): If no target pages are defined, it splits the document into equal chunks.
 * Mode 2 (Precision): If target pages are defined, it isolates the target pages and remainder pages
 *                     into distinct chunk files.
 */
class DocumentSplitterStep implements StepInterface {
    private $inputKey;
    private $outputKey;
    private $targetPagesKey;
    private $remainderOutKey;
    private $chunkSize;
    private $tempDir;

    /**
     * @param string $inputKey State key containing the file path/URI.
     * @param string $outputKey Primary output key for chunks (standard or extracted).
     * @param string $targetPagesKey State key containing an array of chunk definitions (e.g. ['1-3', 4, [5,6]]).
     * @param string $remainderOutKey State key for remainder chunks (in precision mode).
     * @param int $chunkSize Pages for PDF, lines for Text.
     */
    public function __construct(
        string $inputKey = 'input',
        string $outputKey = 'document_chunks',
        string $targetPagesKey = 'target_pages',
        string $remainderOutKey = 'remainder_chunks',
        int $chunkSize = 50,
        ?string $tempDir = null
    ) {
        $this->inputKey = $inputKey;
        $this->outputKey = $outputKey;
        $this->targetPagesKey = $targetPagesKey;
        $this->remainderOutKey = $remainderOutKey;
        $this->chunkSize = $chunkSize;
        $this->tempDir = rtrim($tempDir ?? (sys_get_temp_dir() . '/sapient/chunks_' . uniqid()), '/');
    }

    public function getName(): string {
        return "Document Splitter";
    }

    public function process(StateInterface $state): StateInterface {
        $uri = $state->get($this->inputKey);
        if (!$uri || !file_exists($uri)) {
            throw new \Exception("Input file not found at key: {$this->inputKey}");
        }

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        $localFile = $this->prepareFile($uri);
        $ext = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
        $targetPages = $state->get($this->targetPagesKey);

        if (is_array($targetPages) && $ext === 'pdf') {
            if (empty($targetPages)) {
                $state->set($this->outputKey, []);
                $state->set($this->remainderOutKey, [$localFile]);
            } else {
                $this->processPrecisionSplitting($localFile, $targetPages, $state);
            }
        } else {
            // STANDARD SIZE CHUNKING MODE
            $chunks = ($ext === 'pdf')
                ? $this->splitPdfStandard($localFile)
                : $this->splitTextStandard($localFile);
            $state->set($this->outputKey, $chunks);
            $state->set($this->remainderOutKey, []);
        }

        return $state;
    }

    private function processPrecisionSplitting(string $file, array $targetPages, StateInterface $state): void {
        $npagesCmd = sprintf("qpdf --show-npages %s", escapeshellarg($file));
        $totalPages = (int)trim(shell_exec($npagesCmd));
        $baseName = pathinfo($file, PATHINFO_FILENAME);

        // 1. Prepare Target Ranges
        $targetRanges = [];
        $usedPages = [];
        foreach ($targetPages as $target) {
            $rangeStr = "";
            if (is_array($target)) {
                $rangeStr = implode(',', array_filter($target, 'is_numeric'));
                foreach ($target as $p) {
                    if (is_numeric($p)) {
                        $usedPages[] = (int)$p;
                    }
                }
            } elseif (is_string($target) && strpos($target, '-') !== false) {
                $rangeStr = $target;
                $parts = explode('-', $target);
                if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    for ($i = (int)$parts[0]; $i <= (int)$parts[1]; $i++) $usedPages[] = $i;
                }
            } elseif (is_numeric($target)) {
                $rangeStr = (string)$target;
                $usedPages[] = (int)$target;
            }

            if (!empty($rangeStr)) {
                $targetRanges[] = $rangeStr;
            }
        }

        // 2. Calculate Remainder Ranges
        $remainderPages = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if (!in_array($i, $usedPages)) {
                $remainderPages[] = $i;
            }
        }
        $remainderSequences = $this->compileSequences($remainderPages);
        $remainderRanges = array_map(function($seq) {
            return ($seq['start'] == $seq['end']) ? (string)$seq['start'] : "{$seq['start']}-{$seq['end']}";
        }, $remainderSequences);

        // 3. Execute Cuts
        $state->set($this->outputKey, $this->extractChunks($file, $targetRanges, $baseName));
        $state->set($this->remainderOutKey, $this->extractChunks($file, $remainderRanges, $baseName));
    }

    private function extractChunks(string $file, array $ranges, string $baseName): array {
        $chunks = [];
        foreach ($ranges as $range) {
            $chunkPath = $this->tempDir . '/' . uniqid($baseName . '_') . ".pdf";
            $cmd = sprintf(
                "qpdf --empty --pages %s %s -- %s 2>&1",
                escapeshellarg($file),
                escapeshellarg($range),
                escapeshellarg($chunkPath)
            );
            
            $output = shell_exec($cmd);
            
            if (file_exists($chunkPath)) {
                $chunks[] = $chunkPath;
            } else {
                throw new \Exception("DocumentSplitterStep: Failed to extract range '$range'. Error: " . trim($output));
            }
        }
        return $chunks;
    }

    private function compileSequences(array $pages): array {
        if (empty($pages)) {
            return [];
        }
        sort($pages);
        $sequences = [];
        $start = $pages[0];
        $prev = $start;
        for ($i = 1; $i < count($pages); $i++) {
            if ($pages[$i] == $prev + 1) {
                $prev = $pages[$i];
            } else {
                $sequences[] = ['start' => $start, 'end' => $prev];
                $start = $pages[$i];
                $prev = $start;
            }
        }
        $sequences[] = ['start' => $start, 'end' => $prev];
        return $sequences;
    }

    private function prepareFile($uri): string {
        if (strpos($uri, 'gs://') === 0) {
            $local = $this->tempDir . '/source.pdf';
            shell_exec(sprintf("gsutil cp %s %s", escapeshellarg($uri), escapeshellarg($local)));
            return $local;
        }
        return $uri;
    }

    private function splitPdfStandard($file): array {
        $cmd = sprintf(
            "qpdf --split-pages=%d %s %s/chunk.pdf",
            $this->chunkSize,
            escapeshellarg($file),
            escapeshellarg($this->tempDir)
        );
        shell_exec($cmd);
        $chunks = glob("{$this->tempDir}/chunk*.pdf");
        sort($chunks);
        return $chunks;
    }

    private function splitTextStandard($file): array {
        $chunks = [];
        $handle = fopen($file, 'r');
        if (!$handle) {
            return [];
        }
        $count = 1;
        while (!feof($handle)) {
            $lines = [];
            for ($i = 0; $i < ($this->chunkSize * 10); $i++) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $lines[] = $line;
            }
            if (!empty($lines)) {
                $path = "{$this->tempDir}/chunk_{$count}.txt";
                file_put_contents($path, implode("", $lines));
                $chunks[] = $path;
                $count++;
            }
        }
        fclose($handle);
        return $chunks;
    }
}
