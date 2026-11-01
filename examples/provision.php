<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Sapient\Core\Config;
use Sapient\Core\ConsoleLogger;
use Sapient\Memory\Identity;
use Sapient\Service\OllamaProvisioner;

// 1. Setup
Config::load(dirname(__DIR__));
$logger = new ConsoleLogger();

$dataDir = Config::get('DATA_DIR', dirname(__DIR__) . '/data');
$persona = Config::get('PERSONA', 'helix');
$modelName = Config::get('OLLAMA_MODEL', $persona);
$baseModel = Config::get('OLLAMA_BASE_MODEL', 'gemma4:12b');
$temp = (float)Config::get('OLLAMA_TEMPERATURE', 0.8);
$ctx = (int)Config::get('OLLAMA_NUM_CTX', 8192);

// 2. Initialize Services
$identityService = new Identity($dataDir, $persona);
$provisioner = new OllamaProvisioner($modelName, $baseModel, $temp, $ctx, $logger);

// 3. Execute Provisioning
$logger->info("Starting Identity Provisioning...", ['persona' => ucfirst($persona), 'model' => $modelName]);

$result = $provisioner->provision($identityService);

if ($result['success']) {
    echo "\n\033[1;32mSUCCESS:\033[0m Model '$modelName' is ready!\n";
    echo "You can verify with 'ollama list' or use it immediately.\n";
} else {
    echo "\n\033[1;31mFAILURE:\033[0m Could not create model.\n";
    echo "Detail: " . $result['message'] . PHP_EOL;
    exit(1);
}
