<?php

namespace Sapient\Tests\Unit\Core\Utils;

use PHPUnit\Framework\TestCase;
use Sapient\Core\Utils\JsonParser;

class JsonParserTest extends TestCase
{
    public function testParsesValidJson()
    {
        $json = '{"key": "value"}';
        $result = JsonParser::parse($json);
        $this->assertEquals(['key' => 'value'], $result);
    }

    public function testRemovesMarkdownEscapes()
    {
        // Simulate LLM outputting \$ and \' instead of standard characters
        $jsonWithEscapes = '{"price": "\$20", "name": "O\\\'Connor", "code": "\`print()\`", "bold": "\*test\*"}';
        
        $result = JsonParser::parse($jsonWithEscapes);
        
        $this->assertIsArray($result, "Failed to decode JSON with markdown escapes");
        $this->assertEquals('$20', $result['price']);
        $this->assertEquals("O'Connor", $result['name']);
        $this->assertEquals("`print()`", $result['code']);
        $this->assertEquals("*test*", $result['bold']);
    }

    public function testFixesUnquotedNanAndInf()
    {
        $json = '{
            "not_a_number": NaN,
            "infinity": INF,
            "negative_infinity": -Infinity,
            "normal": 42,
            "arr": [NaN, INF, -INF]
        }';

        $result = JsonParser::parse($json);

        $this->assertIsArray($result, "Failed to decode JSON with unquoted NaN/INF");
        $this->assertEquals('NaN', $result['not_a_number']);
        $this->assertEquals('INF', $result['infinity']);
        $this->assertEquals('-Infinity', $result['negative_infinity']);
        $this->assertEquals(42, $result['normal']);
        $this->assertEquals(['NaN', 'INF', '-INF'], $result['arr']);
    }

    public function testExtractsFromMarkdownJsonBlock()
    {
        $content = "Here is the result:\n```json\n{\"test\": 123}\n```\nHope it helps!";
        $result = JsonParser::parse($content);
        $this->assertEquals(['test' => 123], $result);
    }

    public function testExtractsFromGenericCodeBlock()
    {
        $content = "Result:\n```\n{\"test\": 456}\n```";
        $result = JsonParser::parse($content);
        $this->assertEquals(['test' => 456], $result);
    }

    public function testExtractsFromRawBraces()
    {
        $content = "Some text before {\"test\": 789} and some text after.";
        $result = JsonParser::parse($content);
        $this->assertEquals(['test' => 789], $result);
    }

    public function testReturnsNullOnInvalidJson()
    {
        $content = "This is just a string without JSON";
        $result = JsonParser::parse($content);
        $this->assertNull($result);
    }
    
    public function testFailsOnMarkdownEscapesWithoutSanitization()
    {
        $jsonWithEscapes = '{"price": "\$20", "name": "O\\\'Connor", "code": "\`print()\`", "bold": "\*test\*"}';
        $result = JsonParser::parse($jsonWithEscapes, false);
        $this->assertNull($result, "Expected parse to fail on markdown escapes when sanitize=false"); 
    }

    public function testFailsOnUnquotedNanAndInfWithoutSanitization()
    {
        $json = '{
            "not_a_number": NaN,
            "infinity": INF,
            "negative_infinity": -Infinity,
            "normal": 42,
            "arr": [NaN, INF, -INF]
        }';

        $result = JsonParser::parse($json, false);
        $this->assertNull($result, "Expected parse to fail on unquoted NaN/INF when sanitize=false");
    }
}
