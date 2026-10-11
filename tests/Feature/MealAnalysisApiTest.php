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
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
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

    public function test_text_only_estimate_uses_user_serving_and_requires_confirmation_before_saving(): void
    {
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        Queue::fake([AnalyzeMealPhoto::class]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/v1/meal-analyses', ['description' => 'with coconut milk'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/meal-analyses', ['name' => 'Nasi uduk', 'grams' => 2001])
            ->assertUnprocessable()->assertJsonValidationErrors('grams');
        $this->postJson('/api/v1/meal-analyses', ['name' => 'Nasi uduk', 'image' => UploadedFile::fake()->image('plate.jpg', 640, 480)])
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $id = $this->postJson('/api/v1/meal-analyses', [
            'name' => ' Nasi uduk ', 'description' => ' coconut rice with egg ', 'grams' => 250, 'language' => 'id',
        ])->assertAccepted()->assertJsonPath('data.image_url', null)->json('data.id');
        Queue::assertPushed(AnalyzeMealPhoto::class, fn (AnalyzeMealPhoto $job) => $job->textDetails === [
            'name' => 'Nasi uduk', 'description' => 'coconut rice with egg', 'grams' => 250.0,
        ]);
        MealPhotoAgent::fake([[
            'title' => 'Nasi uduk', 'description' => 'Nasi gurih dengan telur', 'grams' => 200,
            'nutrients' => ['calories' => 450, 'protein' => 15, 'carbs' => 65, 'fat' => 14, 'fiber' => null, 'sodium' => null, 'potassium' => null, 'calcium' => null, 'iron' => null],
        ]]);

        (new AnalyzeMealPhoto($id, '', ['name' => 'Nasi uduk', 'description' => 'coconut rice with egg', 'grams' => 250.0]))
            ->handle(app(NutritionCalculator::class));

        MealPhotoAgent::assertPrompted(fn ($prompt) => $prompt->contains('No photo was supplied') && $prompt->contains('Nasi uduk') && $prompt->contains('Indonesian only'));
        $this->getJson('/api/v1/meal-analyses/'.$id)->assertOk()
            ->assertJsonPath('data.draft.items.0.grams', 250)
            ->assertJsonPath('data.draft.items.0.nutrients.calories', 562.5);
        $this->assertDatabaseCount('meals', 0);

        $this->postJson('/api/v1/meals', [
            'client_request_id' => (string) Str::uuid(), 'analysis_id' => $id, 'meal_date' => now()->toDateString(),
            'meal_time' => '12:30', 'title' => 'Nasi uduk',
            'items' => [['name' => 'Nasi uduk', 'description' => 'Nasi gurih dengan telur', 'grams' => 250, 'nutrients' => [
                'calories' => 450, 'protein' => 15, 'carbs' => 65, 'fat' => 14,
            ]]],
        ])->assertCreated()->assertJsonPath('data.source', 'manual');
        $this->assertDatabaseMissing('meal_analyses', ['id' => $id]);

        $nameOnly = $this->postJson('/api/v1/meal-analyses', ['name' => 'Soto ayam'])
            ->assertAccepted()->json('data.id');
        Queue::assertPushed(AnalyzeMealPhoto::class, fn (AnalyzeMealPhoto $job) => $job->analysisId === $nameOnly && $job->textDetails === [
            'name' => 'Soto ayam', 'description' => '', 'grams' => null,
        ]);
        $this->deleteJson('/api/v1/meal-analyses/'.$nameOnly)->assertNoContent();
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
        $thumbnailPath = $user->meals()->firstOrFail()->thumbnail_path;
        $this->assertSame('/api/v1/meals/'.$saved->json('data.id').'/thumbnail?v='.basename($thumbnailPath), $thumbnailUrl);
        Storage::disk('local')->assertExists($thumbnailPath);
        $this->assertSame([240, 240], array_slice(getimagesizefromstring(Storage::disk('local')->get($thumbnailPath)), 0, 2));
        $this->get($thumbnailUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'immutable, max-age=3600, private');
        $this->get('/api/v1/meals/'.$saved->json('data.id').'/thumbnail')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/meals/'.$saved->json('data.id').'/thumbnail?v=outdated.jpg')->assertNotFound();
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

    public function test_daily_analysis_and_per_meal_regeneration_limits_survive_draft_deletion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        Queue::fake();
        $this->getJson('/api/v1/me/analysis-quota')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->getJson('/api/v1/me/analysis-quota')->assertJsonPath('data.daily_remaining', 7)
            ->assertJsonPath('data.date', '2026-10-09')
            ->assertJsonPath('data.resets_at', '2026-10-10T00:00:00+07:00');
        $first = null;
        for ($number = 0; $number < 7; $number++) {
            $id = $this->postJson('/api/v1/meal-analyses', ['name' => 'Meal '.$number])
                ->assertAccepted()->json('data.id');
            $first ??= $id;
            $this->deleteJson('/api/v1/meal-analyses/'.$id)->assertNoContent();
        }
        $this->getJson('/api/v1/me/analysis-quota')->assertJsonPath('data.daily_used', 7)->assertJsonPath('data.daily_remaining', 0);
        $this->postJson('/api/v1/meal-analyses', ['name' => 'Eighth meal'])->assertStatus(429)->assertJsonPath('code', 'daily_analysis_limit');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $id = $this->postJson('/api/v1/meal-analyses', ['name' => 'Meal 0', 'retry_of' => $first])
                ->assertAccepted()->json('data.id');
            $this->deleteJson('/api/v1/meal-analyses/'.$id)->assertNoContent();
            $this->getJson('/api/v1/me/analysis-quota?analysis_id='.$first)
                ->assertJsonPath('data.regenerations_used', $attempt)
                ->assertJsonPath('data.regenerations_remaining', 2 - $attempt);
        }
        $this->postJson('/api/v1/meal-analyses', ['name' => 'Meal 0', 'retry_of' => $first])
            ->assertStatus(429)->assertJsonPath('code', 'regeneration_limit');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/me/analysis-quota?analysis_id='.$first)->assertNotFound();

        $this->travelTo(Carbon::parse('2026-10-09 16:59:00', 'UTC'));
        $this->actingAs($user)->getJson('/api/v1/me/analysis-quota')->assertJsonPath('data.daily_remaining', 0);
        $this->travelTo(Carbon::parse('2026-10-09 17:01:00', 'UTC'));
        $this->getJson('/api/v1/me/analysis-quota')->assertJsonPath('data.daily_remaining', 7)
            ->assertJsonPath('data.date', '2026-10-10')
            ->assertJsonPath('data.resets_at', '2026-10-11T00:00:00+07:00');
        $this->postJson('/api/v1/meal-analyses', ['name' => 'Next day meal'])->assertAccepted();
        $this->assertDatabaseHas('meal_analysis_usage', ['user_id' => $user->id, 'usage_date' => '2026-10-10', 'is_regeneration' => false]);
    }

    public function test_google_developer_account_can_exceed_daily_and_regeneration_limits(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config()->set('app.developer_account', 'Developer@Example.test');
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        Queue::fake();
        $this->actingAs(User::factory()->create(['email' => 'account@example.test', 'google_subject' => 'google-developer', 'google_email' => 'developer@example.test']));

        $this->getJson('/api/v1/me/analysis-quota')->assertOk()
            ->assertJsonPath('data.unlimited', true)
            ->assertJsonPath('data.daily_limit', null)
            ->assertJsonPath('data.daily_remaining', null);

        $first = null;
        for ($number = 0; $number < 8; $number++) {
            $id = $this->postJson('/api/v1/meal-analyses', ['name' => 'Meal '.$number])->assertAccepted()->json('data.id');
            $first ??= $id;
            $this->deleteJson('/api/v1/meal-analyses/'.$id)->assertNoContent();
        }

        for ($number = 0; $number < 3; $number++) {
            $id = $this->postJson('/api/v1/meal-analyses', ['name' => 'Meal 0', 'retry_of' => $first])->assertAccepted()->json('data.id');
            $this->deleteJson('/api/v1/meal-analyses/'.$id)->assertNoContent();
        }

        $this->getJson('/api/v1/me/analysis-quota?analysis_id='.$first)->assertOk()
            ->assertJsonPath('data.daily_used', 8)
            ->assertJsonPath('data.regenerations_used', 3)
            ->assertJsonPath('data.regeneration_limit', null)
            ->assertJsonPath('data.regenerations_remaining', null);
        $this->postJson('/api/v1/meal-analyses', ['name' => 'Different meal', 'retry_of' => $first])
            ->assertUnprocessable()->assertJsonPath('code', 'different_meal');

        config()->set('app.developer_account', null);
        $this->getJson('/api/v1/me/analysis-quota')->assertJsonPath('data.unlimited', false)->assertJsonPath('data.daily_remaining', 0);
        $this->postJson('/api/v1/meal-analyses', ['name' => 'Another meal'])
            ->assertStatus(429)->assertJsonPath('code', 'daily_analysis_limit');
    }

    public function test_matching_email_without_google_identity_is_not_a_developer_account(): void
    {
        config()->set('app.developer_account', 'developer@example.test');
        $this->actingAs(User::factory()->create(['email' => 'developer@example.test']));

        $this->getJson('/api/v1/me/analysis-quota')->assertOk()
            ->assertJsonPath('data.unlimited', false)
            ->assertJsonPath('data.daily_limit', 7)
            ->assertJsonPath('data.daily_remaining', 7);
    }

    public function test_photo_regeneration_requires_the_same_photo_even_after_draft_deletion(): void
    {
        config()->set('ai.nutrition_enabled', true);
        config()->set('ai.providers.nine_router.url', 'https://example.test/v1');
        config()->set('ai.providers.nine_router.key', 'test-key');
        config()->set('ai.providers.nine_router.models.text.default', 'test-model');
        Storage::fake('local');
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $first = $this->postJson('/api/v1/meal-analyses', ['image' => UploadedFile::fake()->image('plate.jpg', 640, 480)])
            ->assertAccepted()->json('data.id');
        $this->deleteJson('/api/v1/meal-analyses/'.$first)->assertNoContent();

        $this->postJson('/api/v1/meal-analyses', [
            'image' => UploadedFile::fake()->image('different.jpg', 700, 480), 'retry_of' => $first,
        ])->assertUnprocessable()->assertJsonPath('code', 'different_meal');
        $this->postJson('/api/v1/meal-analyses', [
            'image' => UploadedFile::fake()->image('same.jpg', 640, 480), 'retry_of' => $first,
        ])->assertAccepted();
        $this->getJson('/api/v1/me/analysis-quota?analysis_id='.$first)
            ->assertJsonPath('data.daily_used', 1)->assertJsonPath('data.regenerations_used', 1);
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
