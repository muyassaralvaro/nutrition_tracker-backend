<?php

namespace App\Http\Controllers\Api;

use App\AnalysisQuota;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MealAnalysisResource;
use App\Jobs\AnalyzeMealPhoto;
use App\Models\MealAnalysis;
use GdImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MealAnalysisController extends Controller
{
    public function store(Request $request, AnalysisQuota $quota): JsonResponse
    {
        if (! config('ai.nutrition_enabled') || ! config('ai.providers.nine_router.url') || ! config('ai.providers.nine_router.key') || ! config('ai.providers.nine_router.models.text.default')) {
            return response()->json(['message' => 'Meal analysis is unavailable. Enter nutrition details manually.', 'code' => 'provider_unavailable'], 503);
        }

        $data = $request->validate([
            'image' => ['required_without:name', 'prohibits:name,description,grams', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'name' => ['required_without:image', 'prohibits:image,food_context', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'grams' => ['nullable', 'numeric', 'between:0.1,2000'],
            'language' => ['sometimes', 'in:en,id'],
            'food_context' => ['nullable', 'string', 'max:500'],
            'retry_of' => ['nullable', 'uuid'],
        ]);

        if ($request->user()->analyses()->whereIn('status', ['queued', 'processing'])->where('expires_at', '>', now())->count() >= 3) {
            return response()->json(['message' => 'Finish an existing meal analysis first.'], 429);
        }

        if (! $request->hasFile('image')) {
            $id = (string) Str::uuid();
            $analysis = null;
            try {
                $quota->reserve($request->user(), $id, $data['retry_of'] ?? null, hash('sha256', 'text:'.Str::lower(trim($data['name']))));
                $analysis = $request->user()->analyses()->create([
                    'id' => $id,
                    'status' => 'queued',
                    'language' => $data['language'] ?? 'en',
                    'expires_at' => now()->addDay(),
                ]);
                AnalyzeMealPhoto::dispatch($id, '', [
                    'name' => trim($data['name']),
                    'description' => trim($data['description'] ?? ''),
                    'grams' => isset($data['grams']) ? (float) $data['grams'] : null,
                ])->onQueue('photos');
            } catch (\Throwable $exception) {
                $analysis?->delete();
                DB::table('meal_analysis_usage')->where('id', $id)->delete();
                throw $exception;
            }

            return (new MealAnalysisResource($analysis))->response()->setStatusCode(202);
        }

        $image = $request->file('image');
        $size = @getimagesize($image->getRealPath());

        if ($size === false || $size[0] < 64 || $size[1] < 64 || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 12000000) {
            throw ValidationException::withMessages(['image' => 'Image dimensions must be 64–4096 pixels per side and at most 12 megapixels.']);
        }

        $source = @imagecreatefromstring($image->getContent());

        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages(['image' => 'Image could not be decoded.']);
        }

        $sanitized = imagecreatetruecolor($size[0], $size[1]);
        imagefill($sanitized, 0, 0, imagecolorallocate($sanitized, 255, 255, 255));
        imagecopy($sanitized, $source, 0, 0, 0, 0, $size[0], $size[1]);
        imagedestroy($source);
        ob_start();
        imagejpeg($sanitized, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($sanitized);

        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['image' => 'Image could not be prepared.']);
        }

        $id = (string) Str::uuid();
        $path = 'meal-analyses/'.$id.'.jpg';

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('Could not store the sanitized photo.');
        }

        $analysis = null;

        try {
            $quota->reserve($request->user(), $id, $data['retry_of'] ?? null, hash('sha256', 'photo:'.$bytes));
            $analysis = $request->user()->analyses()->create([
                'id' => $id,
                'status' => 'queued',
                'language' => $data['language'] ?? 'en',
                'image_path' => $path,
                'expires_at' => now()->addDay(),
            ]);
            AnalyzeMealPhoto::dispatch($id, trim($data['food_context'] ?? ''))->onQueue('photos');
        } catch (\Throwable $exception) {
            $analysis?->delete();
            DB::table('meal_analysis_usage')->where('id', $id)->delete();
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return (new MealAnalysisResource($analysis))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $id): MealAnalysisResource
    {
        return new MealAnalysisResource($this->owned($request, $id));
    }

    public function quota(Request $request, AnalysisQuota $quota): array
    {
        $data = $request->validate(['analysis_id' => ['sometimes', 'uuid']]);

        return ['data' => $quota->status($request->user(), $data['analysis_id'] ?? null)];
    }

    public function image(Request $request, string $id): Response
    {
        $analysis = $this->owned($request, $id);
        abort_unless($analysis->image_path && Storage::disk('local')->exists($analysis->image_path), 404);

        return response(Storage::disk('local')->get($analysis->image_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $analysis = $this->owned($request, $id);
        $analysis->delete();
        if ($analysis->image_path) {
            Storage::disk('local')->delete($analysis->image_path);
        }

        return response()->noContent();
    }

    private function owned(Request $request, string $id): MealAnalysis
    {
        return $request->user()->analyses()->whereKey($id)->where('expires_at', '>', now())->firstOrFail();
    }
}
