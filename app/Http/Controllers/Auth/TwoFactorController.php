<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function challenge(): Response
    {
        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $request->user();
        if (! $this->twoFactor->challenge($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That authentication code is not valid.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('two_factor_passed', $user->id);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function store(Request $request): RedirectResponse
    {
        $setup = $this->twoFactor->begin($request->user());
        $request->session()->flash('two_factor_setup', $setup);

        return back()->with('success', 'Scan the setup key, then confirm with a code from your authenticator.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $this->twoFactor->confirm($request->user(), $data['code']);
        $request->session()->put('two_factor_passed', $request->user()->id);

        return back()->with('success', 'Two-factor authentication is on.');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $this->requirePassword($request);
        $codes = $this->twoFactor->regenerate($request->user());
        $request->session()->flash('recovery_codes', $codes);

        return back()->with('success', 'New recovery codes generated. Store them now. They will not be shown again.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->requirePassword($request);
        $this->twoFactor->disable($request->user());
        $request->session()->forget('two_factor_passed');

        return back()->with('success', 'Two-factor authentication is off.');
    }

    private function requirePassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('current_password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The password is incorrect.']);
        }
    }
}
