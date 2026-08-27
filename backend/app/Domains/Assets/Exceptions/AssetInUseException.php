<?php

declare(strict_types=1);

namespace App\Domains\Assets\Exceptions;

use App\Domains\Locations\Exceptions\LocationInUseException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised when archiving an asset or PC unit would strand something that still
 * depends on it (SRS FR-AST-012, FR-PC-004). Renders its own 422 — the same
 * self-rendering pattern as {@see LocationInUseException} — carrying a
 * machine-readable blocker report so the UI can name what is in the way.
 *
 * This is the write-path half of SDD DD-29's split: the Policy answers the
 * permission question (403), the Action answers the invariant question (422).
 *
 * Response shape:
 *   {
 *     "message": "...",
 *     "code": "asset_in_use",
 *     "level": "asset" | "pc_unit",
 *     "blockers": { "installed": 1, "open_tickets": 2 },
 *     "installed_in": { "id": "<uuid>", "name": "LAB1-PC-04" }   // assets only
 *   }
 */
class AssetInUseException extends RuntimeException
{
    /**
     * @param  'asset'|'pc_unit'  $level
     * @param  array<string, int>  $blockers
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly string $level,
        private readonly array $blockers,
        private readonly array $context = [],
    ) {
        parent::__construct(self::summarize($level, $blockers));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'asset_in_use',
            'level' => $this->level,
            'blockers' => $this->blockers,
            ...$this->context,
        ], 422);
    }

    /**
     * @param  array<string, int>  $blockers
     */
    private static function summarize(string $level, array $blockers): string
    {
        $nouns = [
            'installed' => 'installed-component link',
            'components' => 'installed component',
            'open_tickets' => 'open ticket',
        ];

        $parts = [];

        foreach ($nouns as $key => $noun) {
            $count = $blockers[$key] ?? 0;

            if ($count > 0) {
                $parts[] = $count.' '.$noun.($count === 1 ? '' : 's');
            }
        }

        $subject = $level === 'asset' ? 'asset' : 'PC unit';
        $held = $parts === [] ? 'live records' : implode(' and ', $parts);

        return "This {$subject} still has {$held}. Remove the components and resolve or close "
            .'the open tickets before archiving it.';
    }
}
