<?php

namespace App\Http\Controllers\Api;

use App\AvatarImage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthController extends Controller
{
    public function options(): array
    {
        return ['data' => [
            'google_enabled' => (bool) config('services.google.client_id') && (bool) config('services.google.client_secret'),
            'photo_analysis_enabled' => (bool) config('ai.nutrition_enabled') && (bool) config('ai.providers.nine_router.url') && (bool) config('ai.providers.nine_router.key') && (bool) config('ai.providers.nine_router.models.text.default'),
        ]];
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response()->setStatusCode(200);
    }

    public function avatar(Request $request): Response
    {
        $path = $request->user()->avatar_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $version = $request->query('v');
        abort_if($version !== null && $version !== basename($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => $version === null ? 'private, no-store' : 'private, max-age=3600, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:3072']]);

        if (! AvatarImage::store($request->user(), $data['image']->getContent())) {
            throw ValidationException::withMessages(['image' => 'Choose a valid JPG, PNG, or WebP photo.']);
        }

        return (new UserResource($request->user()->refresh()))->response()->setStatusCode(200);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        if (now()->timestamp - (int) $request->session()->get('authenticated_at', 0) > 300) {
            abort(403, 'Sign in again before deleting your account.');
        }

        $user = $request->user();

        $ledgerPath = config('app.account_deletion_ledger_path');
        $entry = json_encode(['user_id' => $user->id, 'created_at' => $user->created_at->toIso8601String()], JSON_THROW_ON_ERROR).PHP_EOL;

        File::ensureDirectoryExists(dirname($ledgerPath));

        if (file_put_contents($ledgerPath, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Account deletion ledger is unavailable.');
        }

        $photoPaths = [
            ...$user->analyses()->whereNotNull('image_path')->pluck('image_path')->all(),
            ...$user->meals()->whereNotNull('thumbnail_path')->pluck('thumbnail_path')->all(),
            ...($user->avatar_path ? [$user->avatar_path] : []),
        ];
        Auth::logout();
        $user->delete();
        Storage::disk('local')->delete($photoPaths);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
