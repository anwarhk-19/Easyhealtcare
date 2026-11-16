<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class ConsultationAddendum extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'consultation_addenda';

    protected function casts(): array
    {
        return ['content' => 'encrypted'];
    }

    protected $auditRedact = ['content'];
}
