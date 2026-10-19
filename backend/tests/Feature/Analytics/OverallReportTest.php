<?php

declare(strict_types=1);

use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\Services\PreventiveMaintenanceRules;
use App\Models\Asset;
use App\Models\ReportExport;
use App\Support\SchoolClock;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 R4 — Overall Reports (SDD DD-94): each section's figures over a
 * known estate, the one-population promise (the page agrees with the dashboard
 * and every records table totals to its figure), the rule-based preventive
 * flags, the failed-part attribution, the equipment profile, and documents.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed([TicketLookupSeeder::class, MaintenanceTypeSeeder::class]);
    Storage::fake('local');
    $this->travelTo(ReportFixtures::local('2026-10-18 10:00')->utc());

    $this->admin = userWithRole('administrator');
    $teacher = userWithRole('teacher');
    $floor = ReportFixtures::floor();
    $this->lab = ReportFixtures::room($floor, 'laboratory', ['code' => 'LAB-A', 'name' => 'Computer Lab A']);
    $this->office = ReportFixtures::room($floor, 'office', ['code' => 'OFF-1', 'name' => 'Registrar']);

    $this->pc1 = ReportFixtures::pc($this->lab, ['unit_code' => 'PC-001']);
    $this->pc2 = ReportFixtures::pc($this->lab, ['unit_code' => 'PC-002']);
    $this->pc3 = ReportFixtures::pc($this->lab, ['unit_code' => 'PC-003']);
    Asset::factory()->create(['current_room_id' => $this->office->id, 'asset_tag' => 'PRN-01']);

    foreach (['2026-10-02 09:00', '2026-10-06 10:00', '2026-10-09 11:00', '2026-10-13 14:00'] as $at) {
        ReportFixtures::ticketAt($teacher, $at, 'open', ['room_id' => $this->lab->id, 'pc_unit_id' => $this->pc1->id, 'asset_id' => null]);
    }
    ReportFixtures::ticketAt($teacher, '2026-10-07 08:00', 'open', ['room_id' => $this->office->id, 'pc_unit_id' => null, 'asset_id' => null]);
    ReportFixtures::ticketAt($teacher, '2026-10-08 08:00', 'cancelled', ['room_id' => null, 'pc_unit_id' => null, 'asset_id' => null]);

    $first = ReportFixtures::repair($this->pc1, 'corrective', '2026-10-03 15:00', ['downtime_minutes' => 120, 'cost' => '500.00']);
    $second = ReportFixtures::repair($this->pc1, 'corrective', '2026-10-10 15:00', ['downtime_minutes' => null, 'cost' => null]);
    ReportFixtures::repair($this->pc2, 'preventive', '2026-10-05 09:00');
    ReportFixtures::repair($this->pc3, 'hardware-upgrade', '2026-10-08 09:00');
    ReportFixtures::replacement($first, 'power_supply', 'power_supply', '2026-10-03 15:00');
    ReportFixtures::replacement($second, 'power_supply', 'power_supply', '2026-10-10 15:00');

    $this->get = fn (array $query = []): array => $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/overall?'.http_build_query(['period' => 'month', ...$query]))
        ->assertOk()
        ->json();
    $this->kpi = fn (array $section, string $label): ?array => collect($section['data']['kpis'])->firstWhere('label', $label);
    $this->table = fn (array $section, string $key): ?array => collect($section['data']['tables'])->firstWhere('key', $key);
});

it('agrees with the dashboard on the reported figure, in the Overview and in Tickets', function (): void {
    $dashboard = $this->actingAs($this->admin)->getJson('/api/admin/reports/ticket-overview?period=month')->json('data.kpis.reported.value');
    $overview = ($this->get)();
    $tickets = ($this->get)(['section' => 'tickets']);

    expect($dashboard)->toBe(6)
        ->and(($this->kpi)($overview, 'Tickets reported')['figure'])->toBe(6)
        ->and(($this->kpi)($tickets, 'Reported')['figure'])->toBe(6);
});

