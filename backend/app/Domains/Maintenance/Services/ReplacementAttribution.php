<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

/**
 * Which kind of part failed, for a hardware replacement (Phase 2.10 R4, SDD
 * DD-94; plan decision 9.1-14).
 *
 * A replacement records the part taken out and the part put in. What failed is
 * the **old** part, so that is read first:
 *
 *  1. `old_component` — the catalog component removed;
 *  2. `old_asset_model` — the removed asset's model's component type;
 *  3. `inferred_from_new` — nothing about the old part was recorded, so the
 *     new part's type is used (a like-for-like swap is the normal case), and
 *     the report says it was inferred;
 *  4. `not_recorded` — neither side says.
 *
 * The basis travels with every figure, so "3 failed power supplies" can always
 * be read as "2 recorded, 1 inferred". One SQL expression, used by every
 * report query and the record tables, so they cannot disagree.
 *
 * Expects the aliases `hco` (old component), `hmo`/`hcm` (old asset's model and
 * its component) and `hcn` (new component), joined with {@see joins()}.
 */
final class ReplacementAttribution
{
    public const BASIS_OLD_COMPONENT = 'old_component';

    public const BASIS_OLD_ASSET_MODEL = 'old_asset_model';

    public const BASIS_INFERRED = 'inferred_from_new';

    public const BASIS_NOT_RECORDED = 'not_recorded';

    public const LABELS = [
        self::BASIS_OLD_COMPONENT => 'Removed part recorded',
        self::BASIS_OLD_ASSET_MODEL => 'Removed equipment recorded',
        self::BASIS_INFERRED => 'Inferred from the new part',
        self::BASIS_NOT_RECORDED => 'Not recorded',
    ];

    /** The failed part's component type, or NULL when not recorded. */
    public static function typeSql(): string
    {
        return 'COALESCE(hco.component_type, hcm.component_type, hcn.component_type)';
    }

    public static function basisSql(): string
    {
        return "CASE WHEN hco.component_type IS NOT NULL THEN '".self::BASIS_OLD_COMPONENT."'"
            ." WHEN hcm.component_type IS NOT NULL THEN '".self::BASIS_OLD_ASSET_MODEL."'"
            ." WHEN hcn.component_type IS NOT NULL THEN '".self::BASIS_INFERRED."'"
            ." ELSE '".self::BASIS_NOT_RECORDED."' END";
    }

    /**
     * Left joins for the aliases above, on a query over `hardware_replacements as hr`.
     *
     * @template T of \Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function joins($query)
    {
        return $query
            ->leftJoin('hardware_components as hco', 'hco.id', '=', 'hr.old_component_id')
            ->leftJoin('assets as aold', 'aold.id', '=', 'hr.old_asset_id')
            ->leftJoin('hardware_models as hmo', 'hmo.id', '=', 'aold.hardware_model_id')
            ->leftJoin('hardware_components as hcm', 'hcm.id', '=', 'hmo.hardware_component_id')
            ->leftJoin('hardware_components as hcn', 'hcn.id', '=', 'hr.new_component_id');
    }
}
