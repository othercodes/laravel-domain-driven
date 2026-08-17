<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Infrastructure\Persistence\Seeders;

use App\IdentityAndAccess\Users\Domain\User;
use Illuminate\Database\Seeder;

/**
 * Class UserSeeder
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Through the factory rather than User::create(), so the seeded user
        // goes through new() and gets an identifier and a UserCreated event
        // like any user the application layer makes.
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
