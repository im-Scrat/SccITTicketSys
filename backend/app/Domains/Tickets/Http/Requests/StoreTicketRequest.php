<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Requests;

use App\Domains\Assets\Services\ReportableEquipment;
use App\Domains\Locations\Services\LocationOptions;
use App\Domains\Tickets\Actions\CreateTicket;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new ticket (SRS FR-TKT-001/003).
 *
 * **`priority` is open to every reporter** (Phase 2.9, SDD DD-75; Client
 * decision 2026-10-16). It used to be staff-only, on the reasoning that the SLA
 * clock must not be self-service. It still is not: {@see CreateTicket} records a
 * requester's choice as `reporter` — the second-weakest claim — so a room's
 * priority floor still raises it, the AI may still raise it, and an
 * administrator's later change (`manual`) overrides it. Only IT staff's own
 * choice is recorded as `manual` and locks.
 *
 * **`reporter` stays staff-only.** FR-TKT-003 permits filing *on behalf of*
 * another person, but only for Technicians and Administrators — a teacher may
 * only ever report as themselves.
 *
 * **The server enforces exactly what the report form's lookups offer**
 * (SDD DD-78). The room, PC unit and other asset are addressed by uuid and
 * checked against the same definitions the lookups are built from:
 *  - a room must be **selectable** ({@see LocationOptions::selectableRooms()});
 *  - a PC or asset must be **reportable** ({@see ReportableEquipment}) — an
 *    asset therefore in service, not fitted inside a PC, of an equipment type;
 *  - at most **one item** — a PC or an asset, never both
 *    (`tickets_equipment_target_check`);
 *  - a named item must be **in the named room**; with no room named, the item's
 *    own room (which the ticket inherits) must be selectable or absent — the
 *    lookup never offers an item in a room that is out of use, so a hand-typed
 *    uuid cannot file against one either.
 *
 * Every uuid rule bails on a malformed value before any query, and each closure
 * re-checks `Str::isUuid` itself: a non-uuid string compared with a PostgreSQL
 * `uuid` column is a 22P02 error, which would surface as a 500.
 *
 * **An "Other" category needs a description** (`category_detail`, 3–120
 * characters) — and only an "Other" category takes one (SDD DD-77). Which
 * categories ask is data (`ticket_categories.requires_detail`), not a list here.
 */
class StoreTicketRequest extends FormRequest
{
    /** @var array<string, PcUnit|null> */
    private array $pcUnits = [];

    /** @var array<string, Asset|null> */
    private array $assets = [];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'category' => ['required', 'string', Rule::exists('ticket_categories', 'slug')->where('is_active', true)],

            // Whether it is required — and allowed — depends on the category;
            // see after(). The shape is checked here.
            'category_detail' => ['sometimes', 'nullable', 'string', 'min:3', 'max:120'],

