<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\NutritionCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    private const PROFILE_FIELDS = ['birth_date', 'height_cm', 'sex', 'body_build', 'body_fat_percent', 'goal', 'goal_weight_kg', 'activity_minutes', 'activity_type', 'timezone', 'unit_system'];

    public function show(Request $request): array
    {
        $profile = $request->user()->profile;

        return ['data' => $profile ? ['name' => $request->user()->name, ...Arr::only($profile->toArray(), self::PROFILE_FIELDS)] : null];
    }

    public function estimate(Request $request, NutritionCalculator $calculator): array
    {
        $data = $request->validate([
            ...$this->profileRules($request, false),
            'weight_kg' => ['required', 'numeric', 'between:20,500'],
        ]);

        return ['data' => ['targets' => $calculator->estimate($data), 'formula_version' => 'v1']];
    }

    public function setup(Request $request): array
    {
        $data = $request->validate([
            ...$this->profileRules($request, true),
            'weight_kg' => ['required', 'numeric', 'between:20,500'],
            'target_source' => ['required', Rule::in(['estimated', 'edited'])],
            ...$this->targetRules(),
        ]);

        $today = now($data['timezone'])->toDateString();

        DB::transaction(function () use ($request, $data, $today): void {
            $user = $request->user();
            $user->update(['name' => $data['name']]);
            $user->profile()->updateOrCreate([], Arr::only($data, self::PROFILE_FIELDS));
            $user->weights()->updateOrCreate(['entry_date' => $today], ['kg' => $data['weight_kg']]);
            $user->targets()->updateOrCreate(['effective_on' => $today], [
                ...$data['targets'],
                'source' => $data['target_source'],
                'formula_version' => 'v1',
            ]);
        });

        return $this->show($request);
    }

    public function update(Request $request): array
    {
        $data = $request->validate($this->profileRules($request, true));
        $request->user()->update(['name' => $data['name']]);
        $request->user()->profile()->updateOrCreate([], Arr::only($data, self::PROFILE_FIELDS));
        $request->user()->unsetRelation('profile');

        return $this->show($request);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function profileRules(Request $request, bool $includeName): array
    {
        $timezone = in_array($request->input('timezone'), timezone_identifiers_list(), true) ? $request->input('timezone') : 'UTC';
        $today = now($timezone);

        return [
            ...($includeName ? ['name' => ['required', 'string', 'max:80']] : []),
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->copy()->subYears(18)->toDateString(), 'after_or_equal:'.$today->copy()->subYears(120)->toDateString()],
            'height_cm' => ['required', 'numeric', 'between:80,250'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'body_build' => ['required', Rule::in(['lean', 'soft', 'stocky', 'muscular'])],
            'body_fat_percent' => ['nullable', 'numeric', 'between:3,70'],
            'goal' => ['required', Rule::in(['lose', 'maintain', 'gain'])],
            'goal_weight_kg' => ['nullable', 'numeric', 'between:20,500'],
            'activity_minutes' => ['required', 'integer', 'between:0,240'],
            'activity_type' => ['required', Rule::in(['daily', 'cardio', 'strength', 'mixed'])],
            'timezone' => ['required', 'timezone'],
            'unit_system' => [$includeName ? 'required' : 'sometimes', Rule::in(['metric', 'imperial'])],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function targetRules(): array
    {
        $rules = [];

        foreach (NutritionCalculator::LIMITS as $nutrient => $limit) {
            $rules['targets.'.$nutrient] = ['required', 'numeric', 'between:'.($nutrient === 'calories' ? 800 : 1).','.$limit];
        }

        return $rules;
    }
}
