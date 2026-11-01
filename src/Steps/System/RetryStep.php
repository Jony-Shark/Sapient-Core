<?php

namespace Sapient\Steps\System;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decorator Step that adds retry logic to any other Step.
 * Ideal for wrapping LLM or Network calls that might fail with transient errors (e.g. 429).
 */
class RetryStep implements StepInterface {
    private StepInterface $innerStep;
    private int $maxRetries;
    private int $baseIntervalSeconds;
    private const MULTIPLIER = 2;
    /** @var callable|null */
    private $shouldRetry;
    private LoggerInterface $logger;

    /**
     * @param StepInterface $innerStep The step to wrap and retry.
     * @param int $maxRetries Maximum number of retry attempts (default: 3).
     * @param int $baseIntervalSeconds Base delay in seconds before the first retry. Each subsequent retry doubles the delay (exponential backoff) (default: 5s).
     * @param callable|null $shouldRetry Optional callback `fn(\Exception $e): bool`. If it returns false, the error is thrown immediately without retrying.
     * @param LoggerInterface|null $logger Logger for retry events.
     */
    public function __construct(
        StepInterface $innerStep, 
        int $maxRetries = 3, 
        int $baseIntervalSeconds = 5,
        ?callable $shouldRetry = null,
        ?LoggerInterface $logger = null
    ) {
        $this->innerStep = $innerStep;
        $this->maxRetries = $maxRetries;
        $this->baseIntervalSeconds = $baseIntervalSeconds;
        $this->shouldRetry = $shouldRetry;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string {
        return "RetryWrapper[" . $this->innerStep->getName() . "]";
    }

    public function process(StateInterface $state): StateInterface {
        $attempts = 0;
        $currentDelay = $this->baseIntervalSeconds;
        
        while (true) {
            try {
                // Attempt to run the inner step
                return $this->innerStep->process($state);

            } catch (\Exception $e) {
                $truncatedError = substr($e->getMessage(), 0, 150) . (strlen($e->getMessage()) > 150 ? '...' : '');
                $isFatal = $this->shouldRetry !== null && !($this->shouldRetry)($e);

                $attempts++;

                if ($isFatal || $attempts > $this->maxRetries) {
                    $reason = $isFatal ? "Fatal error matched" : "Retry limit reached";
                    
                    $this->logger->error("RetryStep aborted: {$reason}.", [
                        'step' => $this->innerStep->getName(),
                        'error' => $truncatedError
                    ]);
                    
                    $state->setLastError("RetryStep aborted ({$reason}) for " . $this->innerStep->getName() . ": " . $e->getMessage());
                    throw $e;
                }

                $this->logger->warning("RetryStep caught error: {$truncatedError}. Retrying ({$attempts}/{$this->maxRetries}) in {$currentDelay}s...", [
                    'step' => $this->innerStep->getName()
                ]);

                sleep($currentDelay);

                // Double the delay for the next potential failure (exponential backoff)
                $currentDelay = $currentDelay * self::MULTIPLIER;
            }
        }
    }
}
