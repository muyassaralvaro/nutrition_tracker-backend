<?php

namespace Tests\Feature;

use App\Models\Food;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportFdcFoodsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_import_keeps_source_and_unknown_minerals(): void
    {
        config()->set('services.fdc.api_key', 'test-key');
        Http::fake(['api.nal.usda.gov/*' => Http::response([
            'fdcId' => 173944, 'dataType' => 'SR Legacy', 'description' => 'Bananas, raw',
            'foodNutrients' => [
                ['nutrient' => ['id' => 1008], 'amount' => 89],
                ['nutrient' => ['id' => 1003], 'amount' => 1.09],
                ['nutrient' => ['id' => 1004], 'amount' => 0.33],
                ['nutrient' => ['id' => 1005], 'amount' => 22.84],
                ['nutrient' => ['id' => 1092], 'amount' => 358],
            ],
        ])]);

        $this->artisan('app:import-fdc-foods', ['ids' => ['173944']])->assertExitCode(0);
        $this->artisan('app:import-fdc-foods', ['ids' => ['173944']])->assertExitCode(0);

        $food = Food::sole();
        $this->assertSame('USDA FDC', $food->source);
        $this->assertSame('173944', $food->source_id);
        $this->assertSame('Pisang', $food->name_id);
        $this->assertSame(89.0, $food->calories);
        $this->assertSame(358.0, $food->potassium);
        $this->assertNull($food->sodium);
    }
}
