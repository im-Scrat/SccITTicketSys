<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Services;

use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Domains\WorkSupport\Http\Resources\TechnicianSubmissionResource;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Everything a technician personally submitted** (SRS FR-WSR-009).
 *
 * > *"…a page tracking **everything they personally submitted** — proof-of-work
 * >  records **and** support requests — … A technician shall reach their own
 * >  records and no others, in lists **and** by direct identifier."*
 *
 * Stage E delivered the second half of that list. This composes both.
 *
 * ── It defines no ownership rule, and that is the point ────────────────────
 *
 * "Mine" already has exactly two authoritative definitions in this codebase,
 * one per module:
 *
 *   proof of work    {@see MaintenanceVisibility::scopeOwn()}   assigned or created by me
 *   support request  {@see WorkSupportVisibility::scopeOwn()}   submitted by me
 *
 * Both are **called**. A third definition living here — even one that started
 * out identical — would be free to drift from the module it was copied from,
 * and a disagreement about whose record something is would be an IDOR
 * (NFR-SEC-003). There is no `technician_id` comparison anywhere in this class;
 * grep for one and the answer must stay zero.
 *
 * ── What makes a maintenance record a *submission* ─────────────────────────
 *
 * Owning a maintenance record is not the same as having submitted proof against
 * it: an administrator may schedule work for a technician who has not been to
 * the machine yet, and that is not something the technician submitted. So the
 * proof-of-work half is **ownership AND a scan this person bound to it** —
 * `qr_scan_logs.maintenance_record_id` set by their own scan, which is precisely
 * the DD-50 binding Stage D writes. A record with no such scan is a job, not a
 * submission, and belongs on the maintenance queue rather than here.
 *
 * ── Why the union is on keys rather than on rows ──────────────────────────
 *
 * The two halves are different shapes, so they cannot be unioned as rows
 * without flattening one of them into the other's columns and losing the
 * difference. Instead the union carries only `(kind, id, submitted_at)` — enough
 * to order and page the combined feed correctly in the database — and the page's
 * models are then loaded per kind. Merging two already-paginated lists in PHP
 * would have produced a feed that is wrong at every page boundary.
 */
class TechnicianSubmissions
{
    public const KIND_PROOF = 'proof_of_work';

    public const KIND_REQUEST = 'support_request';

    /** @var list<string> */
    public const KINDS = [self::KIND_PROOF, self::KIND_REQUEST];

    public function __construct(
        private readonly MaintenanceVisibility $maintenance,
        private readonly WorkSupportVisibility $workSupport,
    ) {}

