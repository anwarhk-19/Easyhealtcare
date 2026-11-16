<?php

namespace App\Support\Tenancy;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A tenant-scoped model was queried without an active organisation context.');
    }
}
