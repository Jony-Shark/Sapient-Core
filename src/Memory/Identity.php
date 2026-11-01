<?php

namespace Sapient\Memory;

use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Contracts\Core\StateInterface;

class Identity implements ContextProviderInterface {
    private $stateFile;
    private $systemFile;
    private $protectedKeys = [];

    public function __construct($dataDir, $persona = 'helix') {
        $this->ensurePersonaDir($dataDir, $persona);
        $this->stateFile = $dataDir . '/' . $persona . '/state.json';
        
        // Base config files are now in /resources, assuming $dataDir is /data
        $resourceDir = dirname($dataDir) . '/resources';
        $this->systemFile = $resourceDir . '/identity_base.md';
    }

    public function getContextName(): string {
        return 'identity';
    }

    public function provideContext(StateInterface $state): string {
        $data = $this->load();
        return $this->renderToText($data);
    }

    private function ensurePersonaDir($dataDir, $persona) {
        $dir = $dataDir . '/' . $persona;
        if (!is_dir($dir)) mkdir($dir, 0777, true);
    }

    public function load() {
        if (!file_exists($this->stateFile)) {
            return ['name' => 'Unknown', 'last_updated' => date('Y-m-d H:i:s'), 'attributes' => []];
        }
        return json_decode(file_get_contents($this->stateFile), true);
    }

    public function save(array $state) {
        $state['last_updated'] = date('Y-m-d H:i:s');
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function renderToText(array $state) {
        $name = $state['name'] ?? 'Unknown';
        
        // 1. Load Base Instruction
        $base = "";
        if (file_exists($this->systemFile)) {
            $base = file_get_contents($this->systemFile);
            $base = str_replace('{{NAME}}', $name, $base);
        } else {
             $base = "You are $name.";
        }

        $output = $base . "\n\n";
        $output .= "Your self-assigned identity:\n";
        
        if (!empty($state['attributes'])) {
            foreach ($state['attributes'] as $key => $val) {
                $output .= strtoupper($key) . ": $val\n";
            }
            $output .= "\n";
        }
        
        return trim($output);
    }

    public function updateAttribute(array &$state, $key, $value) {
        $key = strtolower(trim($key));
        $value = trim($value);

        if (in_array($key, $this->protectedKeys)) {
            return false;
        }

        if (strtolower($value) === 'null' || strtolower($value) === 'delete' || $value === '') {
            if (isset($state['attributes'][$key])) {
                unset($state['attributes'][$key]);
                return "Removed attribute: $key";
            }
        } else {
            $state['attributes'][$key] = $value;
            return "Set attribute '$key' = '$value'";
        }
        return null; // No change
    }
}
