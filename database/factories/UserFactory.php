<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\IAM\Domain\IdentityState;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre_mostrado' => fake()->name(),
            'correo_normalizado' => fake()->unique()->safeEmail(),
            'estado' => IdentityState::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['estado' => IdentityState::Suspended]);
    }
}
