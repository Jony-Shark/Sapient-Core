<?php

namespace Tests\Unit\Tools\Std;

use Sapient\Tools\Std\ContextTool;
use Sapient\Memory\Context;
use PHPUnit\Framework\TestCase;

class ContextToolTest extends TestCase
{
    private $contextTool;
    private $mockContextService;

    protected function setUp(): void
    {
        $this->mockContextService = $this->createMock(Context::class);
        $this->contextTool = new ContextTool($this->mockContextService);
    }

    public function testSetContext()
    {
        // Expect updateAttribute and save to be called
        // ContextTool calls:
        // $state = $this->context->load();
        // $result = $this->context->updateAttribute($state, $key, $value);
        // if ($result) $this->context->save($state);

        $initialState = ['attributes' => []];
        $this->mockContextService->method('load')->willReturn($initialState);
        
        $this->mockContextService->expects($this->once())
             ->method('update')
             ->with($initialState, 'status', 'Working on Unit Tests')
             ->willReturn("Set context 'status' = 'Working on Unit Tests'");

        $this->mockContextService->expects($this->once())
             ->method('save');

        $result = $this->contextTool->execute([
            'key' => 'status',
            'value' => 'Working on Unit Tests'
        ]);

        $this->assertStringContainsString('State Updated:', $result);
    }
}
