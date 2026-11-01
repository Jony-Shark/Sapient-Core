<?php

namespace Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Sapient\Memory\Identity;
use Sapient\Core\State;

class IdentityTest extends TestCase
{
    private $tempDir;
    private $dataDir;
    private $resourceDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sapient_test_' . uniqid();
        mkdir($this->tempDir);
        
        $this->dataDir = $this->tempDir . '/data';
        $this->resourceDir = $this->tempDir . '/resources';
        
        mkdir($this->dataDir);
        mkdir($this->resourceDir);

        // Create base system file
        file_put_contents($this->resourceDir . '/identity_base.md', "You are {{NAME}}.");
    }
// ...
    protected function tearDown(): void
    {
        $this->recursiveRmdir($this->tempDir);
    }

    private function recursiveRmdir($dir)
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursiveRmdir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testInitialLoad()
    {
        $identity = new Identity($this->dataDir, 'TestBot');
        $state = $identity->load();
        
        $this->assertEquals('Unknown', $state['name']);
        $this->assertArrayHasKey('last_updated', $state);
    }

    public function testUpdateAttribute()
    {
        $identity = new Identity($this->dataDir, 'TestBot');
        $state = $identity->load();
        
        // Update first time
        $identity->updateAttribute($state, 'mood', 'happy');
        // Save
        $identity->save($state);
        
        // Reload new instance
        $identity2 = new Identity($this->dataDir, 'TestBot');
        $state2 = $identity2->load();
        
        $this->assertEquals('happy', $state2['attributes']['mood']);
    }

    public function testProvideContext()
    {
        $identity = new Identity($this->dataDir, 'TestBot');
        $state = $identity->load();
        $state['name'] = 'TestBot';
        $state['attributes']['role'] = 'assistant';
        $identity->save($state);
        
        $context = $identity->provideContext(new State());
        
        $this->assertStringContainsString('ROLE: assistant', $context);
    }
}
