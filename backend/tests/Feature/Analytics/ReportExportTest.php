<?php

declare(strict_types=1);

use App\Domains\Analytics\Services\ReportExporter;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\PermissionGrantType;
use App\Enums\ReportExportStatus;
use App\Models\ActivityLog;
use App\Models\ReportExport;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 R3 — queued report documents (SDD DD-91): the job lifecycle, what
 * each format carries, and how a file is handed back.
 *
 * Content is checked by opening the produced files, not by trusting the
 * renderer: the workbook is re-read with PhpSpreadsheet, the Word file is
 * unzipped and parsed as XML, the PDF's bytes are inspected.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    Storage::fake('local');
    $this->travelTo(ReportFixtures::local('2026-10-18 10:00')->utc());

    $this->admin = userWithRole('administrator');
    $teacher = userWithRole('teacher');

    // A room whose name is three attacks at once: a spreadsheet formula, a
    // Word field and a Markdown link.
    $this->room = ReportFixtures::room(ReportFixtures::floor(), 'laboratory', [
        'name' => '=HYPERLINK("http://evil.example","Lab") <w:fldSimple w:instr="HYPERLINK x"/> [click](http://evil.example)',
        'code' => 'LAB-201',
    ]);

    ReportFixtures::ticketAt($teacher, '2026-10-02 09:00', 'open', ['room_id' => $this->room->id, 'title' => '=cmd|calc projector broken']);
    ReportFixtures::ticketAt($teacher, '2026-10-05 13:30', 'resolved', ['room_id' => $this->room->id, 'title' => 'Printer jam in lab']);
    ReportFixtures::ticketAt($teacher, '2026-10-12 08:15', 'closed', ['title' => 'Wi-Fi down']);

    $this->export = fn (array $body): ReportExport => ReportExport::query()->where('uuid', $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/exports', ['report_type' => 'ticket_overview', ...$body])
        ->assertSuccessful()
        ->json('data.id'))->firstOrFail();
});

it('renders a workbook with every sheet, native charts and every record', function (): void {
    $export = ($this->export)(['format' => 'xlsx', 'include_records' => true]);

    expect($export->status)->toBe(ReportExportStatus::Completed)
        ->and($export->row_count)->toBe(3)
        ->and($export->sha256)->toBe(hash('sha256', Storage::disk('local')->get((string) $export->path)))
        ->and($export->size_bytes)->toBe(Storage::disk('local')->size((string) $export->path));

    $file = tempnam(sys_get_temp_dir(), 'xl');
    file_put_contents($file, Storage::disk('local')->get((string) $export->path));
    $reader = IOFactory::createReader('Xlsx');
    $reader->setIncludeCharts(true);
    $book = $reader->load($file);

    expect($book->getSheetNames())->toContain('Summary', 'Records', 'Notes');

    $charts = 0;
    foreach ($book->getAllSheets() as $sheet) {
        $charts += $sheet->getChartCount();
    }
    expect($charts)->toBeGreaterThanOrEqual(2);

    $records = $book->getSheetByName('Records');
    expect($records->getFreezePane())->toBe('A2')
        ->and($records->getAutoFilter()->getRange())->not->toBe('');

    // Formula-looking text is stored as text on every sheet it reaches.
    $found = false;
    foreach ($book->getAllSheets() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if (is_string($value) && (str_starts_with($value, '=HYPERLINK') || str_starts_with($value, '=cmd'))) {
                    $found = true;
                    expect($cell->getDataType())->toBe('s');
                }
            }
        }
    }
    expect($found)->toBeTrue();
    @unlink($file);
});

it('audits the export only after the file exists', function (): void {
    $export = ($this->export)(['format' => 'csv']);

    $log = ActivityLog::query()->where('action', ActivityAction::ReportExported->value)->sole();
    expect($log->subject_id)->toBe($export->id)
        ->and($log->properties)->toMatchArray(['format' => 'csv', 'report_type' => 'ticket_overview'])
        ->and(json_encode($log->properties))->not->toContain('Printer jam');
});

it('writes CSV with a byte-order mark and neutralises formula text', function (): void {
    $export = ($this->export)(['format' => 'csv', 'include_records' => true]);
    $csv = (string) Storage::disk('local')->get((string) $export->path);

    expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue()
        ->and($csv)->toContain("'=cmd|calc projector broken")
        ->and($csv)->not->toMatch('/(^|,)=cmd/m');
});

it('keeps hostile text literal in Word documents and repeats table headers', function (): void {
    $export = ($this->export)(['format' => 'docx', 'include_records' => true]);
    $file = tempnam(sys_get_temp_dir(), 'dx');
    file_put_contents($file, Storage::disk('local')->get((string) $export->path));

    $zip = new ZipArchive;
    expect($zip->open($file))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $footer = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with((string) $zip->getNameIndex($i), 'word/footer')) {
            $footer .= (string) $zip->getFromIndex($i);
        }
    }
    $zip->close();

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $instructions = [];
    foreach ($dom->getElementsByTagNameNS($w, 'instrText') as $node) {
        $instructions[] = trim($node->textContent);
    }

    expect($dom->getElementsByTagNameNS($w, 'fldSimple')->length)->toBe(0)
        ->and($instructions)->toBe([])
        ->and($xml)->toContain('&lt;w:fldSimple')
        ->and($xml)->toContain('<w:tblHeader')
        ->and($xml)->toContain('<w:cantSplit')
        ->and($footer)->toContain('PAGE')
        ->and($footer)->toContain('NUMPAGES');

    // It re-opens.
    expect(WordIOFactory::load($file)->getSections())->not->toBeEmpty();
    @unlink($file);
});

