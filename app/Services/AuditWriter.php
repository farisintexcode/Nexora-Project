<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/** M12 / Database.md Audit trail. Call within the business transaction for sensitive writes. */
class AuditWriter
{
    public function record(?User $actor, string $action, ?string $entityId = null, array $metadata = [], ?string $reason = null, string $module = 'M01', string $entityType = 'user', array $previous = []): AuditLog
    {
        $audit = new AuditLog;
        $audit->actor_id = $actor?->id;
        $audit->module = $module;
        $audit->action = $action;
        $audit->entity_type = $entityType;
        $audit->entity_id = $entityId ?? $actor?->public_id;
        $allowed = array_flip(['basis', 'customer_id', 'evidence_fingerprint', 'status', 'portal', 'login_fingerprint', 'terms_version', 'role', 'roles', 'permissions', 'changed_fields', 'party_type', 'category_ids', 'duplicate_reviewed', 'is_active', 'is_rider']);
        $audit->new_values_redacted = array_intersect_key($metadata, $allowed);
        $audit->previous_values_redacted = $previous === [] ? null : array_intersect_key($previous, $allowed);
        $audit->ip_address = app()->runningInConsole() ? null : request()->ip();
        $audit->user_agent = app()->runningInConsole() ? null : Str::limit(request()->userAgent() ?? '', 1024, '');
        $audit->occurred_at = now();
        $audit->correlation_id = (string) Str::uuid7();
        $audit->reason = $reason;
        $audit->save();

        return $audit;
    }
}
