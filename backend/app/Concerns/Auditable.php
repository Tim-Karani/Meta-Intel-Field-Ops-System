<?php

namespace App\Concerns;

use App\Domain\Audit\AuditService;
use Illuminate\Support\Arr;

/** Records created/updated/deleted events with before/after values. Sensitive fields are redacted by AuditService. */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => AuditService::record('created', $m->getTable(), $m, null, $m->getAttributes()));

        static::updated(function ($m) {
            $changes = Arr::except($m->getChanges(), ['updated_at']);
            if ($changes !== []) {
                AuditService::record('updated', $m->getTable(), $m, Arr::only($m->getOriginal(), array_keys($changes)), $changes);
            }
        });

        static::deleted(fn ($m) => AuditService::record('deleted', $m->getTable(), $m, $m->getAttributes(), null));
    }
}
