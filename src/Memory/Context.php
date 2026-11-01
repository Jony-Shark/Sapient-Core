<?php

namespace Sapient\Memory;

use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Contracts\Core\StateInterface;

class Context implements ContextProviderInterface {
    private $contextFile;

    public function __construct($dataDir, $persona = 'helix') {
        $this->contextFile = $dataDir . '/' . $persona . '/context.json';
        // Directory creation handled by Identity (or could duplicate check here for safety)
        if (!is_dir(dirname($this->contextFile))) mkdir(dirname($this->contextFile), 0777, true);
    }

    public function load() {
        if (!file_exists($this->contextFile)) return [];
        return json_decode(file_get_contents($this->contextFile), true);
    }

    public function save(array $ctx) {
        file_put_contents($this->contextFile, json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // --- ContextProviderInterface ---

    public function getContextName(): string {
        return 'persistent_custom_context';
    }

    public function provideContext(StateInterface $state): ?string {
        return $this->renderToText();
    }

    public function renderToText() {
        $ctx = $this->load();
        if (empty($ctx)) return "";
        
        $output = "[PERSISTENT STATE]\n";

        foreach ($ctx as $key => $val) {
            $output .= strtoupper($key) . ": $val\n";
        }
        return $output;
    }

    public function update(array &$ctx, $key, $value) {
        $key = strtolower(trim($key));
        $value = trim($value);

        if (strtolower($value) === 'null' || strtolower($value) === 'delete' || $value === '') {
            if (isset($ctx[$key])) {
                unset($ctx[$key]);
                return "Cleared context '$key'";
            }
        } else {
            $ctx[$key] = $value;
            return "Set context '$key' = '$value'";
        }
        return null;
    }
}
