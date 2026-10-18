<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\DTOs\ImportIssue;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves an asset row's supplier and custodian (Phase 2.10 R6).
 *
 * Both are **matched only**. A supplier is found by name, case-insensitively,
 * among suppliers that are not archived — and is never created from a
 * spreadsheet cell. A custodian is found by email among technicians and
 * administrators, the only people who may hold equipment (FR-AST-007). An
 * unmatched value holds the row for review; acknowledging it saves the asset
 * without that reference.
 */
final class ImportReferenceResolver
{
    /** @var array<string, array{name: string, active: bool}>|null */
    private ?array $suppliers = null;

    /** @var array<string, string>|null lower(email) => uuid */
    private ?array $custodians = null;

    /**
     * @return array{payload: array<string, string>, issues: list<ImportIssue>}
     */
    public function resolve(?string $supplier, ?string $custodian): array
    {
        $payload = [];
        $issues = [];

        if ($supplier !== null && trim($supplier) !== '') {
            $match = $this->suppliers()[mb_strtolower(trim($supplier))] ?? null;
            if ($match === null) {
                $issues[] = ImportIssue::make('supplier_unmatched', 'supplier', "No supplier is called \"{$supplier}\". Acknowledge to save this asset without a supplier, or correct the name.");
            } else {
                $payload['supplier'] = $match['name'];
                if (! $match['active']) {
                    $issues[] = ImportIssue::make('supplier_inactive', 'supplier', "Supplier {$match['name']} is marked inactive.");
                }
            }
        }

        if ($custodian !== null && trim($custodian) !== '') {
            $email = trim($custodian);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $issues[] = ImportIssue::make('custodian_not_email', 'custodian_email', "Custodian \"{$custodian}\" is not an email address. Enter the email of a technician or administrator.");
            } else {
                $uuid = $this->custodians()[mb_strtolower($email)] ?? null;
                if ($uuid === null) {
                    $issues[] = ImportIssue::make('custodian_unmatched', 'custodian_email', "No technician or administrator has the email {$email}. Acknowledge to save this asset without a custodian, or correct it.");
                } else {
                    $payload['technician'] = $uuid;
                }
            }
        }

        return ['payload' => $payload, 'issues' => $issues];
    }

    /**
     * @return array<string, array{name: string, active: bool}>
     */
    private function suppliers(): array
    {
        if ($this->suppliers === null) {
            $this->suppliers = [];
            foreach (Supplier::query()->get(['name', 'is_active']) as $supplier) {
                $this->suppliers[mb_strtolower($supplier->name)] = ['name' => $supplier->name, 'active' => (bool) $supplier->is_active];
            }
        }

        return $this->suppliers;
    }

    /**
     * @return array<string, string>
     */
    private function custodians(): array
    {
        if ($this->custodians === null) {
            $this->custodians = User::query()
                ->whereHas('role', fn (Builder $q): Builder => $q->whereIn('slug', ['technician', 'administrator']))
                ->get(['uuid', 'email'])
                ->mapWithKeys(fn (User $u): array => [mb_strtolower((string) $u->email) => (string) $u->uuid])
                ->all();
        }

        return $this->custodians;
    }
}
