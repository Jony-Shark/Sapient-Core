<?php

namespace Sapient\Steps\IO;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;

/**
 * DocumentMergeStep takes an array of PDF chunk paths and merges them together
 * sequentially into a single PDF document.
 */
class DocumentMergeStep implements StepInterface {
    private $chunksKey;
    private $outputKey;
    private $tempDir;

    /**
     * @param string $chunksKey State key containing the array of PDF chunks to merge.
     * @param string $outputKey State key where the merged PDF path will be saved.
     * @param string|null $tempDir Directory to save the final merged file.
     */
    public function __construct(
        string $chunksKey = 'remainder_chunks',
        string $outputKey = 'merged_remainder',
        ?string $tempDir = null
    ) {
        $this->chunksKey = $chunksKey;
        $this->outputKey = $outputKey;
        $this->tempDir = rtrim($tempDir ?? (sys_get_temp_dir() . '/sapient/merged_' . uniqid()), '/');
    }

    public function getName(): string {
        return "Document Merge";
    }

    public function process(StateInterface $state): StateInterface {
        $chunks = $state->get($this->chunksKey);
        
        if (empty($chunks) || !is_array($chunks)) {
            // Nothing to merge. We just continue state silently. 
            // (e.g. if target pages covered the entire document, there is no remainder to merge).
            $state->set($this->outputKey, null);
            return $state;
        }

        // Validate chunks exist
        $validChunks = [];
        foreach ($chunks as $chunk) {
            if (file_exists($chunk)) {
                $validChunks[] = escapeshellarg($chunk);
            }
        }

        if (empty($validChunks)) {
            return $state;
        }
        
        // Return quickly if only one chunk exists (no need to spin up qpdf to merge 1 file)
        if (count($validChunks) === 1) {
            $path = str_replace(["'", '"'], "", $validChunks[0]);
            $state->set($this->outputKey, $path);
            return $state;
        }

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        // Merge using qpdf
        $mergedPath = $this->tempDir . '/merged_doc_' . uniqid() . '.pdf';
        
        // qpdf syntax for merging multiple files: qpdf --empty --pages file1 file2 ... -- out.pdf
        $filesStr = implode(' ', $validChunks);
        $cmd = sprintf("qpdf --empty --pages %s -- %s 2>&1", $filesStr, escapeshellarg($mergedPath));
        
        $output = shell_exec($cmd);

        if (file_exists($mergedPath)) {
            $state->set($this->outputKey, $mergedPath);
        } else {
            throw new \Exception("DocumentMergeStep: Failed to merge PDF chunks. Error: " . trim($output));
        }

        return $state;
    }
}
