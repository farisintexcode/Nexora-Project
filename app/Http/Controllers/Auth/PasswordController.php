<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** M01 / UI02–03: shared broker, no public registration or status escalation. */
class PasswordController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:254']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($request->input('email'))), 'status' => 'active']);

        return back()->with('status', 'If this account is eligible, a password recovery link will be sent.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', ''), 'activation' => $request->routeIs('activation.edit')]);
    }

    public function update(Request $request, AuditWriter $audit): RedirectResponse
    {
        $activation = $request->routeIs('activation.update');
        $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
            ...($activation ? ['name' => ['required', 'string', 'max:160'], 'terms' => ['accepted']] : []),
        ]);
        if ($activation && (! config('dcms.terms_version') || ! config('dcms.privacy_url'))) {
            throw ValidationException::withMessages(['terms' => 'Account activation is awaiting the approved terms and privacy notice.']);
        }
        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');
        $credentials['email'] = mb_strtolower(trim($credentials['email']));
        $credentials['status'] = $activation ? 'invited' : 'active';
        $status = DB::transaction(function () use ($credentials, $activation, $request, $audit): string {
            DB::table('password_reset_tokens')->where('email', $credentials['email'])->lockForUpdate()->first();

            return Password::reset($credentials, function (User $user, string $password) use ($activation, $request, $audit): void {
                DB::transaction(function () use ($user, $password, $activation, $request, $audit): void {
                    $user = User::query()->lockForUpdate()->findOrFail($user->id);
                    if ($user->status !== ($activation ? AccountStatus::Invited : AccountStatus::Active) || ($user->staff_id && ! $user->staff()->where('is_active', true)->exists())) {
                        throw ValidationException::withMessages(['email' => 'This recovery link cannot be used.']);
                    }
                    if ($activation && $user->staff_id && ! $user->staff()->whereRaw('lower(email) = ?', [$user->email])->exists()) {
                        throw ValidationException::withMessages(['email' => 'This invitation no longer matches the staff record. Ask for a new invitation.']);
                    }
                    $user->password = $password;
                    $user->remember_token = Str::random(60);
                    if ($activation) {
                        $user->name = (string) $request->string('name');
                        $user->status = AccountStatus::Active;
                        $user->email_verified_at = now();
                    }
                    $user->save();
                    $audit->record($user, $activation ? 'activated' : 'password_reset', metadata: $activation ? ['terms_version' => config('dcms.terms_version'), 'status' => 'active'] : []);
                });
            });
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'This recovery link is invalid or has expired.']);
        }

        if ($activation) {
            $user = User::query()->where('email', $credentials['email'])->firstOrFail();
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route($user->can('admin.access') ? 'admin.home' : 'rider.home');
        }

        return redirect()->route('login')->with('status', 'Your password has been saved. You can now sign in.');
    }
}
