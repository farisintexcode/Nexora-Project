<?php

namespace App\Livewire\Rider;

use App\Actions\Attendance\ManageLeave;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Attendance\LeaveAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** M10 / Rider Attendance: own applications, signed review and immutable decisions. */
#[Layout('layouts.rider')]
class LeaveRequests extends Component
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public string $requestId = '';

    #[Locked]
    public int $requestVersion = 0;

    #[Locked]
    public bool $applying = false;

    #[Locked]
    public array $review = [];

    public string $type = '';

    public string $start_date = '';

    public string $end_date = '';

    public string $reason = '';

    public ?TemporaryUploadedFile $support = null;

    public string $status = '';

    public string $filterType = '';

    public string $from = '';

    public string $until = '';

    public function mount(?LeaveRequest $leave = null): void
    {
        abort_unless(app(LeaveAccess::class)->own(auth()->user()), 403);
        $this->applying = request()->routeIs('rider.leave.apply');
        if ($leave?->exists) {
            abort_unless($leave->rider_id === auth()->user()->staff_id && $leave->requested_by === auth()->id(), 404);
            $this->requestId = $leave->public_id;
            $this->requestVersion = $leave->lock_version;
        }
    }

    public function reviewApplication(ManageLeave $workflow): void
    {
        $this->review = $workflow->preview(auth()->user(), $this->only(['type', 'start_date', 'end_date', 'reason']), $this->support);
    }

    public function editApplication(): void
    {
        $this->review = [];
    }

    public function submit(ManageLeave $workflow): void
    {
        $request = $workflow->submit(auth()->user(), $this->only(['type', 'start_date', 'end_date', 'reason']), $this->review['token'] ?? '', $this->support);
        session()->flash('status', $request->status === 'pending' ? 'Your leave application has been saved and is pending approval.' : 'Your leave application was already saved. Current status: '.ucfirst($request->status).'.');
        $this->redirectRoute('rider.leave.show', ['leave' => $request->public_id]);
    }

    public function cancelApplication(ManageLeave $workflow): void
    {
        $request = $this->own()->where('public_id', $this->requestId)->firstOrFail();
        $workflow->cancel(auth()->user(), $request, $this->requestVersion);
        session()->flash('status', 'Your leave application has been cancelled.');
        $this->redirectRoute('rider.leave.show', ['leave' => $request->public_id]);
    }

    public function filterRequests(): void
    {
        $this->validate(['status' => ['nullable', 'in:pending,approved,rejected,cancelled'], 'filterType' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])]]);
        $this->resetPage();
    }

    private function own(): Builder
    {
        return LeaveRequest::query()->where('rider_id', auth()->user()->staff_id)->where('requested_by', auth()->id());
    }

    public function render(LeaveAccess $access): View
    {
        abort_unless($access->own(auth()->user()), 403);
        $selected = $this->requestId ? $this->own()->with('reviewer', 'document')->where('public_id', $this->requestId)->firstOrFail() : null;
        $query = $this->own()->when(in_array($this->status, ['pending', 'approved', 'rejected', 'cancelled'], true), fn ($q) => $q->where('status', $this->status))->when(Str::isUuid($this->filterType), fn ($q) => $q->whereHas('leaveType', fn ($t) => $t->where('public_id', $this->filterType)));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) && checkdate((int) substr($this->from, 5, 2), (int) substr($this->from, 8, 2), (int) substr($this->from, 0, 4))) {
            $query->where('end_date', '>=', $this->from);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->until) && checkdate((int) substr($this->until, 5, 2), (int) substr($this->until, 8, 2), (int) substr($this->until, 0, 4))) {
            $query->where('start_date', '<=', $this->until);
        }
        $duration = null;
        $durationError = '';
        if ($this->applying && $this->type !== '' && $this->start_date !== '' && $this->end_date !== '') {
            try {
                $duration = app(ManageLeave::class)->duration(auth()->user(), $this->only(['type', 'start_date', 'end_date']));
            } catch (ValidationException $error) {
                $durationError = collect($error->errors())->flatten()->first();
            } catch (ModelNotFoundException) {
                $durationError = 'Choose an available leave type.';
            }
        }

        return view('livewire.rider.leave-requests', ['duration' => $duration, 'durationError' => $durationError, 'selected' => $selected, 'requests' => $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(12), 'types' => LeaveType::query()->orderBy('name')->get(), 'counts' => $this->own()->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status'), 'staff' => auth()->user()->staff]);
    }
}
