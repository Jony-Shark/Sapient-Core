<?php

namespace Sapient\Traits;

use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\Rpc\Code;

/**
 * Trait GaxRetryWorkaroundTrait
 * 
 * Provides a workaround for a bug in versions of google/gax prior to 1.43.1
 * where the RetryMiddleware calculates exponential backoff but fails to call sleep().
 * From GAX 1.43.1 onwards, the native retry works correctly.
 * 
 * @see https://github.com/googleapis/gax-php/issues/673
 */
trait GaxRetryWorkaroundTrait {

    /**
     * @var bool If true, bypasses the custom workaround and uses the native GAX retry logic.
     */
    protected bool $useNativeRetry = false;

    /**
     * Executes the given API call with a custom retry loop that properly implements usleep(),
     * unless $useNativeRetry is set to true.
     *
     * @param callable $action The closure executing the API call
     * @param array &$optionalArgs The arguments array (will be modified to unset retrySettings if using workaround)
     * @return mixed The response from the API call
     * @throws ApiException
     */
    private function executeWithRealRetry(callable $action, array &$optionalArgs)
    {
        if ($this->useNativeRetry) {
            // Bypass the workaround and let the native GAX RetryMiddleware handle it
            return $action($optionalArgs);
        }

        $maxRetries = 0;
        $delayMs = 0;
        $multiplier = 1.0;
        $retryableCodes = [];
        $totalTimeoutMillis = 0;

        if (isset($optionalArgs['retrySettings'])) {
            $rs = $optionalArgs['retrySettings'];
            // Native GAX sometimes uses an array, sometimes an object
            if (is_object($rs) && method_exists($rs, 'retriesEnabled') && $rs->retriesEnabled()) {
                $maxRetries = method_exists($rs, 'getMaxRetries') && $rs->getMaxRetries() > 0 ? $rs->getMaxRetries() : 5;
                $delayMs = method_exists($rs, 'getInitialRetryDelayMillis') ? $rs->getInitialRetryDelayMillis() : 2000;
                $multiplier = method_exists($rs, 'getRetryDelayMultiplier') ? $rs->getRetryDelayMultiplier() : 2.0;
                $retryableCodes = method_exists($rs, 'getRetryableCodes') ? $rs->getRetryableCodes() : [];
                $totalTimeoutMillis = method_exists($rs, 'getTotalTimeoutMillis') ? $rs->getTotalTimeoutMillis() : 0;
            } elseif (is_array($rs) && ($rs['retriesEnabled'] ?? false)) {
                $maxRetries = $rs['maxRetries'] ?? 5;
                $delayMs = $rs['initialRetryDelayMillis'] ?? 2000;
                $multiplier = $rs['retryDelayMultiplier'] ?? 2.0;
                $retryableCodes = $rs['retryableCodes'] ?? [];
                $totalTimeoutMillis = $rs['totalTimeoutMillis'] ?? 0;
            }

            // Remove from args so GAX doesn't run its bugged internal loop
            unset($optionalArgs['retrySettings']);
        }

        $startTimeMs = microtime(true) * 1000;
        $deadlineMs = $totalTimeoutMillis > 0 ? $startTimeMs + $totalTimeoutMillis : 0;

        for ($attempt = 1; $attempt <= max(1, $maxRetries + 1); $attempt++) {
            try {
                return $action($optionalArgs);
            } catch (ApiException $e) {
                // Not retryable or out of retries
                if ($attempt > $maxRetries || !in_array($e->getCode(), $retryableCodes)) {
                    throw $e;
                }

                $currentTimeMs = microtime(true) * 1000;
                if ($deadlineMs > 0 && $currentTimeMs >= $deadlineMs) {
                    throw new ApiException(
                        'Retry total timeout exceeded.',
                        Code::DEADLINE_EXCEEDED,
                        ApiStatus::DEADLINE_EXCEEDED
                    );
                }

                $sleepMicroseconds = $delayMs * 1000;
                usleep($sleepMicroseconds);

                // Exponential backoff
                $delayMs = (int) min($delayMs * $multiplier, 60000);
            }
        }

        return null;
    }
}
