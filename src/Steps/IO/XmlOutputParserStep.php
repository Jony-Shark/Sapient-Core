<?php

namespace Sapient\Steps\IO;

use Sapient\Contracts\Core\StepInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Tools\ToolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class XmlOutputParserStep implements StepInterface {
    /** @var string[] */
    private $registeredTags = [];
    private $logger;

    /**
     * @param string[] $registeredTags List of tag names to look for (e.g. ['memorize', 'log_event'])
     */
    public function __construct(array $registeredTags, ?LoggerInterface $logger = null) {
        $this->registeredTags = $registeredTags;
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string {
        return 'XmlOutputParser';
    }

    public function process(StateInterface $state): StateInterface {
        $response = $state->get('last_response');
        if (empty($response)) {
            return $state;
        }

        $actions = [];

        foreach ($this->registeredTags as $tagName) {
            // Regex to find <tag key="value">Content</tag>
            // Supports attributes and content.
            $pattern = '/<(' . preg_quote($tagName) . ')(?:(?:\s+([^>]+))?)>(.*?)<\/\1>/s';
            
            if (preg_match_all($pattern, $response, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $fullMatch = $match[0];
                    $tagNameFound = $match[1];
                    $attrString = $match[2] ?? '';
                    $content = trim($match[3] ?? '');
                    
                    // Parse attributes
                    $args = [];
                    if (!empty($attrString)) {
                        if (preg_match_all('/(\w+)="([^"]+)"/', $attrString, $attrMatches, PREG_SET_ORDER)) {
                            foreach ($attrMatches as $am) {
                                $args[$am[1]] = $am[2];
                            }
                        }
                    }
                    
                    // Add content to args as 'value'
                    $args['value'] = $content;
                    $args['content'] = $content; 
                    
                    $actions[] = [
                        'tool' => $tagNameFound,
                        'args' => $args
                    ];
                    
                    // Strip the tag from the response so it doesn't show up in the chat text
                    // We use str_replace for safety if multiple identical tags exist
                    $response = str_replace($fullMatch, '', $response);
                }
            }
        }
        
        // Update cleaned response in State
        $state->set('last_response', trim($response));
        
        // Save pending actions to state
        if (!empty($actions)) {
            $state->set('pending_actions', $actions);
            // Also append a debug note to history? maybe not.
            $this->logger->debug("Parser found " . count($actions) . " actions.");
        }
        
        return $state;
    }
}