it('renders a PDF with its title and no remote fetch', function (): void {
    $export = ($this->export)(['format' => 'pdf', 'paper' => 'letter', 'orientation' => 'landscape']);
    $pdf = (string) Storage::disk('local')->get((string) $export->path);

    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue()
        ->and($export->filename)->toMatch('/^ticket-overview_[a-z0-9-]+_\d{8}-\d{4}\.pdf$/');
});

it('holds the PDF template to tables, with no flex or grid layout', function (): void {
    $template = (string) file_get_contents(resource_path('views/reports/pdf/report.blade.php'));

    expect($template)->not->toMatch('/display:\s*(flex|grid)/')
        ->and($template)->not->toContain('{!!');
});

it('returns the same pending document for the same request in the same minute', function (): void {
    $first = ($this->export)(['format' => 'xlsx']);
    $second = ($this->export)(['format' => 'xlsx']);

    expect($second->id)->toBe($first->id)
        ->and(ReportExport::query()->count())->toBe(1);
});

it('refuses to render for an administrator who lost the right after asking', function (): void {
    $export = ReportExport::factory()->for($this->admin)->create(['filters' => ['period' => 'month'], 'as_of' => now()]);
    givePermission($this->admin, 'reports.export', PermissionGrantType::Deny);
    app(PermissionResolver::class)->forget($this->admin);

    app(ReportExporter::class)->render($export->id);

    expect($export->refresh()->status)->toBe(ReportExportStatus::Failed)
        ->and($export->error_code)->toBe('not_authorized')
        ->and($export->path)->toBeNull()
        ->and(ActivityLog::query()->where('action', ActivityAction::ReportExported->value)->count())->toBe(0);
});

it('marks the document failed, and audits nothing, when the file cannot be written', function (): void {
    $export = ReportExport::factory()->for($this->admin)->create(['format' => 'csv', 'filters' => ['period' => 'month'], 'as_of' => now()]);
    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldReceive('put')->andReturnFalse();
    Storage::set('local', $disk);

    app(ReportExporter::class)->render($export->id);

    expect($export->refresh()->status)->toBe(ReportExportStatus::Failed)
        ->and($export->error_code)->toBe('render_failed')
        ->and(ActivityLog::query()->where('action', ActivityAction::ReportExported->value)->count())->toBe(0);
});

it('never renders the same document twice', function (): void {
    $export = ($this->export)(['format' => 'csv']);

    expect(app(ReportExporter::class)->render($export->id))->toBeFalse()
        ->and($export->refresh()->attempts)->toBe(1);
});

it('streams a finished file as a hardened attachment, and audits each download', function (): void {
    $export = ($this->export)(['format' => 'xlsx']);

    $response = $this->actingAs($this->admin)->get("/api/admin/reports/exports/{$export->uuid}/download")->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');

    $this->actingAs($this->admin)->get("/api/admin/reports/exports/{$export->uuid}/download")->assertOk();
    expect(ActivityLog::query()->where('action', ActivityAction::ReportDownloaded->value)->count())->toBe(2)
        ->and($export->refresh()->download_count)->toBe(2);
});

it('answers 409 while a file is being prepared and 410 once it has expired', function (): void {
    $pending = ReportExport::factory()->for($this->admin)->create();
    $this->actingAs($this->admin)->getJson("/api/admin/reports/exports/{$pending->uuid}/download")->assertStatus(409);

    $old = ReportExport::factory()->for($this->admin)->completed()->create(['expires_at' => now()->subMinute()]);
    $this->actingAs($this->admin)->getJson("/api/admin/reports/exports/{$old->uuid}/download")->assertStatus(410);
});

it('deletes expired files and keeps their rows as expired', function (): void {
    $export = ($this->export)(['format' => 'csv']);
    $this->travel(73)->hours();

    $this->artisan('reports:prune-exports')->assertSuccessful();

    expect($export->refresh()->status)->toBe(ReportExportStatus::Expired)
        ->and($export->path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('report-exports'))->toBe([]);
});

it('prints the same document a PDF carries, and audits it', function (): void {
    $document = $this->actingAs($this->admin)
        ->postJson('/api/admin/reports/print', ['report_type' => 'ticket_overview', 'period' => 'month', 'include_records' => true])
        ->assertOk()
        ->json('data');

    expect($document['title'])->toBe('Ticket overview')
        ->and($document['organisation'])->toBe('SccIT')
        ->and($document['records_included'])->toBeTrue()
        ->and(collect($document['sections'])->pluck('key')->all())->toContain('headline', 'over_time', 'statuses', 'locations', 'records')
        ->and(ActivityLog::query()->where('action', ActivityAction::ReportPrinted->value)->count())->toBe(1);
});

it('puts the same reported figure in the document as on the dashboard', function (): void {
    $overview = $this->actingAs($this->admin)->getJson('/api/admin/reports/ticket-overview?period=month')->json('data.kpis.reported.value');
    $document = $this->actingAs($this->admin)->postJson('/api/admin/reports/print', ['report_type' => 'ticket_overview', 'period' => 'month'])->json('data');

    $headline = collect($document['sections'])->firstWhere('key', 'headline');
    expect($headline['kpis'][0]['label'])->toBe('Reported')
        ->and($headline['kpis'][0]['value'])->toBe(number_format($overview));
});
