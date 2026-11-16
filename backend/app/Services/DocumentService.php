<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Private document storage: tenant-prefixed paths, checksums, MIME allow-list, and short-lived download URLs. */
class DocumentService
{
    public const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/dicom'];

    public function store(Patient $patient, UploadedFile $file, string $category, string $title, ?string $branchId = null, array $extra = []): PatientDocument
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new BusinessRuleException('FILE_TYPE_NOT_ALLOWED', 'Only PDF and image files can be uploaded.');
        }
        $disk = config('clinic.documents_disk');
        $path = sprintf('orgs/%s/patients/%s/%s/%s.%s', $patient->organization_id, $patient->id, now()->format('Y/m'), Str::ulid(), $file->guessExtension() ?: 'bin');
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        return PatientDocument::create($extra + [
            'patient_id' => $patient->id,
            'branch_id' => $branchId,
            'category' => $category,
            'title' => $title,
            'file_path' => $path,
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            // A ClamAV scan job flips this to clean/infected in production; local disks are treated as clean.
            'scan_status' => config('clinic.antivirus_enabled') ? 'pending' : 'clean',
            'is_sensitive' => $category === 'clinical_photo',
            'uploaded_by' => auth()->id(),
        ]);
    }

    /** Returns a temporary URL on S3, or streams locally. Callers must authorise first. */
    public function download(PatientDocument $doc)
    {
        if ($doc->scan_status !== 'clean') {
            throw BusinessRuleException::conflict('FILE_NOT_SCANNED', 'This file is still being scanned.');
        }
        $disk = Storage::disk(config('clinic.documents_disk'));
        if (config('filesystems.disks.'.config('clinic.documents_disk').'.driver') === 's3') {
            return redirect()->away($disk->temporaryUrl($doc->file_path, now()->addMinutes(5)));
        }

        return $disk->download($doc->file_path, Str::slug($doc->title).'.'.pathinfo($doc->file_path, PATHINFO_EXTENSION));
    }
}
