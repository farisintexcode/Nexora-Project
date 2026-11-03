<?php

namespace App\Actions\Imports;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ImportColumnMapping;
use App\Models\ImportField;
use App\Models\ImportMappingProfile;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\OutboxEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Imports\ImportTargets;
use App\Services\Imports\MalwareScanner;
use App\Services\Imports\WorkbookReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Plan.md M04 / Module.md M04 / UI14–15,39,57–58 / Database.md import_* + documents. */
class ImportWorkflow
{
    public function __construct(private AuditWriter $audit, private WorkbookReader $reader, private ImportTargets $targets) {}

    public function upload(User $actor, array $input, UploadedFile $file): ImportBatch
    {
        Validator::make($input, ['kind' => ['required', Rule::in(['assignment_batch', 'task'])], 'template_id' => ['required', 'integer'], 'assignment_batch_id' => ['required_if:kind,task', 'nullable', 'integer']])->validate();
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'extensions:xlsx,xls', 'max:'.config('imports.max_kilobytes')]])->validate();
        Gate::forUser($actor)->authorize('create', ImportBatch::class);
        $template = ImportTemplate::query()->where('is_active', true)->where('kind', $input['kind'])->findOrFail($input['template_id']);
        $hash = hash_file('sha256', $file->getRealPath());
        $key = hash('sha256', implode('|', [$input['kind'], $template->id, $input['assignment_batch_id'] ?? '', $hash]));
        $disk = config('imports.disk');
        $bucket = $this->storageBucket($disk);
        if (! is_string($bucket) || $bucket === '') {
            throw ValidationException::withMessages(['file' => 'Configure private file storage before uploading workbooks.']);
        }
        $object = 'imports/quarantine/'.Str::uuid7().'.encrypted';
        $stored = false;
        try {
            return DB::transaction(function () use ($actor, $input, $file, $template, $hash, $key, $disk, $bucket, $object, &$stored): ImportBatch {
                Gate::forUser($actor->fresh())->authorize('create', ImportBatch::class);
                while ($existing = ImportBatch::query()->where('idempotency_key', $key)->first()) {
                    if (! in_array($existing->status, ['failed', 'cancelled'], true)) {
                        return $existing;
                    }
                    // A failed or cancelled attempt gets a separate review on re-upload; previous attempts stay immutable.
                    $key = hash('sha256', $key.'|review-after|'.$existing->public_id);
                }
                if ($input['assignment_batch_id'] ?? null) {
                    $parent = AssignmentBatch::query()->lockForUpdate()->findOrFail($input['assignment_batch_id']);
                    abort_unless($parent->status === BatchStatus::Active, 422);
                }
                $batch = new ImportBatch;
                $batch->forceFill(['kind' => $input['kind'], 'template_id' => $template->id, 'assignment_batch_id' => $input['assignment_batch_id'] ?? null, 'uploaded_by' => $actor->id, 'content_sha256' => $hash, 'idempotency_key' => $key, 'status' => 'uploaded'])->save();
                if (! Storage::disk($disk)->put($object, Crypt::encryptString(file_get_contents($file->getRealPath())), ['visibility' => 'private'])) {
                    throw new RuntimeException('private_storage_failed');
                }
                $stored = true;
                $document = new Document;
                $document->forceFill(['owner_type' => 'import', 'owner_id' => $batch->id, 'uploaded_by' => $actor->id, 'disk' => $disk, 'bucket' => $bucket, 'object_key' => $object, 'original_filename' => Str::limit(preg_replace('/[^\pL\pN._ -]/u', '_', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 255, ''), 'mime_type' => 'application/octet-stream', 'size_bytes' => $file->getSize(), 'sha256' => $hash, 'document_type_code' => 'workbook', 'scan_status' => 'pending'])->save();
                $batch->source_document_id = $document->id;
                $batch->save();
                $this->event($batch, 'scan');
                $this->audit->record($actor, 'import_uploaded', $batch->public_id, ['status' => 'uploaded'], module: 'M04', entityType: 'import');

                return $batch;
            }, 3);
        } catch (\Throwable $error) {
            if ($stored) {
                Storage::disk($disk)->delete($object);
            }
            throw $error;
        }
    }

    public function source(ImportBatch $batch, callable $callback, bool $clean = true): mixed
    {
        $document = Document::query()->findOrFail($batch->source_document_id);
        if ($clean && ! $this->scanCleared($document->scan_status)) {
            throw new RuntimeException('source_not_clean');
        }
        if ($document->owner_type !== 'import' || $document->owner_id !== $batch->id || $document->bucket !== $this->storageBucket($document->disk)) {
            throw new RuntimeException('source_scope_invalid');
        }
        $encrypted = Storage::disk($document->disk)->get($document->object_key);
        if (! is_string($encrypted) || strlen($encrypted) > config('imports.max_kilobytes') * 2048 + 4096) {
            throw new RuntimeException('source_unavailable');
        }
        $bytes = Crypt::decryptString($encrypted);
        if (! hash_equals($document->sha256, hash('sha256', $bytes)) || strlen($bytes) !== $document->size_bytes) {
            throw new RuntimeException('source_integrity_failed');
        }
        $path = tempnam(sys_get_temp_dir(), 'debtops-import-');
        try {
            chmod($path, 0600);
            file_put_contents($path, $bytes);

            return $callback($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function scan(int $id): void
    {
        $this->locked($id, function (ImportBatch $batch): void {
            if (! in_array($batch->status, ['uploaded', 'scanning'], true)) {
                return;
            }
            $actor = User::query()->findOrFail($batch->uploaded_by);
            Gate::forUser($actor)->authorize('map', $batch);
            $batch->status = 'scanning';
            $batch->save();
            try {
                $info = $this->source($batch, function (string $path): array {
                    if ($this->scanRequired()) {
                        app(MalwareScanner::class)->scan($path);
                    }

                    return $this->reader->inspect($path);
                }, false);
                $batch->source()->update(['scan_status' => $this->scanRequired() ? 'clean' : 'skipped']);
                $batch->status = 'mapping';
                $batch->selected_sheet = $info[0]['worksheetName'];
                $batch->header_row_number = 1;
                $batch->error_summary = null;
            } catch (\Throwable $error) {
                $batch->source()->update(['scan_status' => $error->getMessage() === 'malware_detected' ? 'rejected' : 'error']);
                $batch->status = 'failed';
                $batch->error_summary = 'Source scan or workbook validation failed. No records were imported.';
                $batch->completed_at = now();
            }
            $batch->save();
            $this->audit->record($actor, 'import_scan_finished', $batch->public_id, ['status' => $batch->status], module: 'M04', entityType: 'import');
        });
        $this->proposeMapping($id);
    }

    /** Detects mappings for the first sheet straight after the scan; a workbook that cannot be mapped yet stays on the sheet and header form. */
    private function proposeMapping(int $id): void
    {
        $batch = ImportBatch::query()->findOrFail($id);
        if ($batch->status !== 'mapping' || $batch->mappings()->exists()) {
            return;
        }
        try {
            $this->map(User::query()->findOrFail($batch->uploaded_by), $batch, $batch->selected_sheet, $batch->header_row_number);
        } catch (ValidationException|RuntimeException) {
            return;
        }
    }

    /**
     * Fails an upload whose first sheet cannot supply every required column, so the uploader hears straight away.
     *
     * @return string|null why the upload was refused, or null when it can go on to review
     */
    public function rejectionReason(int $id): ?string
    {
        return $this->locked($id, function (ImportBatch $batch): ?string {
            if ($batch->status === 'failed') {
                return $batch->error_summary ?? 'This workbook could not be read.';
            }
            if ($batch->status !== 'mapping') {
                return null;
            }
            $mappings = $batch->mappings()->get();
            $required = $batch->template->schema_definition['required'];
            $mapped = ImportField::query()->whereIn('id', $mappings->where('is_ignored', false)->pluck('field_id')->filter())->pluck('canonical_field');
            $missing = array_values(array_diff($required, $mapped->all()));
            if ($missing === []) {
                return null;
            }
            $reason = $mappings->isEmpty()
                ? 'This workbook could not be read. Put the column headers in row 1 of the first sheet, and remove any formulas.'
                : 'Missing required columns: '.implode(', ', $missing).'.';
            $batch->status = 'failed';
            $batch->error_summary = $reason;
            $batch->completed_at = now();
            $batch->save();
            $this->audit->record(User::query()->findOrFail($batch->uploaded_by), 'import_columns_rejected', $batch->public_id, ['missing' => $missing], module: 'M04', entityType: 'import');

            return $reason;
        });
    }

    public static function normalize(string $header): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower(str_replace("\xEF\xBB\xBF", '', $header))));
    }

    public function map(User $actor, ImportBatch $original, string $sheet, int $header, ?int $profileId = null): void
    {
        $this->locked($original->id, function (ImportBatch $batch) use ($actor, $sheet, $header, $profileId): void {
            Gate::forUser($actor->fresh())->authorize('map', $batch);
            $this->editable($batch);
            $profile = $profileId ? ImportMappingProfile::query()->where('template_id', $batch->template_id)->where('is_active', true)->findOrFail($profileId) : null;
            $rows = $this->source($batch, fn (string $path) => $this->reader->read($path, $sheet, $header));
            $fields = ImportField::query()->whereIn('id', $batch->template->schema_definition['field_ids'])->where('is_active', true)->get();
            $batch->mappings()->delete();
            $batch->rows()->delete();
            $batch->forceFill(['status' => 'mapping', 'selected_sheet' => $sheet, 'header_row_number' => $header, 'mapping_profile_id' => $profile?->id, 'mapping_digest' => null, 'mapping_confirmed_by' => null, 'mapping_confirmed_at' => null, 'input_count' => 0, 'accepted_count' => 0, 'rejected_count' => 0, 'duplicate_count' => 0, 'processed_count' => 0, 'failed_count' => 0])->save();
            $aliases = $this->aliasFields($batch->kind, $fields);
            $suggestions = collect($rows[$header])->map(function (string $label) use ($fields, $aliases): array {
                if (mb_strlen($label) > 255) {
                    throw ValidationException::withMessages(['mapping' => 'A header exceeds 255 characters. Correct the workbook.']);
                }
                $normalized = self::normalize($label);
                $canonical = $fields->filter(fn ($field) => self::normalize($field->canonical_field) === $normalized)->pluck('id');
                $ids = $canonical->merge($aliases[$normalized] ?? [])->unique();
                $match = $ids->count() === 1 ? $ids->first() : null;

                return ['normalized' => $normalized, 'ids' => $ids, 'match' => $match, 'method' => $match ? ($canonical->contains($match) ? 'canonical' : 'alias') : 'unmatched'];
            });
            $exactFields = $suggestions->where('method', 'canonical')->pluck('match');
            $aliasClaims = $suggestions->where('method', 'alias')->countBy('match');
            $proposed = [];
            foreach ($rows[$header] as $ordinal => $label) {
                ['normalized' => $normalized, 'ids' => $ids, 'match' => $match, 'method' => $method] = $suggestions[$ordinal];
                // An exact header outranks an alias; aliases competing for one field are left for the reviewer.
                $contested = $method === 'alias' && ($exactFields->contains($match) || $aliasClaims[$match] > 1);
                if ($contested) {
                    $match = null;
                    $method = 'unmatched';
                }
                $profileRule = $profile?->mapping_definition[$ordinal] ?? null;
                if ($profileRule && ($profileRule['header'] ?? null) === $normalized && ($profileRule['ignore'] || $fields->contains('id', $profileRule['field_id']))) {
                    $match = $profileRule['field_id'];
                    $method = 'profile';
                }
                if ($match && in_array($match, $proposed, true)) {
                    $match = null;
                    $method = 'unmatched';
                }
                if ($match) {
                    $proposed[] = $match;
                }
                $mapping = new ImportColumnMapping;
                $mapping->forceFill(['import_batch_id' => $batch->id, 'source_sheet' => $sheet, 'source_column_index' => $ordinal, 'original_header' => $label, 'normalized_header' => $normalized, 'field_id' => $match, 'match_method' => $method, 'confidence' => $match ? ($method === 'alias' ? 0.9 : 1) : null, 'transform_code' => $profileRule && $method === 'profile' ? $profileRule['transform'] : 'exact', 'is_ignored' => $method === 'profile' && $profileRule['ignore'], 'matched_rule_snapshot' => ['ambiguous' => $ids->count() > 1 || $contested]])->save();
            }
            unset($rows[$header]);
            foreach ($rows as $number => $values) {
                $row = new ImportRow;
                $row->forceFill(['import_batch_id' => $batch->id, 'sheet_name' => $sheet, 'row_number' => $number, 'raw_payload_ciphertext' => $values])->save();
            }
            $batch->input_count = count($rows);
            $batch->save();
        });
    }

    public function preview(User $actor, ImportBatch $original, array $selections): void
    {
        $this->locked($original->id, function (ImportBatch $batch) use ($actor, $selections): void {
            Gate::forUser($actor->fresh())->authorize('map', $batch);
            $this->editable($batch);
            $fields = ImportField::query()->whereIn('id', $batch->template->schema_definition['field_ids'])->where('is_active', true)->get()->keyBy('id');
            Validator::make(['selections' => $selections], [
                'selections' => ['required', 'array', 'max:'.config('imports.max_columns')],
                'selections.*' => ['array:field_id,transform'],
                'selections.*.field_id' => ['required', Rule::in(['ignore', ...$fields->keys()->all()])],
                'selections.*.transform' => ['required', 'string', Rule::in(['exact', 'trim'])],
            ])->validate();
            $mappings = $batch->mappings()->orderBy('source_column_index')->get();
            if ($mappings->isEmpty() || $batch->rows()->count() === 0) {
                throw ValidationException::withMessages(['mapping' => 'Select a sheet and header containing data rows first.']);
            }
            $used = [];
            foreach ($mappings as $mapping) {
                $choice = $selections[$mapping->id] ?? [];
                $fieldId = $choice['field_id'] ?? null;
                $ignore = $fieldId === 'ignore';
                $field = $ignore ? null : $fields->get($fieldId);
                $transform = $choice['transform'] ?? 'exact';
                if ((! $ignore && ! $field) || ! in_array($transform, ['exact', 'trim'], true) || ($field && in_array($field->id, $used, true))) {
                    throw ValidationException::withMessages(['mapping' => 'Resolve every column, ignore unused columns explicitly and map each target only once.']);
                }
                if ($field) {
                    $used[] = $field->id;
                }
            }
            $required = $batch->template->schema_definition['required'];
            if (array_diff($required, $fields->only($used)->pluck('canonical_field')->all())) {
                throw ValidationException::withMessages(['mapping' => 'Map every required target before previewing.']);
            }
            // Clear targets first so swapping two mapped columns respects the unique constraint.
            $batch->mappings()->update(['field_id' => null]);
            $digest = [];
            foreach ($mappings as $mapping) {
                $choice = $selections[$mapping->id];
                $ignore = $choice['field_id'] === 'ignore';
                $newId = $ignore ? null : (int) $choice['field_id'];
                $method = $mapping->field_id === $newId && $mapping->transform_code === ($choice['transform'] ?? 'exact') ? $mapping->match_method : 'manual';
                $mapping->refresh();
                $mapping->forceFill(['field_id' => $newId, 'is_ignored' => $ignore, 'transform_code' => $choice['transform'] ?? 'exact', 'match_method' => $method, 'confidence' => null, 'confirmed_by' => $actor->id, 'confirmed_at' => now()])->save();
                $digest[] = $mapping->only(['source_sheet', 'source_column_index', 'original_header', 'field_id', 'transform_code', 'is_ignored', 'match_method', 'matched_rule_snapshot']);
            }
            $batch->forceFill(['status' => 'validating', 'mapping_confirmed_by' => $actor->id, 'mapping_confirmed_at' => now(), 'mapping_digest' => hash('sha256', json_encode([$batch->template_id, $batch->mapping_profile_id, $digest], JSON_THROW_ON_ERROR)), 'processed_count' => 0, 'failed_count' => 0])->save();
            $seen = [];
            $customerNames = [];
            foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
                $values = [];
                foreach ($mappings as $mapping) {
                    if (! $mapping->is_ignored) {
                        $value = $row->raw_payload_ciphertext[$mapping->source_column_index] ?? '';
                        $values[$fields[$mapping->field_id]->canonical_field] = $mapping->transform_code === 'trim' ? trim($value) : $value;
                    }
                }
                // Preview rolls each row back, so a later row repeating a new customer borrows the name its earlier row will have saved.
                $customerReference = trim($values['customer_reference'] ?? '');
                if ($batch->kind === 'task' && $customerReference !== '') {
                    if (trim($values['customer_name'] ?? '') !== '') {
                        $customerNames[$customerReference] = $values['customer_name'];
                    } elseif (isset($customerNames[$customerReference])) {
                        $values['customer_name'] = $customerNames[$customerReference];
                    }
                }
                $identity = trim($values[$batch->template->schema_definition['identity']] ?? '');
                $row->normalized_payload_ciphertext = null;
                $row->validation_errors = [];
                $taskNumber = $batch->kind === 'task' ? Task::numberFromId(trim($values['task_id'] ?? '')) : null;
                if (($identity !== '' && isset($seen[$identity])) || ($taskNumber !== null && isset($seen['task:'.$taskNumber]))) {
                    $row->state = 'duplicate';
                    $row->validation_errors = [['field' => 'reference', 'code' => 'duplicate_in_workbook']];
                } else {
                    if ($identity !== '') {
                        $seen[$identity] = true;
                    }
                    if ($taskNumber !== null) {
                        $seen['task:'.$taskNumber] = true;
                    }
                    try {
                        $this->validateValues($fields, $values);
                        $row->normalized_payload_ciphertext = $this->targets->prepare($actor, $batch, $values);
                        $row->state = 'accepted';
                    } catch (ValidationException $error) {
                        $row->state = 'rejected';
                        // Only fixed snake_case codes are stored, so a writer's message can never carry workbook values into the error report.
                        $row->validation_errors = collect($error->errors())->map(fn (array $messages, string $field): array => ['field' => preg_replace('/[^a-z0-9_.]/i', '', $field), 'code' => preg_match('/^[a-z_]+$/', $messages[0]) ? $messages[0] : 'invalid_value'])->values()->all();
                    } catch (AuthorizationException) {
                        $row->state = 'rejected';
                        $row->validation_errors = [['field' => 'permission', 'code' => 'target_permission_required']];
                    }
                }
                $row->save();
            }
            $counts = $batch->rows()->selectRaw('state,count(*) as total')->groupBy('state')->pluck('total', 'state');
            $batch->forceFill(['accepted_count' => (int) ($counts['accepted'] ?? 0), 'rejected_count' => (int) ($counts['rejected'] ?? 0), 'duplicate_count' => (int) ($counts['duplicate'] ?? 0), 'status' => 'ready'])->save();
            $this->audit->record($actor, 'import_mapping_reviewed', $batch->public_id, ['status' => 'ready'], module: 'M04', entityType: 'import');
        });
    }

    public function profile(User $actor, ImportBatch $original, string $name): ImportMappingProfile
    {
        return $this->locked($original->id, function (ImportBatch $batch) use ($actor, $name): ImportMappingProfile {
            Gate::forUser($actor->fresh())->authorize('map', $batch);
            if ($batch->status !== 'ready' || ! $batch->mapping_digest) {
                throw ValidationException::withMessages(['mapping' => 'Review the mapping and preview before saving a profile.']);
            }
            Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:160']])->validate();
            $code = Str::slug($name);
            if ($code === '' || strlen($code) > 100) {
                throw ValidationException::withMessages(['profileName' => 'Use a profile name containing letters and numbers, up to 100 characters.']);
            }
            $definitions = [];
            foreach ($batch->mappings()->get() as $mapping) {
                $definitions[$mapping->source_column_index] = ['header' => $mapping->normalized_header, 'field_id' => $mapping->field_id, 'ignore' => $mapping->is_ignored, 'transform' => $mapping->transform_code];
            }
            $version = (int) ImportMappingProfile::query()->where('code', $code)->max('version') + 1;
            $profile = new ImportMappingProfile;
            $profile->forceFill(['template_id' => $batch->template_id, 'code' => $code, 'version' => $version, 'name' => $name, 'mapping_definition' => $definitions, 'created_by' => $actor->id])->save();
            $this->audit->record($actor, 'import_profile_version_created', $profile->public_id, ['status' => 'active'], module: 'M04', entityType: 'import_profile');

            return $profile;
        });
    }

    public function confirm(User $actor, ImportBatch $original, string $digest): void
    {
        $this->locked($original->id, function (ImportBatch $batch) use ($actor, $digest): void {
            Gate::forUser($actor->fresh())->authorize('confirm', $batch);
            if ($batch->confirmed_at) {
                return;
            }
            if ($batch->status !== 'ready' || ! $batch->mapping_digest || ! hash_equals($batch->mapping_digest, $digest) || $batch->accepted_count === 0) {
                throw ValidationException::withMessages(['confirmation' => 'Reload and review the current preview before committing accepted rows.']);
            }
            if (! $this->scanCleared($batch->source?->scan_status)) {
                throw ValidationException::withMessages(['confirmation' => 'The source must have a clean scan before confirmation.']);
            }
            $batch->forceFill(['confirmed_by' => $actor->id, 'confirmed_at' => now(), 'status' => 'processing', 'started_at' => now()])->save();
            $this->event($batch, 'commit');
            $this->audit->record($actor, 'import_confirmed', $batch->public_id, ['status' => 'processing'], module: 'M04', entityType: 'import');
        });
    }

    public function cancel(User $actor, ImportBatch $original): void
    {
        $this->locked($original->id, function (ImportBatch $batch) use ($actor): void {
            Gate::forUser($actor->fresh())->authorize('map', $batch);
            if (in_array($batch->status, ['completed', 'failed', 'cancelled'], true)) {
                return;
            }
            $remaining = $batch->rows()->where('state', 'accepted')->get();
            foreach ($remaining as $row) {
                $row->state = 'failed';
                $row->validation_errors = [['field' => 'row', 'code' => 'cancelled']];
                $row->save();
            }
            $batch->failed_count += $remaining->count();
            $batch->status = 'cancelled';
            $batch->completed_at = now();
            $batch->save();
            $this->audit->record($actor, 'import_cancelled', $batch->public_id, ['status' => 'cancelled'], module: 'M04', entityType: 'import');
        }, allowArchived: true);
    }

    public function retry(User $actor, ImportBatch $original): void
    {
        $this->locked($original->id, function (ImportBatch $batch) use ($actor): void {
            Gate::forUser($actor->fresh())->authorize('confirm', $batch);
            if (! in_array($batch->status, ['failed', 'completed'], true)) {
                throw ValidationException::withMessages(['retry' => 'Only finished jobs can be retried.']);
            }
            if (! $batch->confirmed_at) {
                Gate::forUser($actor)->authorize('map', $batch);
                if ($batch->source?->scan_status === 'rejected') {
                    throw ValidationException::withMessages(['retry' => 'Rejected sources cannot be retried. Upload a clean workbook.']);
                }
                $batch->status = 'uploaded';
                $batch->completed_at = null;
                $batch->error_summary = null;
                $batch->save();
                $this->event($batch, 'scan');

                return;
            }
            $batch->rows()->where('state', 'failed')->update(['state' => 'accepted', 'validation_errors' => '[]']);
            $batch->failed_count = 0;
            $batch->status = 'processing';
            $batch->completed_at = null;
            $batch->error_summary = null;
            $batch->save();
            $this->event($batch, 'commit');
            $this->audit->record($actor, 'import_retry_requested', $batch->public_id, ['status' => 'processing'], module: 'M04', entityType: 'import');
        });
    }

    public function process(int $id): void
    {
        $batch = ImportBatch::query()->findOrFail($id);
        if ($batch->status !== 'processing') {
            return;
        }
        $ids = $batch->rows()->where('state', 'accepted')->orderBy('id')->pluck('id');
        foreach ($ids as $rowId) {
            $this->locked($id, function (ImportBatch $batch) use ($rowId): void {
                if ($batch->status !== 'processing') {
                    return;
                }
                $row = $batch->rows()->lockForUpdate()->findOrFail($rowId);
                if ($row->state !== 'accepted') {
                    return;
                }
                $actor = User::query()->findOrFail($batch->confirmed_by);
                try {
                    DB::transaction(function () use ($batch, $row, $actor): void {
                        Gate::forUser($actor)->authorize('confirm', $batch);
                        if (! $this->scanCleared($batch->source?->scan_status)) {
                            throw new RuntimeException('source_not_clean');
                        }
                        $target = $this->targets->write($actor, $batch, $row->normalized_payload_ciphertext, $row);
                        $row->state = 'processed';
                        $row->processed_at = now();
                        $row->validation_errors = [];
                        $row->{$batch->kind === 'task' ? 'target_task_id' : 'target_assignment_batch_id'} = $target->id;
                        $row->save();
                    });
                    $batch->processed_count++;
                } catch (\Throwable $error) {
                    $row->refresh();
                    $row->state = 'failed';
                    $code = $error instanceof ValidationException ? 'target_changed_or_invalid' : ($error instanceof AuthorizationException ? 'permission_revoked' : 'processing_error');
                    $row->validation_errors = [['field' => 'row', 'code' => $code]];
                    $row->save();
                    $batch->failed_count++;
                }
                $batch->save();
            });
        }
        $this->locked($id, function (ImportBatch $batch): void {
            if ($batch->status !== 'processing' || $batch->rows()->where('state', 'accepted')->exists()) {
                return;
            }
            $batch->status = $batch->failed_count > 0 ? 'failed' : 'completed';
            $batch->completed_at = now();
            $batch->save();
            $actor = User::query()->find($batch->confirmed_by);
            $this->audit->record($actor, 'import_reconciled', $batch->public_id, ['status' => $batch->status], module: 'M04', entityType: 'import');
            if ($actor && Gate::forUser($actor)->allows('view', $batch)) {
                DB::table('notifications')->insert(['id' => (string) Str::uuid7(), 'type' => 'import_reconciled', 'notifiable_type' => 'user', 'notifiable_id' => $actor->id, 'data' => json_encode(['import_id' => $batch->public_id, 'status' => $batch->status, 'processed' => $batch->processed_count, 'failed' => $batch->failed_count]), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function editable(ImportBatch $batch): void
    {
        if ($batch->confirmed_at || ! in_array($batch->status, ['mapping', 'ready'], true)) {
            throw ValidationException::withMessages(['mapping' => 'This import is not editable.']);
        }
    }

    public function interrupted(int $id): void
    {
        $this->locked($id, function (ImportBatch $batch): void {
            if (in_array($batch->status, ['completed', 'cancelled', 'failed'], true)) {
                return;
            }
            $remaining = $batch->rows()->where('state', 'accepted')->get();
            foreach ($remaining as $row) {
                $row->state = 'failed';
                $row->validation_errors = [['field' => 'row', 'code' => 'worker_interrupted']];
                $row->save();
            }
            $batch->failed_count += $remaining->count();
            $batch->status = 'failed';
            $batch->completed_at = now();
            $batch->error_summary = 'Background processing stopped. Review access and services, then retry failed work.';
            $batch->save();
            $this->audit->record(null, 'import_worker_interrupted', $batch->public_id, ['status' => 'failed'], module: 'M04', entityType: 'import');
        }, allowArchived: true);
    }

    /**
     * Maps each normalized alias from config/imports.php to the fields it can suggest.
     *
     * @param  Collection<int, ImportField>  $fields
     * @return array<string, array<int, int>>
     */
    private function aliasFields(string $kind, Collection $fields): array
    {
        $aliases = [];
        foreach ($fields as $field) {
            foreach (config("imports.aliases.{$kind}.{$field->canonical_field}", []) as $alias) {
                $aliases[self::normalize($alias)][] = $field->id;
            }
        }

        return $aliases;
    }

    /**
     * Checks required values and data types for the mapped fields before the target writer runs.
     *
     * @param  Collection<int, ImportField>  $fields
     * @param  array<string, string>  $values
     */
    private function validateValues(Collection $fields, array $values): void
    {
        $errors = [];
        foreach ($fields as $field) {
            $name = $field->canonical_field;
            if (! array_key_exists($name, $values)) {
                continue;
            }
            $value = trim((string) $values[$name]);
            if ($value === '') {
                if ($field->validation_schema['required'] ?? false) {
                    $errors[$name] = 'required';
                }
            } elseif ($field->data_type === 'date' && \DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) {
                $errors[$name] = 'invalid_date';
            } elseif (mb_strlen($value) > 5000) {
                $errors[$name] = 'too_long';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function storageBucket(string $disk): ?string
    {
        return match ($disk) {
            's3' => config('filesystems.disks.s3.bucket'),
            'local' => 'local',
            default => null,
        };
    }

    /** Production always scans, whatever the environment configuration says. */
    private function scanRequired(): bool
    {
        return config('imports.scan_required') || app()->isProduction();
    }

    private function scanCleared(?string $status): bool
    {
        return $status === 'clean' || ($status === 'skipped' && ! $this->scanRequired());
    }

    private function locked(int $id, callable $callback, bool $allowArchived = false): mixed
    {
        return DB::transaction(function () use ($id, $callback, $allowArchived): mixed {
            $original = ImportBatch::query()->findOrFail($id);
            if ($original->assignment_batch_id) {
                $parent = AssignmentBatch::query()->lockForUpdate()->findOrFail($original->assignment_batch_id);
                if ($parent->status !== BatchStatus::Active && ! $allowArchived) {
                    throw ValidationException::withMessages(['batch' => 'The parent assignment is archived.']);
                }
            }

            return $callback(ImportBatch::query()->with(['template', 'source'])->lockForUpdate()->findOrFail($id));
        }, 3);
    }

    private function event(ImportBatch $batch, string $phase): void
    {
        $event = new OutboxEvent;
        $event->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'import.'.$phase, 'aggregate_type' => 'import', 'aggregate_id' => $batch->id, 'payload' => ['phase' => $phase], 'available_at' => now()])->save();
    }
}
