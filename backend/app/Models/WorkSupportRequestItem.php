<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a technician asked for (SRS FR-WSR-002).
 *
 * A line item names **either** a catalogue hardware model **or** a free-text
 * description — a database CHECK enforces that at least one is present, because
 * a request for "something" is not a request. Free text is deliberately allowed:
 * a technician standing at a machine needs to be able to ask for a part the
 * catalogue has never carried, and forcing every request through the catalogue
 * would mean the ones that matter most cannot be made.
 *
 * No uuid. Items are only ever reached through their parent request, which has
 * one — the same nesting rule `maintenance_checklists` follows.
 *
 * @property int $id
 * @property int $work_support_request_id
 * @property int|null $hardware_model_id
 * @property string|null $description
 * @property int $quantity
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkSupportRequest|null $request
 * @property-read HardwareModel|null $hardwareModel
 */
class WorkSupportRequestItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<WorkSupportRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(WorkSupportRequest::class, 'work_support_request_id');
    }

    /**
     * The catalogue entry, when the technician picked one.
     *
     * `ON DELETE SET NULL`: retiring a model from the catalogue must not erase
     * what someone once asked for, so the row survives with its `description`
     * carrying the meaning.
     *
     * @return BelongsTo<HardwareModel, $this>
     */
    public function hardwareModel(): BelongsTo
    {
        return $this->belongsTo(HardwareModel::class);
    }

    /** What to show a reader: the catalogue name, or the technician's own words. */
    public function displayName(): string
    {
        $model = $this->hardwareModel;

        return $model !== null ? $model->model_name : ($this->description ?? 'Unspecified item');
    }
}
