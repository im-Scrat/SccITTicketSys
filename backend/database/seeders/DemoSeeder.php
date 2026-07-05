<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Models\AiAnalysisLog;
use App\Models\AiKnowledgeArticle;
use App\Models\AiModel;
use App\Models\AiPrediction;
use App\Models\AiRecommendation;
use App\Models\Announcement;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Consumable;
use App\Models\Floor;
use App\Models\FloorPlanPosition;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\Notification;
use App\Models\PcSpecification;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketVote;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Realistic demo dataset for local development. Never runs in production
 * (see DatabaseSeeder). Uses the reference data seeded earlier rather than
 * factory-generating new lookups.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $teacherRole = Role::query()->where('slug', 'teacher')->firstOrFail();
        $techRole = Role::query()->where('slug', 'technician')->firstOrFail();
        $admin = User::query()->where('role_id', Role::query()->where('slug', 'administrator')->value('id'))->firstOrFail();

        $teachers = User::factory(6)->create(['role_id' => $teacherRole->id]);
        $technicians = User::factory(3)->create(['role_id' => $techRole->id]);

        // Pending registration requests so the approval queue has data locally.
        User::factory(3)->pending()->create(['role_id' => $teacherRole->id]);
        User::factory(2)->pending()->create(['role_id' => $techRole->id]);

        // Non-active accounts so the User Management dashboard/directory/export
        // exercise every status locally (Phase 2.3).
        User::factory(2)->suspended()->create(['role_id' => $teacherRole->id]);
        User::factory(2)->inactive()->create(['role_id' => $techRole->id]);
        User::factory(2)->rejected()->create(['role_id' => $teacherRole->id]);
        User::factory(1)->mustResetPassword()->create(['role_id' => $techRole->id]);

        // Location hierarchy + PCs (floor plan ready).
        $building = Building::factory()->create(['name' => 'Main Building', 'code' => 'MAIN']);

        foreach (range(1, 2) as $floorNumber) {
            $floor = Floor::factory()->create([
                'building_id' => $building->id,
                'floor_number' => $floorNumber,
                'name' => "Floor {$floorNumber}",
            ]);

            $rooms = Room::factory(2)->laboratory()->create(['floor_id' => $floor->id]);

            foreach ($rooms as $room) {
                $layout = RoomLayout::factory()->create(['room_id' => $room->id]);
                $pcs = PcUnit::factory(5)->create(['room_id' => $room->id]);

                foreach ($pcs as $index => $pc) {
                    PcSpecification::factory()->create(['pc_unit_id' => $pc->id]);
                    QrCode::factory()->create(['pc_unit_id' => $pc->id, 'asset_id' => null]);
                    FloorPlanPosition::factory()->create([
                        'room_layout_id' => $layout->id,
                        'pc_unit_id' => $pc->id,
                        'pos_x' => ($index % 5) * 120 + 40,
                        'pos_y' => intdiv($index, 5) * 120 + 40,
                    ]);
                }
            }
        }

        // Inventory catalog + stock.
        Asset::factory(12)->create();
        Consumable::factory(6)->create();

        // Tickets referencing seeded lookups.
        $categories = TicketCategory::query()->get();
        $priorities = TicketPriority::query()->get();
        $openStatus = TicketStatus::query()->where('slug', 'open')->firstOrFail();
        $inProgress = TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $maintenanceTypes = MaintenanceType::query()->get();
        $pcUnits = PcUnit::query()->get();
        $chatModel = AiModel::query()->where('is_default', true)->firstOrFail();
        $voters = $teachers->merge($technicians);

        $tickets = collect();
        foreach (range(1, 18) as $n) {
            $pc = $pcUnits->random();
            $assignTech = $n % 3 === 0;

            $ticket = Ticket::factory()->create([
                'reporter_id' => $teachers->random()->id,
                'category_id' => $categories->random()->id,
                'priority_id' => $priorities->random()->id,
                'current_status_id' => $assignTech ? $inProgress->id : $openStatus->id,
                'pc_unit_id' => $pc->id,
                'room_id' => $pc->room_id,
                'assigned_technician_id' => $assignTech ? $technicians->random()->id : null,
            ]);

            TicketComment::factory(random_int(0, 3))->create([
                'ticket_id' => $ticket->id,
                'user_id' => $voters->random()->id,
            ]);

            foreach ($voters->random(random_int(1, 4)) as $voter) {
                TicketVote::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $voter->id]);
            }

            if ($assignTech) {
                TechnicianAssignment::factory()->create([
                    'ticket_id' => $ticket->id,
                    'technician_id' => $ticket->assigned_technician_id,
                    'assigned_by' => $admin->id,
                    'status' => AssignmentStatus::InProgress->value,
                    'started_at' => now(),
                ]);
            }

            $tickets->push($ticket);
        }

        // Maintenance history.
        foreach ($pcUnits->random(8) as $pc) {
            MaintenanceRecord::factory()->create([
                'pc_unit_id' => $pc->id,
                'technician_id' => $technicians->random()->id,
                'maintenance_type_id' => $maintenanceTypes->random()->id,
            ]);
        }

        // AI outputs.
        foreach ($tickets->random(8) as $ticket) {
            $log = AiAnalysisLog::factory()->create([
                'ticket_id' => $ticket->id,
                'ai_model_id' => $chatModel->id,
            ]);
            AiRecommendation::factory(random_int(1, 3))->create(['ai_analysis_log_id' => $log->id]);
        }

        AiKnowledgeArticle::factory(5)->published()->create(['created_by' => $admin->id]);

        foreach ($pcUnits->random(6) as $pc) {
            AiPrediction::factory()->create(['pc_unit_id' => $pc->id]);
        }

        // Announcements + notifications.
        Announcement::factory(3)->create(['created_by' => $admin->id]);
        foreach ($teachers->merge($technicians) as $user) {
            Notification::factory(random_int(1, 3))->create(['user_id' => $user->id]);
        }
    }
}
