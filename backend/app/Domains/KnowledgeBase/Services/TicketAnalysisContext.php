<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\PcSpecification;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assembles the **minimized, real** context {@see TicketPreScreeningAgent}
 * is given for one ticket — WP-I's "use minimized relevant context: PC
 * specification; actual maintenance records; relevant ticket history."
 *
 * "Actual" is the operative word: every fact here is read straight from
 * `pc_specifications` / `maintenance_records` / `tickets`, never derived from
 * `activity_logs` (which is this project's audit trail, not a second history
 * mechanism to mine for content) and never invented when a machine has no
 * history to offer — an unremarkable machine gets a short prompt, not a
 * padded one.
 *
 * Every piece of reporter- or technician-authored text this class touches
 * (the ticket's own title/description, a past maintenance visit's diagnosis,
 * another ticket's title) is DATA, and is wrapped as such through
 * {@see PromptGuard::wrapUntrustedData()} before it reaches the prompt string
 * — this class is where that wrapping actually happens for this feature, not
 * merely where it is documented.
 */
class TicketAnalysisContext
{
    /** Recent visits/prior tickets are relevant; a machine's whole life is not. */
    private const HISTORY_LIMIT = 5;

    /**
     * Build the full user-turn prompt for one ticket: its own report, plus
     * whatever real context this machine and its history actually offer.
     */
    public function buildPrompt(Ticket $ticket): string
    {
        $blocks = [
            PromptGuard::wrapUntrustedData('reported fault', $this->reportBlock($ticket)),
        ];

        if ($ticket->pcUnit !== null) {
            $spec = $ticket->pcUnit->specification;

            if ($spec !== null) {
                $blocks[] = PromptGuard::wrapUntrustedData('PC specification', $this->specificationBlock($spec));
            }

            $maintenance = $this->maintenanceHistory($ticket);

            if ($maintenance->isNotEmpty()) {
                $blocks[] = PromptGuard::wrapUntrustedData('past maintenance on this machine', $this->maintenanceBlock($maintenance));
            }

            $priorTickets = $this->ticketHistory($ticket);

            if ($priorTickets->isNotEmpty()) {
                $blocks[] = PromptGuard::wrapUntrustedData('other tickets reported against this machine', $this->ticketHistoryBlock($priorTickets));
            }
        }

        $prompt = implode("\n\n", $blocks);

        PromptGuard::assertWithinLimit($prompt);

        return $prompt;
    }

    private function reportBlock(Ticket $ticket): string
    {
        return "Title: {$ticket->title}\nDescription: {$ticket->description}";
    }

    private function specificationBlock(PcSpecification $spec): string
    {
        $lines = array_filter([
            'CPU' => $spec->cpu,
            'RAM' => $spec->ram,
            'Storage' => $spec->storage_primary,
            'GPU' => $spec->gpu,
            'Operating system' => $spec->operating_system,
        ]);

        if ($lines === []) {
            return 'No specification recorded.';
        }

        return collect($lines)
            ->map(fn (string $value, string $label): string => "{$label}: {$value}")
            ->implode("\n");
    }

    /**
     * @return Collection<int, MaintenanceRecord>
     */
    private function maintenanceHistory(Ticket $ticket): Collection
    {
        return MaintenanceRecord::query()
            ->where('pc_unit_id', $ticket->pc_unit_id)
            ->where('status', MaintenanceStatus::Completed->value)
            ->orderByDesc('maintenance_date')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'title', 'diagnosis', 'root_cause', 'resolution', 'maintenance_date']);
    }

    /**
     * @param  Collection<int, MaintenanceRecord>  $records
     */
    private function maintenanceBlock(Collection $records): string
    {
        return $records
            ->map(function (MaintenanceRecord $record): string {
                $date = $record->maintenance_date?->toDateString() ?? 'unknown date';
                $parts = array_filter([
                    "- {$date}: {$record->title}",
                    $record->diagnosis !== null ? "  Diagnosis: {$record->diagnosis}" : null,
                    $record->root_cause !== null ? "  Root cause: {$record->root_cause}" : null,
                    $record->resolution !== null ? "  Resolution: {$record->resolution}" : null,
                ]);

                return implode("\n", $parts);
            })
            ->implode("\n");
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function ticketHistory(Ticket $ticket): Collection
    {
        return Ticket::query()
            ->where('pc_unit_id', $ticket->pc_unit_id)
            ->where('id', '!=', $ticket->id)
            ->with('status:id,name,is_terminal')
            ->orderByDesc('created_at')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'title', 'current_status_id', 'created_at']);
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    private function ticketHistoryBlock(Collection $tickets): string
    {
        return $tickets
            ->map(function (Ticket $prior): string {
                $date = $prior->created_at?->toDateString() ?? 'unknown date';
                $statusRow = $prior->statusRow();
                $status = $statusRow !== null ? $statusRow->name : 'unknown status';

                return "- {$date} ({$status}): {$prior->title}";
            })
            ->implode("\n");
    }
}
