<?php

namespace Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Sapient\Memory\Context;

class ContextTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sapient_test_ctx_' . uniqid();
        mkdir($this->tempDir);
    }

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

    public function testInitialContextIsEmpty()
    {
        $context = new Context($this->tempDir, 'TestBot');
        $data = $context->load();
        
        $this->assertEmpty($data, "Context should be empty initially");
    }

    public function testUpdateContext()
    {
        $context = new Context($this->tempDir, 'TestBot');
        $data = $context->load();
        
        $msg = $context->update($data, 'status', 'working');
        $this->assertEquals("Set context 'status' = 'working'", $msg);
        
        $context->save($data);

        // Reload
        $context2 = new Context($this->tempDir, 'TestBot');
        $data2 = $context2->load();
        $this->assertEquals('working', $data2['status']);
    }

    public function testDeleteContextKey()
    {
        $context = new Context($this->tempDir, 'TestBot');
        $data = ['foo' => 'bar'];
        
        $msg = $context->update($data, 'foo', 'delete');
        $this->assertEquals("Cleared context 'foo'", $msg);
        $this->assertArrayNotHasKey('foo', $data);
    }
}
