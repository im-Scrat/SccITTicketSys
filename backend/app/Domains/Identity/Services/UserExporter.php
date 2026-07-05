<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Exports\UsersExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Produces RBAC-respecting User directory exports (SRS v1.2 FR-USER export).
 * Delegates format handling to maatwebsite/excel and reuses UserDirectoryQuery
 * so the export mirrors the administrator's current search/filter/sort. Requested
 * columns are intersected with the allow-list ({@see UsersExport::COLUMNS}).
 */
class UserExporter
{
    public function __construct(private readonly UserDirectoryQuery $directory) {}

    /**
     * Export the current filtered directory (search/filter/sort honoured).
     *
     * @param  array<string, mixed>  $params  directory params
     * @param  'csv'|'xlsx'  $format
     * @param  list<string>  $columns  requested columns (empty = all allowed)
     */
    public function download(array $params, string $format = 'xlsx', array $columns = []): BinaryFileResponse
    {
        return $this->stream($this->directory->builder($params), $format, $columns);
    }

    /**
     * Export a specific set of users (the bulk "export selected" action).
     *
     * @param  list<string>  $uuids
     * @param  'csv'|'xlsx'  $format
     * @param  list<string>  $columns
     */
    public function downloadForIds(array $uuids, string $format = 'xlsx', array $columns = []): BinaryFileResponse
    {
        $query = User::query()->with('role')->whereIn('uuid', $uuids)->orderBy('first_name');

        return $this->stream($query, $format, $columns);
    }

    /**
     * @param  Builder<User>  $query
     * @param  'csv'|'xlsx'  $format
     * @param  list<string>  $columns
     */
    private function stream(Builder $query, string $format, array $columns): BinaryFileResponse
    {
        $allowed = array_keys(UsersExport::COLUMNS);
        $columns = array_values(array_intersect($columns, $allowed));

        if ($columns === []) {
            $columns = $allowed;
        }

        $export = new UsersExport($query, $columns);
        $filename = 'users-'.now()->format('Ymd-His');

        return $format === 'csv'
            ? ExcelFacade::download($export, "{$filename}.csv", Excel::CSV, ['Content-Type' => 'text/csv'])
            : ExcelFacade::download($export, "{$filename}.xlsx", Excel::XLSX);
    }
}
