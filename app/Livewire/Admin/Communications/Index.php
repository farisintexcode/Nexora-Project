<?php

namespace App\Livewire\Admin\Communications;

use App\Models\CommunicationBatch;
use App\Models\CommunicationTemplate;
use App\Services\Communications\LetterAccess;
use App\Services\Communications\RecipientAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** M14/UI65 template readiness; delivery is a separately activated workflow. */
#[Layout('layouts.admin')]
class Index extends Component
{
    public function render(): View
    {
        Gate::authorize('viewAny', CommunicationTemplate::class);
        $rows = CommunicationTemplate::query()->where('channel', 'email')->whereIn('email_slot', [1, 2, 3])->whereIn('status', ['draft', 'pending_approval', 'approved'])->orderBy('email_slot')->orderByDesc('version')->get();
        $options = collect([1, 2, 3])->map(fn ($slot) => ['slot' => $slot, 'draft' => $rows->first(fn ($r) => $r->email_slot === $slot && in_array($r->status, ['draft', 'pending_approval'], true)), 'approved' => $rows->first(fn ($r) => $r->email_slot === $slot && $r->status === 'approved'), 'latest' => CommunicationTemplate::query()->where('channel', 'email')->where('email_slot', $slot)->latest('version')->first()]);
        $context = [];
        foreach (['customer', 'task'] as $key) {
            $value = request()->query($key);
            if (is_string($value) && Str::isUuid($value)) {
                $context[$key] = $value;
            }
        }

        $actor = auth()->user();
        $canSeeRecords = app(RecipientAccess::class)->allowed($actor);
        $emailConnected = filled(config('communications.email_account'));

        return view('livewire.admin.communications.index', [
            'options' => $options,
            'approvedCount' => $rows->where('status', 'approved')->count(),
            'pendingApproval' => $rows->where('status', 'pending_approval')->count(),
            'context' => $context,
            'email' => [
                'live' => config('communications.delivery_enabled') && $emailConnected && ! config('communications.sandbox_enabled'),
                'checks' => [
                    ['label' => 'Approved email wording', 'ok' => $rows->where('status', 'approved')->isNotEmpty()],
                    ['label' => 'Email provider account connected', 'ok' => $emailConnected],
                    ['label' => 'Live sending switched on', 'ok' => (bool) config('communications.delivery_enabled')],
                    ['label' => 'Test (sandbox) mode off', 'ok' => ! config('communications.sandbox_enabled')],
                    ['label' => 'Delivery receipts switched on', 'ok' => (bool) config('communications.ses_receipts_enabled')],
                ],
            ],
            'activity' => $canSeeRecords ? [
                'batches' => CommunicationBatch::query()->count(),
                'letters' => app(LetterAccess::class)->query($actor)->count(),
            ] : null,
        ]);
    }
}
