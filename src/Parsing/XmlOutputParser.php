<?php

namespace Sapient\Parsing;

use Sapient\Contracts\Core\OutputParserInterface;

class XmlOutputParser implements OutputParserInterface {
    /** @var string[] */
    private $registeredTags = [];

    /**
     * @param string[] $registeredTags List of tag names to look for (e.g. ['memorize', 'log_event'])
     */
    public function __construct(array $registeredTags) {
        $this->registeredTags = $registeredTags;
    }

    public function parse(string $response): array {
        $actions = [];
        $thoughts = [];
        $cleanedResponse = preg_replace('/^```xml\s*|\s*```$/', '', $response);
        $thoughtPattern = '/<thought>(.*?)<\/thought>/s';

        if (preg_match_all($thoughtPattern, $cleanedResponse, $thoughtMatches)) {
            foreach ($thoughtMatches[1] as $t) {
                $thoughts[] = trim($t);
            }
            // Strip thoughts from response so they don't appear in the standard text output
            $cleanedResponse = preg_replace($thoughtPattern, '', $cleanedResponse);
        }
        
        foreach ($this->registeredTags as $tagName) {
            // Regex Breakdown:
            // <TAG                         Match start tag
            // (                             Capture Group 1: Attributes and Closing
            //   (?:\s+                      Attributes start with space
            //     (?:                       Attribute Loop
            //       (?:\w+)                 Attribute Key
            //       (?:                     Value Part (Optional for boolean)
            //         \s*=\s*               Equals with optional space
            //         (?:                   Value Variants
            //           "[^"\\\\]*(?:\\\\.[^"\\\\]*)*"  |  Double Quoted String (handles escaped quotes)
            //           \'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\' |  Single Quoted String
            //           [^\s>]+             Unquoted (risky but possible)
            //         )
            //       )?
            //     )+
            //   )?                          End Attributes
            //   \s*                         Optional space
            //   (?:/>|>(.*?)<\/\1>)         Self-Closing OR Content + Closing Tag (Capture Group 2 for Content)
            // )
            // s modifier: dot matches newline
            
            // Simplified approach: Match the TAG structure loosely, then parse attributes
            // Match: <TAG (attributes) > (content) </TAG> OR <TAG (attributes) />
            $pattern = '/<(' . preg_quote($tagName) . ')([^>]*)(\/>|>(.*?)<\/\1>)/s';
            
            if (preg_match_all($pattern, $cleanedResponse, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $fullMatch = $match[0];
                    $tagNameFound = $match[1];
                    $attrString = $match[2] ?? '';
                    $isSelfClosing = strpos($match[3], '/>') === 0;
                    $content = $isSelfClosing ? '' : ($match[4] ?? '');
                    
                    // Parse attributes with robust regex
                    $args = [];
                    if (!empty($attrString)) {
                        // Match key="value" or key='value' or key
                        if (preg_match_all('/(\w+)(?:\s*=\s*(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|([^\s>]+)))?/', $attrString, $attrMatches, PREG_SET_ORDER)) {
                            foreach ($attrMatches as $am) {
                                $key = $am[1];
                                $val = '';
                                
                                if (isset($am[2]) && $am[2] !== '') {
                                    $val = $am[2]; // Double quoted
                                } elseif (isset($am[3]) && $am[3] !== '') {
                                    $val = $am[3]; // Single quoted
                                } elseif (isset($am[4]) && $am[4] !== '') {
                                    $val = $am[4]; // Unquoted
                                }
                                
                                // Unescape quotes
                                $val = stripslashes($val);
                                $args[$key] = $val;
                            }
                        }
                    }
                    
                    // Add content to args as 'value' and 'content' for flexibility
                    $content = trim($content);
                    $args['value'] = $content;
                    $args['content'] = $content; 
                    
                    $actions[] = [
                        'tool' => $tagNameFound,
                        'args' => $args
                    ];
                    
                    // Strip the exact tag match from the response ONCE
                    $pos = strpos($cleanedResponse, $fullMatch);
                    if ($pos !== false) {
                        $cleanedResponse = substr_replace($cleanedResponse, '', $pos, strlen($fullMatch));
                    }
                }
            }
        }

        return [
            'actions' => $actions,
            'thoughts' => $thoughts,
            'response' => trim($cleanedResponse)
        ];
    }
}
