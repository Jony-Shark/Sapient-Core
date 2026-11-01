<?php

namespace Sapient\Tests\Unit\Core\Utils;

use PHPUnit\Framework\TestCase;
use Sapient\Core\Utils\TemplateRenderer;
use Sapient\Core\State;

class TemplateRendererTest extends TestCase {

    public function testRenderWithArrayReplacesKeys() {
        $template = "Hello {{ name }}, welcome to {{ location }}.";
        $data = [
            'name' => 'Alice',
            'location' => 'Wonderland'
        ];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("Hello Alice, welcome to Wonderland.", $result);
    }

    public function testRenderWithStateInterfaceReplacesKeys() {
        $template = "User {{ user_id }} has role {{ role }}.";
        $state = new State();
        $state->set('user_id', 42);
        $state->set('role', 'admin');

        $result = TemplateRenderer::render($template, $state);
        $this->assertEquals("User 42 has role admin.", $result);
    }

    public function testRenderIgnoresWhitespaceInTags() {
        $template = "Test {{key1}} and {{ key2 }} and {{   key3   }}.";
        $data = [
            'key1' => 'A',
            'key2' => 'B',
            'key3' => 'C'
        ];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("Test A and B and C.", $result);
    }

    public function testRenderWithDotNotationKeys() {
        $template = "Literal: {{ nested.key }}, Deep: {{ nested.user.name }}, Missing: {{ nested.missing.value }}.";
        $data = [
            'nested.key' => 'LiteralSuccess',
            'nested' => [
                'user' => [
                    'name' => 'Alice'
                ]
            ]
        ];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("Literal: LiteralSuccess, Deep: Alice, Missing: .", $result);
    }

    public function testRenderWithStateInterfaceAndDotNotationKeys() {
        $template = "Literal: {{ nested.key }}, Deep: {{ nested.user.name }}, Missing: {{ nested.missing.value || Fallback }}.";
        $state = new State([
            'nested.key' => 'LiteralSuccess',
            'nested' => [
                'user' => [
                    'name' => 'Bob'
                ]
            ]
        ]);

        $result = TemplateRenderer::render($template, $state);
        $this->assertEquals("Literal: LiteralSuccess, Deep: Bob, Missing: Fallback.", $result);
    }

    public function testRenderJsonEncodesArrayValues() {
        $template = "Data: {{ payload }}";
        $data = [
            'payload' => [
                'id' => 1,
                'status' => 'active'
            ]
        ];

        $result = TemplateRenderer::render($template, $data);
        
        $expectedJson = json_encode(['id' => 1, 'status' => 'active'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertEquals("Data: " . $expectedJson, $result);
    }

    public function testRenderReplacesMissingKeysWithEmptyString() {
        $template = "Start {{ missing }} End";
        $data = [];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("Start  End", $result); // Note the double space because {{ missing }} becomes ""
    }

    public function testRenderWithDefaultValues() {
        $template = "Hello {{ missing || Guest }}, age {{ age || 18 }}, role {{ role || 'User' }}, status {{ status || \"Active\" }}.";
        $data = [
            'age' => 25 // 'missing', 'role', 'status' are intentionally left out
        ];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("Hello Guest, age 25, role User, status Active.", $result);
    }

    public function testRenderReplacesNullValuesWithEmptyString() {
        $template = "Null is {{ null_key }}";
        $data = [
            'null_key' => null
        ];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("Null is ", $result);
    }

    public function testRenderReplacesNullValuesWithDefault() {
        $template = "Null is {{ null_key || Fallback }}";
        $data = [
            'null_key' => null
        ];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("Null is Fallback", $result);
    }

    public function testRenderDoesNotReplaceTagsWithoutDoubleBraces() {
        $template = "This is a {single} brace.";
        $data = ['single' => 'value'];

        $result = TemplateRenderer::render($template, $data);
        $this->assertEquals("This is a {single} brace.", $result);
    }
}
