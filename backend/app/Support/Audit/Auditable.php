<?php

namespace App\Support\Audit;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => AuditLogger::log('created', $m, null, $m->auditValues($m->getAttributes())));
        static::updated(function ($m) {
            $changes = $m->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $changes);
            AuditLogger::log('updated', $m, $m->auditValues($old), $m->auditValues($changes));
        });
        static::deleted(fn ($m) => AuditLogger::log('deleted', $m));
    }

    /** Never write encrypted/secret columns into the audit trail in clear text. */
    public function auditValues(array $values): array
    {
        foreach ($this->auditRedact ?? [] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }
        unset($values['password'], $values['remember_token'], $values['two_factor_secret']);

        return $values;
    }
}
