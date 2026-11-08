@props(['name', 'label', 'type' => 'text', 'help' => null])
<div class="grid min-w-0 content-start gap-2" x-data="{ revealed: false }">
    <div class="flex items-center gap-1.5"><label for="{{ $name }}" class="field-label">{{ $label }}</label>@if($attributes->has('required'))<span class="text-xs text-ink-500" aria-hidden="true" title="Required">*</span>@endif</div>
    <div class="relative min-w-0"><input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if($type === 'password') x-bind:type="revealed ? 'text' : 'password'" @endif {{ $attributes->class(['field', 'pr-12' => $type === 'password']) }} @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @elseif($help) aria-describedby="{{ $name }}-help" @enderror>
    @if($type === 'password')<button type="button" class="absolute inset-y-0 right-0 grid min-w-12 place-items-center rounded-r-xl text-ink-500 hover:text-brand-dark" @click="revealed = !revealed" x-bind:aria-label="revealed ? 'Hide password' : 'Show password'" x-bind:aria-pressed="revealed"><x-icon name="eye" class="size-4" /></button>@endif</div>
    @if($help)<p id="{{ $name }}-help" class="field-help">{{ $help }}</p>@endif
    @error($name)<p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
