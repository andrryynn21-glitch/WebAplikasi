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
            ->assertSee('id="app-dialog"', false)
            ->assertSee('id="toasts"', false)
            ->assertDontSee('Bearer ')
            ->assertDontSee('reporter_id');
    }

    public function test_frontend_entry_does_not_replace_api_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
