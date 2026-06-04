<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 联系人工厂
 *
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'identification_number' => fake()->numerify('##################'),
            'title' => fake()->randomElement(['Manager', 'Engineer', 'Director', 'Officer', 'Analyst', 'Specialist', 'Consultant', 'Coordinator', 'Supervisor', 'Administrator']),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
        ];
    }
}
