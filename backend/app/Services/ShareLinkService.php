<?php

namespace App\Services;

use App\Models\ShareLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Expiring, view-limited links for patients. Only the SHA-256 of the token is stored. */
class ShareLinkService
{
    public function create(Model $shareable, string $patientId, int $hours = 72): array
    {
        $token = Str::random(48);
        $link = ShareLink::create([
            'patient_id' => $patientId,
            'shareable_type' => class_basename($shareable),
            'shareable_id' => $shareable->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours($hours),
            'created_by' => auth()->id(),
        ]);

        return ['link' => $link, 'token' => $token, 'url' => rtrim(config('app.url'), '/').'/api/public/v1/s/'.$token];
    }
}
