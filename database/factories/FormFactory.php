<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Form>
 */
class FormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Contact form',
            'retention_days' => Form::DEFAULT_RETENTION_DAYS,
        ];
    }
}
