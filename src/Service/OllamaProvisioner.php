<?php

namespace Sapient\Service;

use Sapient\Contracts\Core\ProvisionerInterface;
use Sapient\Memory\Identity;
use Psr\Log\LoggerInterface;

class OllamaProvisioner implements ProvisionerInterface {
    private $modelName;
    private $baseModel;
    private $temp;
    private $ctx;
    private $logger;

    public function __construct(string $modelName, string $baseModel = 'llama3', float $temp = 0.7, int $ctx = 8192, ?LoggerInterface $logger = null) {
        $this->modelName = $modelName;
        $this->baseModel = $baseModel;
        $this->temp = $temp;
        $this->ctx = $ctx;
        $this->logger = $logger;
    }

    /**
     * Rebuilds the Ollama model using the provided Identity state.
     * 
     * @return array ['success' => bool, 'message' => string]
     */
    public function provision(Identity $identity): array {

        if ($this->logger) {
            $this->logger->info("Provisioning Identity...", [
                'base_model' => $this->baseModel,
                'temperature' => $this->temp,
                'context' => $this->ctx
            ]);
        }

        // 2. Generate Modelfile System Prompt
        try {
            $modelfileContent = $this->generateModelfile($identity, $this->baseModel, $this->temp, $this->ctx);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => "Failed to generate Modelfile: " . $e->getMessage()];
        }

        // 3. Write Temporary Modelfile
        $tempModelfile = sys_get_temp_dir() . '/Modelfile_' . uniqid();
        if (file_put_contents($tempModelfile, $modelfileContent) === false) {
             return ['success' => false, 'message' => "Failed to write temporary Modelfile."];
        }

        // 4. Create Model directly via CLI
        $result = $this->executeCreateCommand($tempModelfile);

        // Cleanup
        unlink($tempModelfile);

        if ($result['success']) {
            if ($this->logger) {
                $this->logger->info("Model provisioning successful.");
            }
            return ['success' => true, 'message' => "Model successfully rebuilt."];
        } else {
            $error = is_array($result['output']) ? implode("\n", $result['output']) : $result['output'];
            if ($this->logger) {
                $this->logger->error("Model provisioning failed: $error");
            }
            return ['success' => false, 'message' => "Ollama create failed: $error"];
        }
    }

    /**
     * Executes the Ollama CLI create command.
     *
     * @param string $tempModelfile
     * @return array ['success' => bool, 'output' => array|string]
     */
    protected function executeCreateCommand(string $tempModelfile): array {
        $cmd = "ollama create {$this->modelName} -f " . escapeshellarg($tempModelfile) . " 2>&1";
        exec($cmd, $output, $returnVar);
        return [
            'success' => $returnVar === 0,
            'output' => $output
        ];
    }

    /**
     * Generates the Ollama Modelfile content.
     */
    private function generateModelfile(Identity $identity, string $baseModel, float $temp, int $ctx): string {
        $state = $identity->load();
        $identityText = $identity->renderToText($state);

        $modelfile = 'FROM ' . $baseModel . "\n\n";
        $modelfile .= 'PARAMETER temperature ' . $temp . PHP_EOL;
        $modelfile .= 'PARAMETER num_ctx ' . $ctx ."\n\n";
        $modelfile .= 'SYSTEM """' . PHP_EOL;
        $modelfile .= $identityText . PHP_EOL;
        $modelfile .= '"""';
        
        return $modelfile;
    }
}
