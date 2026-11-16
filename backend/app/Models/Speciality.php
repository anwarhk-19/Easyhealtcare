<?php

namespace App\Models;

class Speciality extends BaseModel
{
    protected $table = 'specialities';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
