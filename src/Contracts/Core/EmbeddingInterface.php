<?php

namespace Sapient\Contracts\Core;

/**
 * Interface EmbeddingInterface
 * 
 * Defines the contract for all Embedding Adapters.
 */
interface EmbeddingInterface {
    /**
     * Get the embedding vector for a single text string.
     *
     * @param string $text The text to embed.
     * @return float[] The embedding vector.
     */
    public function embed(string $text): array;

    /**
     * Get embedding vectors for an array of text strings.
     *
     * @param string[] $texts An array of texts to embed.
     * @return array<int, float[]> An array of embedding vectors.
     */
    public function embedBatch(array $texts): array;
}