    /**
     * One page of the combined feed, newest first.
     *
     * Each item is `{kind, submitted_at, record}` — the shape
     * {@see TechnicianSubmissionResource}
     * reads. It is documented rather than declared because
     * `LengthAwarePaginator`'s value template is invariant, and a precise
     * array shape here is rejected as incompatible with the identical shape
     * inferred from the constructor.
     *
     * @param  string|null  $kind  one of {@see KINDS}, or null for both
     * @return Paginator<int, array<string, mixed>>
     */
    public function paginate(User $user, ?string $kind = null, int $perPage = 20): Paginator
    {
        $sources = [];

        if ($kind === null || $kind === self::KIND_PROOF) {
            $sources[] = $this->proofKeys($user);
        }

        if ($kind === null || $kind === self::KIND_REQUEST) {
            $sources[] = $this->requestKeys($user);
        }

        $union = array_shift($sources);

        foreach ($sources as $next) {
            $union->unionAll($next);
        }

        $keys = DB::query()
            ->fromSub($union, 'submissions')
            // `id` breaks the tie so the order is total — two submissions in the
            // same second must not swap places between page one and page two.
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        /*
         * A fresh paginator rather than `setCollection()` on the key paginator.
         * The keys page is typed by its own rows, and re-labelling it with a
         * different value type is exactly the covariance PHPStan refuses —
         * rightly, since the two really are different collections. Constructing
         * one carries the page metadata across without pretending.
         */
        return new Paginator(
            $this->hydrate(collect($keys->items()), $user),
            $keys->total(),
            $keys->perPage(),
            $keys->currentPage(),
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /* --------------------------------------------------------- the halves */

    /**
     * Maintenance records this user owns **and** submitted proof against.
     */
    private function proofKeys(User $user): QueryBuilder
    {
        $query = MaintenanceRecord::query()->whereExists(
            fn (QueryBuilder $sub) => $sub->selectRaw('1')
                ->from('qr_scan_logs')
                ->whereColumn('qr_scan_logs.maintenance_record_id', 'maintenance_records.id')
                ->where('qr_scan_logs.scanned_by', $user->getKey()),
        );

        // The module's own definition of "mine", called rather than restated.
        $this->maintenance->scopeOwn($query, $user);

        return $query->toBase()->select([
            DB::raw("'".self::KIND_PROOF."' as kind"),
            'maintenance_records.id as id',
        ])->selectRaw(
            // When proof was submitted, not when the job was scheduled — the
            // feed is a record of submissions.
            '(select max(scanned_at) from qr_scan_logs q
                where q.maintenance_record_id = maintenance_records.id
                  and q.scanned_by = ?) as submitted_at',
            [$user->getKey()],
        );
    }

    /**
     * Support requests this user submitted.
     */
    private function requestKeys(User $user): QueryBuilder
    {
        $query = $this->workSupport->scopeOwn(WorkSupportRequest::query(), $user);

        return $query->toBase()->select([
            DB::raw("'".self::KIND_REQUEST."' as kind"),
            'work_support_requests.id as id',
            'work_support_requests.created_at as submitted_at',
        ]);
    }

    /* ------------------------------------------------------------ loading */

    /**
     * Load the page's models, preserving the union's order.
     *
     * Two queries rather than N: the ids are grouped by kind, each kind loaded
     * once with its relations, then the rows are re-associated in the order the
     * database returned them.
     *
     * The scopes are applied **again** on load. That is not belt-and-braces for
     * its own sake — it is what makes a row that slipped out of scope between
     * the count and the fetch disappear rather than render.
     *
     * Returns a plain list rather than a Collection: `Collection`'s value
     * template is not covariant, so handing one back from here and into the
     * paginator is a type error PHPStan is right to refuse. The caller wraps it.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function hydrate(Collection $rows, User $user): array
    {
        $proofIds = $rows->where('kind', self::KIND_PROOF)->pluck('id')->all();
        $requestIds = $rows->where('kind', self::KIND_REQUEST)->pluck('id')->all();

        $proof = $proofIds === []
            ? collect()
            : $this->maintenance->scopeOwn(MaintenanceRecord::query(), $user)
                ->whereKey($proofIds)
                ->with(['type:id,name,slug', 'pcUnit:id,uuid,unit_code,pc_name', 'ticket:id,uuid,ticket_number', 'images'])
                ->get()
                ->keyBy('id');

        $requests = $requestIds === []
            ? collect()
            : $this->workSupport->scopeOwn(WorkSupportRequest::query(), $user)
                ->whereKey($requestIds)
                ->with(['items.hardwareModel', 'attachments', 'pcUnit.room', 'maintenanceRecord', 'ticket', 'technician', 'decidedBy', 'cancelledBy'])
                ->get()
                ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($proof, $requests): ?array {
                $record = $row->kind === self::KIND_PROOF
                    ? $proof->get($row->id)
                    : $requests->get($row->id);

                if (! $record instanceof MaintenanceRecord && ! $record instanceof WorkSupportRequest) {
                    // The row went out of scope between the count and the fetch.
                    // Dropping it is the correct outcome: it is no longer this
                    // person's to see.
                    return null;
                }

                return [
                    'kind' => (string) $row->kind,
                    'submitted_at' => $row->submitted_at === null ? null : (string) $row->submitted_at,
                    'record' => $record,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
