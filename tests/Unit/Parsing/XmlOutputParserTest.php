<?php

namespace Tests\Unit\Parsing;

use Sapient\Parsing\XmlOutputParser;
use PHPUnit\Framework\TestCase;

class XmlOutputParserTest extends TestCase
{
    private $parser;

    protected function setUp(): void
    {
        $this->parser = new XmlOutputParser(['tool', 'other']);
        // The parser takes registered tags, so we test with specific ones.
    }

    public function testStandardDoubleQuotes()
    {
        $input = 'Hello <tool arg="val">Content</tool> World';
        $result = $this->parser->parse($input);
        
        $this->assertCount(1, $result['actions']);
        $this->assertEquals('val', $result['actions'][0]['args']['arg']);
        $this->assertEquals('Content', $result['actions'][0]['args']['content']);
        $this->assertEquals('Hello  World', trim($result['response'])); // Parser replaces match with empty string
    }

    public function testSingleQuotes()
    {
        $input = "Hello <tool arg='val'>Content</tool>";
        $result = $this->parser->parse($input);
        
        $this->assertCount(1, $result['actions'], 'Should parse single quotes');
        $this->assertEquals('val', $result['actions'][0]['args']['arg']);
    }

    public function testEscapedQuotes()
    {
        // <tool message="He said \"Hello\"">
        $input = 'call <tool message="He said \\"Hello\\"">Test</tool>';
        $result = $this->parser->parse($input);
        
        $this->assertCount(1, $result['actions'], 'Should parse escaped quotes');
        $this->assertEquals('He said "Hello"', $result['actions'][0]['args']['message']);
    }

    public function testNewlinesInAttributes()
    {
        $input = '<tool 
            arg1="val1"
            arg2="val2">Content</tool>';
        $result = $this->parser->parse($input);
        
        $this->assertCount(1, $result['actions']);
        $this->assertEquals('val1', $result['actions'][0]['args']['arg1']);
        $this->assertEquals('val2', $result['actions'][0]['args']['arg2']);
    }

    public function testSelfClosingTags() 
    {
        // Not currently supported by Regex, but desirable?
        // Let's test if it handles it (fail expectation if needed)
        // For tools, usually self-closing isn't standard in Sapient yet (always <tag>...</tag>)
        // But LLMs might generate it.
        $input = '<tool arg="val" />';
        $result = $this->parser->parse($input);
        
        // Current implementation is regex with closing tag </\1> required.
        // So this will likely fail (count 0).
        // If we want to support it, we need to update the parser.
        // For now, let's assert 0 to document current behavior, or 1 if we plan to fix it.
        // I PLAN TO FIX IT. So assert 1.
        $this->assertCount(1, $result['actions'], 'Should parse self-closing tags');
    }
    
    public function testMultipleSameTags()
    {
        $input = '<tool id="1">A</tool> spacer <tool id="2">B</tool>';
        $result = $this->parser->parse($input);
        
        $this->assertCount(2, $result['actions']);
        $this->assertEquals('1', $result['actions'][0]['args']['id']);
        $this->assertEquals('2', $result['actions'][1]['args']['id']);
    }

    public function testAttributeWithoutValues()
    {
         // <tool flag>Content</tool> -> flag="flag" or flag=true?
         // Current regex requires key="value".
         // LLMs often forget values for boolean flags.
         $input = '<tool flag>Content</tool>';
         $result = $this->parser->parse($input);
         
         // Plan to support this? Maybe. 
         // Let's assert it parses content at least?
         // Current regex match will fail completely on <tool flag>.
         // Actually, the main regex `(?:(?:\s+([^>]+))?)` matches anything inside.
         // But the attribute parsing regex `(\w+)="([^"]+)"` is strict.
         // So it will see the tool, but args will be empty except content.
         
         $this->assertCount(1, $result['actions']);
         $this->assertEquals('Content', $result['actions'][0]['args']['content']);
         // Ensure it didn't crash
    }
}
