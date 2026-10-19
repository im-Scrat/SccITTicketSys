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
        'locations' => ['view', 'create', 'update', 'delete'],
        'assets' => ['view', 'create', 'update', 'delete', 'transfer', 'dispose'],
        'maintenance' => ['view', 'create', 'update', 'delete', 'complete'],
        'inventory' => ['view', 'create', 'update', 'delete', 'adjust'],
        'ai' => ['view', 'configure', 'feedback'],
        'knowledge' => ['view', 'create', 'update', 'delete', 'publish'],
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'manage'],
        'reports' => ['view', 'export'],
        'floorplan' => ['view', 'manage'],
        // WP-M. Administrator-only predictive-maintenance findings. Granted to the
        // Administrator by the `$all` sync below and to nobody else; the policy
        // additionally requires the role (see PcPredictionAccess), so a per-user
        // grant to anyone else opens nothing.
        'predictions' => ['view', 'manage'],
        'system' => ['settings.manage', 'backup.manage', 'audit.view', 'announcements.manage'],

        // WP-L/WP-M: predictive-maintenance findings. Not folded into the `ai`
        // module deliberately — `ai.view`/`ai.feedback` are already seeded to
        // Technicians and Teachers (the ticket AI panel), and reusing either
        // for this surface would not make it Administrator-only. Same shape as
        // `floorplan`: a module nobody but the Administrator baseline holds.
        'predictions' => ['view', 'manage'],
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

        // The `assets.*` module is Administrator-only for the same reason as
        // `locations.*`: maintaining the equipment register is site
        // administration, not day-to-day work. A Technician reaches the machine
        // they are repairing through their assigned ticket or maintenance record,
        // via the narrow lookup at `/api/lookups/*` — authorized by
        // `tickets.*`/`maintenance.*`, never by an assets permission
        // (see AssetPolicy::selectAsset, SDD DD-38).
        // Phase 2.6: `tickets.assign` and `tickets.export` are withdrawn from the
        // Technician baseline. Assignment authority is the Administrator's, and
        // export is a whole-directory read that a technician who cannot browse
        // the directory must not have. `tickets.assign` remains grantable
        // *per-user* — FR-ASN-001's "(and permitted Technicians)" is the SRS's
        // own narrow exception for a lead technician distributing workload
        // (SDD DD-40). `tickets.view` is deliberately retained: it is scoped to
        // their own assignments by TicketVisibility, not by the permission.
        $technician = Permission::query()->where(function ($q) {
            $q->whereIn('module', ['maintenance'])
                ->orWhereIn('name', [
                    'tickets.view', 'tickets.update', 'tickets.comment',
                    'inventory.view', 'inventory.adjust',
                    'ai.view', 'ai.feedback',
                    'knowledge.view', 'knowledge.create',
                    'reports.view',
                ]);
        })->pluck('id')->all();

        // The `locations.*` module is Administrator-only: it is site administration,
        // not day-to-day work. Non-admins never receive `locations.view`.
        //
        // Where a non-admin workflow needs to name a place (a teacher reporting
        // where a fault is, a technician recording where work happened), the
        // narrow room lookup at `/api/lookups/*` serves it instead — authorized by
        // the permission of the workflow that needs it, not by a locations
        // permission (see RoomPolicy::selectLocation).
        $teacher = Permission::query()->whereIn('name', [
            'tickets.view', 'tickets.create', 'tickets.comment', 'tickets.vote',
            'knowledge.view', 'ai.view', 'ai.feedback',
        ])->pluck('id')->all();

        Role::query()->where('slug', 'administrator')->first()?->permissions()->syncWithoutDetaching($all);
        Role::query()->where('slug', 'technician')->first()?->permissions()->syncWithoutDetaching($technician);
        Role::query()->where('slug', 'teacher')->first()?->permissions()->syncWithoutDetaching($teacher);

        $this->revokeWithdrawnBaselineGrants();
    }

    /**
     * Permissions that were once seeded to a role and have since been withdrawn
     * from the baseline: role slug => permission names to detach.
     *
     * Grants are applied with `syncWithoutDetaching` so that runtime adjustments
     * an Administrator makes (FR-USER-010) survive re-seeding — which also means a
     * grant removed from the matrix above would otherwise linger forever in an
     * existing database. This list is the explicit correction. It is deliberately
     * narrow rather than a blanket `sync()`, which would wipe every legitimate
     * customization on each deployment seed.
     *
     * @var array<string, list<string>>
     */
    private array $withdrawn = [
        // Phase 2.4 scope change: Locations is Administrator-only. Non-admins use
        // the narrow room lookup instead (FR-LOC-011).
        //
        // Phase 2.5 scope change: Asset Management is Administrator-only on the
        // same reasoning (SDD DD-38). Technicians previously held
        // assets.view/update/transfer from the 2.2 baseline; those are withdrawn
        // here so an existing database converges on the current matrix. They
        // reach the equipment they are working on through the narrow lookup at
        // `/api/lookups/*` instead.
        //
        // Phase 2.6 scope change: assignment authority is the Administrator's,
        // and export is a whole-directory read. `tickets.assign` stays grantable
        // per-user for a deputized lead technician (FR-ASN-001); the role
        // baseline no longer carries either.
        //
        // Phase 2.8 scope change: the Interactive Floor Plan is Administrator-
        // only — the client's brief says technicians and teachers "will not have
        // this access". `floorplan.view` was seeded to Technicians from the 2.2
        // baseline (SRS FR-FP-006); it is withdrawn here so an existing database
        // converges. The floor-plan policies additionally require the
        // Administrator role, so a later per-user grant cannot reopen it.
        'technician' => [
            'locations.view', 'assets.view', 'assets.update', 'assets.transfer',
            'tickets.assign', 'tickets.export',
            'floorplan.view',
        ],
        'teacher' => ['locations.view'],
    ];

    private function revokeWithdrawnBaselineGrants(): void
    {
        foreach ($this->withdrawn as $roleSlug => $names) {
            $role = Role::query()->where('slug', $roleSlug)->first();

            if ($role === null) {
                continue;
            }

            $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();

            if ($ids !== []) {
                $role->permissions()->detach($ids);
            }
        }
    }
}
