<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthController extends Controller
{
    public function options(): array
    {
        return ['data' => [
            'phone_registration_enabled' => app()->environment('local', 'testing'),
            'google_enabled' => (bool) config('services.google.client_id') && (bool) config('services.google.client_secret'),
            'password_recovery_enabled' => false,
            'photo_analysis_enabled' => (bool) config('ai.nutrition_enabled') && (bool) config('ai.providers.nine_router.url') && (bool) config('ai.providers.nine_router.key') && (bool) config('ai.providers.nine_router.models.text.default'),
        ]];
    }

    public function register(Request $request): JsonResponse
    {
        abort_unless(app()->environment('local', 'testing'), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone_e164' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique('users', 'phone_e164')],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('authenticated_at', now()->timestamp);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(Request $request): UserResource
    {
        $data = $request->validate([
            'phone_e164' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['phone_e164' => 'Invalid phone or password.']);
        }

        $request->session()->regenerate();
        $request->session()->put('authenticated_at', now()->timestamp);

        return new UserResource($request->user());
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response()->setStatusCode(200);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function changePassword(Request $request): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $user = $request->user();

        if ($user->password === null || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => $data['password']]);
        $request->session()->regenerate();
        $request->session()->put('authenticated_at', now()->timestamp);

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        if (now()->timestamp - (int) $request->session()->get('authenticated_at', 0) > 300) {
            abort(403, 'Sign in again before deleting your account.');
        }

        $user = $request->user();

        if ($user->password !== null) {
            $data = $request->validate(['password' => ['required', 'string']]);

            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Password is incorrect.']);
            }
        }

        $ledgerPath = config('app.account_deletion_ledger_path');
        $entry = json_encode(['user_id' => $user->id, 'created_at' => $user->created_at->toIso8601String()], JSON_THROW_ON_ERROR).PHP_EOL;

        File::ensureDirectoryExists(dirname($ledgerPath));

        if (file_put_contents($ledgerPath, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Account deletion ledger is unavailable.');
        }

        $photoPaths = $user->analyses()->whereNotNull('image_path')->pluck('image_path')->all();
        Auth::logout();
        $user->delete();
        Storage::disk('local')->delete($photoPaths);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
