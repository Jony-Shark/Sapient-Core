<?php

namespace Sapient\Adapter;

use Google\Cloud\AIPlatform\V1\Client\PredictionServiceClient;
use Google\Cloud\AIPlatform\V1\GenerateContentRequest;
use Google\Cloud\AIPlatform\V1\Content;
use Google\Cloud\AIPlatform\V1\Part;
use Google\Cloud\AIPlatform\V1\Blob;
use Google\Cloud\AIPlatform\V1\FileData;
use Google\Cloud\AIPlatform\V1\GenerationConfig;
use Google\ApiCore\ApiException;
use Sapient\Contracts\Core\LLMInterface;
use Sapient\Traits\GaxRetryWorkaroundTrait;

/**
 * VertexAIAdapter interfaces with Google Cloud's Vertex AI Platform.
 *
 * @note **File Size & Inline Data Constraints:**
 * When passing local files or base64 data directly (inlineData), Google's WAF
 * (Web Application Firewall) may block requests exceeding ~0.8MB - 1MB to prevent
 * automated abuse, resulting in a 'FAILED_PRECONDITION' / HTML Captcha error.
 *
 * For industrial processing or larger documents, it is highly recommended to
 * upload files to Google Cloud Storage first and pass the `gs://...` URI.
 * This adapter inherently supports handling `gs://` URLs natively.
 */
class VertexAIAdapter implements LLMInterface {
    use GaxRetryWorkaroundTrait;

    private $projectId;
    private $location;
    private $timeout;
    private $adapterOptions;
    private array $generationConfig;
    private $callOptions;
    private $client;
    private bool $isTransient = false;
    protected string $model;

    public function __construct(string $model, string $projectId = '', string $location = 'us-central1', int $timeout = 180, array $adapterOptions = [], array $callOptions = []) {
        $this->projectId = $projectId;
        $this->location = $location;
        $this->model = $model;
        $this->timeout = $timeout * 1000; // Store as ms
        $this->adapterOptions = $adapterOptions;
        $this->callOptions = $callOptions;

        // Toggle to bypass the GAX retry workaround
        $this->useNativeRetry = $this->adapterOptions['use_native_retry'] ?? false;
        $this->generationConfig = $this->adapterOptions['generationConfig'] ?? [];
        unset($this->adapterOptions['use_native_retry'], $this->adapterOptions['generationConfig']);

        $this->client = $this->createClient($this->adapterOptions);
    }

    /**
     * Set whether to use a fresh client for every request (useful for bulk processing)
     */
    public function setTransientMode(bool $isTransient): self {
        $this->isTransient = $isTransient;
        return $this;
    }

    private function createClient(array $options = []): PredictionServiceClient {
        $apiEndpoint = sprintf('%s-aiplatform.googleapis.com', $this->location);

        $config = array_merge([
            'apiEndpoint' => $apiEndpoint
        ], $options);

        return new PredictionServiceClient($config);
    }

    /**
     * Detect MIME type from a URI string.
     */
    private function getMimeTypeFromUri(string $uri): string {
        $uri = strtolower($uri);

        if (strpos($uri, '.pdf') !== false) {
            return 'application/pdf';
        } else if (strpos($uri, '.png') !== false) {
            return 'image/png';
        } else if (strpos($uri, '.gif') !== false) {
            return 'image/gif';
        } else if (strpos($uri, '.webp') !== false) {
            return 'image/webp';
        } else if (strpos($uri, '.jpg') !== false || strpos($uri, '.jpeg') !== false) {
            return 'image/jpeg';
        }

        // Generic fallback for images if extension is found but not specifically mapped
        if (preg_match('/\.(bmp|tiff|svg)$/', $uri, $matches)) {
            return 'image/' . $matches[1];
        }

        return 'application/octet-stream';
    }

    // executeWithRealRetry has been moved to GaxRetryWorkaroundTrait

