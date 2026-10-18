<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Assets: the spreadsheet import (Phase 2.10 R6, SDD DD-96)
|--------------------------------------------------------------------------
|
| Every value has a default, so `.env.production` needs no edit. The caps are
| sized for one school's inventory read in a single load inside a 512 MB
| worker; a larger register is imported in several files.
|
*/

return [

    'import' => [
        // A file larger than this, or longer or wider, is refused.
        'max_kb' => (int) env('ASSET_IMPORT_MAX_KB', 10240),
        'max_rows' => (int) env('ASSET_IMPORT_MAX_ROWS', 5000),
        'max_columns' => (int) env('ASSET_IMPORT_MAX_COLUMNS', 100),

        // The zip container of an .xlsx: entries, expanded size, and the
        // largest worksheet or shared-strings part PhpSpreadsheet will parse.
        'max_zip_entries' => 2000,
        'max_uncompressed_mb' => 40,
        'max_compression_ratio' => 100,
        'max_part_mb' => 15,

        // Rows saved per job, and the time one job may spend before it hands
        // over to the next — well inside the bulk worker's 170 s timeout.
        'chunk_rows' => (int) env('ASSET_IMPORT_CHUNK_ROWS', 100),
        'chunk_seconds' => (int) env('ASSET_IMPORT_CHUNK_SECONDS', 50),

        // A running import whose heartbeat is older than this is resumed by
        // `assets:imports-maintain` (a worker was killed mid-chunk).
        'stall_minutes' => (int) env('ASSET_IMPORT_STALL_MINUTES', 10),

        // Drafts nobody confirmed expire; source files are deleted after this.
        'draft_days' => (int) env('ASSET_IMPORT_DRAFT_DAYS', 7),
        'file_days' => (int) env('ASSET_IMPORT_FILE_DAYS', 30),

        // The queue every import job runs on (the dedicated bulk worker).
        'queue' => env('ASSET_IMPORT_QUEUE', 'bulk'),
    ],

];
