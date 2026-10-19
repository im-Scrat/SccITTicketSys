<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Analytics\Services\DashboardService;
use App\Domains\KnowledgeBase\Assistant\Tools\AssistantDashboardText;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "about your account" block every assistant prompt opens with
 * (pre-commit fix pass 2, Phase 2.9).
 *
 * Why it exists: the model was told nothing about the person's account, so a
 * teacher asking "how many tickets have I reported?" was — correctly, given
 * its input — told it had no access. This block answers the common questions
 * with zero tool calls; the tools cover the rest.
 *
 * What it contains, and only this:
 *  - the role and capabilities line ({@see AssistantContext::userBlock});
 *  - the role dashboard's KPI numbers (items, never rows — rows can name people);
 *  - exact ticket totals in the user's own scope, with the newest few;
 *  - where each thing lives in the application, per role.
 *
 * It is wrapped as untrusted data by the service (ticket titles are typed by
 * people) and redacted by the invoker on the way out. Bounded in size so it can
 * never crowd out the question.
 */
class AssistantAccountOverview
{
    private const MAX_CHARS = 3500;

    private const RECENT = 5;

    /** @var array<string, list<array{string, string}>> role slug → [page, path] */
    private const PAGES = [
        'teacher' => [
            ['Dashboard', '/app'], ['Report a problem', '/app/tickets/new'], ['My tickets', '/app/tickets/mine'],
            ['Community feed (what others reported)', '/app/tickets'], ['Announcements', '/app/announcements'],
            ['Notifications', '/app/notifications'], ['Account settings (name, password change)', '/app/account'],
        ],
        'technician' => [
            ['Dashboard', '/app'], ['My work (assigned tickets)', '/app/tickets/assigned'], ['Completed work', '/app/tickets/history'],
            ['Maintenance', '/app/maintenance'], ['My submissions (support requests)', '/app/work-support'],
            ['My assets', '/app/my-assets'], ['Announcements', '/app/announcements'], ['Notifications', '/app/notifications'],
            ['Account settings (name, password change)', '/app/account'],
        ],
        'administrator' => [
            ['Dashboard', '/app'], ['Tickets', '/app/tickets'], ['Ticket management', '/app/tickets/manage'],
            ['Technicians', '/app/technicians'], ['Maintenance management', '/app/maintenance/manage'],
            ['Support requests', '/app/work-support/manage'], ['Assets', '/app/assets'], ['Locations', '/app/locations'],
            ['Floor plan', '/app/floor-plan'], ['Predictive maintenance', '/app/predictions'], ['Users', '/app/users'],
            ['Registrations', '/app/registrations'], ['Announcement management', '/app/announcements/manage'],
            ['AI settings', '/app/ai-settings'], ['Account settings (name, password change)', '/app/account'],
        ],
    ];

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly TicketVisibility $visibility,
    ) {}

    public function text(User $user): string
    {
        // No role line here: it is in the agent's instructions already, and this
        // block is name-redacted on the way out — an account *named*
        // "technician" would turn "an IT technician" into "an IT [person]".
        $blocks = [
            'As of '.now()->timezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i').'.',
            AssistantDashboardText::items($this->dashboard->forUser($user)),
            $this->tickets($user),
            $this->pages($user),
        ];

        $text = implode("\n\n", array_filter($blocks, fn (?string $b): bool => $b !== null && $b !== ''));

        return mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS)."\n[summary truncated]" : $text;
    }

    /** Exact counts the dashboard rounds off or omits, inside the user's own scope. */
    private function tickets(User $user): ?string
    {
        if (! $user->hasPermissionTo('tickets.view')) {
            return null;
        }

        $sections = [];

        // Everyone who can report sees their own total — including staff, whose
        // dashboards are about work, not about what they themselves reported.
        if ($user->hasPermissionTo('tickets.create')) {
            $sections[] = $this->summarise('Tickets you reported', $this->visibility->scopeOwn(Ticket::query(), $user));
        }

        if ($user->role?->slug === 'technician') {
            $sections[] = $this->summarise('Tickets assigned to you', $this->visibility->scopeAssigned(Ticket::query(), $user));
        }

        if ($user->isAdministrator()) {
            $sections[] = $this->summarise('All tickets in the system', $this->visibility->scope(Ticket::query(), $user), recent: 0);
        }

        return implode("\n", $sections);
    }

    /** @param  Builder<Ticket>  $query */
    private function summarise(string $label, Builder $query, int $recent = self::RECENT): string
    {
        $total = (clone $query)->count();

        $byStatus = (clone $query)
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.current_status_id')
            ->groupBy('ticket_statuses.name', 'ticket_statuses.sort_order')
            ->orderBy('ticket_statuses.sort_order')
            ->selectRaw('ticket_statuses.name as status_name, count(*) as aggregate')
            ->pluck('aggregate', 'status_name');

        $line = sprintf('%s: %d total', $label, $total);

        if ($byStatus->isNotEmpty()) {
            $line .= ' ('.$byStatus->map(fn ($n, $name) => "{$name} {$n}")->implode(', ').')';
        }

        $line .= '.';

        if ($recent > 0 && $total > 0) {
            $rows = (clone $query)->with('status:id,name')->latest('tickets.created_at')->limit($recent)->get();
            $line .= "\nNewest:";
            foreach ($rows as $ticket) {
                $line .= sprintf("\n- %s · %s · %s · %s", $ticket->ticket_number, $ticket->status->name, $ticket->created_at?->toDateString() ?? '?', $ticket->title);
            }
        }

        return $line;
    }

    private function pages(User $user): string
    {
        $pages = self::PAGES[$user->role->slug] ?? self::PAGES['teacher'];

        return "Where things are in the application:\n".implode("\n", array_map(
            fn (array $p): string => "- {$p[0]}: {$p[1]}",
            $pages,
        ));
    }
}
