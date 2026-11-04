<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendShellTest extends TestCase
{
    public function test_public_shell_is_indonesian_and_contains_no_private_user_data(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertViewIs('app')
            ->assertSee('lang="id"', false)
            ->assertSee('data-api-base=', false)
            ->assertSee('data-api-credentials="omit"', false)
            ->assertSee('id="app-dialog"', false)
            ->assertSee('id="toasts"', false)
            ->assertDontSee('Bearer ')
            ->assertDontSee('reporter_id');
    }

    public function test_public_shell_exposes_opt_in_credentials_configuration(): void
    {
        config(['app.frontend_api_credentials' => 'same-origin']);

        $this->withoutVite()->get('/')->assertOk()
            ->assertSee('data-api-credentials="same-origin"', false);
    }

    public function test_frontend_entry_does_not_replace_api_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_opt_in_cookies_do_not_start_api_sessions_or_replace_bearer_authentication(): void
    {
        config(['app.frontend_api_credentials' => 'same-origin', 'sanctum.stateful' => ['localhost']]);
        $this->withCredentials()->withCookie(config('session.cookie'), 'synthetic-session')
            ->withHeader('Origin', 'http://localhost');

        foreach (['/api/v1/auth/me', '/api/v1/attachments/1', '/api/v1/identity-verifications/1/ktm'] as $path) {
            $this->getJson($path)->assertUnauthorized()
                ->assertJsonPath('error.code', 'UNAUTHENTICATED');
            $this->assertFalse($this->app['request']->hasSession());
        }
    }
}
