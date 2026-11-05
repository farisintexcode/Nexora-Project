<?php

namespace App\Services\Customers;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Database.md debtors.search_tokens: keyed prefixes, exact identifier/contact lookup. */
class CustomerSearch
{
    public static function isConfigured(): bool
    {
        $key = base64_decode((string) config('dcms.search_key'), true);

        return $key !== false && strlen($key) >= 32;
    }

    public function token(string $kind, string $value): string
    {
        $key = base64_decode((string) config('dcms.search_key'), true);
        if ($key === false || strlen($key) < 32) {
            throw new LogicException('DCMS_SEARCH_KEY must be a separate base64-encoded key of at least 32 bytes.');
        }

        return hash_hmac('sha256', 'v1:'.$kind.':'.$value, $key);
    }

    public function identity(string $value): string
    {
        return mb_strtoupper(preg_replace('/[\s-]+/u', '', trim($value)));
    }

    public function contact(string $kind, string $value): string
    {
        return $kind === 'email' ? mb_strtolower(trim($value)) : preg_replace('/[^0-9+]/', '', $value);
    }

    public function words(string $value): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($value))) ?: []));
    }

    public function names(string $name): array
    {
        $tokens = [];
        foreach ($this->words($name) as $word) {
            for ($length = (int) config('dcms.name_prefix_min', 3); $length <= mb_strlen($word); $length++) {
                $tokens[] = $this->token('name', mb_substr($word, 0, $length));
            }
        }

        return array_values(array_unique($tokens));
    }

    public function apply(Builder $query, string $kind, string $value): Builder
    {
        $value = trim($value);
        if ($value === '') {
            return $query;
        }
        if ($kind === 'reference') {
            return $query->where('customer_reference', $value);
        }
        if ($kind === 'name') {
            $words = $this->words($value);
            if ($words === [] || collect($words)->contains(fn ($word) => mb_strlen($word) < (int) config('dcms.name_prefix_min', 3))) {
                return $query->whereRaw('false');
            }
            foreach ($words as $word) {
                $query->whereJsonContains('search_tokens', $this->token('name', $word));
            }

            return $query;
        }
        if ($kind === 'identifier') {
            return $query->where('identity_fingerprint', $this->token('identity', $this->identity($value)));
        }
        if ($kind === 'contact') {
            return $query->whereHas('contacts', function (Builder $q) use ($value): void {
                $q->whereNull('valid_to')->where(function (Builder $q) use ($value): void {
                    foreach (['telephone', 'mobile', 'fax', 'email'] as $contactKind) {
                        $q->orWhere(fn (Builder $q) => $q->where('kind_code', $contactKind)->where('value_fingerprint', $this->token('contact:'.$contactKind, $this->contact($contactKind, $value))));
                    }
                });
            });
        }

        return $query->whereRaw('false');
    }

    public static function masked(?string $value): string
    {
        return $value ? '••••'.mb_substr($value, -4) : 'Not provided';
    }
}
