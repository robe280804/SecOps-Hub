<?php

namespace Database\Seeders;

use App\Enums\UserRole;
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

        $this->call(AdminSeeder::class);
    }
}
