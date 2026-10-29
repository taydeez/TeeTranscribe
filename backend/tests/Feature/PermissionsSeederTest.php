<?php

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(LazilyRefreshDatabase::class);

test('permission seeding is repeatable when model events are disabled', function () {
    app(PermissionRegistrar::class)->getPermissions();
    Model::withoutEvents(function () {
        $this->seed(PermissionsSeeder::class);
        $this->seed(PermissionsSeeder::class);
    });

    expect(Permission::query()->where('name', 'View_Dashboard')->where('guard_name', 'web')->count())->toBe(1);
    expect(Permission::query()->count())->toBe(count(PermissionsSeeder::MODELS) * count(PermissionsSeeder::ACTIONS));
    expect(Role::findByName('admin', 'web')->hasPermissionTo('View_Dashboard', 'web'))->toBeTrue();
    expect(Role::findByName('super_admin', 'web')->hasPermissionTo('Create_Role', 'web'))->toBeTrue();
    expect(Role::findByName('user', 'web')->permissions()->count())->toBe(0);
});

test('database seeder can run twice without duplicate permissions', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Permission::query()->where('name', 'View_Dashboard')->where('guard_name', 'web')->count())->toBe(1);
    expect(Role::findByName('super_admin', 'web')->permissions()->count())
        ->toBe(count(PermissionsSeeder::MODELS) * count(PermissionsSeeder::ACTIONS));
});
