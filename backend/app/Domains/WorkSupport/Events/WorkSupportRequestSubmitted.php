<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Events;

use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a technician raises a work support request from a scanned machine
 * (SRS FR-WSR-001, FR-WSR-012; notification matrix T9).
 *
 * This is half of the debt WP-2.6b carried forward explicitly: the workflow
 * shipped and was verified, but nothing told the administrators a request was
 * waiting for them.
 */
class WorkSupportRequestSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkSupportRequest $request,
        public readonly User $technician,
    ) {}
}
