<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureConfigured();

        if ($request->boolean('link')) {
            abort_unless(Auth::check(), 401);
            $request->session()->put('google_link_user_id', Auth::id());
        } else {
            $request->session()->forget('google_link_user_id');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->ensureConfigured();
        $frontend = rtrim(config('services.frontend.url'), '/');

        try {
            $google = Socialite::driver('google')->user();
            $subject = $google->getId();

            if (! $subject) {
                return redirect($frontend.'/login?oauth=failed');
            }

            $linkUserId = $request->session()->pull('google_link_user_id');

            if ($linkUserId !== null) {
                if (Auth::id() !== (int) $linkUserId || User::where('google_subject', $subject)->whereKeyNot($linkUserId)->exists()) {
                    return redirect($frontend.'/settings?oauth=conflict');
                }

                $user = User::findOrFail($linkUserId);
                $user->update(['google_subject' => $subject]);

                return redirect($frontend.'/settings?oauth=linked');
            }

            $user = User::where('google_subject', $subject)->first();

            if ($user === null) {
                $email = $google->getEmail();

                if ($email !== null && User::where('email', $email)->exists()) {
                    return redirect($frontend.'/login?oauth=account_exists');
                }

                $user = User::create([
                    'name' => $google->getName() ?: 'Google user',
                    'email' => $email,
                    'google_subject' => $subject,
                ]);
            }

            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put('authenticated_at', now()->timestamp);

            return redirect($frontend.'/home');
        } catch (Throwable $exception) {
            report($exception);

            return redirect($frontend.'/login?oauth=failed');
        }
    }

    private function ensureConfigured(): void
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google sign-in is unavailable.');
    }
}
