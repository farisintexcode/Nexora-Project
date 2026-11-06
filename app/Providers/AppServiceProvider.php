<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveAccount;
use App\Models\CollectionActivity;
use App\Models\Communication;
use App\Models\ImportBatch;
use App\Models\Task;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Relation::enforceMorphMap(['user' => User::class, 'import' => ImportBatch::class, 'task' => Task::class, 'activity' => CollectionActivity::class, 'communication' => Communication::class]);
        Gate::define('use-rider-interface', fn (User $user): bool => $user->canUseRiderInterface());
        Gate::define('viewPulse', fn (User $user): bool => $user->isActive() && $user->can('monitoring.view'));
        Livewire::addPersistentMiddleware([EnsureActiveAccount::class, AuthenticateSession::class]);
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(hash('sha256', mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip())),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('recovery', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('service-email', fn () => Limit::perSecond(max(1, config('communications.rate_limit')))->by('debtops-email-global'));
        RateLimiter::for('ses-receipts', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}
