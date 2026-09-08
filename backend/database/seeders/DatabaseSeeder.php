<?php

namespace Database\Seeders;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::findOrCreate(UserRole::Admin);
        Role::findOrCreate(UserRole::User);

        // User::factory(10)->create();

        User::factory()->admin()->create([
            'name' => 'Test Admin',
            'email' => 'test@example.com',
        ]);
    }
}
