<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoAuthTest extends TestCase
{
    public function test_finance_pages_require_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_real_credentials_start_and_end_an_authenticated_session(): void
    {
        User::factory()->create(['email' => 'owner@example.com', 'password' => 'Password!234']);

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'Password!234'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->from('/login')->post('/login', ['email' => 'wrong@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }

    public function test_install_command_creates_one_owner_and_default_categories(): void
    {
        $this->artisan('mycoins:install', ['--name' => 'Proprietário', '--email' => 'owner@example.com'])
            ->assertSuccessful();

        $owner = User::firstOrFail();
        $this->assertTrue($owner->must_change_password);
        $this->assertSame('Proprietário', $owner->name);
        $this->assertCount(17, Category::all());
        $this->assertSame(11, Category::where('type', 'expense')->count());
        $this->assertSame(6, Category::where('type', 'income')->count());
        foreach (DefaultCategories::definitions() as $category) {
            $this->assertDatabaseHas('categories', $category + ['user_id' => $owner->id]);
        }

        $this->artisan('mycoins:install', ['--name' => 'Outro', '--email' => 'other@example.com'])
            ->expectsOutputToContain('já possui um proprietário')
            ->assertSuccessful();
        $this->assertSame(1, User::count());
    }

    public function test_temporary_password_blocks_finance_until_a_strong_password_is_set(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'Temporary!234',
            'must_change_password' => true,
        ]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Temporary!234']);

        $this->get('/dashboard')->assertRedirect('/password/change');
        $this->get('/password/change')->assertOk()->assertSee('Crie uma nova senha');
        $this->put('/password/change', [
            'current_password' => 'Temporary!234',
            'password' => 'NewPassword!234',
            'password_confirmation' => 'NewPassword!234',
        ])->assertRedirect('/dashboard');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPassword!234', $user->fresh()->password));
        $this->get('/dashboard')->assertOk();
    }

    public function test_reset_password_command_marks_owner_for_another_required_change(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->artisan('mycoins:reset-password')->assertSuccessful();

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertNull($user->fresh()->password_changed_at);
    }
}
