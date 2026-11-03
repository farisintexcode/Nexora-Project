@props(['status'])
@php($tone = match($status) { 'approved'=>'success', 'rejected'=>'danger', 'pending'=>'warning', default=>'neutral' })
<x-badge :tone="$tone">{{ ucfirst($status) }}</x-badge>
