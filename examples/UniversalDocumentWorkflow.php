<?php

namespace Sapient\Examples;

use Sapient\Core\Pipeline;
use Sapient\Workflow\AdvancedWorkflow;
use Sapient\Steps\Agent\ExtractionStep;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\LLMInterface;

/**
 * UniversalDocumentWorkflow demonstrates a generic, intelligent processing flow:
 * 1. Analyze the input to detect the document type.
 * 2. Route to specialized sub-tasks based on type.
 * 3. Consolidate all findings into a final overview.
 */
class UniversalDocumentWorkflow extends AdvancedWorkflow {
    private $llm;

    public function __construct(LLMInterface $llm) {
        $this->llm = $llm;
        parent::__construct('universal_doc', 'Intelligently analyzes, routes, and summarizes any document input.');
    }

    protected function build(): void {
        
        // --- STAGE 1: CLASSIFICATION ---
        $this->extract(
            $this->llm,
            "Identify the document type. Valid types: [Invoice, Report, Medical, Other].",
            "Target: {{ input }}",
            "doc_info"
        );

        // --- STAGE 2: CONDITIONAL ROUTING ---
        $this->decide($this->llm, "Based on doc_info.type [{{ doc_info.type }}], which workflow logic should we apply?", [
            'Invoice' => (new Pipeline())
                ->addStep(new ExtractionStep($this->llm, "Extract financial data.", "Source: {{ input }}", "findings[]")),
            
            'Legal' => (new Pipeline())
                ->addStep(new ExtractionStep($this->llm, "Extract legal clauses and parties.", "Source: {{ input }}", "findings[]")),
            
            'Report' => (new Pipeline())
                ->addStep(new ExtractionStep($this->llm, "Extract key findings and headers.", "Source: {{ input }}", "findings[]")),
                
            'Other' => (new Pipeline())
                ->addStep(new ExtractionStep($this->llm, "Perform generic analysis.", "Source: {{ input }}", "findings[]"))
        ]);

        // --- STAGE 3: SYNTHESIS ---
        $this->extract(
            $this->llm,
            "Synthesize all findings into a final executive summary.",
            "Type detected: {{ doc_info.type }}\nExtracted Findings: {{ findings }}\n\nOriginal Input: {{ input }}",
            "final_report"
        );

        // Final result formatting
        $this->then(new class implements StepInterface {
            public function getName(): string { return 'final_formatter'; }
            public function process(StateInterface $state): StateInterface {
                $report = $state->get('final_report');
                $type = $state->get('doc_info')['type'] ?? 'Unknown';
                
                $summary = "### Document Analysis Result ({$type})\n\n";
                $summary .= ($report['summary'] ?? $report['overview'] ?? 'No summary generated.') . "\n\n";
                
                $state->set('last_response', $summary);
                return $state;
            }
        });
    }
}
