<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\NutritionCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TargetController extends Controller
{
    public function index(Request $request): array
    {
        return ['data' => $request->user()->targets()->orderByDesc('effective_on')->limit(100)->get()];
    }

    public function put(Request $request, string $date): array
    {
        $timezone = $request->user()->profile?->timezone;

        if ($timezone === null || $date !== now($timezone)->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Targets can only be set for your current local day after profile setup.']);
        }

        $rules = ['source' => ['required', Rule::in(['estimated', 'edited'])]];

        foreach (NutritionCalculator::LIMITS as $nutrient => $limit) {
            $rules[$nutrient] = ['required', 'numeric', 'between:'.($nutrient === 'calories' ? 800 : 1).','.$limit];
        }

        $data = $request->validate($rules);
        $source = $data['source'];
        unset($data['source']);

        $target = $request->user()->targets()->updateOrCreate(['effective_on' => $date], [
            ...$data,
            'source' => $source,
            'formula_version' => 'v1',
        ]);

        return ['data' => $target];
    }
}
