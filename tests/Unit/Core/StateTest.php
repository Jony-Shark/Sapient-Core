<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Sapient\Core\State;
use Sapient\Core\Message;

class StateTest extends TestCase
{
    private $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/sapient_state_test_' . uniqid() . '.json';
    }

    public function testPersistence()
    {
        $state = new State();
        $state->set('session_id', '12345');
        $state->appendHistory(Message::user('Test input'));
        $state->appendHistory(Message::assistant('Test response'));
        
        // Complex content (multimodal style)
        $complexContent = [
            ['type' => 'text', 'text' => 'Check this image'],
            ['type' => 'image_url', 'image_url' => ['url' => 'http://example.com/img.png']]
        ];
        $state->appendHistory(Message::user($complexContent));

        // Save
        $success = $state->saveToFile($this->tempFile);
        $this->assertTrue($success, "State should be saved successfully.");
        $this->assertFileExists($this->tempFile);

        // Load into a new instance
        $newState = new State();
        $loadSuccess = $newState->loadFromFile($this->tempFile);
        $this->assertTrue($loadSuccess, "State should be loaded successfully.");

        // Assert Data
        $this->assertEquals('12345', $newState->get('session_id'));
        
        // Assert History
        $history = $newState->getHistory();
        $this->assertCount(3, $history);
        $this->assertEquals('Test input', $history[0]->content);
        $this->assertEquals('assistant', $history[1]->role);
        $this->assertEquals($complexContent, $history[2]->content);
        $this->assertTrue($history[2]->isUser());
    }

    public function testLoadNonExistentFile()
    {
        $state = new State();
        $this->assertFalse($state->loadFromFile('/non/existent/path/state.json'));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }
}
