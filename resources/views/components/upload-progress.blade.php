@props(['label' => 'Uploading…'])
{{-- Animated upload bar. Place inside an element whose x-data has `uploading` and `progress`, set from Livewire's livewire-upload-* events. --}}
<div x-show="uploading" x-cloak role="status" aria-live="polite" {{ $attributes->class(['upload-progress-wrap']) }}>
    <div class="flex items-center justify-between gap-3 text-xs font-semibold"><span class="flex items-center gap-2"><span class="upload-spinner" aria-hidden="true"></span>{{ $label }}</span><span class="tabular-nums" x-text="progress + '%'"></span></div>
    <div class="upload-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progress"><div class="upload-progress-bar" :style="`width: ${Math.max(progress, 4)}%`"></div></div>
</div>
