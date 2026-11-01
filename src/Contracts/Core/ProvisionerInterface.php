<?php

namespace Sapient\Contracts\Core;

use Sapient\Memory\Identity;

interface ProvisionerInterface {
    /**
     * Rebuilds/Provisions the model based on the provided Identity state.
     * @param Identity $identity The identity instance to build from.
     * @return array ['success' => bool, 'message' => string]
     */
    public function provision(Identity $identity): array;
}
