# Sapient AI Framework

Sapient is a **Pipeline-First, Fractal AI Framework** designed for building autonomous agents, complex business workflows, and multimodal memory systems in PHP.

> **Origin:** Co-created with Gemini. A framework built *with* AI, *for* AI.
> **Philosophy:** "Everything is a Step."

Unlike traditional monolithic agent loops, Sapient decomposes intelligence into atomic, composable `Steps`. A Workflow is just a Step. An Agent is just a Step. This allows for infinitely nested, fractal architectures where a high-level agent can execute a business workflow, which in turn can execute a sub-agent for a specific task.

## Key Features

- **Fractal Architecture:** Workflows, Agents, and Tools are all `StepInterface` implementations. Nest them as deep as you need.
- **Pipeline-First:** Define logic as a linear or branching chain of steps (`Input` -> `Think` -> `Act` -> `Output`).
- **Multi-Model & Multi-Provider:** Built-in adapters for Vertex AI, Google Gemini, OpenAI, Anthropic Claude, and Ollama (Local).
- **Multimodal Memory:** Persistent context handling with [Sapient-Hippocampus](https://github.com/Jony-Shark/Sapient-Hippocampus) (Vector + Relational Audit DB) and `Journal` (Event Log).
- **Business Process Automation:** Integrated Workflow Engine with Control Flow (`If/Else`, `Loop`, `DecisionStep`) and Stateless LLM Tasks (`GenerativeTaskStep`, `ExtractionStep`).

## LLM Adapters

Sapient supports multiple LLM providers through swappable adapters. All adapters implement `LLMInterface`:

```php
public function chat(array $messages, array $options = []): string;
```

### Available Adapters

| Adapter | Provider | Transport |
|---------|----------|-----------|
| `GeminiAdapter` | Google AI Studio (REST) | Guzzle |
| `VertexAIAdapter` | Google Cloud Vertex AI | Google SDK |
| `OpenAIAdapter` | OpenAI / Compatible APIs | Guzzle |
| `OllamaAdapter` | Ollama (Local) | Guzzle |
| `AnthropicAdapter` | Anthropic Claude | Guzzle |

### Constructor Pattern

Every adapter follows the same pattern: **essential params → timeout → adapterOptions → guzzleOptions**.

```php
// Gemini
$llm = new GeminiAdapter('gemini-2.5-flash', $apiKey, timeout: 300);

// Vertex AI
$llm = new VertexAIAdapter('gemini-2.5-flash', $projectId, 'us-central1', timeout: 300);

// OpenAI
$llm = new OpenAIAdapter('gpt-4o', $apiKey, timeout: 300);

// Ollama
$llm = new OllamaAdapter('gemma4:26b', 'http://localhost:11434', timeout: 500);

// Anthropic
$llm = new AnthropicAdapter('claude-sonnet-4-20250514', $apiKey, timeout: 300);
```

Switching providers is a **one-line change**. All optional parameters have sensible defaults.

### Generation Options (`$options`)

The `$options` parameter in `chat()` controls AI generation behavior. **Use `camelCase` keys:**

```php
$response = $llm->chat($messages, [
    'temperature' => 0.9,
    'topK' => 40,
    'topP' => 0.95,
    'maxOutputTokens' => 8192,
]);
```

Each adapter translates these to the format its API expects:
- **Gemini:** Passed as-is (API is natively camelCase)
- **Vertex:** Mapped to SDK setters dynamically (`topK` → `setTopK()`)
- **OpenAI / Ollama / Anthropic:** Converted to `snake_case` internally (`topK` → `top_k`)

> **Note:** If no options are provided, the adapter omits the generation config entirely and lets the provider use its own defaults.

### Adapter Options vs Guzzle Options

The constructor separates two concerns:

| Parameter | Purpose | Example |
|-----------|---------|---------|
| `$adapterOptions` | API request-level defaults | Ollama: `['stream' => false, 'keep_alive' => '1h']` |
| `$guzzleOptions` | HTTP client configuration | `['verify' => false, 'proxy' => '...']` |

```php
// Ollama with custom keep_alive (merged with defaults: stream=false, keep_alive=30m)
$llm = new OllamaAdapter('gemma4:26b', adapterOptions: ['keep_alive' => '1h']);

// Gemini behind a proxy
$llm = new GeminiAdapter('gemini-2.5-flash', $apiKey, guzzleOptions: ['proxy' => 'http://proxy:8080']);
```

### Retry Configuration

Sapient supports retry at two levels, depending on where you need it:

#### Transport Level (Adapter)

For transient API errors (e.g. `429 Resource Exhausted`, `503 Unavailable`), configure retry directly on the transport layer. This retries only the HTTP/gRPC call without re-running any pipeline logic.

**Guzzle Adapters** (Gemini, OpenAI, Ollama, Anthropic) — use Guzzle Middleware:

```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$stack = HandlerStack::create();
$stack->push(Middleware::retry(
    function ($retries, $request, $response, $exception) {
        if ($retries >= 3) return false;
        if ($response && in_array($response->getStatusCode(), [429, 503])) return true;
        return false;
    },
    function ($retries) {
        return 5000 * pow(2, $retries); // 5s, 10s, 20s (milliseconds)
    }
));

$llm = new GeminiAdapter('gemini-2.5-flash', $apiKey, guzzleOptions: ['handler' => $stack]);
```

**Vertex AI** — use Google SDK RetrySettings via `$callOptions`:

> **Note:** Due to bugs in older versions of the `google/gax` package and the Vertex AI SDK, Sapient implements custom workarounds for Vertex AI (e.g., custom retry loops and embedding endpoint fixes). If you are using `google/gax` ^1.43.1, you can pass `['use_native_retry' => true]` in `$adapterOptions` to disable the retry workaround. See the Adapter classes for details.

```php
use Google\ApiCore\RetrySettings;

$llm = new VertexAIAdapter('gemini-2.5-flash', $projectId, callOptions: [
    'retrySettings' => new RetrySettings([
        'retriesEnabled'          => true,
        'retryableCodes'          => [8, 14],   // RESOURCE_EXHAUSTED, UNAVAILABLE
        'initialRetryDelayMillis' => 5000,
        'retryDelayMultiplier'    => 2.0,
        'maxRetryDelayMillis'     => 120000,
        'initialRpcTimeoutMillis' => 60000,
        'rpcTimeoutMultiplier'    => 1.0,
        'maxRpcTimeoutMillis'     => 60000,
        'totalTimeoutMillis'      => 600000,    // 10 minutes total
    ])
]);
```

#### Pipeline Level (RetryStep)

For broader retry logic (e.g. wrapping an entire workflow step), use the `RetryStep` decorator. This retries the full step execution with exponential backoff and optional error filtering:

```php
use Sapient\Steps\System\RetryStep;

$pipeline->addStep(
    new RetryStep(
        innerStep: new SimpleAgentStep($llm),
        maxRetries: 3,
        baseIntervalSeconds: 5,
        shouldRetry: fn(\Exception $e) => str_contains($e->getMessage(), 'Resource exhausted')
    )
);
```

## Installation

```bash
composer require sapient/core
```

- PHP 8.2+
- Composer
- Ollama (for local LLM or Google Gemini API Key)

### Optional (Full Features)
- **Vertex AI** (for Enterprise Cloud LLM): `composer require google/cloud-ai-platform`
- **[Sapient-Hippocampus](https://github.com/Jony-Shark/Sapient-Hippocampus)** (Long-Term Vector & Audit Memory): `composer require sapient/hippocampus` (requires Python 3.10+ for the FastAPI/FAISS server)

### 1. Basic Usage (Minimal)
The `chat.php` entry point is a lightweight, minimal-dependency starter.
It uses Ollama by default and file-based memory (Journal).

```bash
php chat.php
```

### 2. Full Feature Demo (Kitchen Sink)
To see the full power of Sapient (Vertex AI, Hippocampus, Workflows), use the demo:

```bash
php examples/demo_chat.php
```

### 3. Custom / Local Development
The best way to build your own agent is to copy the demo and customize it.
`local_chat.php` is ignored by git, making it perfect for your private experiments.

```bash
cp examples/demo_chat.php local_chat.php
php local_chat.php
```

### 4. Creating a Custom Agent
The `AgentLoop` manages the conversation lifecycle.

```php
use Sapient\Core\State;
use Sapient\Core\Message;
use Sapient\Core\Pipeline;
use Sapient\Core\AgentLoop;
use Sapient\Adapter\OllamaAdapter;
use Sapient\Steps\Agent\SimpleAgentStep;

// 1. Setup LLM
$llm = new OllamaAdapter('llama3', 'http://localhost:11434');

// 2. Build Pipeline
$pipeline = new Pipeline();
$pipeline->addStep(new SimpleAgentStep($llm));

// 3. Run Loop
$state = new State();
$state->appendHistory(Message::user('Hello, who are you?'));

$loop = new AgentLoop($pipeline);
$state = $loop->run($state);

echo $state->get('last_response');
```

### 5. Defining a Business Workflow
Workflows allow for structured, deterministic logic mixed with AI reasoning.

```php
use Sapient\Workflow\Workflow;
use Sapient\Steps\Control\LoopStep;
use Sapient\Steps\Control\ConditionalStep;
use Sapient\Steps\Agent\GenerativeTaskStep;

class NewsSummaryWorkflow extends Workflow {
    protected function build(): void {
        // 1. Gather Data (Mock)
        $this->addStep(new class implements StepInterface { /* ... fetches RSS ... */ });

        // 2. Process Items
        $this->addStep(new LoopStep(
            'feed_items', 'current_article',
            new GenerativeTaskStep(
                $this->llm,
                "You are a summarizer.",
                "Summarize in 1 sentence: {{ current_article }}",
                "summary"
            )
        ));
    }
}
```

## Advanced Usage

### Custom Tools & Integration (e.g., Laravel)
You can integrate any PHP class as a Tool by implementing `Sapient\Contracts\Tools\ToolInterface`.
See [examples/laravel_service_tool_example.php](examples/laravel_service_tool_example.php) for a guide on how to wrap existing services (like a Laravel `InvoiceService`) as AI tools.

## Architecture Overview

### The Step Interface
Every component implements `StepInterface`:
```php
public function process(StateInterface $state): StateInterface;
```
This simplicity guarantees interoperability.

### The State
Data flows through the pipeline in a `State` object, acting as a "Blackboard" system. Steps read from and write to the State, never communicating directly.

## License
MIT
