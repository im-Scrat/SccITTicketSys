<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| dompdf (Phase 2.10, SDD DD-85)
|--------------------------------------------------------------------------
|
| Report PDFs are rendered from a template the application writes, but the
| values in it come from records people typed. Everything that could let a
| value reach outside the document is therefore off: no remote fetching, no
| PHP, no JavaScript, and a chroot on the views directory. Charts arrive as
| `data:` SVG built by ReportChartImageRenderer; that is the only protocol
| allowed.
|
| Fonts: DejaVu Sans, which renders every glyph the reports use (– ≥ ₱ “ ” ✓
| ▲ ▼, verified in R0). The font cache lives in storage/fonts (kept in git by
| its .gitignore) and the temp files in the system temp dir.
|
*/

return [
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => resource_path('views/reports'),
        'allowed_protocols' => [
            'data://' => ['rules' => []],
        ],
        'artifactPathValidation' => null,
        'log_output_file' => null,
        'enable_font_subsetting' => true,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'DejaVu Sans',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];
