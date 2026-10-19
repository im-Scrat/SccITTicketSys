{{--
    Report PDF (Phase 2.10, SDD DD-85/DD-91). Tables only: dompdf has no flex
    or grid layout. Every value is escaped by {{ }}; charts are data: SVG.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $document->title }} — {{ $document->periodLabel }}</title>
<style>
    @page { margin: 18mm 14mm 20mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111; line-height: 1.35; }
    h1 { font-size: 20pt; margin: 0 0 4pt; }
    h2 { font-size: 14pt; margin: 16pt 0 4pt; page-break-after: avoid; }
    h3 { font-size: 11pt; margin: 10pt 0 4pt; page-break-after: avoid; }
    p { margin: 0 0 4pt; }
    .muted { color: #444; }
    .small { font-size: 8.5pt; }
    .break { page-break-before: always; }
    table { width: 100%; border-collapse: collapse; margin: 2pt 0 6pt; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th, td { border: 0.5pt solid #999; padding: 3pt 4pt; text-align: left; vertical-align: top; }
    th { background: #e9e6df; font-weight: bold; }
    td.number, th.number { text-align: right; white-space: nowrap; }
    td.code { font-family: 'DejaVu Sans Mono', monospace; white-space: nowrap; width: 22mm; }
    table.kv th { width: 34mm; }
    .kpis td { border: 0.5pt solid #bbb; width: 25%; }
    .kpi-value { font-size: 18pt; font-weight: bold; }
    figure { margin: 4pt 0; page-break-inside: avoid; }
    ul { margin: 2pt 0 6pt 14pt; padding: 0; }
</style>
</head>
<body>
    <h1>{{ $document->title }}</h1>
    <p><strong>{{ $document->organisation }}</strong></p>
    <p>{{ $document->periodLabel }}@if ($document->comparisonLabel) · compared with {{ $document->comparisonLabel }}@endif</p>
    <p class="muted">{{ $document->asOfLabel }} · {{ $document->generatedLabel }}</p>

    <h3>Filters</h3>
    <table class="kv">
        @foreach ($document->filters as $filter)
            <tr><th>{{ $filter['label'] }}</th><td>{{ $filter['value'] }}</td></tr>
        @endforeach
    </table>

    <h3>Summary</h3>
    <p class="small muted">{{ $document->summaryLabel }}</p>
    <ul>
        @foreach ($document->summary as $sentence)
            <li>{{ $sentence }}</li>
        @endforeach
    </ul>
    @foreach ($document->caveats as $caveat)
        <p class="small"><em>{{ $caveat }}</em></p>
    @endforeach

    @foreach ($document->sections as $section)
        <h2 @class(['break' => ! $loop->first && $section->key === 'records'])>{{ $section->title }}</h2>
        @if ($section->intro)
            <p>{{ $section->intro }}</p>
        @endif

        @if ($section->kpis !== [])
            <table class="kpis">
                <tr>
                    @foreach ($section->kpis as $kpi)
                        <td>
                            <div class="small muted">{{ $kpi->label }}</div>
                            <div class="kpi-value">{{ $kpi->value }}</div>
                            @if ($kpi->detail)<div class="small">{{ $kpi->detail }}</div>@endif
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif

        @foreach ($section->charts as $i => $chart)
            @isset($charts[$section->key][$i])
                <figure>
                    <h3>{{ $chart->title }}</h3>
                    <img src="{{ $charts[$section->key][$i] }}" width="{{ $chartWidth }}" height="{{ $chartHeight }}" alt="{{ $chart->description }}">
                    <p class="small muted">{{ $chart->description }}</p>
                </figure>
            @endisset
        @endforeach

        @foreach ($section->tables as $table)
            @if ($section->kpis === [] || $table->key !== $section->key)
                <h3>{{ $table->title }}</h3>
                <table>
                    <thead>
                        <tr>
                            @foreach ($table->columns as $column)
                                <th @class(['number' => in_array($column['type'], ['number', 'percent'], true)])>{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table->rows as $row)
                            <tr>
                                @foreach ($table->columns as $c => $column)
                                    @php($value = $row[$c] ?? '')
                                    <td @class(['number' => in_array($column['type'], ['number', 'percent'], true), 'code' => $column['type'] === 'code'])>{{ is_int($value) ? number_format($value) : \Illuminate\Support\Str::limit((string) $value, $cellLimit) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @foreach (array_filter([$table->note, $table->truncationNote()]) as $note)
                    <p class="small"><em>{{ $note }}</em></p>
                @endforeach
            @endif
        @endforeach

        @foreach ($section->notes as $note)
            <p class="small muted">{{ $note }}</p>
        @endforeach
    @endforeach

    <h2 class="break">How the figures are counted</h2>
    <table class="kv">
        @foreach ($document->definitions as $definition)
            <tr><th>{{ $definition['term'] }}</th><td>{{ $definition['definition'] }}</td></tr>
        @endforeach
    </table>

    <h2>Limitations</h2>
    <ul>
        @foreach ($document->limitations as $limitation)
            <li>{{ $limitation }}</li>
        @endforeach
    </ul>
</body>
</html>
