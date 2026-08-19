<?php

namespace Tests\Feature;

use Tests\TestCase;

class DemoAuthTest extends TestCase
{
    public function test_finance_pages_require_demo_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_demo_credentials_start_and_end_a_session(): void
    {
        $this->post('/login', ['email' => 'demo@mycoins.local', 'password' => 'demo1234'])
            ->assertRedirect('/dashboard');
        $this->assertTrue(session('demo_authenticated'));

        $this->post('/logout')->assertRedirect('/login');
        $this->assertFalse(session()->has('demo_authenticated'));
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->from('/login')->post('/login', ['email' => 'wrong@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }
}
