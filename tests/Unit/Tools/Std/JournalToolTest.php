<?php

namespace Tests\Unit\Tools\Std;

use Sapient\Tools\Std\JournalTool;
use Sapient\Memory\Journal;
use PHPUnit\Framework\TestCase;

class JournalToolTest extends TestCase
{
    private $journalTool;
    private $mockJournalService;

    protected function setUp(): void
    {
        $this->mockJournalService = $this->createMock(Journal::class);
        $this->journalTool = new JournalTool($this->mockJournalService);
    }

    public function testLogEventWrapper()
    {
        $inputContent = "User asked for help with taxes.";
        
        $this->mockJournalService->expects($this->once())
             ->method('append')
             ->with($inputContent);

        $result = $this->journalTool->execute([
            'content' => $inputContent
        ]);
        
        $this->assertStringContainsString('Journal Entry Logged:', $result);
    }
}
