<?php

declare(strict_types=1);

use App\Support\Exports\SafeValueBinder;

/*
|--------------------------------------------------------------------------
| maatwebsite/excel overrides (Phase 2.10, SDD DD-85)
|--------------------------------------------------------------------------
|
| Only the keys the application changes. The package merges its own
| defaults underneath (mergeConfigFrom works per top-level key), so every
| other setting keeps the package's value.
|
| `value_binder`: every exported cell goes through SafeValueBinder, so text
| that looks like a formula is stored as text (formula injection).
|
*/

return [
    'value_binder' => [
        'default' => SafeValueBinder::class,
    ],
];
