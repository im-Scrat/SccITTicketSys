<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Models\AssetImport;
use Illuminate\Http\Request;

/**
 * The request a queued import step audits under (Phase 2.10 R6).
 *
 * The records an import creates are created by a worker, long after the
 * confirming request has gone. Their audit rows should still name the
 * administrator's address and browser, not the worker's, so confirm captures
 * both and every job rebuilds a request from them for `AuditLogger`.
 */
final class ImportRequestContext
{
    public static function for(AssetImport $import): Request
    {
        return Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => $import->confirm_ip ?? '127.0.0.1',
            'HTTP_USER_AGENT' => $import->confirm_user_agent ?? 'SccIT asset import',
        ]);
    }

    /**
     * The import's reference on each created record's audit row.
     *
     * @return array{uuid: string, row: int|null, file: string}
     */
    public static function auditContext(AssetImport $import, ?int $rowNumber = null): array
    {
        return ['uuid' => $import->uuid, 'row' => $rowNumber, 'file' => $import->original_filename];
    }
}
