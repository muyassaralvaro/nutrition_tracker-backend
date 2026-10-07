<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MealResource;
use App\NutritionCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class SummaryController extends Controller
{
    public function day(Request $request, string $date, NutritionCalculator $calculator): array
    {
        $this->validateDate($date);
        $user = $request->user();
        $meals = $user->meals()->with('items')->whereDate('meal_date', $date)->orderBy('meal_time')->orderBy('id')->get();
        $target = $user->targets()->whereDate('effective_on', '<=', $date)->orderByDesc('effective_on')->first();
        $nutrition = $calculator->summarize($meals->flatMap(fn ($meal) => $meal->items), $target, $meals->isNotEmpty());

        return ['data' => [
            'date' => $date,
            'target' => $target,
            'nutrition' => $nutrition,
            'weight' => $user->weights()->whereDate('entry_date', $date)->first(),
            'meals' => $meals->map(fn ($meal) => (new MealResource($meal))->toArray($request)),
        ]];
    }

    public function calendar(Request $request, NutritionCalculator $calculator): array
    {
        $filters = $request->validate(['month' => ['required', 'regex:/^(19|20)\d{2}-(0[1-9]|1[0-2])$/']]);
        $start = Carbon::createFromFormat('!Y-m-d', $filters['month'].'-01');
        $end = $start->copy()->endOfMonth();
        $user = $request->user();
        $meals = $user->meals()->with('items')->whereBetween('meal_date', [$start->toDateString(), $end->toDateString()])->get()->groupBy(fn ($meal) => $meal->meal_date->toDateString());
        $weights = $user->weights()->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])->orderBy('entry_date')->get()->keyBy(fn ($weight) => $weight->entry_date->toDateString());
        $targets = $user->targets()->whereDate('effective_on', '<=', $end->toDateString())->orderByDesc('effective_on')->get();
        $days = [];
        $completed = 0;
        $logged = 0;

        for ($day = 1; $day <= $end->day; $day++) {
            $date = $start->copy()->day($day)->toDateString();
            $dayMeals = $meals->get($date, collect());
            $target = $targets->first(fn ($candidate) => $candidate->effective_on->toDateString() <= $date);
            $nutrition = $calculator->summarize($dayMeals->flatMap(fn ($meal) => $meal->items), $target, $dayMeals->isNotEmpty());
            $completed += $nutrition['status'] === 'complete' ? 1 : 0;
            $logged += $dayMeals->isNotEmpty() ? 1 : 0;
            $days[] = [
                'date' => $date,
                'status' => $nutrition['status'],
                'unknown_nutrients' => array_keys(array_filter($nutrition['known'], fn ($known) => ! $known)),
                'meal_count' => $dayMeals->count(),
                'calories' => $nutrition['totals']['calories'],
                'weight_kg' => $weights->get($date)?->kg,
            ];
        }

        $firstWeight = $weights->first()?->kg;
        $lastWeight = $weights->last()?->kg;

        return ['data' => [
            'month' => $filters['month'],
            'days' => $days,
            'completed_days' => $completed,
            'logged_days' => $logged,
            'weigh_ins' => $weights->count(),
            'weight_change_kg' => $firstWeight !== null && $lastWeight !== null && $weights->count() > 1 ? round($lastWeight - $firstWeight, 2) : null,
        ]];
    }

    private function validateDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
    }
}
