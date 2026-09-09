<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $attributes = Validator::make([
            'name' => config('admin.name'),
            'email' => config('admin.email'),
            'password' => config('admin.password'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate();

        DB::transaction(function () use ($attributes): void {
            $user = User::query()->firstOrCreate(['email' => $attributes['email']], $attributes);

            if (! $user->wasRecentlyCreated && ! $user->hasRole(UserRole::Admin)) {
                throw new RuntimeException('ADMIN_EMAIL belongs to an existing non-admin user. Choose an unused email or manage its role explicitly.');
            }

            $user->assignRole(Role::findOrCreate(UserRole::Admin, 'web'));
        });
    }
}
