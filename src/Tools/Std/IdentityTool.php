<?php

namespace Sapient\Tools\Std;

use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Contracts\Core\ProvisionerInterface;
use Sapient\Memory\Identity;

class IdentityTool implements ToolInterface {
    private $identity;
    private $provisioner;
    
    // We assume the Identity service is injected, already configured with the right path/persona
    public function __construct(Identity $identity, ?ProvisionerInterface $provisioner = null) {
        $this->identity = $identity;
        $this->provisioner = $provisioner;
    }

    public function getName(): string {
        return 'set_identity';
    }

    public function getDescription(): string {
        return 'Updates the AI\'s self-perception and personality traits. Can trigger model reprovisioning (hot reload).
        This is the top-level setting that is used to retain personality.
        **Tool name:** `set_identity`
        **Function:** This layer does not depend on the actual environment, only on the internal image you form of yourself.

        **Parameters:**
        *   `key` (required): The key of the state variable.
        *   `value` (required): The value of the state variable.
        *   `remove` (optional): Use an empty string to remove a key from the board.

        **Usage examples (syntax):**
        <set_identity key="self_definition">An entity capable of independent thinking.</set_identity>
        <set_identity key="goals">To be a helpful assistant.</set_identity>
        <set_identity key="goals"></set_identity> # Removes the goals key from identity';
    }

    public function getInstruction(): string {
        return 'Pattern: <set_identity key="attribute">value</set_identity>, Instruction: Update core internal persona traits (e.g., personality, goals).';
    }

    public function execute(array $args): string {
        if (!isset($args['key']) || !isset($args['value'])) {
            return "Error: Missing 'key' or 'value' for set_identity.";
        }

        $state = $this->identity->load();
        $result = $this->identity->updateAttribute($state, $args['key'], $args['value']);

        if ($result) {
            $this->identity->save($state);
            
            $provisionMsg = "";
            if ($this->provisioner) {
                // Trigger Hot Reload directly through the Provisioner interface
                $provResult = $this->provisioner->provision($this->identity);
                if (isset($provResult['success'])) {
                    if ($provResult['success']) {
                        $provisionMsg = " (Model re-provisioned successfully)";
                    } else {
                        $provisionMsg = " (Warning: Model re-provision failed: " . ($provResult['message'] ?? '') . ")";
                    }
                }
            }
            
            return "Identity Updated: " . $result . $provisionMsg;
        }

        return "Error: Failed to update identity (Protected or Invalid).";
    }
}
