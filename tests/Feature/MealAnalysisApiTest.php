<?php

namespace Tests\Feature;

use App\Ai\Agents\MealPhotoAgent;
use App\Jobs\AnalyzeMealPhoto;
use App\Models\Food;
use App\Models\MealAnalysis;
use App\Models\User;
use App\NutritionCalculator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MealAnalysisApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_photo_upload_reports_unavailable_provider_without_creating_a_draft(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.jpg', 640, 480)])
            ->assertStatus(503)->assertJsonPath('code', 'provider_unavailable');
        $this->assertDatabaseCount('meal_analyses', 0);
    }

    public function test_photo_stays_private_and_a_confirmed_draft_saves_once(): void
    {
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);

        $upload = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.png', 640, 480)]);
        $upload->assertAccepted()->assertJsonPath('data.status', 'queued');
        $id = $upload->json('data.id');
        Queue::assertPushed(AnalyzeMealPhoto::class);
        $analysis = MealAnalysis::findOrFail($id);
        $this->assertSame("\xff\xd8", substr(Storage::disk('local')->get($analysis->image_path), 0, 2));

        $this->actingAs($other);
        $this->getJson('/api/v1/meal-analyses/'.$id)->assertNotFound();
        $this->get('/api/v1/meal-analyses/'.$id.'/image')->assertNotFound();

        Food::create([
            'name' => 'Rice', 'source' => 'test-source', 'source_id' => '1',
            'calories' => 130, 'protein' => 2.7, 'carbs' => 28, 'fat' => 0.3,
        ]);
        MealPhotoAgent::fake([['title' => 'Rice bowl', 'items' => [[
            'name' => 'Rice', 'grams' => 200, 'uncertain' => false, 'note' => '',
        ]]]]);
        (new AnalyzeMealPhoto($id))->handle(app(NutritionCalculator::class));

        $this->actingAs($user);
        $draft = $this->getJson('/api/v1/meal-analyses/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.draft.items.0.nutrients.calories', 260)
            ->assertJsonPath('data.draft.items.0.nutrients.sodium', null)
            ->json('data.draft');
        $this->get('/api/v1/meal-analyses/'.$id.'/image')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $meal = [
            'client_request_id' => (string) Str::uuid(), 'analysis_id' => $id,
            'meal_date' => now()->toDateString(), 'meal_time' => '12:30', 'title' => $draft['title'],
            'items' => [[
                'food_id' => $draft['items'][0]['food_id'], 'name' => 'Rice', 'grams' => 200,
                'nutrients' => $draft['items'][0]['nutrients'],
            ]],
        ];
        $this->postJson('/api/v1/meals', $meal)->assertCreated();
        $this->postJson('/api/v1/meals', $meal)->assertOk();
        $this->assertDatabaseCount('meals', 1);
        $this->assertDatabaseMissing('meal_analyses', ['id' => $id]);
        Storage::disk('local')->assertMissing($analysis->image_path);
    }

    public function test_bad_images_and_daily_quota_fail_safely(): void
    {
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        config()->set('ai.daily_attempt_limit', 1);
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->create('bad.jpg', 10, 'image/jpeg')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $first = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('one.jpg', 640, 480)])->json('data.id');
        $second = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('two.jpg', 640, 480)])->json('data.id');
        MealPhotoAgent::fake([['title' => 'Unknown dish', 'items' => [[
            'name' => 'Uncatalogued dish', 'grams' => null, 'uncertain' => true, 'note' => 'Ingredients unclear',
        ]]]]);
        (new AnalyzeMealPhoto($first))->handle(app(NutritionCalculator::class));
        (new AnalyzeMealPhoto($second))->handle(app(NutritionCalculator::class));

        $this->getJson('/api/v1/meal-analyses/'.$first)->assertJsonPath('data.draft.items.0.nutrients.calories', null);
        $this->getJson('/api/v1/meal-analyses/'.$second)->assertJsonPath('data.error_code', 'quota_exhausted');
        $this->assertDatabaseHas('ai_usage_days', ['attempts' => 1]);
    }

    public function test_expired_photo_is_removed_by_scheduled_cleanup(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        $path = 'meal-analyses/'.$id.'.jpg';
        Storage::disk('local')->put($path, 'sanitized photo bytes');
        $user->analyses()->create([
            'id' => $id, 'status' => 'failed', 'image_path' => $path,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('app:purge-expired-analyses')->assertExitCode(0);

        $this->assertDatabaseMissing('meal_analyses', ['id' => $id]);
        Storage::disk('local')->assertMissing($path);
    }
}
