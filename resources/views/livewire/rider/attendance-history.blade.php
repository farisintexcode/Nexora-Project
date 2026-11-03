<section class="rider-page rider-attendance-page nexora-attendance">
    <x-feedback />
    <x-page-header title="Attendance History" eyebrow="" description="Review your shifts, recorded times and attendance details.">
        <x-slot:actions><a href="{{ route('rider.attendance') }}" class="btn btn-secondary"><x-icon name="arrow-left" class="size-4" />Back to Attendance</a></x-slot:actions>
    </x-page-header>
    @include('livewire.rider.attendance-history-panel')
</section>