    public function chat(array $messages, array $options = []): string {
        // endpoint: projects/{project}/locations/{location}/publishers/google/models/{model}
        $modelName = sprintf(
            'projects/%s/locations/%s/publishers/google/models/%s',
            $this->projectId,
            $this->location,
            $this->model
        );

        // Convert messages to Vertex AI Content objects
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $msg) {
            $role = $msg->role;
            $text = $msg->content;

            if ($role === 'system') {
                // Accumulate system instructions
                if ($systemInstruction === null) {
                    $systemInstruction = new Content();
                    $systemInstruction->setRole('system');
                }
                $part = new Part();
                $part->setText($text);
                // System instruction content parts
                $currentParts = $systemInstruction->getParts();
                $arr = iterator_to_array($currentParts);
                $arr[] = $part;
                $systemInstruction->setParts($arr);

                continue;
            }

            $vertexRole = ($role === 'assistant') ? 'model' : 'user';
            $parts = [];

            if (is_array($text)) {
                foreach ($text as $partData) {
                    $part = new Part();
                    if (($partData['type'] ?? '') === 'text') {
                        $part->setText($partData['text']);
                    } elseif (($partData['type'] ?? '') === 'image_url') {
                        $url = $partData['image_url']['url'] ?? '';
                        if (strpos($url, 'gs://') === 0) {
                            // GCS File Data (Reference)
                            $mimeType = $this->getMimeTypeFromUri($url);
                            $fileData = new FileData();
                            $fileData->setMimeType($mimeType);
                            $fileData->setFileUri($url);
                            $part->setFileData($fileData);
                        } elseif (strpos($url, 'data:') === 0) {
                            // Inline data
                            $base64 = substr($url, strpos($url, ',') + 1);
                            $mimeType = substr($url, 5, strpos($url, ';') - 5);
                            $blob = new Blob();
                            $blob->setMimeType($mimeType);
                            $blob->setData(base64_decode($base64));
                            $part->setInlineData($blob);
                        } elseif (!preg_match('/^[a-z]+:\/\//i', $url) && file_exists($url)) {
                            // Local file
                            $fileData = file_get_contents($url);
                            if ($fileData !== false) {
                                $mimeType = mime_content_type($url);
                                $blob = new Blob();
                                $blob->setMimeType($mimeType ?: 'image/jpeg');
                                $blob->setData($fileData);
                                $part->setInlineData($blob);
                            }
                        }
                    }
                    $parts[] = $part;
                }
            } else {
                $rawText = (string)$text;

                // Split the text to find [LOCAL_IMAGE:path] tokens
                $chunks = preg_split('/\[LOCAL_IMAGE:(.*?)\]/', $rawText, -1, PREG_SPLIT_DELIM_CAPTURE);

                foreach ($chunks as $index => $chunk) {
                    if (empty($chunk)) continue;

                    // Because of PREG_SPLIT_DELIM_CAPTURE, every odd index is the captured path
                    if ($index % 2 === 1) {
                        $imagePath = trim($chunk);

                        // Handle GCS URIs specifically
                        if (strpos($imagePath, 'gs://') === 0) {
                            $mimeType = $this->getMimeTypeFromUri($imagePath);
                            $fileData = new FileData();
                            $fileData->setMimeType($mimeType);
                            $fileData->setFileUri($imagePath);

                            $part = new Part();
                            $part->setFileData($fileData);
                            $parts[] = $part;
                            continue;
                        }

                        // Local file (only if NOT a URI and exists)
                        if (!preg_match('/^[a-z]+:\/\//i', $imagePath) && file_exists($imagePath)) {
                            $fileData = file_get_contents($imagePath);
                            if ($fileData !== false) {
                                $mimeType = mime_content_type($imagePath);
                                $blob = new Blob();
                                $blob->setMimeType($mimeType ?: 'image/jpeg');
                                $blob->setData($fileData);

                                $part = new Part();
                                $part->setInlineData($blob);
                                $parts[] = $part;
                                continue;
                            }
                        }
                        // Fallback
                        $part = new Part();
                        $part->setText(strpos($imagePath, 'gs://') === 0 ? $imagePath : "[IMAGE NOT FOUND: $imagePath]");
                        $parts[] = $part;
                    } else {
                        // Even index is normal text
                        // Check if the whole chunk is a gs:// URI (sometimes prompt passes it directly)
                        $trimmedChunk = trim($chunk);
                        if (strpos($trimmedChunk, 'gs://') === 0 && strpos($trimmedChunk, ' ') === false) {
                            $mimeType = $this->getMimeTypeFromUri($trimmedChunk);
                            $fileData = new FileData();
                            $fileData->setMimeType($mimeType);
                            $fileData->setFileUri($trimmedChunk);
                            $part = new Part();
                            $part->setFileData($fileData);
                            $parts[] = $part;
                            continue;
                        }

                        $part = new Part();
                        $part->setText($chunk);
                        $parts[] = $part;
                    }
                }
            }

            $content = new Content();
            $content->setRole($vertexRole);
            $content->setParts($parts);

            $contents[] = $content;
        }

        $generationConfig = (new GenerationConfig());
        $mergedOptions = array_merge($this->generationConfig, $options);

        foreach ($mergedOptions as $key => $value) {
            $setter = 'set' . ucfirst($key);

            if (method_exists($generationConfig, $setter)) {
                $generationConfig->$setter($value);
            }
        }

        $request = (new GenerateContentRequest())
        ->setModel($modelName)
        ->setContents($contents)
        ->setGenerationConfig($generationConfig);

        if ($systemInstruction) {
            if (method_exists($request, 'setSystemInstruction')) {
                $request->setSystemInstruction($systemInstruction);
            } else {
                array_unshift($contents, $systemInstruction);
                $request->setContents($contents);
            }
        }

        $optionalArgs = array_merge(
            ['timeoutMillis' => $this->timeout],
            $this->callOptions
        );

        // Use our workaround method to handle the waitless GAX retry middleware
        // TODO: After RetryMiddleware fix simplify this:
        // $response = $this->client->generateContent($request, $optionalArgs);
        $response = $this->executeWithRealRetry(function($args) use ($request) {
            return $this->client->generateContent($request, $args);
        }, $optionalArgs);

        if (!$response) {
            throw new \RuntimeException("Vertex AI request failed: Empty response");
        }

        // If in transient mode, rotate the client for this request
        if ($this->isTransient) {
            $this->client->close();
            $this->client = $this->createClient($this->adapterOptions);
        }


        $responseText = '';
        foreach ($response->getCandidates() as $candidate) {
            foreach ($candidate->getContent()->getParts() as $part) {
                $responseText .= $part->getText();
            }
        }

        // Safety Fallback: Vertex API crashes if we try to send empty content in the next turn
        return (trim($responseText) === '') ? '...' : $responseText;
    }
}
