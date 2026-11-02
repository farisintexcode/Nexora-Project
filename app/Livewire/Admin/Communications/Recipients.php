<?php

namespace App\Livewire\Admin\Communications;

use App\Actions\Communications\PreviewRecipients;
use App\Models\AccountParty;
use App\Models\CommunicationTemplate;
use App\Models\Task;
use App\Services\Communications\RecipientAccess;
use App\Services\Communications\TemplateContext;
use App\Services\Customers\CustomerSearch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** UI66 explicit contacts and per-recipient context; no all-page implicit selection. */
#[Layout('layouts.admin')]
class Recipients extends Component
{
    use WithPagination;

    #[Locked]
    public string $requestKey;

    public string $template = '';

    public string $mode = 'single';

    public string $query = '';

    public string $searchBy = 'reference';

    public string $from = '';

    public string $until = '';

    public array $selected = [];

    public array $contexts = [];

    public function mount(): void
    {
        $this->requestKey = (string) Str::uuid7();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['query', 'searchBy', 'from', 'until'], true)) {
            $this->resetPage();
        }
        if (preg_match('/^contexts\.([a-zA-Z0-9-]+)\.(account|task)$/', $property, $m)) {
            $this->contexts[$m[1]][$m[2] === 'account' ? 'task' : 'account'] = '';
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->contexts = [];
        $this->requestKey = (string) Str::uuid7();
        $this->resetValidation();
    }

    public function savePreview(PreviewRecipients $preview): void
    {
        Validator::make(['selected' => $this->selected, 'contexts' => $this->contexts], ['contexts' => ['array'], 'contexts.*' => ['array'], 'contexts.*.account' => ['nullable', 'uuid'], 'contexts.*.task' => ['nullable', 'uuid'], 'selected' => ['required', 'array', 'list', 'max:'.config('communications.preview_max_recipients')], 'selected.*' => ['uuid']])->validate();
        $rows = array_map(fn ($id) => ['contact' => $id, 'account' => $this->contexts[$id]['account'] ?? '', 'task' => $this->contexts[$id]['task'] ?? ''], $this->selected);
        $batch = $preview->create(auth()->user(), ['template' => $this->template, 'mode' => $this->mode, 'rows' => $rows], $this->requestKey);
        $this->redirectRoute('admin.communications.batches.show', ['batch' => $batch->public_id]);
    }

    public function render(RecipientAccess $access, CustomerSearch $search, TemplateContext $context): View
    {
        $access->compose(auth()->user());
        $query = $access->contacts(auth()->user())->whereNull('valid_to')->where('valid_from', '<=', now());
        if ($this->query !== '') {
            if (in_array($this->searchBy, ['reference', 'name', 'contact'], true) && mb_strlen($this->query) <= 200) {
                $query->whereHas('debtor', fn ($q) => $search->apply($q, $this->searchBy, $this->query));
            } elseif ($this->searchBy === 'account') {
                $query->whereIn('debtor_id', AccountParty::query()->select('debtor_id')->whereNull('valid_to')->whereHas('account', fn ($q) => $q->where('account_number', $this->query)));
            } elseif ($this->searchBy === 'task' && Str::isUuid($this->query) && Gate::allows('viewAny', Task::class)) {
                $query->whereIn('debtor_id', Task::query()->select('customer_id')->where('public_id', $this->query));
            } else {
                $query->whereRaw('false');
            }
        }
        foreach (['from', 'until'] as $field) {
            if ($this->$field !== '') {
                if (! Validator::make([$field => $this->$field], [$field => ['date_format:Y-m-d']])->fails()) {
                    $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->$field, config('dcms.business_timezone'));
                    $query->whereHas('debtor', fn ($q) => $q->where('created_at', $field === 'from' ? '>=' : '<', $field === 'from' ? $date->utc() : $date->addDay()->utc()));
                } else {
                    $query->whereRaw('false');
                }
            }
        }
        $selected = $access->contacts(auth()->user())->whereIn('public_id', array_slice(array_filter($this->selected, fn ($id) => is_string($id) && Str::isUuid($id)), 0, config('communications.preview_max_recipients')))->with('debtor')->orderBy('id')->get();
        $options = [];
        foreach ($selected as $contact) {
            $options[$contact->public_id] = ['accounts' => $context->accounts($contact->debtor)->limit(100)->get(), 'tasks' => Gate::allows('viewAny', Task::class) ? Task::query()->where('customer_id', $contact->debtor_id)->with('account')->latest('id')->limit(100)->get() : collect()];
        }

        return view('livewire.admin.communications.recipients', ['templates' => CommunicationTemplate::query()->where('channel', 'email')->where('status', 'approved')->orderBy('email_slot')->get(), 'contacts' => $query->with('debtor')->orderBy('debtor_id')->orderBy('id')->paginate(25), 'selectedContacts' => $selected, 'contextOptions' => $options, 'batches' => $access->batches(auth()->user())->with('template')->latest('id')->limit(10)->get()]);
    }
}
