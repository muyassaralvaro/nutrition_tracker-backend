<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_public_root_and_private_file_url_are_not_served(): void
    {
        $this->get('/')->assertNotFound();
        $this->get('/storage/meal-analyses/example.jpg')->assertNotFound();
    }

    public function test_private_gets_require_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me/weights')->assertUnauthorized();
        $this->getJson('/api/v1/meals')->assertUnauthorized();
        $this->getJson('/api/v1/calendar')->assertUnauthorized();
        $this->getJson('/api/v1/auth/options')->assertOk();
    }
}
