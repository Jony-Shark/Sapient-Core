<?php

namespace Sapient\Examples;

use Sapient\Workflow\AdvancedWorkflow;
use Sapient\Core\Pipeline;
use Sapient\Steps\Control\LoopStep;
use Sapient\Steps\Agent\ExtractionStep;
use Sapient\Steps\IO\DocumentSplitterStep;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;

/**
 * AdvancedRecursiveWorkflow: A generic template for processing large files.
 * It uses "Rolling Context" to maintain continuity while maintaining low memory footprint.
 */
class AdvancedRecursiveWorkflow extends AdvancedWorkflow {
    protected $llm;

    public function __construct(LLMInterface $llm) {
        $this->llm = $llm;
        parent::__construct('advanced_recursive', 'A scalable template for analyzing large document packages.');
    }

    protected function build(): void {
        
        // 1. Split the input into manageable parts
        $this->then(new DocumentSplitterStep('input_file', 50)); 

        // 2. Loop through parts with a small "rolling context" window
        $this->then(new LoopStep('document_chunks', 'current_chunk', 
            (new Pipeline())->addStep(
                new class implements StepInterface {
                    public function getName(): string { return 'logger'; }
                    public function process(StateInterface $state): StateInterface {
                         echo "Analyzing part: " . basename($state->get('current_chunk')) . "...\n";
                         return $state;
                    }
                }
            )->addStep(
                new ExtractionStep(
                    $this->llm,
                    "Extract a summary of the current section. If a document spans into the next section, provide notes in 'next_context'.",
                    "Context from previous part: {{ rolling_notes }}\n\nAnalyze this chunk and identify core entities/topics.",
                    [
                        'summary' => 'section_summaries[]',
                        'next_context' => 'rolling_notes'
                    ],
                    'current_chunk'
                )
            )
        ));

        // 3. Final Synthesis (Reduce phase)
        $this->extract(
            $this->llm,
            "Create a final executive overview based on all section summaries.",
            "Sections summaries collected: {{ section_summaries }}\n\nProvide a high-level report.",
            'final_report'
        );

        // 4. Conclude
        $this->then(new class implements StepInterface {
            public function getName(): string { return 'concluder'; }
            public function process(StateInterface $state): StateInterface {
                $report = $state->get('final_report');
                $state->set('last_response', "Analysis Complete.\n\nSummary:\n" . ($report['summary'] ?? 'N/A'));
                return $state;
            }
        });
    }
}