it('counts completed repairs by class, with effort coverage and no currency', function (): void {
    $repairs = ($this->get)(['section' => 'repairs']);

    expect(($this->kpi)($repairs, 'Completed repairs')['figure'])->toBe(4)
        ->and(($this->table)($repairs, 'repair_classes')['rows'])->toBe([['Corrective repairs', 2], ['Preventive visits', 1], ['Upgrades', 1]]);

    $effort = ($this->table)($repairs, 'repair_effort')['rows'];
    expect($effort[2][0])->toBe('Recorded cost (no currency stored)')
        ->and($effort[2][1])->toBe('500.00')
        ->and($effort[2][2])->toBe('Recorded on 1 of 4');
});

it('narrows repairs by class, and says the ticket filters do not apply there', function (): void {
    $repairs = ($this->get)(['section' => 'repairs', 'maintenance_class' => 'preventive', 'status' => 'open']);

    expect(($this->kpi)($repairs, 'Completed repairs')['figure'])->toBe(1)
        ->and($repairs['data']['not_applicable'])->toContain('Ticket status, category and problem area do not apply to repairs.');
});

it('attributes failed parts to the removed part first, and says when it was inferred', function (): void {
    $record = ReportFixtures::repair($this->pc3, 'corrective', '2026-10-12 10:00');
    ReportFixtures::replacement($record, null, 'ram', '2026-10-12 10:00');

    $repairs = ($this->get)(['section' => 'repairs']);
    $basis = collect(($this->table)($repairs, 'attribution')['rows'])->mapWithKeys(fn (array $r) => [$r[0] => $r[1]]);

    expect($basis['Removed part recorded'])->toBe(2)
        ->and($basis['Inferred from the new part'])->toBe(1)
        ->and(collect(($this->table)($repairs, 'failed_parts')['rows'])->first())->toBe(['Power Supply', 2]);
});

it('flags a PC with a recurring fault by rule, and never one whose records are too young for the interval rule', function (): void {
    $candidates = app(PreventiveMaintenanceRules::class)->candidates(
        ReportFilters::fromValidated(['period' => 'month'], SchoolClock::asOf()),
    );

    expect(array_column($candidates, 'code'))->toBe(['PC-001'])
        ->and($candidates[0]['rules'])->toBe([PreventiveMaintenanceRules::R1]);
});

it('flags a PC with no preventive visit once the interval has passed since records began', function (): void {
    $this->travel(100)->days();

    $codes = array_column(app(PreventiveMaintenanceRules::class)->candidates(
        ReportFilters::fromValidated(['period' => 'month'], SchoolClock::asOf()),
    ), 'code');

    // PC-002 had a preventive visit 100+ days ago too, so all three are due.
    expect($codes)->toContain('PC-001', 'PC-002', 'PC-003');
});

it('totals every records table to its section figure', function (): void {
    $records = fn (string $table, array $q = []): array => $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/overall/records?'.http_build_query(['period' => 'month', 'table' => $table, ...$q]))
        ->assertOk()->json();

    $tickets = $records('tickets');
    expect($tickets['meta']['total'])->toBe(6)
        ->and($tickets['data']['links'][0]['path'])->toStartWith('/app/tickets/manage/');

    expect($records('repairs')['meta']['total'])->toBe(4)
        ->and($records('replacements')['meta']['total'])->toBe(2)
        ->and($records('pc_units')['meta']['total'])->toBe(3)
        ->and($records('assets')['meta']['total'])->toBe(1)
        ->and($records('rooms')['meta']['total'])->toBe(2);

    $page = $records('tickets', ['per_page' => 2, 'page' => 2, 'sort' => 'reported', 'direction' => 'asc']);
    expect($page['data']['rows'])->toHaveCount(2)
        ->and($page['meta']['last_page'])->toBe(3);
});

