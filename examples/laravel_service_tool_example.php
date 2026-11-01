<?php

namespace App\Services;

use Sapient\Contracts\Tools\ToolInterface;

/**
 * Example: A Laravel Service that is ALSO a Sapient Tool.
 * 
 * Scenario: You have a service that generates invoices. You want the AI to be able to call it.
 * 
 * Usage in Laravel:
 * 1. Register this service in the container as usual.
 * 2. When building the Sapient Pipeline, inject this service into the ToolExecutionStep.
 */
class InvoiceService implements ToolInterface {
    
    // --- Standard Service Logic ---
    
    public function createInvoice(int $userId, float $amount, array $items): int {
        // Logic to create invoice in DB...
        // e.g. Invoice::create([...]);
        return 12345; // Return Invoice ID
    }

    // --- ToolInterface Implementation ---

    public function getName(): string {
        return 'create_invoice';
    }

    public function getDescription(): string {
        return 'Generates a new invoice for a user given their user_id and amount.';
    }

    public function getInstruction(): string {
        return 'Pattern: <create_invoice user_id="123" amount="10.5">Invoice description or items</create_invoice>, Instruction: Generates a new invoice for a user.';
    }

    public function execute(array $args): string {
        // Validate args
        if (!isset($args['user_id']) || !isset($args['amount'])) {
            return "Error: Missing required arguments (user_id, amount).";
        }

        try {
            // Call the internal service method
            $invoiceId = $this->createInvoice(
                (int)$args['user_id'],
                (float)$args['amount'],
                $args['items'] ?? []
            );

            return "Success: Invoice #{$invoiceId} created.";
        } catch (\Exception $e) {
            return "Error creating invoice: " . $e->getMessage();
        }
    }
}

/*
// --- Laravel Controller Example ---

use Sapient\Core\Pipeline;
use Sapient\Steps\Tools\ToolExecutionStep;
use App\Services\InvoiceService;

class AgentController extends Controller {
    public function chat(Request $request, InvoiceService $invoiceService) {
        // ... setup LLM ...
        
        // Inject the Service AS A TOOL
        $toolStep = new ToolExecutionStep([
            new IdentityTool(...),
            $invoiceService // <--- IT WORKS!
        ]);
        
        // ... build pipeline ...
    }
}
*/
