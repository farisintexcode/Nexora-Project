@props(['label', 'title', 'message', 'variant' => 'primary', 'action' => null, 'confirmLabel' => 'Confirm', 'cancelLabel' => 'Cancel', 'disabled' => false])
@php($confirmVariant = $variant === 'secondary' ? 'primary' : $variant)
<div x-data>
    <x-button type="button" :variant="$variant" :disabled="$disabled" @click="$refs.confirmDialog.showModal()" wire:loading.attr="disabled">{{ $label }}</x-button>
    <dialog wire:ignore.self x-ref="confirmDialog" aria-label="{{ $title }}" class="confirmation-dialog" @click="if ($event.target === $refs.confirmDialog) $refs.confirmDialog.close()">
        <div class="p-6 text-center sm:p-8"><span @class(['icon-tile', 'border-red-200 bg-red-50 text-red-800' => $confirmVariant === 'danger'])><x-icon :name="$confirmVariant === 'danger' ? 'info' : 'shield'" /></span><h2 class="mt-5 text-xl font-semibold">{{ $title }}</h2><p class="mt-3 text-sm leading-6 text-ink-600">{{ $message }}</p><div class="mt-7 flex flex-wrap justify-center gap-3"><x-button type="button" variant="secondary" @click="$refs.confirmDialog.close()">{{ $cancelLabel }}</x-button>@if($action)<x-button type="button" :variant="$confirmVariant" @click="$refs.confirmDialog.close()" wire:click="{{ $action }}" wire:loading.attr="disabled">{{ $confirmLabel }}</x-button>@else<x-button type="button" :variant="$confirmVariant" @click="$refs.confirmDialog.close(); $el.closest('form').requestSubmit()" wire:loading.attr="disabled">{{ $confirmLabel }}</x-button>@endif</div></div>
    </dialog>
</div>