            'priority' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('ticket_priorities', 'slug')->where('is_active', true),
            ],
            'reporter' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                'uuid',
                Rule::exists('users', 'uuid')->whereNull('deleted_at'),
                Rule::prohibitedIf(fn (): bool => ! $this->actorIsStaff()),
            ],

            'room' => ['bail', 'sometimes', 'nullable', 'string', 'uuid', $this->selectableRoom()],
            'pc_unit' => ['bail', 'sometimes', 'nullable', 'string', 'uuid', $this->reportableItem('pc_unit')],
            'asset' => ['bail', 'sometimes', 'nullable', 'string', 'uuid', $this->reportableItem('asset')],

            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', Rule::exists('tags', 'slug')],
        ];
    }

    /**
     * Rules that span fields. Each returns early when a field it reads already
     * failed, so one mistake produces one message rather than a cascade.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['pc_unit', 'asset'])) {
                    return;
                }

                // The application-side statement of
                // `tickets_equipment_target_check`; without it the constraint
                // would surface as a 500.
                if ($this->filledString('pc_unit') !== null && $this->filledString('asset') !== null) {
                    $validator->errors()->add('asset', 'Choose either a computer or another item, not both.');
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['room', 'pc_unit', 'asset'])) {
                    return;
                }

                $this->checkItemLocation($validator);
            },
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['category', 'category_detail'])) {
                    return;
                }

                $this->checkCategoryDetail($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.min' => 'Give the problem a title of at least 5 characters, so it can be recognised in the feed.',
            'description.min' => 'Describe what is wrong in at least 10 characters — what you were doing, and what happened.',
            'category.exists' => 'Choose one of the available categories.',
            'category_detail.min' => 'Describe the kind of problem in at least 3 characters.',
            'category_detail.max' => 'Keep the kind of problem to 120 characters — the details go in the description.',
            'priority.exists' => 'Choose one of the available priorities.',
            'reporter.prohibited' => 'You can only report an issue as yourself.',
            'room.uuid' => 'Choose an available room.',
            'pc_unit.uuid' => 'Choose an available PC unit.',
            'asset.uuid' => 'Choose an available item.',
        ];
    }

    /**
     * A named room must be one the location lookup would offer.
     */
    private function selectableRoom(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $this->locations()->findSelectable($value) === null) {
                $fail('Choose an available room.');
            }
        };
    }

    /**
     * A named PC or asset must be one the equipment lookup would offer.
     *
     * @param  'pc_unit'|'asset'  $kind
     */
    private function reportableItem(string $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind): void {
            $found = is_string($value)
                ? ($kind === 'pc_unit' ? $this->pcUnit($value) : $this->asset($value))
                : null;

            if ($found === null) {
                $fail($kind === 'pc_unit' ? 'Choose an available PC unit.' : 'Choose an available item.');
            }
        };
    }

    /**
     * The item and the place must agree.
     *
     *  - **Room named:** the item must be in it. The form lists only the chosen
     *    room's equipment, so a mismatch is a stale form or a hand-built request.
     *  - **No room named:** the ticket inherits the item's room
     *    ({@see CreateTicket}), so that room must itself be one a reporter could
     *    have chosen — or there must be none. Otherwise a uuid from the global
     *    PC lookup would file a ticket into a room the room lookup hides, where
     *    it would also block the administrator from archiving that room.
     */
    private function checkItemLocation(Validator $validator): void
    {
        [$key, $itemRoomId, $noun] = $this->namedItem();

        if ($key === null) {
            return;
        }

        $roomUuid = $this->filledString('room');

        if ($roomUuid !== null) {
            $room = $this->locations()->findSelectable($roomUuid);

            if ($room !== null && ($itemRoomId === null || (int) $itemRoomId !== (int) $room->getKey())) {
                $validator->errors()->add($key, "That {$noun} is not in the room you chose.");
            }

            return;
        }

        if ($itemRoomId !== null
            && ! $this->locations()->selectableRooms()->whereKey($itemRoomId)->exists()) {
            $validator->errors()->add($key, "That {$noun} is not in a room you can report from.");
        }
    }

    /**
     * Which item the request names, and the room it is in.
     *
     * @return array{0: 'pc_unit'|'asset'|null, 1: int|null, 2: string}
     */
    private function namedItem(): array
    {
        $pcUuid = $this->filledString('pc_unit');

        if ($pcUuid !== null) {
            return ['pc_unit', $this->pcUnit($pcUuid)?->room_id, 'computer'];
        }

        $assetUuid = $this->filledString('asset');

        if ($assetUuid !== null) {
            return ['asset', $this->asset($assetUuid)?->current_room_id, 'item'];
        }

        return [null, null, ''];
    }

    /**
     * An "Other" category asks for a description; no other category takes one.
     *
     * The key's *absence* under a flagged category is told apart from an empty
     * value on purpose. The current form always sends `category_detail` for a
     * flagged category, so a missing key means a page loaded before this rule
     * existed — one that has no description field to show the error under. The
     * message therefore goes on `category`, which every version of the form
     * renders, and says what to do.
     */
    private function checkCategoryDetail(Validator $validator): void
    {
        $category = TicketCategory::query()
            ->where('slug', (string) $this->input('category'))
            ->first();

        if ($category === null) {
            return;
        }

        $detail = $this->filledString('category_detail');

        if ($category->requires_detail && ! $this->has('category_detail')) {
            $validator->errors()->add(
                'category',
                'This category now asks for a short description — reload the page to add it.',
            );
        } elseif ($category->requires_detail && $detail === null) {
            $validator->errors()->add('category_detail', 'Say in a few words what kind of problem it is.');
        } elseif (! $category->requires_detail && $detail !== null) {
            $validator->errors()->add(
                'category_detail',
                'Only an "Other" category takes a description of the kind of problem.',
            );
        }
    }

    /** A trimmed, non-empty string input, or null. */
    private function filledString(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = Str::trim($value);

        return $value === '' ? null : $value;
    }

    /** A reportable PC by uuid, resolved once per request. */
    private function pcUnit(string $uuid): ?PcUnit
    {
        if (! array_key_exists($uuid, $this->pcUnits)) {
            $this->pcUnits[$uuid] = Str::isUuid($uuid)
                ? app(ReportableEquipment::class)->pcUnits()->where('pc_units.uuid', $uuid)->first()
                : null;
        }

        return $this->pcUnits[$uuid];
    }

    /** A reportable asset by uuid, resolved once per request. */
    private function asset(string $uuid): ?Asset
    {
        if (! array_key_exists($uuid, $this->assets)) {
            $this->assets[$uuid] = Str::isUuid($uuid)
                ? app(ReportableEquipment::class)->assets()->where('assets.uuid', $uuid)->first()
                : null;
        }

        return $this->assets[$uuid];
    }

    private function locations(): LocationOptions
    {
        return app(LocationOptions::class);
    }

    /** Technicians and Administrators may file on behalf of someone else. */
    private function actorIsStaff(): bool
    {
        return in_array($this->user()?->role?->slug, ['administrator', 'technician'], true);
    }
}
