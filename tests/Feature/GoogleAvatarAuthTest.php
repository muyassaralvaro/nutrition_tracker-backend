<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleAvatarAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_google_photo_is_imported_once_and_custom_photo_survives_later_sign_ins(): void
    {
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.frontend.url', 'http://localhost:3000');
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['https://lh3.googleusercontent.com/first' => Http::response(UploadedFile::fake()->image('google.jpg', 300, 200)->getContent(), 200, ['Content-Type' => 'image/jpeg'])]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->twice()->andReturn(
            GoogleUser::fake(['id' => 'google-123', 'email' => 'google@example.test', 'avatar' => 'https://lh3.googleusercontent.com/first']),
            GoogleUser::fake(['id' => 'google-123', 'email' => 'google@example.test', 'avatar' => 'https://lh3.googleusercontent.com/updated']),
        );
        Socialite::shouldReceive('driver')->twice()->with('google')->andReturn($provider);

        $this->getJson('/api/v1/me/avatar')->assertUnauthorized();
        $this->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        $user = User::where('google_subject', 'google-123')->sole();
        $googlePath = $user->avatar_path;
        $this->assertNotNull($googlePath);
        Storage::disk('local')->assertExists($googlePath);
        $this->assertSame([256, 256], array_slice(getimagesizefromstring(Storage::disk('local')->get($googlePath)), 0, 2));
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.avatar_url', '/api/v1/me/avatar?v='.basename($googlePath));
        $this->get('/api/v1/me/avatar')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');

        $changed = $this->putJson('/api/v1/me/avatar', ['image' => UploadedFile::fake()->image('mine.png', 400, 400)])->assertOk();
        $customPath = $user->fresh()->avatar_path;
        $this->assertNotSame($googlePath, $customPath);
        Storage::disk('local')->assertMissing($googlePath);
        Storage::disk('local')->assertExists($customPath);
        $changed->assertJsonPath('data.avatar_url', '/api/v1/me/avatar?v='.basename($customPath));
        $this->putJson('/api/v1/me/avatar', ['image' => UploadedFile::fake()->create('broken.jpg', 10, 'image/jpeg')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame($customPath, $user->fresh()->avatar_path);

        $this->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        $this->assertSame($customPath, $user->fresh()->avatar_path);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_linking_google_adds_photo_to_existing_account(): void
    {
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.frontend.url', 'http://localhost:3000');
        Storage::fake('local');
        Http::fake(['https://lh3.googleusercontent.com/linked' => Http::response(UploadedFile::fake()->image('linked.jpg', 100, 100)->getContent(), 200, ['Content-Type' => 'image/jpeg'])]);
        $user = User::factory()->create();

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
            'id' => 'google-456', 'email' => $user->email, 'avatar' => 'https://lh3.googleusercontent.com/linked',
        ]));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->actingAs($user)->withSession(['google_link_user_id' => $user->id])
            ->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/settings?oauth=linked');

        $this->actingAs($user->fresh(), 'web')
            ->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.avatar_url', '/api/v1/me/avatar?v='.basename($user->fresh()->avatar_path));
        Storage::disk('local')->assertExists($user->fresh()->avatar_path);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_subject' => 'google-456']);
    }

    public function test_google_photo_must_use_https_and_googleusercontent_host(): void
    {
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        Http::fake();

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->twice()->andReturn(
            GoogleUser::fake(['id' => 'google-789', 'email' => 'safe@example.test', 'avatar' => 'https://googleusercontent.com.evil.test/photo']),
            GoogleUser::fake(['id' => 'google-789', 'email' => 'safe@example.test', 'avatar' => 'http://lh3.googleusercontent.com/photo']),
        );
        Socialite::shouldReceive('driver')->twice()->with('google')->andReturn($provider);

        $this->get('/api/v1/auth/google/callback')->assertRedirect();
        $this->assertDatabaseHas('users', ['google_subject' => 'google-789', 'avatar_path' => null]);

        $this->get('/api/v1/auth/google/callback')->assertRedirect();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.avatar_url', null);
        Http::assertSentCount(0);
    }

    public function test_google_photo_download_failure_keeps_sign_in_available(): void
    {
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        Http::fake(['https://lh3.googleusercontent.com/bad' => Http::response('not an image', 200, ['Content-Type' => 'text/html'])]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
            'id' => 'google-bad', 'email' => 'bad@example.test', 'avatar' => 'https://lh3.googleusercontent.com/bad',
        ]));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->get('/api/v1/auth/google/callback')->assertRedirect();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.avatar_url', null);
    }
}
