<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Sapient\Core\Message;

class MessageTest extends TestCase
{
    public function testSystemFactory()
    {
        $msg = Message::system('Hello system');
        $this->assertEquals('system', $msg->role);
        $this->assertEquals('Hello system', $msg->content);
        $this->assertTrue($msg->isSystem());
        $this->assertFalse($msg->isUser());
    }

    public function testUserFactory()
    {
        $msg = Message::user('Hello user');
        $this->assertEquals('user', $msg->role);
        $this->assertTrue($msg->isUser());
    }

    public function testAssistantFactory()
    {
        $msg = Message::assistant('Hello assistant');
        $this->assertEquals('assistant', $msg->role);
        $this->assertTrue($msg->isAssistant());
    }

    public function testMultimodalContent()
    {
        $parts = [
            ['type' => 'text', 'text' => 'Look at this'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,abc']]
        ];
        $msg = Message::user($parts);
        $this->assertIsArray($msg->content);
        $this->assertCount(2, $msg->content);
    }

    public function testToArray()
    {
        $msg = Message::user('test');
        $arr = $msg->toArray();
        $this->assertEquals(['role' => 'user', 'content' => 'test'], $arr);
    }

    public function testFromArray()
    {
        $msg = Message::fromArray(['role' => 'assistant', 'content' => 'hi']);
        $this->assertEquals('assistant', $msg->role);
        $this->assertEquals('hi', $msg->content);
    }

    public function testWithContent()
    {
        $msg = Message::system('original');
        $updated = $msg->withContent('modified');
        
        // Original unchanged (immutable)
        $this->assertEquals('original', $msg->content);
        // New message has updated content but same role
        $this->assertEquals('modified', $updated->content);
        $this->assertEquals('system', $updated->role);
    }
}
