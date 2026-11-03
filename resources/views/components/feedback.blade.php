@if (session('status'))
<dialog wire:key="feedback-{{ uniqid() }}" x-data="{ timer: null }" x-init="$el.showModal(); timer = setTimeout(() => $el.close(), 3500)" @close="clearTimeout(timer)" aria-labelledby="feedback-title" class="confirmation-dialog" @click="if ($event.target === $el) $el.close()">
    <div class="p-6 text-center sm:p-8"><span class="icon-tile border-green-200 bg-green-50 text-green-800"><x-icon name="check" /></span><h2 id="feedback-title" class="mt-5 text-xl font-semibold">Success</h2><p role="status" class="mt-3 text-sm leading-6 text-ink-600">{{ session('status') }}</p><div class="mt-7 flex justify-center"><x-button type="button" @click="$root.close()" autofocus>OK</x-button></div></div>
</dialog>
@endif
@if (session('warning'))
<dialog wire:key="feedback-warning-{{ uniqid() }}" x-data x-init="$el.showModal()" aria-labelledby="feedback-warning-title" class="confirmation-dialog" @click="if ($event.target === $el) $el.close()">
    <div class="p-6 text-center sm:p-8"><span class="icon-tile border-amber-200 bg-amber-50 text-amber-800"><x-icon name="info" /></span><h2 id="feedback-warning-title" class="mt-5 text-xl font-semibold">Needs attention</h2><p role="alert" class="mt-3 text-sm leading-6 text-ink-600">{{ session('warning') }}</p><div class="mt-7 flex justify-center"><x-button type="button" @click="$root.close()" autofocus>OK</x-button></div></div>
</dialog>
@endif
@if (isset($errors) && $errors->any())
<dialog wire:key="feedback-errors-{{ md5(implode('|', $errors->all())) }}" x-data x-init="$el.showModal()" aria-labelledby="feedback-error-title" class="confirmation-dialog" @click="if ($event.target === $el) $el.close()">
    <div class="p-6 sm:p-8"><div class="text-center"><span class="icon-tile border-red-200 bg-red-50 text-red-800"><x-icon name="info" /></span><h2 id="feedback-error-title" class="mt-5 text-xl font-semibold">Please check the following</h2></div><div role="alert" class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><ul class="grid list-disc gap-1.5 pl-4 text-xs leading-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div><div class="mt-7 flex justify-center"><x-button type="button" @click="$root.close()" autofocus>OK</x-button></div></div>
</dialog>
@endif
