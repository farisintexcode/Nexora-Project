<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** M06 / Database.md canonical execution and submission history. */
class CollectionActivity extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['notes_ciphertext', 'tps_contact_ciphertext', 'person_contacted_ciphertext', 'site_address_ciphertext', 'visit_remarks_ciphertext', 'collection_remarks_ciphertext', 'customer_remarks_ciphertext', 'backdating_reason_ciphertext', 'call_details_ciphertext'];

    protected function casts(): array
    {
        return ['paid_amount_ciphertext' => 'encrypted', 'call_details_ciphertext' => 'encrypted:array', 'occurred_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'notes_ciphertext' => 'encrypted', 'tps_contact_ciphertext' => 'encrypted', 'person_contacted_ciphertext' => 'encrypted', 'site_address_ciphertext' => 'encrypted', 'visit_remarks_ciphertext' => 'encrypted', 'collection_remarks_ciphertext' => 'encrypted', 'customer_remarks_ciphertext' => 'encrypted', 'backdating_reason_ciphertext' => 'encrypted', 'business_applicable' => 'boolean', 'signboard_available' => 'boolean'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'customer_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'activity_id');
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(PropertyInspection::class, 'activity_id')->latestOfMany();
    }
}