it('names no person in any records table', function (): void {
    foreach (['tickets', 'repairs', 'replacements', 'pc_units', 'assets', 'rooms'] as $table) {
        $labels = collect($this->actingAs($this->admin)->getJson("/api/admin/reports/overall/records?period=month&table={$table}")->json('data.columns'))->pluck('label')->implode(' ');
        expect($labels)->not->toMatch('/reporter|technician|custodian|assigned/i');
    }
});

it('becomes the equipment profile, and scopes tickets to it, when equipment is chosen', function (): void {
    $equipment = 'pc:'.$this->pc1->uuid;
    $profile = ($this->get)(['section' => 'equipment', 'equipment' => $equipment]);
    $tickets = ($this->get)(['section' => 'tickets', 'equipment' => $equipment]);

    expect($profile['data']['title'])->toBe('Equipment: PC-001')
        ->and(($this->kpi)($profile, 'Tickets')['figure'])->toBe(4)
        ->and(($this->kpi)($profile, 'Completed repairs')['figure'])->toBe(2)
        ->and(($this->table)($profile, 'timeline')['rows'])->toHaveCount(8)
        ->and(($this->kpi)($tickets, 'Reported')['figure'])->toBe(4);
});

it('reports the equipment register as of today', function (): void {
    $equipment = ($this->get)(['section' => 'equipment']);

    expect(($this->kpi)($equipment, 'PC units')['figure'])->toBe(3)
        ->and(($this->kpi)($equipment, 'Other equipment')['figure'])->toBe(1)
        ->and(collect(($this->table)($equipment, 'most_problems')['rows'])->first())->toBe(['PC-001', 'PC unit', 4, $this->pc1->pc_name]);
});

it('groups problems by room, floor or building', function (): void {
    $byRoom = ($this->get)(['section' => 'locations']);
    $byBuilding = ($this->get)(['section' => 'locations', 'location_group' => 'building']);

    expect(($this->table)($byRoom, 'by_place')['rows'][0][1])->toBe(4)
        ->and(($this->table)($byRoom, 'by_place')['rows'][0][0])->toContain('LAB-A')
        ->and(($this->table)($byBuilding, 'by_place')['rows'])->toHaveCount(1)
        ->and(($this->table)($byBuilding, 'by_place')['rows'][0][1])->toBe(5);
});

it('says when there is too little to chart instead of drawing empty frames', function (): void {
    $tickets = ($this->get)(['section' => 'tickets', 'period' => 'day']);

    expect($tickets['data']['charts'])->toBe([])
        ->and($tickets['data']['insufficient'])->toContain('Not enough tickets');
});

it('exports the overall report as a workbook with a sheet per section', function (): void {
    $id = $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/exports', ['report_type' => 'overall', 'format' => 'xlsx', 'period' => 'month', 'maintenance_class' => 'corrective'])
        ->assertSuccessful()->json('data.id');
    $export = ReportExport::query()->where('uuid', $id)->sole();

    expect($export->status->value)->toBe('completed')
        ->and($export->filters['maintenance_class'])->toBe('corrective');

    $file = tempnam(sys_get_temp_dir(), 'ov');
    file_put_contents($file, Storage::disk('local')->get((string) $export->path));
    $names = IOFactory::createReader('Xlsx')->listWorksheetNames($file);
    @unlink($file);

    expect($names)->toContain('Summary', 'Overview', 'Tickets', 'Equipment', 'Repairs & maintenance', 'Locations', 'Notes');
});

it('prints the overall report with every visible section and its own filters', function (): void {
    $document = $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/print', ['report_type' => 'overall', 'period' => 'month', 'component_type' => 'power_supply'])
        ->assertOk()->json('data');

    expect(collect($document['sections'])->pluck('key')->all())->toBe(['overview', 'tickets', 'equipment', 'repairs', 'locations'])
        ->and(collect($document['filters'])->pluck('label')->all())->toContain('Component type (Repairs only)');
});
