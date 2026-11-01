# Changelog

All notable changes to the Sapient project will be documented in this file.

## [1.0.0-beta] - 2026-02-19

### Highlights
- **Production-Ready Core**: Refactored `chat.php` to serve as a robust, minimal entry point.
- **Optional Vertex AI**: Google Cloud dependencies are now optional (`suggest` in composer) to reduce default footprint.
- **Multiple LLM Drivers**: Native support for Ollama, Gemini Pro (API Key), and Vertex AI (Service Account).
- **Multimodal Support**: Handle images via local paths or GCS URIs (`gs://`).
- **Memory & Context**: Integrated Vector DB (HippocampusClient), Journal for episodic memory, and Context compression.
- **Tooling Ecosystem**: A rich suite of tools including Identity management, Workflows, and Calculator.

### Added
- **Core Framework**:
  - `Pipeline`, `State`, `StepInterface` architecture.
  - `Config` system loaded via `.env`.
  - `LoggerInterface` for consistent output.
- **Adapters**:
  - `OllamaAdapter`: Local LLM support.
  - `GeminiAdapter`: AI Studio support.
  - `VertexAIAdapter`: Google Cloud Platform support (with GCS referencing).
- **Memory**:
  - `HippocampusClient`: Interface to vector database service.
  - `Journal`: Local file-based event logging.
  - `Context`: Key-value store for session data.
  - `ContextCompressionStep`: Automatic history summarization.
- **Tools**:
  - `IdentityTool`: Manage AI persona traits.
  - `CalculatorTool`: Safe arithmetic evaluation.
  - `WorkflowTool`: Execute complex nested pipelines.
  - `HelpTool`: Self-introspection of available capabilities.
- **Workflow Engine**:
  - Define complex tasks as sequences of steps.
  - Registry system for named workflows.
- **CLI Interface**:
  - `chat.php`: Main interactive loop.
  - `examples/demo_chat.php`: Developer "kitchen sink" loop.

### Optimizations
- **Performance**: Optional Vertex AI driver prevents monolithic dependency bloat.
- **Reliability**: Strong validation in `CalculatorTool` and XML parsing.
- **UX**: Improved error handling and color-coded console output.

### Fixed
- Argument parsing logic in `InputProviderInterface` and `XmlOutputParser`.
- Path resolution for local files.
- Syntax errors in early Adapter implementations.
