<?php

namespace Sapient\Workflow;

use Sapient\Contracts\Workflow\WorkflowInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Steps\Agent\ExtractionStep;
use Sapient\Steps\Control\DecisionStep;
use Sapient\Steps\System\RetryStep;
use Sapient\Core\Pipeline;
use Psr\Log\LoggerInterface;

/**
 * AdvancedWorkflow provides a fluent API for building complex, multi-stage
 * processes with intelligence (routing and extraction) built-in.
 */
abstract class AdvancedWorkflow implements WorkflowInterface {
    protected $pipeline;
    protected $name;
    protected $description;
    protected $config = [];
    private ?array $pendingRetryConfig = null;

    public function __construct(string $name, string $description) {
        $this->name = $name;
        $this->description = $description;
        $this->pipeline = new Pipeline();
        $this->build();
    }

    /**
     * Define the flow using the fluent methods.
     */
    abstract protected function build(): void;

    /**
     * Helper to add a step to the pipeline, optionally wrapping it in a pending RetryStep.
     */
    protected function addStepToPipeline(StepInterface $step): void {
        if ($this->pendingRetryConfig !== null) {
            $config = $this->pendingRetryConfig;
            $step = new RetryStep(
                $step,
                $config['maxRetries'],
                $config['baseIntervalSeconds'],
                $config['shouldRetry'],
                $config['logger']
            );
            $this->pendingRetryConfig = null; // Consume the pending config
        }
        $this->pipeline->addStep($step);
    }

    /**
     * Adds a standard Step to the flow.
     */
    public function then(StepInterface $step): self {
        $this->addStepToPipeline($step);
        return $this;
    }

    /**
     * Adds an Intelligent Routing point.
     *
     * @param LLMInterface $llm
     * @param string $prompt Question to ask the AI (e.g. "Which category does this belong to?")
     * @param array $branches Map of possible answers to Steps/Pipelines.
     */
    public function decide(LLMInterface $llm, string $prompt, array $branches): self {
        $this->addStepToPipeline(new DecisionStep($llm, $prompt, $branches));
        return $this;
    }

    /**
     * Adds a Structured Extraction step.
     * @param LLMInterface $llm
     * @param string $systemPrompt
     * @param string $userTemplate
     * @param string|array $mappings Either a outputKey string or ['json_field' => 'state_key']
     * @param string|null $fileKey Optional state key containing a file path to attach.
     */
    public function extract(LLMInterface $llm, string $systemPrompt, string $userTemplate, $mappings, ?string $fileKey = null): self {
        $this->addStepToPipeline(new ExtractionStep($llm, $systemPrompt, $userTemplate, $mappings, $fileKey));
        return $this;
    }

    /**
     * Set a pending retry wrapper config for the NEXT step added to the workflow.
     *
     * @param int $maxRetries Maximum number of retry attempts (default: 3).
     * @param int $baseIntervalSeconds Base delay in seconds before the first retry (default: 5s).
     * @param callable|null $shouldRetry Optional callback `fn(\Exception $e): bool`.
     * @param LoggerInterface|null $logger Logger for retry events.
     * @return self
     */
    public function retry(
        int $maxRetries = 3,
        int $baseIntervalSeconds = 5,
        ?callable $shouldRetry = null,
        ?LoggerInterface $logger = null
    ): self {
        $this->pendingRetryConfig = [
            'maxRetries' => $maxRetries,
            'baseIntervalSeconds' => $baseIntervalSeconds,
            'shouldRetry' => $shouldRetry,
            'logger' => $logger
        ];
        return $this;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function configure(array $config): self {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    public function process(StateInterface $state): StateInterface {
        return $this->pipeline->run($state);
    }
}
