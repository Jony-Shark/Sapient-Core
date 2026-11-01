<?php

namespace Sapient\Contracts\Core;

interface InputProviderInterface {
    /**
     * Attempt to resolve a reference string (e.g. file path or URL) to a content array.
     * 
     * @param string $reference The extracted reference (e.g. "/path/to/image.jpg")
     * @return array|null Returns array compatible with LLM usage (e.g. ['type' => 'image_url', ...]) or null if not handled.
     */
    public function resolve(string $reference): ?array;
}
