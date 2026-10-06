<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminUser> */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Correct-Horse-9-Battery!',
            'is_active' => true,
            // MFA configured so tests are not redirected to the mandatory set-up page.
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
        ];
    }

    public function withoutMfa(): static
    {
        return $this->state(['app_authentication_secret' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
