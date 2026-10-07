<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class WeightController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $from = $filters['from'] ?? (isset($filters['to']) ? null : now($request->user()->profile?->timezone ?? 'UTC')->subDays(90)->toDateString());

        return JsonResource::collection($request->user()->weights()
            ->when($from, fn ($query, $from) => $query->whereDate('entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('entry_date', '<=', $to))
            ->orderByDesc('entry_date')->paginate(30));
    }

    public function store(Request $request): array
    {
        $data = $request->validate($this->rules($request));
        $entry = $request->user()->weights()->firstOrCreate(['entry_date' => $data['entry_date']], ['kg' => $data['kg']]);
        abort_unless($entry->wasRecentlyCreated, 409, 'Weight already logged for this date.');

        return ['data' => $entry];
    }

    public function update(Request $request, int $id): array
    {
        $entry = $request->user()->weights()->findOrFail($id);
        $data = $request->validate($this->rules($request, $id));
        $entry->update($data);

        return ['data' => $entry];
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->weights()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, ?int $id = null): array
    {
        $today = now($request->user()->profile?->timezone ?? 'UTC')->toDateString();

        return [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:1900-01-01', ...($id === null ? [] : [Rule::unique('weight_entries')->where('user_id', $request->user()->id)->ignore($id)])],
            'kg' => ['required', 'numeric', 'between:20,500'],
        ];
    }
}
