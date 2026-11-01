<?php

namespace Sapient\Input;

use Sapient\Contracts\Core\InputProviderInterface;

class LocalFileProvider implements InputProviderInterface {
    public function resolve(string $reference): ?array {
        // Resolve ~ to home directory if needed (optional, but good for CLI)
        if (strpos($reference, '~') === 0) {
            $home = getenv('HOME');
            $reference = str_replace('~', $home, $reference);
        }

        if (file_exists($reference)) {
            $extension = strtolower(pathinfo($reference, PATHINFO_EXTENSION));
            $textExtensions = ['txt', 'md', 'php', 'json', 'yml', 'yaml', 'xml', 'css', 'js', 'sql', 'sh'];

            // Handle Images
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                return [
                    'type' => 'image_url',
                    'image_url' => ['url' => $reference]
                ];
            }

            // Handle Text Files
            if (in_array($extension, $textExtensions) || empty($extension)) {
                $content = @file_get_contents($reference);
                if ($content !== false) {
                    $filename = basename($reference);
                    return [
                        'type' => 'text',
                        'text' => "\n[CONTENT OF FILE: $filename]\n" . $content . "\n[END OF FILE: $filename]\n"
                    ];
                }
            }
        }

        return null;
    }
}
