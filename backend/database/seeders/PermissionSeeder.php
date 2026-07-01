<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    /**
     * module => list of actions.
     *
     * @var array<string, list<string>>
     */
    private array $matrix = [
        'tickets' => ['view', 'create', 'update', 'delete', 'assign', 'comment', 'vote', 'export'],
        'assets' => ['view', 'create', 'update', 'delete', 'transfer', 'dispose'],
        'maintenance' => ['view', 'create', 'update', 'delete', 'complete'],
        'inventory' => ['view', 'create', 'update', 'delete', 'adjust'],
        'ai' => ['view', 'configure', 'feedback'],
        'knowledge' => ['view', 'create', 'update', 'delete', 'publish'],
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'manage'],
        'reports' => ['view', 'export'],
        'floorplan' => ['view', 'manage'],
        'system' => ['settings.manage', 'backup.manage', 'audit.view', 'announcements.manage'],
    ];

    public function run(): void
    {
        foreach ($this->matrix as $module => $actions) {
            foreach ($actions as $action) {
                $name = "{$module}.{$action}";
                Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['slug' => Str::slug($name, '.'), 'module' => $module, 'description' => Str::headline("{$module} {$action}")],
                );
            }
        }

        $this->assignToRoles();
    }

    private function assignToRoles(): void
    {
        $all = Permission::query()->pluck('id')->all();

        $technician = Permission::query()->where(function ($q) {
            $q->whereIn('module', ['maintenance'])
                ->orWhereIn('name', [
                    'tickets.view', 'tickets.update', 'tickets.assign', 'tickets.comment', 'tickets.export',
                    'assets.view', 'assets.update', 'assets.transfer',
                    'inventory.view', 'inventory.adjust',
                    'ai.view', 'ai.feedback',
                    'knowledge.view', 'knowledge.create',
                    'reports.view', 'floorplan.view',
                ]);
        })->pluck('id')->all();

        $teacher = Permission::query()->whereIn('name', [
            'tickets.view', 'tickets.create', 'tickets.comment', 'tickets.vote',
            'knowledge.view', 'ai.view', 'ai.feedback',
        ])->pluck('id')->all();

        Role::query()->where('slug', 'administrator')->first()?->permissions()->syncWithoutDetaching($all);
        Role::query()->where('slug', 'technician')->first()?->permissions()->syncWithoutDetaching($technician);
        Role::query()->where('slug', 'teacher')->first()?->permissions()->syncWithoutDetaching($teacher);
    }
}
