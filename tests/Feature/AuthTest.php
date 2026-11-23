<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_successfully(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'phone_number' => '09987654321',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'maria@example.com')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'uuid', 'name', 'email', 'phone_number', 'role', 'is_active'],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'maria@example.com',
            'role' => 'customer',
        ]);
    }

    public function test_user_can_login_and_retrieve_profile(): void
    {
        $user = User::create([
            'name' => 'Staff Member',
            'email' => 'staff@canteen.local',
            'password' => 'staffpass123',
            'role' => 'staff',
            'is_active' => true,
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@canteen.local',
            'password' => 'staffpass123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'staff');

        $token = $loginResponse->json('data.token');

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'staff@canteen.local');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::create([
            'name' => 'Deactivated User',
            'email' => 'inactive@canteen.local',
            'password' => 'password123',
            'role' => 'customer',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@canteen.local',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
