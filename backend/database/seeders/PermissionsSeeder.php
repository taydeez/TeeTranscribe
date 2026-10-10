<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    public const MODELS = [
        'AdminAccount', 'Role', 'User', 'Folder', 'Transcription', 'TranscriptionExport', 'Translation',
        'Dubbing', 'CreditPackage', 'CreditLedger', 'Usage', 'PaymentMethod', 'PaymentTransaction',
        'Invoice', 'AIProvider', 'Pricing', 'Setting', 'PrivacyDeletion', 'OutboxEvent', 'Dashboard',
    ];

    public const ACTIONS = ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore'];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $permissions = [];
        foreach (self::MODELS as $model) {
            foreach (self::ACTIONS as $action) {
                $name = "{$action}_{$model}";
                $permissions[$name] = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
        }
        $registrar->forgetCachedPermissions();
        Role::findOrCreate('user', 'web');
        $admin = Role::findOrCreate('admin', 'web');
        $admin->givePermissionTo($permissions['View_Dashboard']);
        Role::findOrCreate('super_admin', 'web')->syncPermissions($permissions);
        $registrar->forgetCachedPermissions();
    }
}
