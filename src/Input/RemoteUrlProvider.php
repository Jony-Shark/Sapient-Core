<?php

namespace Sapient\Input;

use Sapient\Contracts\Core\InputProviderInterface;

class RemoteUrlProvider implements InputProviderInterface {
    public function resolve(string $reference): ?array {
        if (filter_var($reference, FILTER_VALIDATE_URL)) {
             // For now, treat all URLs as potential image sources if they look like images.
             // Or better, let the Adapter fail if it can't handle it.
             // But we can check extension to be helpful.
             
             return [
                 'type' => 'image_url',
                 'image_url' => ['url' => $reference]
             ];
        }

        return null;
    }
}
