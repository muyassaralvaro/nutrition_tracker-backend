<?php

namespace Tests\Feature;

use App\Ai\Agents\MealPhotoAgent;
use App\Jobs\AnalyzeMealPhoto;
use App\Models\Food;
use App\Models\MealAnalysis;
use App\Models\User;
use App\NutritionCalculator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Tests\TestCase;

class MealAnalysisApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_photo_agent_requests_non_streaming_response_from_proxy(): void
    {
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        $reply = ['choices' => [[
            'finish_reason' => 'stop',
            'message' => ['content' => '{"title":"Rice bowl","description":"Rice with egg","grams":200,"nutrients":{"calories":400,"protein":18,"carbs":55,"fat":12,"fiber":2,"sodium":400,"potassium":200,"calcium":30,"iron":2}}'],
        ]], 'model' => 'test-model'];
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/v1/chat/completions' => fn (ClientRequest $request) => ($request->data()['stream'] ?? null) === false
                ? Http::response($reply)
                : Http::response(json_encode($reply, JSON_THROW_ON_ERROR).'data: [DONE]', 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $response = MealPhotoAgent::make()->prompt(
            'Describe the meal.',
            attachments: [Image::fromBase64(base64_encode('synthetic-photo'), 'image/jpeg')],
            provider: 'nine_router',
            model: 'test-model',
        );

        $this->assertSame('Rice bowl', $response->toArray()['title']);
        $this->assertSame(400, $response->toArray()['nutrients']['calories']);
        Http::assertSent(fn (ClientRequest $request) => ($request->data()['stream'] ?? null) === false);
    }

    public function test_photo_upload_reports_unavailable_provider_without_creating_a_draft(): void
    {
        config()->set('ai.nutrition_enabled', false);
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.jpg', 640, 480)])
            ->assertStatus(503)->assertJsonPath('code', 'provider_unavailable');
        $this->assertDatabaseCount('meal_analyses', 0);
    }

    public function test_user_dish_details_omit_unrelated_confirmed_dishes_from_the_prompt(): void
    {
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        Storage::fake('local');
        Queue::fake([AnalyzeMealPhoto::class]);
        $user = User::factory()->create();
        Food::create([
            'user_id' => $user->id, 'name' => 'Nasi Ayam Suir Bumbu', 'description' => 'Shredded chicken rice',
            'source' => 'confirmed-photo', 'source_id' => 'older-meal', 'serving_grams' => 250,
            'calories' => 400, 'protein' => 25, 'carbs' => 50, 'fat' => 12,
        ]);
        $this->actingAs($user);
        $id = $this->postJson('/api/v1/meal-analyses', [
            'image' => UploadedFile::fake()->image('plate.jpg', 640, 480),
            'food_context' => 'ayam geprek pake nasi dan mozarella',
        ])->assertAccepted()->json('data.id');
        MealPhotoAgent::fake([[
            'title' => 'Nasi ayam geprek mozzarella', 'description' => 'Ayam geprek dengan nasi dan mozzarella', 'grams' => 300,
            'nutrients' => ['calories' => 600, 'protein' => 35, 'carbs' => 65, 'fat' => 22, 'fiber' => null, 'sodium' => null, 'potassium' => null, 'calcium' => null, 'iron' => null],
        ]]);

        (new AnalyzeMealPhoto($id, 'ayam geprek pake nasi dan mozarella'))->handle(app(NutritionCalculator::class));

        MealPhotoAgent::assertPrompted(fn ($prompt) => $prompt->contains('ayam geprek pake nasi dan mozarella') && ! $prompt->contains('Nasi Ayam Suir Bumbu'));
        $this->getJson('/api/v1/meal-analyses/'.$id)->assertJsonPath('data.draft.title', 'Nasi ayam geprek mozzarella');
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

        $upload = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.png', 640, 480), 'language' => 'id', 'food_context' => '  Matcha filling and one strawberry, one small piece  ']);
        $upload->assertAccepted()->assertJsonPath('data.status', 'queued');
        $id = $upload->json('data.id');
        Queue::assertPushed(AnalyzeMealPhoto::class, fn (AnalyzeMealPhoto $job) => $job->foodContext === 'Matcha filling and one strawberry, one small piece');
        $analysis = MealAnalysis::findOrFail($id);
        $this->assertSame('id', $analysis->language);
        $this->assertSame("\xff\xd8", substr(Storage::disk('local')->get($analysis->image_path), 0, 2));

        $this->actingAs($other);
        $this->getJson('/api/v1/meal-analyses/'.$id)->assertNotFound();
        $this->get('/api/v1/meal-analyses/'.$id.'/image')->assertNotFound();

        MealPhotoAgent::fake([[
            'title' => 'Mochi matcha stroberi', 'description' => 'Mochi isi stroberi dan matcha', 'grams' => 90,
            'nutrients' => ['calories' => 230, 'protein' => 4, 'carbs' => 43, 'fat' => 5, 'fiber' => 2, 'sodium' => null, 'potassium' => null, 'calcium' => null, 'iron' => null],
        ]]);
        (new AnalyzeMealPhoto($id, 'Matcha filling and one strawberry, one small piece'))->handle(app(NutritionCalculator::class));
        MealPhotoAgent::assertPrompted(fn ($prompt) => $prompt->contains('Indonesian only') && $prompt->contains('calories and nutrients') && $prompt->contains('Matcha filling and one strawberry'));

        $this->actingAs($user);
        $draft = $this->getJson('/api/v1/meal-analyses/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.draft.items.0.nutrients.calories', 230)
            ->assertJsonPath('data.draft.items.0.nutrients.sodium', null)
            ->json('data.draft');
        $this->assertCount(1, $draft['items']);
        $this->assertSame('Mochi isi stroberi dan matcha', $draft['items'][0]['description']);
        $this->get('/api/v1/meal-analyses/'.$id.'/image')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $meal = [
            'client_request_id' => (string) Str::uuid(), 'analysis_id' => $id,
            'meal_date' => now()->toDateString(), 'meal_time' => '12:30', 'title' => $draft['title'],
            'items' => [[
                'name' => 'Mochi matcha stroberi', 'description' => 'Mochi stroberi dengan isian matcha', 'grams' => 90,
                'nutrients' => [...$draft['items'][0]['nutrients'], 'calories' => 225],
            ]],
        ];
        $saved = $this->postJson('/api/v1/meals', $meal)->assertCreated();
        $thumbnailUrl = $saved->json('data.thumbnail_url');
        $this->assertSame('/api/v1/meals/'.$saved->json('data.id').'/thumbnail', $thumbnailUrl);
        $thumbnailPath = $user->meals()->firstOrFail()->thumbnail_path;
        Storage::disk('local')->assertExists($thumbnailPath);
        $this->assertSame([240, 240], array_slice(getimagesizefromstring(Storage::disk('local')->get($thumbnailPath)), 0, 2));
        $this->get($thumbnailUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/days/'.now()->toDateString())->assertJsonPath('data.meals.0.thumbnail_url', $thumbnailUrl);
        $this->postJson('/api/v1/meals', $meal)->assertOk()->assertJsonPath('data.thumbnail_url', $thumbnailUrl);
        $this->assertDatabaseCount('meals', 1);
        Storage::disk('local')->assertExists($thumbnailPath);
        $this->actingAs($other);
        $this->get($thumbnailUrl)->assertNotFound();
        $this->actingAs($user);
        $this->assertDatabaseHas('meal_items', ['name' => 'Mochi matcha stroberi', 'description' => 'Mochi stroberi dengan isian matcha', 'calories' => 225]);
        $food = Food::query()->where('source', 'confirmed-photo')->sole();
        $this->assertSame($user->id, $food->user_id);
        $this->assertSame('Mochi stroberi dengan isian matcha', $food->description);
        $this->assertSame(90.0, $food->serving_grams);
        $this->assertSame(250.0, $food->calories);
        $edited = [...$meal, 'items' => [[...$meal['items'][0],
            'description' => 'Mochi stroberi isi matcha, porsi kecil',
            'nutrients' => [...$meal['items'][0]['nutrients'], 'calories' => 180],
        ]]];
        $this->putJson('/api/v1/meals/'.$saved->json('data.id'), $edited)->assertOk();
        $this->assertSame('Mochi stroberi isi matcha, porsi kecil', $food->fresh()->description);
        $this->assertSame(200.0, $food->fresh()->calories);
        $this->assertDatabaseMissing('meal_analyses', ['id' => $id]);
        Storage::disk('local')->assertMissing($analysis->image_path);

        $this->actingAs($other);
        $next = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('next.jpg', 640, 480), 'language' => 'en'])
            ->assertAccepted()->json('data.id');
        MealPhotoAgent::fake([[
            'title' => 'Strawberry matcha mochi', 'description' => 'Mochi with strawberry and matcha', 'grams' => 90,
            'nutrients' => ['calories' => 225, 'protein' => 4, 'carbs' => 43, 'fat' => 5, 'fiber' => 2, 'sodium' => null, 'potassium' => null, 'calcium' => null, 'iron' => null],
        ]]);
        (new AnalyzeMealPhoto($next))->handle(app(NutritionCalculator::class));
        MealPhotoAgent::assertPrompted(fn ($prompt) => $prompt->contains('English only') && $prompt->contains('Mochi stroberi isi matcha, porsi kecil'));
        $user->delete();
        $this->assertDatabaseMissing('foods', ['id' => $food->id]);
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
        $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.jpg', 640, 480), 'language' => 'zh'])
            ->assertUnprocessable()->assertJsonValidationErrors('language');
        $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.jpg', 640, 480), 'food_context' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('food_context');

        $first = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('one.jpg', 640, 480)])->json('data.id');
        $second = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('two.jpg', 640, 480)])->json('data.id');
        MealPhotoAgent::fake([[
            'title' => 'Unknown dish', 'description' => 'One mixed dish', 'grams' => 200,
            'nutrients' => ['calories' => 300, 'protein' => 10, 'carbs' => 40, 'fat' => 12, 'fiber' => null, 'sodium' => null, 'potassium' => null, 'calcium' => null, 'iron' => null],
        ]]);
        (new AnalyzeMealPhoto($first))->handle(app(NutritionCalculator::class));
        (new AnalyzeMealPhoto($second))->handle(app(NutritionCalculator::class));

        $this->getJson('/api/v1/meal-analyses/'.$first)->assertJsonPath('data.draft.items.0.nutrients.calories', 300);
        $this->getJson('/api/v1/meal-analyses/'.$second)->assertJsonPath('data.error_code', 'quota_exhausted');
        $this->assertDatabaseHas('ai_usage_days', ['attempts' => 1]);
    }

    public function test_chinese_model_text_is_rejected_before_reaching_the_review(): void
    {
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $id = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.jpg', 640, 480)])
            ->assertAccepted()->json('data.id');

        MealPhotoAgent::fake([[
            'title' => '草莓麻糬', 'description' => 'Mochi with strawberry', 'grams' => 90,
            'nutrients' => ['calories' => 230, 'protein' => 4, 'carbs' => 43, 'fat' => 5, 'fiber' => null, 'sodium' => null, 'potassium' => null, 'calcium' => null, 'iron' => null],
        ]]);
        (new AnalyzeMealPhoto($id))->handle(app(NutritionCalculator::class));

        $this->getJson('/api/v1/meal-analyses/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', 'language_mismatch')
            ->assertJsonPath('data.draft', null);

        $saved = $this->postJson('/api/v1/meals', [
            'client_request_id' => (string) Str::uuid(), 'analysis_id' => $id,
            'meal_date' => now()->toDateString(), 'meal_time' => '12:30', 'title' => 'Strawberry mochi',
            'items' => [['name' => 'Strawberry mochi', 'grams' => 90, 'nutrients' => [
                'calories' => 230, 'protein' => 4, 'carbs' => 43, 'fat' => 5,
            ]]],
        ])->assertCreated();
        $this->get($saved->json('data.thumbnail_url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_deleting_a_meal_removes_its_shared_dish_reference(): void
    {
        $user = User::factory()->create();
        $meal = $user->meals()->create([
            'client_request_id' => (string) Str::uuid(),
            'create_payload_sha256' => str_repeat('0', 64),
            'meal_date' => now()->toDateString(),
            'meal_time' => '12:30',
            'title' => 'Mochi',
            'source' => 'photo',
            'thumbnail_path' => 'meal-thumbnails/test.jpg',
        ]);
        Storage::fake('local');
        Storage::disk('local')->put($meal->thumbnail_path, 'thumbnail bytes');
        $food = Food::create([
            'user_id' => $user->id, 'name' => 'Mochi', 'description' => 'Small mochi',
            'serving_grams' => 90, 'source' => 'confirmed-photo', 'source_id' => (string) $meal->id,
            'calories' => 200, 'protein' => 4, 'carbs' => 40, 'fat' => 4,
        ]);

        $this->actingAs($user)->deleteJson('/api/v1/meals/'.$meal->id)->assertNoContent();

        $this->assertDatabaseMissing('foods', ['id' => $food->id]);
        Storage::disk('local')->assertMissing($meal->thumbnail_path);
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
