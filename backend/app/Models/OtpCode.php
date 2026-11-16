<?php

namespace App\Models;

class OtpCode extends BaseModel
{
    protected $table = 'otp_codes';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
