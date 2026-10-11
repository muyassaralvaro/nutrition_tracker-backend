<?php

namespace App\Http\Controllers\Api;

use App\AvatarImage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureConfigured();

        if ($request->boolean('link')) {
            abort_unless(Auth::guard('web')->check(), 401);
            $request->session()->put('google_link_user_id', Auth::guard('web')->id());
            $request->session()->forget('google_signup_consent');
        } else {
            $request->session()->forget('google_link_user_id');
            $request->session()->put('google_signup_consent', $request->boolean('consent'));
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->ensureConfigured();
        $frontend = rtrim(config('services.frontend.url'), '/');

        try {
            $google = Socialite::driver('google')->user();
            $signupConsent = $request->session()->pull('google_signup_consent', false);
            $subject = $google->getId();

            if (! $subject) {
                return redirect($frontend.'/login?oauth=failed');
            }

            $email = $google->getEmail();
            $linkUserId = $request->session()->pull('google_link_user_id');

            if ($linkUserId !== null) {
                if (Auth::guard('web')->id() !== (int) $linkUserId || User::where('google_subject', $subject)->whereKeyNot($linkUserId)->exists()) {
                    return redirect($frontend.'/settings?oauth=conflict');
                }

                $user = User::findOrFail($linkUserId);
                $user->update(['google_subject' => $subject, 'google_email' => $email]);
                $this->importAvatar($user, $google->getAvatar());

                return redirect($frontend.'/settings?oauth=linked');
            }

            $user = User::where('google_subject', $subject)->first();

            if ($user !== null && $user->google_email !== $email) {
                $user->update(['google_email' => $email]);
            }

            if ($user === null) {
                if (! $signupConsent) {
                    return redirect($frontend.'/login?oauth=consent_required');
                }

                if ($email !== null && User::where('email', $email)->exists()) {
                    return redirect($frontend.'/login?oauth=account_exists');
                }

                $key = 'google-signup:'.hash('sha256', (string) $request->ip());
                $createUser = fn () => User::create([
                    'name' => $google->getName() ?: 'Google user',
                    'email' => $email,
                    'google_subject' => $subject,
                    'google_email' => $email,
                    'accepted_terms_at' => now(),
                ]);
                $user = Cache::lock($key.':lock', 10)->block(3, fn () => User::matchesDeveloperEmail($email)
                    ? $createUser()
                    : RateLimiter::attempt($key, 3, $createUser, 86400));

                if ($user === false) {
                    return redirect($frontend.'/login?oauth=signup_limited');
                }
            }

            $this->importAvatar($user, $google->getAvatar());

            Auth::guard('web')->login($user);
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

    private function importAvatar(User $user, mixed $url): void
    {
        try {
            AvatarImage::importGoogle($user, $url);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
