<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Http\Requests\ExportUsersRequest;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Identity\Services\UserExporter;
use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Directory export (SRS v1.2 FR-USER export). Honours the current search /
 * filter / sort and the requested column subset (allow-listed), in CSV or true
 * XLSX. Gated by the `export` policy; the download is audited.
 */
class UserExportController extends Controller
{
    public function index(ExportUsersRequest $request, UserExporter $exporter, AuditLogger $audit): BinaryFileResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $audit->activity(
            ActivityAction::UsersExported,
            actor: $admin,
            properties: [
                'format' => $request->outputFormat(),
                'columns' => $request->columns(),
                'filters' => $request->only(['search', 'status', 'role', 'trashed', 'sort', 'direction']),
            ],
            request: $request,
            description: 'Users exported',
        );

        return $exporter->download($request->validated(), $request->outputFormat(), $request->columns());
    }
}
