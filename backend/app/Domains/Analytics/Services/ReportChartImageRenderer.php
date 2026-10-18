<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use DOMDocument;
use DOMElement;

/**
 * Draws a {@see ChartSpec} as SVG for the PDF (Phase 2.10, SDD DD-85).
 *
 * Built element by element with `DOMDocument`, so every label — a room name,
 * a status — is a text node and can never become markup. Only basic shapes and
 * text are emitted: no `<image>`, `<use>`, `href` or style sheet, nothing that
 * could fetch or reference anything. Measured in R0: dompdf's php-svg-lib draws
 * the dashed comparison line and `text-anchor` correctly; the GD/PNG
 * alternative lost the dashes and drew hairlines.
 *
 * The drawing follows the screen's chart rules: no area fill, the comparison
 * dashed `6 4`, unknown values left as gaps (never drawn as zero), ticks thinned
 * so labels never collide, and a donut whose slices carry labels only from 8%
 * up (the table under it carries the rest).
 */
final class ReportChartImageRenderer
{
    public const WIDTH = 680;

    public const HEIGHT = 300;

    private const FONT = 'DejaVu Sans';

    private DOMDocument $doc;

    private DOMElement $svg;

    /** A `data:` URI for an `<img>` in the PDF template. */
    public function dataUri(ChartSpec $spec): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($spec));
    }

    public function svg(ChartSpec $spec): string
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->svg = $this->doc->createElementNS('http://www.w3.org/2000/svg', 'svg');
        $this->svg->setAttribute('width', (string) self::WIDTH);
        $this->svg->setAttribute('height', (string) self::HEIGHT);
        $this->svg->setAttribute('viewBox', '0 0 '.self::WIDTH.' '.self::HEIGHT);
        $this->doc->appendChild($this->svg);

        match ($spec->kind) {
            ChartSpec::LINE => $this->line($spec),
            ChartSpec::DONUT => $this->donut($spec),
            default => $this->bar($spec),
        };

        return (string) $this->doc->saveXML();
    }

    private function line(ChartSpec $spec): void
    {
        [$left, $right, $top, $bottom] = [52, self::WIDTH - 16, 16, self::HEIGHT - 64];
        $max = $this->niceMax($spec);
        $count = max(count($spec->categories), 1);
        $x = static fn (int $i): float => $count === 1 ? ($left + $right) / 2 : $left + ($right - $left) * $i / ($count - 1);
        $y = static fn (float $v): float => $bottom - ($bottom - $top) * $v / $max;

        $this->grid($left, $right, $top, $bottom, $max);

        $step = max(1, (int) ceil($count / 8));
        foreach ($spec->categories as $i => $label) {
            if ($i % $step === 0 || $i === $count - 1) {
                $this->text($x($i), $bottom + 20, $this->shorten($label, 14), 11, 'middle');
            }
        }

        // The comparison first, so the current period is drawn on top.
        $order = array_keys($spec->series);
        usort($order, static fn (int $a, int $b): int => ($spec->series[$a]['style'] === 'compare' ? 0 : 1) <=> ($spec->series[$b]['style'] === 'compare' ? 0 : 1));

        foreach ($order as $s) {
            $series = $spec->series[$s];
            $color = $spec->colors[$s] ?? ReportPalette::CURRENT;
            $segment = [];
            foreach ($series['values'] as $i => $value) {
                if ($value === null) {
                    $this->polyline($segment, $color, $series['style'] === 'compare');
                    $segment = [];

                    continue;
                }
                $segment[] = [$x($i), $y((float) $value)];
            }
            $this->polyline($segment, $color, $series['style'] === 'compare');
        }

        $this->legend($spec, $left, self::HEIGHT - 22);
    }

    private function bar(ChartSpec $spec): void
    {
        $values = $spec->series[0]['values'] ?? [];
        $count = max(count($values), 1);
        [$labelWidth, $right, $top] = [210, self::WIDTH - 56, 12];
        $band = (self::HEIGHT - $top - 12) / $count;
        $max = max([1.0, ...array_map(static fn ($v): float => (float) ($v ?? 0), $values)]);

        foreach ($values as $i => $value) {
            $y = $top + $band * $i;
            $length = ($right - $labelWidth) * (float) ($value ?? 0) / $max;
            $this->text($labelWidth - 8, $y + $band / 2 + 4, $this->shorten($spec->categories[$i] ?? '', 30), 11, 'end');
            $this->el('rect', ['x' => $labelWidth, 'y' => round($y + $band * 0.18, 1), 'width' => round($right - $labelWidth, 1), 'height' => round($band * 0.64, 1), 'fill' => '#efede8']);
            $this->el('rect', ['x' => $labelWidth, 'y' => round($y + $band * 0.18, 1), 'width' => round(max($length, 0), 1), 'height' => round($band * 0.64, 1), 'fill' => $spec->colors[0] ?? ReportPalette::BAR]);
            $this->text($right + 6, $y + $band / 2 + 4, $value === null ? '—' : number_format((float) $value), 11, 'start');
        }
    }

    private function donut(ChartSpec $spec): void
    {
        $values = array_map(static fn ($v): float => (float) ($v ?? 0), $spec->series[0]['values'] ?? []);
        $total = array_sum($values);
        [$cx, $cy, $outer, $inner] = [170, self::HEIGHT / 2, 120, 72];

        if ($total <= 0) {
            return;
        }

        $angle = -M_PI / 2;
        foreach ($values as $i => $value) {
            if ($value <= 0) {
                continue;
            }
            $sweep = 2 * M_PI * $value / $total;
            $color = $spec->colors[$i] ?? ReportPalette::STATUS_FALLBACK;

            if ($sweep >= 2 * M_PI - 0.0001) {
                $this->el('circle', ['cx' => $cx, 'cy' => $cy, 'r' => ($outer + $inner) / 2, 'fill' => 'none', 'stroke' => $color, 'stroke-width' => $outer - $inner]);
            } else {
                $this->el('path', ['d' => $this->arc($cx, $cy, $outer, $inner, $angle, $angle + $sweep), 'fill' => $color, 'stroke' => '#ffffff', 'stroke-width' => 2]);
            }

            if ($value / $total >= 0.08) {
                $mid = $angle + $sweep / 2;
                $r = ($outer + $inner) / 2;
                $this->text($cx + $r * cos($mid), $cy + $r * sin($mid) + 4, number_format($value / $total * 100, 0).'%', 11, 'middle', '#ffffff', true);
            }
            $angle += $sweep;
        }

        $this->text($cx, $cy - 2, number_format($total), 22, 'middle', '#111111', true);
        $this->text($cx, $cy + 18, strtolower($spec->valueLabel), 11, 'middle');

        $y = 40;
        foreach ($spec->categories as $i => $label) {
            $this->el('rect', ['x' => 330, 'y' => $y - 10, 'width' => 12, 'height' => 12, 'fill' => $spec->colors[$i] ?? ReportPalette::STATUS_FALLBACK]);
            $this->text(350, $y, $this->shorten($label, 28).' — '.number_format($values[$i] ?? 0), 12, 'start');
            $y += 22;
        }
    }

    private function grid(float $left, float $right, float $top, float $bottom, float $max): void
    {
        for ($i = 0; $i <= 4; $i++) {
            $value = $max * $i / 4;
            $y = $bottom - ($bottom - $top) * $i / 4;
            $this->el('line', ['x1' => $left, 'y1' => round($y, 1), 'x2' => $right, 'y2' => round($y, 1), 'stroke' => ReportPalette::GRID, 'stroke-width' => 1]);
            $this->text($left - 6, $y + 4, number_format($value, $max < 4 ? 1 : 0), 11, 'end');
        }
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function polyline(array $points, string $color, bool $dashed): void
    {
        if ($points === []) {
            return;
        }

        if (count($points) === 1) {
            $this->el('circle', ['cx' => round($points[0][0], 1), 'cy' => round($points[0][1], 1), 'r' => 3, 'fill' => $color]);

            return;
        }

        $attributes = [
            'points' => implode(' ', array_map(static fn (array $p): string => round($p[0], 1).','.round($p[1], 1), $points)),
            'fill' => 'none',
            'stroke' => $color,
            'stroke-width' => 2,
        ];

        if ($dashed) {
            $attributes['stroke-dasharray'] = '6 4';
        }

        $this->el('polyline', $attributes);
    }

    private function legend(ChartSpec $spec, float $x, float $y): void
    {
        foreach ($spec->series as $s => $series) {
            $attributes = ['x1' => $x, 'y1' => $y - 4, 'x2' => $x + 24, 'y2' => $y - 4, 'stroke' => $spec->colors[$s] ?? ReportPalette::CURRENT, 'stroke-width' => 2];
            if ($series['style'] === 'compare') {
                $attributes['stroke-dasharray'] = '6 4';
            }
            $this->el('line', $attributes);
            $label = $this->shorten($series['name'], 44);
            $this->text($x + 30, $y, $label, 11, 'start');
            $x += 30 + mb_strlen($label) * 6.4 + 24;
        }
    }

    private function niceMax(ChartSpec $spec): float
    {
        $max = 0.0;
        foreach ($spec->series as $series) {
            foreach ($series['values'] as $value) {
                $max = max($max, (float) ($value ?? 0));
            }
        }

        if ($max <= 4) {
            return 4.0;
        }

        $magnitude = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($step * $magnitude >= $max) {
                return (float) ($step * $magnitude);
            }
        }

        return $max;
    }

    private function arc(float $cx, float $cy, float $outer, float $inner, float $from, float $to): string
    {
        $large = $to - $from > M_PI ? 1 : 0;
        $p = static fn (float $r, float $a): string => round($cx + $r * cos($a), 2).' '.round($cy + $r * sin($a), 2);

        return sprintf(
            'M %s A %s %s 0 %d 1 %s L %s A %s %s 0 %d 0 %s Z',
            $p($outer, $from), $outer, $outer, $large, $p($outer, $to),
            $p($inner, $to), $inner, $inner, $large, $p($inner, $from),
        );
    }

    private function text(float $x, float $y, string $content, int $size, string $anchor, string $fill = '#333333', bool $bold = false): void
    {
        $node = $this->el('text', [
            'x' => round($x, 1),
            'y' => round($y, 1),
            'font-family' => self::FONT,
            'font-size' => $size,
            'text-anchor' => $anchor,
            'fill' => $fill,
        ]);

        if ($bold) {
            $node->setAttribute('font-weight', 'bold');
        }

        $node->appendChild($this->doc->createTextNode($content));
    }

    /**
     * @param  array<string, string|int|float>  $attributes
     */
    private function el(string $name, array $attributes): DOMElement
    {
        $node = $this->doc->createElementNS('http://www.w3.org/2000/svg', $name);
        foreach ($attributes as $key => $value) {
            $node->setAttribute($key, (string) $value);
        }
        $this->svg->appendChild($node);

        return $node;
    }

    private function shorten(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
