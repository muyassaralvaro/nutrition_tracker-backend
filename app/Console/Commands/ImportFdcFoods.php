<?php

namespace App\Console\Commands;

use App\Models\Food;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('app:import-fdc-foods {ids?* : USDA FoodData Central IDs; defaults to starter basics}')]
#[Description('Import sourced food nutrients per 100 g from USDA FoodData Central')]
class ImportFdcFoods extends Command
{
    private const STARTER_IDS = [168930, 173423, 171477, 173944, 169967, 172688, 170438];

    private const NUTRIENT_IDS = [
        'protein' => 1003, 'fat' => 1004, 'carbs' => 1005, 'fiber' => 1079,
        'calcium' => 1087, 'iron' => 1089, 'potassium' => 1092, 'sodium' => 1093,
    ];

    private const INDONESIAN_NAMES = [
        168930 => 'Nasi putih matang', 173423 => 'Telur goreng',
        171477 => 'Dada ayam panggang', 173944 => 'Pisang',
        169967 => 'Brokoli rebus', 172688 => 'Roti gandum', 170438 => 'Kentang rebus',
    ];

    public function handle(): int
    {
        $key = config('services.fdc.api_key');

        if (! $key) {
            $this->error('Set FDC_API_KEY to a free USDA FoodData Central API key.');

            return self::FAILURE;
        }

        $ids = $this->argument('ids') ?: self::STARTER_IDS;

        foreach ($ids as $id) {
            if (! ctype_digit((string) $id)) {
                $this->error('FoodData Central IDs must be numeric.');

                return self::FAILURE;
            }

            $response = Http::timeout(20)->get('https://api.nal.usda.gov/fdc/v1/food/'.$id, ['api_key' => $key]);

            if (! $response->successful()) {
                $this->error('USDA request failed for '.$id.' (HTTP '.$response->status().').');

                return self::FAILURE;
            }

            $food = $response->json();

            if (! in_array($food['dataType'] ?? null, ['Foundation', 'SR Legacy'], true) || ! is_array($food['foodNutrients'] ?? null)) {
                $this->error('Unsupported USDA food record '.$id.'.');

                return self::FAILURE;
            }

            $amounts = [];

            foreach ($food['foodNutrients'] as $entry) {
                $nutrientId = $entry['nutrient']['id'] ?? $entry['nutrientId'] ?? null;
                $amount = $entry['amount'] ?? $entry['value'] ?? null;

                if (is_numeric($amount) && (float) $amount >= 0) {
                    $amounts[$nutrientId] = round((float) $amount, 2);
                }
            }

            $calories = $amounts[1008] ?? $amounts[2047] ?? $amounts[2048] ?? null;

            if ($calories === null || ! isset($amounts[1003], $amounts[1004], $amounts[1005])) {
                $this->warn('Skipping '.$id.': required energy or macronutrients are missing.');

                continue;
            }

            $nutrients = ['calories' => $calories];

            foreach (self::NUTRIENT_IDS as $name => $nutrientId) {
                $nutrients[$name] = $amounts[$nutrientId] ?? null;
            }

            Food::updateOrCreate(['source' => 'USDA FDC', 'source_id' => (string) $id], [
                'name' => mb_substr($food['description'], 0, 160),
                'name_id' => self::INDONESIAN_NAMES[(int) $id] ?? null,
                'source_url' => 'https://fdc.nal.usda.gov/food-details/'.$id.'/nutrients',
                ...$nutrients,
            ]);
            $this->info('Imported '.$id.'.');
        }

        return self::SUCCESS;
    }
}
