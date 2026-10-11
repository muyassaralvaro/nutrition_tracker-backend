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
        $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        $user = User::where('google_subject', 'google-123')->sole();
        $this->assertSame('google@example.test', $user->google_email);
        $this->assertNotNull($user->accepted_terms_at);
        $googlePath = $user->avatar_path;
        $this->assertNotNull($googlePath);
        Storage::disk('local')->assertExists($googlePath);
        $this->assertSame([256, 256], array_slice(getimagesizefromstring(Storage::disk('local')->get($googlePath)), 0, 2));
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.avatar_url', '/api/v1/me/avatar?v='.basename($googlePath));
        $this->get('/api/v1/me/avatar')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/me/avatar?v='.basename($googlePath))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=3600, private');

        $changed = $this->putJson('/api/v1/me/avatar', ['image' => UploadedFile::fake()->image('mine.png', 400, 400)])->assertOk();
        $customPath = $user->fresh()->avatar_path;
        $this->assertNotSame($googlePath, $customPath);
        Storage::disk('local')->assertMissing($googlePath);
        Storage::disk('local')->assertExists($customPath);
        $changed->assertJsonPath('data.avatar_url', '/api/v1/me/avatar?v='.basename($customPath));
        $this->get('/api/v1/me/avatar?v='.basename($googlePath))->assertNotFound();
        $this->get($changed->json('data.avatar_url'))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=3600, private');
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
        $this->assertDatabaseHas('users', ['id' => $user->id, 'google_subject' => 'google-456', 'google_email' => $user->email]);
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

        $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')->assertRedirect();
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

        $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')->assertRedirect();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.avatar_url', null);
    }

    public function test_new_google_account_requires_consent_and_records_acceptance(): void
    {
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.frontend.url', 'http://localhost:3000');
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->twice()->andReturn(GoogleUser::fake([
            'id' => 'google-new', 'email' => 'new@example.test', 'avatar' => null,
        ]));
        Socialite::shouldReceive('driver')->twice()->with('google')->andReturn($provider);

        $this->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/login?oauth=consent_required');
        $this->assertDatabaseMissing('users', ['google_subject' => 'google-new']);

        $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        $this->assertNotNull(User::where('google_subject', 'google-new')->sole()->accepted_terms_at);
    }

    public function test_google_registration_redirect_remembers_consent_for_callback(): void
    {
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->get('/api/v1/auth/google/redirect?consent=1')
            ->assertRedirect('https://accounts.google.com')
            ->assertSessionHas('google_signup_consent', true);
    }

    public function test_new_google_accounts_are_limited_by_ip_without_blocking_existing_sign_in(): void
    {
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.frontend.url', 'http://localhost:3000');
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->times(5)->andReturn(
            ...array_map(fn (int $number) => GoogleUser::fake([
                'id' => 'google-ip-'.$number, 'email' => 'ip-'.$number.'@example.test', 'avatar' => null,
            ]), [1, 2, 3, 4, 1]),
        );
        Socialite::shouldReceive('driver')->times(5)->with('google')->andReturn($provider);

        for ($number = 1; $number <= 3; $number++) {
            $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        }
        $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')
            ->assertRedirect('http://localhost:3000/login?oauth=signup_limited');
        $this->assertDatabaseCount('users', 3);
        $this->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
    }

    public function test_developer_google_signup_bypasses_full_ip_allowance_without_refilling_it(): void
    {
        config()->set('app.developer_account', 'Developer@Example.test');
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.frontend.url', 'http://localhost:3000');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.37']);
        $provider = Mockery::mock(Provider::class);
        $googleUsers = array_map(fn (int $number) => GoogleUser::fake([
            'id' => 'google-signup-'.$number, 'email' => 'signup-'.$number.'@example.test', 'avatar' => null,
        ]), [1, 2, 3]);
        $googleUsers[] = GoogleUser::fake(['id' => 'google-developer', 'email' => 'developer@example.test', 'avatar' => null]);
        $googleUsers[] = GoogleUser::fake(['id' => 'google-signup-4', 'email' => 'signup-4@example.test', 'avatar' => null]);
        $provider->shouldReceive('user')->times(5)->andReturn(...$googleUsers);
        Socialite::shouldReceive('driver')->times(5)->with('google')->andReturn($provider);

        for ($number = 0; $number < 3; $number++) {
            $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        }
        $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        $this->getJson('/api/v1/me/analysis-quota')->assertOk()->assertJsonPath('data.unlimited', true);
        $this->withSession(['google_signup_consent' => true])->get('/api/v1/auth/google/callback')
            ->assertRedirect('http://localhost:3000/login?oauth=signup_limited');
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseHas('users', ['email' => 'developer@example.test', 'google_subject' => 'google-developer', 'google_email' => 'developer@example.test']);
    }

    public function test_google_login_records_actual_google_email_for_developer_check(): void
    {
        config()->set('app.developer_account', 'developer@example.test');
        config()->set('services.google.client_id', 'test-id');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.frontend.url', 'http://localhost:3000');
        $user = User::factory()->create(['email' => 'developer@example.test', 'google_subject' => 'google-other']);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn(GoogleUser::fake([
            'id' => 'google-other', 'email' => 'other@example.test', 'avatar' => null,
        ]));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->actingAs($user)->getJson('/api/v1/me/analysis-quota')->assertOk()->assertJsonPath('data.unlimited', false);
        $this->get('/api/v1/auth/google/callback')->assertRedirect('http://localhost:3000/home');
        $this->assertSame('developer@example.test', $user->fresh()->email);
        $this->assertSame('other@example.test', $user->fresh()->google_email);
        $this->getJson('/api/v1/me/analysis-quota')->assertOk()->assertJsonPath('data.unlimited', false);
    }
}
