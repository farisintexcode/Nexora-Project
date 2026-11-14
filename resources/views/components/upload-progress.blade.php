@props(['label' => 'Uploading…', 'processingLabel' => 'Finishing upload…', 'doneLabel' => 'Upload complete'])
{{--
    Animated upload bar. Place inside an element whose x-data has `uploading`, `done` and `progress`, set from Livewire's
    livewire-upload-* events. Sending the file fills the bar to 90%; only Livewire's finish event (the server has stored
    the file) takes it to 100%, so a full bar always means the upload is complete.
--}}
<div x-show="uploading" x-cloak role="status" aria-live="polite" {{ $attributes->class(['upload-progress-wrap']) }}>
    <div class="flex items-center justify-between gap-3 text-xs font-semibold">
        <span class="flex items-center gap-2">
            <span x-show="!done" class="upload-spinner" aria-hidden="true"></span>
            <x-icon x-show="done" x-cloak name="check" class="size-4 text-green-700" />
            <span x-text="done ? @js($doneLabel) : (progress >= 90 ? @js($processingLabel) : @js($label))">{{ $label }}</span>
        </span>
        <span class="tabular-nums" x-text="progress + '%'"></span>
    </div>
    <div class="upload-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progress"><div class="upload-progress-bar" :class="{ 'is-done': done }" :style="`width: ${Math.max(progress, 4)}%`"></div></div>
</div>
