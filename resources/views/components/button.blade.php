@props(['variant' => 'primary'])
<button {{ $attributes->merge(['type' => 'submit'])->class(['btn', 'btn-primary' => $variant === 'primary', 'btn-secondary' => $variant === 'secondary', 'btn-danger' => $variant === 'danger', 'btn-success' => $variant === 'success']) }}>{{ $slot }}</button>
