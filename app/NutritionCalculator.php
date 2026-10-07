<?php

namespace App;

use App\Models\Food;
use App\Models\NutrientTarget;

class NutritionCalculator
{
    public const NUTRIENTS = ['calories', 'protein', 'carbs', 'fat', 'fiber', 'sodium', 'potassium', 'calcium', 'iron'];

    public const LIMITS = ['calories' => 6000, 'protein' => 1000, 'carbs' => 1500, 'fat' => 800, 'fiber' => 200, 'sodium' => 20000, 'potassium' => 20000, 'calcium' => 10000, 'iron' => 500];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int>
     */
    public function estimate(array $input): array
    {
        $today = now($input['timezone'])->toDateString();
        $birth = $input['birth_date'];
        $age = (int) substr($today, 0, 4) - (int) substr($birth, 0, 4) - (substr($today, 5) < substr($birth, 5) ? 1 : 0);
        $weight = (float) $input['weight_kg'];
        $height = (float) $input['height_cm'];
        $minutes = (int) $input['activity_minutes'];
        $sex = $input['sex'];
        $activity = $input['activity_type'];
        $goal = $input['goal'];
        $build = $input['body_build'];

        $intensity = ['daily' => 0.5, 'cardio' => 1.2, 'strength' => 1, 'mixed' => 1][$activity];
        $activeMinutes = $minutes * $intensity;
        $multiplier = $activeMinutes < 20 ? 1.2 : ($activeMinutes < 45 ? 1.375 : ($activeMinutes < 90 ? 1.55 : 1.725));
        $bmr = 10 * $weight + 6.25 * $height - 5 * $age + ($sex === 'male' ? 5 : -161);
        $delta = $goal === 'lose' ? -250 : ($goal === 'gain' ? 250 : 0);
        $calories = (int) round(max(1200, min(4500, $bmr * $multiplier + $delta)) / 10) * 10;
        $trainingProtein = $minutes === 0 ? 0 : (in_array($activity, ['strength', 'mixed'], true) ? 0.3 : ($activity === 'cardio' ? 0.1 : 0));
        $proteinFactor = min(2, 1.2 + $trainingProtein + ($build === 'muscular' ? 0.2 : 0) + ($goal === 'lose' ? 0.2 : 0));
        $protein = (int) round(min($weight * $proteinFactor, $calories * 0.35 / 4));
        $fat = (int) round($calories * 0.28 / 9);
        $carbs = max(0, (int) round(($calories - $protein * 4 - $fat * 9) / 4));
        $over50 = $age > 50;

        return [
            'calories' => $calories,
            'protein' => $protein,
            'carbs' => $carbs,
            'fat' => $fat,
            'fiber' => $sex === 'male' ? ($over50 ? 30 : 38) : ($over50 ? 21 : 25),
            'sodium' => 2300,
            'potassium' => $sex === 'male' ? 3400 : 2600,
            'calcium' => $age > ($sex === 'female' ? 50 : 70) ? 1200 : 1000,
            'iron' => $sex === 'female' && ! $over50 ? 18 : 8,
        ];
    }

    /**
     * @return array<string, float|null>
     */
    public function fromFood(Food $food, float $grams): array
    {
        $result = [];

        foreach (self::NUTRIENTS as $nutrient) {
            $value = $food->{$nutrient};
            $result[$nutrient] = $value === null ? null : round($value * $grams / 100, 2);
        }

        return $result;
    }

    /**
     * @param  iterable<object>  $items
     * @return array{totals: array<string, float|null>, known: array<string, bool>, remaining: array<string, float|null>, status: string}
     */
    public function summarize(iterable $items, ?NutrientTarget $target, bool $hasMeals): array
    {
        $totals = array_fill_keys(self::NUTRIENTS, 0.0);
        $known = array_fill_keys(self::NUTRIENTS, true);

        foreach ($items as $item) {
            foreach (self::NUTRIENTS as $nutrient) {
                if ($item->{$nutrient} === null) {
                    $known[$nutrient] = false;
                } else {
                    $totals[$nutrient] += (float) $item->{$nutrient};
                }
            }
        }

        $remaining = [];

        foreach (self::NUTRIENTS as $nutrient) {
            $remaining[$nutrient] = $target && $known[$nutrient] ? round($target->{$nutrient} - $totals[$nutrient], 2) : null;
            $totals[$nutrient] = $known[$nutrient] ? round($totals[$nutrient], 2) : null;
        }

        $status = 'progress';

        if (! $hasMeals) {
            $status = 'empty';
        } elseif ($target === null) {
            $status = 'no_target';
        } elseif (in_array(false, $known, true)) {
            $status = 'partial';
        } else {
            $complete = $totals['calories'] >= $target->calories * 0.9
                && $totals['calories'] <= $target->calories * 1.1
                && $totals['sodium'] <= $target->sodium;

            foreach (['protein', 'carbs', 'fat', 'fiber', 'potassium', 'calcium', 'iron'] as $nutrient) {
                $complete = $complete && $totals[$nutrient] >= $target->{$nutrient} * 0.9;
            }

            if ($complete) {
                $status = 'complete';
            }
        }

        return compact('totals', 'known', 'remaining', 'status');
    }
}
