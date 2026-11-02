<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Customer is the business label; Database.md retains Debtor/debtors. */
class Debtor extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['display_name_ciphertext', 'identity_ciphertext', 'identity_fingerprint', 'search_tokens'];

    protected function casts(): array
    {
        return ['display_name_ciphertext' => 'encrypted', 'identity_ciphertext' => 'encrypted', 'search_tokens' => 'array'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function revision(): string
    {
        return hash('sha256', json_encode([
            [$this->id, $this->customer_reference, $this->party_type, $this->status ?? 'active',
                $this->getRawOriginal('display_name_ciphertext'), $this->getRawOriginal('identity_ciphertext'),
                $this->identity_fingerprint, $this->nationality_code, $this->verification_status ?? 'unverified',
                $this->search_tokens, $this->updated_at?->format('Y-m-d H:i:s.uP')],
            $this->contacts()->selectRaw('count(*) as total, max(id) as latest, max(updated_at) as changed_at')->toBase()->first(),
            $this->addresses()->selectRaw('count(*) as total, max(id) as latest, max(updated_at) as changed_at')->toBase()->first(),
        ], JSON_THROW_ON_ERROR));
    }
}
