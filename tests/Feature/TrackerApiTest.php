<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrackerApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_phone_registration_creates_a_session(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ayu',
            'phone_e164' => '+6281234567890',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()->assertJsonPath('data.phone_e164', '+6281234567890');
        $this->assertDatabaseHas('users', ['phone_e164' => '+6281234567890']);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.name', 'Ayu');
    }

    public function test_password_change_rejects_old_password_after_logout(): void
    {
        User::factory()->create(['phone_e164' => '+6281234567892', 'password' => 'oldsecret123']);
        $this->postJson('/api/v1/auth/login', ['phone_e164' => '+6281234567892', 'password' => 'oldsecret123'])->assertOk();
        $this->patchJson('/api/v1/me/password', [
            'current_password' => 'oldsecret123', 'password' => 'newsecret123', 'password_confirmation' => 'newsecret123',
        ])->assertNoContent();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['phone_e164' => '+6281234567892', 'password' => 'oldsecret123'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['phone_e164' => '+6281234567892', 'password' => 'newsecret123'])->assertOk();
    }

    public function test_target_estimate_matches_frontend_formula(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'UTC'));
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/target-estimates', [
            'birth_date' => '1996-05-01',
            'height_cm' => 175,
            'weight_kg' => 70,
            'sex' => 'male',
            'body_build' => 'lean',
            'goal' => 'maintain',
            'activity_minutes' => 30,
            'activity_type' => 'daily',
            'timezone' => 'Asia/Jakarta',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.targets.calories', 1980)
            ->assertJsonPath('data.targets.protein', 84)
            ->assertJsonPath('data.targets.carbs', 272)
            ->assertJsonPath('data.targets.sodium', 2300);
    }

    public function test_invalid_setup_saves_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->putJson('/api/v1/me/setup', [
            'name' => 'Ayu',
            'birth_date' => '1996-05-01',
            'height_cm' => 175,
            'weight_kg' => 70,
            'sex' => 'female',
            'body_build' => 'lean',
            'goal' => 'maintain',
            'activity_minutes' => 30,
            'activity_type' => 'daily',
            'timezone' => 'Asia/Jakarta',
            'unit_system' => 'metric',
            'target_source' => 'edited',
            'targets' => ['calories' => 1980, 'protein' => 0, 'carbs' => 272, 'fat' => 62, 'fiber' => 25, 'sodium' => 2300, 'potassium' => 2600, 'calcium' => 1000, 'iron' => 18],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('targets.protein');
        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('weight_entries', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('nutrient_targets', ['user_id' => $user->id]);
    }

    public function test_meal_retries_do_not_duplicate_nutrition(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = [
            'client_request_id' => (string) Str::uuid(),
            'meal_date' => now()->toDateString(),
            'meal_time' => '12:30',
            'title' => 'Rice and eggs',
            'items' => [[
                'name' => 'Rice and eggs',
                'nutrients' => ['calories' => 400, 'protein' => 20, 'carbs' => 50, 'fat' => 14],
            ]],
        ];

        $this->postJson('/api/v1/meals', $payload)->assertCreated()->assertJsonPath('data.nutrition.known.sodium', false);
        $this->postJson('/api/v1/meals', $payload)->assertOk();
        $this->postJson('/api/v1/meals', [...$payload, 'title' => 'Changed'])->assertConflict();

        $this->assertDatabaseCount('meals', 1);
        $this->assertDatabaseCount('meal_items', 1);
    }

    public function test_calendar_uses_historical_targets_and_marks_unknown_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $targets = [
            'source' => 'edited', 'formula_version' => 'v1', 'calories' => 2000,
            'protein' => 80, 'carbs' => 250, 'fat' => 60, 'fiber' => 25,
            'sodium' => 2300, 'potassium' => 2600, 'calcium' => 1000, 'iron' => 18,
        ];
        $user->targets()->create(['effective_on' => '2024-02-01', ...$targets]);
        $user->targets()->create(['effective_on' => '2024-02-29', ...$targets, 'calories' => 2200]);

        foreach (['2024-02-28', '2024-02-29'] as $date) {
            $meal = $user->meals()->create([
                'client_request_id' => (string) Str::uuid(), 'create_payload_sha256' => str_repeat('0', 64),
                'meal_date' => $date, 'meal_time' => '12:00', 'title' => 'Daily food', 'source' => 'manual',
            ]);
            $meal->items()->create([
                'position' => 0, 'name' => 'Daily food',
                'calories' => 2000, 'protein' => 80, 'carbs' => 250, 'fat' => 60,
                'fiber' => $date === '2024-02-29' ? null : 25,
                'sodium' => 2000, 'potassium' => 2600, 'calcium' => 1000, 'iron' => 18,
            ]);
        }

        $this->getJson('/api/v1/days/2024-02-28')->assertOk()
            ->assertJsonPath('data.target.calories', 2000)
            ->assertJsonPath('data.nutrition.status', 'complete');
        $this->getJson('/api/v1/days/2024-02-29')->assertOk()
            ->assertJsonPath('data.target.calories', 2200)
            ->assertJsonPath('data.nutrition.status', 'partial')
            ->assertJsonPath('data.nutrition.known.fiber', false)
            ->assertJsonPath('data.nutrition.totals.fiber', null);
        $this->getJson('/api/v1/calendar?month=2024-02')->assertOk()
            ->assertJsonCount(29, 'data.days')
            ->assertJsonPath('data.completed_days', 1)
            ->assertJsonPath('data.days.28.status', 'partial');
        $this->getJson('/api/v1/days/2024-02-30')->assertUnprocessable();
    }

    public function test_account_deletion_ledger_prevents_restore_from_resurrecting_account(): void
    {
        $ledger = storage_path('framework/testing/deletions-'.Str::uuid().'.jsonl');
        config()->set('app.account_deletion_ledger_path', $ledger);
        $user = User::factory()->create(['phone_e164' => '+6281234567891', 'password' => 'secret123']);
        $id = $user->id;
        $createdAt = $user->created_at;
        $passwordHash = $user->password;

        try {
            $this->actingAs($user)->withSession(['authenticated_at' => now()->timestamp])
                ->deleteJson('/api/v1/me', ['password' => 'secret123'])->assertNoContent();
            $this->assertFileExists($ledger);
            $this->assertSame($id, json_decode(file_get_contents($ledger), true)['user_id']);
            $this->assertDatabaseMissing('users', ['id' => $id]);

            DB::table('users')->insert([
                'id' => $id, 'name' => 'Restored', 'phone_e164' => '+6281234567891',
                'password' => $passwordHash, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            $this->artisan('app:replay-account-deletions')->assertExitCode(0);
            $this->assertDatabaseMissing('users', ['id' => $id]);
        } finally {
            File::delete($ledger);
        }
    }
}
