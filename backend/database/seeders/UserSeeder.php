<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $userRole = Role::findOrCreate('user', 'web');
        $adminRole = Role::findOrCreate('admin', 'web');
        $adminRole->syncPermissions([
            Permission::findOrCreate('access admin', 'web'),
            Permission::findOrCreate('manage users', 'web'),
        ]);
        User::query()->updateOrCreate(['email' => 'user@example.com'], ['name' => 'Example User', 'password' => 'password'])->syncRoles([$userRole]);
        User::query()->updateOrCreate(['email' => 'admin@example.com'], ['name' => 'Example Admin', 'password' => 'password'])->syncRoles([$adminRole]);
    }
}
