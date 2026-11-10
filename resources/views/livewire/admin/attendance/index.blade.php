<section class="hr-page grid min-w-0 gap-6">
<x-feedback /><x-page-header title="Attendance" eyebrow="People & operations" description="Schedule-aware attendance, immutable punches and independent corrections.">
<x-slot:actions>@can('configure',\App\Models\Attendance::class)<a class="btn btn-primary" href="{{ route('admin.attendance.configuration') }}"><x-icon name="settings" />Schedules & policies</a>@endcan</x-slot:actions>
</x-page-header>
<x-filter-bar name="attendance" :count="(int)($search !== '') + (int)($rider !== '') + (int)($status !== '')">
<x-date-range name="service-date" label="Service date" from="from" until="until" />
<x-select name="rider" label="Rider" wire:model="rider"><option value="">All accessible Riders</option>@foreach($riders as $option)<option value="{{ $option->public_id }}">{{ $option->staff_no }} - {{ $option->full_name }}</option>@endforeach</x-select>
<x-select name="status" label="Attendance status" wire:model="status"><option value="">All statuses</option>@foreach(\App\Services\Attendance\AttendanceStatus::LABELS as $key=>$text)<option value="{{ $key }}">{{ $text }}</option>@endforeach</x-select>
<x-slot:advanced><x-input name="search" label="Rider name or staff number" wire:model="search" placeholder="Starts with..." /></x-slot:advanced>
</x-filter-bar>
@if($filterError)<p role="alert" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950">{{ $filterError }}</p>@endif
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">@foreach(['present','half_day','absent','incomplete','leave','needs_review'] as $key)<button type="button" class="surface p-4 text-left hover:border-brand-primary" wire:click="selectStatus('{{ $key }}')"><p class="text-xs text-ink-600">{{ \App\Services\Attendance\AttendanceStatus::LABELS[$key] }}</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $totals[$key] ?? 0 }}</p></button>@endforeach</div>
<div class="flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-ink-500">Same filtered calendar and punch records · {{ $from }}–{{ $until }} · {{ $asOf->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s') }} MYT</p>
@if(!$filterError) @can('export',\App\Models\Attendance::class)<div class="flex flex-wrap gap-2"><a class="btn btn-secondary" href="{{ route('admin.attendance.export',['format'=>'csv']) }}"><x-icon name="upload" class="rotate-180" />Export CSV</a><a class="btn btn-secondary" href="{{ route('admin.attendance.export',['format'=>'print']) }}" target="_blank" rel="noopener"><x-icon name="clipboard" />Print / Save PDF</a></div>@endcan @endif</div>
<div wire:loading.delay role="status" class="rounded-xl bg-brand-soft p-3 text-sm">Updating attendance…</div>
<x-attendance-rows :rows="$rows" admin />{{ $rows->links() }}
</section>
