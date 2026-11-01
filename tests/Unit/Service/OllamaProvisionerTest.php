<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Sapient\Service\OllamaProvisioner;
use Sapient\Memory\Identity;
use Sapient\Core\Config;

class OllamaProvisionerTest extends TestCase
{
    private $tempDir;
    private $dataDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sapient_prov_test_' . uniqid();
        mkdir($this->tempDir);
        $this->dataDir = $this->tempDir . '/data';
        mkdir($this->dataDir);
        
        $resourceDir = $this->tempDir . '/resources';
        mkdir($resourceDir);
        file_put_contents($resourceDir . '/identity_base.md', "You are {{NAME}}.");
        
        Config::load($this->tempDir);
    }

    public function testProvisioningFlow()
    {
        $identity = new Identity($this->dataDir, 'TestBot');
        $state = $identity->load();
        $state['name'] = 'TestBot';
        $state['attributes']['role'] = 'tester';
        $identity->save($state);

        $provisioner = $this->getMockBuilder(OllamaProvisioner::class)
            ->setConstructorArgs(['testbot:latest', 'llama3', 0.7, 8192])
            ->onlyMethods(['executeCreateCommand'])
            ->getMock();

        $provisioner->expects($this->once())
            ->method('executeCreateCommand')
            ->with($this->callback(function($tempFile) {
                $content = file_get_contents($tempFile);
                return strpos($content, 'FROM llama3') !== false &&
                       strpos($content, 'ROLE: tester') !== false;
            }))
            ->willReturn(['success' => true, 'output' => []]);

        $result = $provisioner->provision($identity);

        $this->assertTrue($result['success']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveRmdir($this->tempDir);
        }
    }

    private function recursiveRmdir($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursiveRmdir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
