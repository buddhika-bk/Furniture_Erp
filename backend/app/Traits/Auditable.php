<?php
namespace App\Traits;

use App\Models\AuditLog;

/** Writes old/new values for create/update/delete to audit_logs (spec sections 15-16). */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => self::writeAudit('created', $m, null, $m->getAttributes()));
        static::updated(function ($m) {
            $changes = $m->getChanges();
            unset($changes['updated_at']);
            if (!$changes) return;
            self::writeAudit('updated', $m, array_intersect_key($m->getOriginal(), $changes), $changes);
        });
        static::deleted(fn ($m) => self::writeAudit('deleted', $m, $m->getOriginal(), null));
    }

    protected static function writeAudit(string $action, $m, ?array $old, ?array $new): void
    {
        foreach (['password', 'api_token'] as $secret) {
            if ($old) unset($old[$secret]);
            if ($new) unset($new[$secret]);
        }
        AuditLog::create([
            'user_id' => auth_user_id(),
            'action' => $action,
            'auditable_type' => class_basename($m),
            'auditable_id' => $m->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => request()?->ip(),
        ]);
    }
}
