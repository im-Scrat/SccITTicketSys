import { cn } from '@/lib/cn'
import type { DocumentTable, ReportDocument } from '../../types'

/**
 * A report document laid out for paper (Phase 2.10 R3, SDD DD-91): the same
 * content, in the same order, as the PDF — cover, filters, summary, each
 * section with its figures, charts and tables, then definitions and
 * limitations.
 *
 * Always light: the page wraps it in `data-theme="light"`, and the print
 * stylesheet re-asserts the light tokens for Ctrl+P on any page. Charts are
 * the PDF's own SVG as `data:` images at a fixed 680×300 (the CSP's img-src
 * allows them, and an image cannot run script); each is followed by its table.
 */
export function PrintableReport({ document: doc }: { document: ReportDocument }) {
  return (
    <article className="mx-auto flex w-full max-w-[52rem] flex-col gap-6 text-ink print:max-w-none">
      <header className="flex flex-col gap-1 border-b-2 border-ink pb-4">
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">{doc.title}</h1>
        <p className="text-base font-semibold">{doc.organisation}</p>
        <p className="text-sm">
          {doc.period_label}
          {doc.comparison_label ? ` · compared with ${doc.comparison_label}` : ''}
        </p>
        <p className="text-xs text-muted">
          {doc.as_of_label} · {doc.generated_label}
        </p>
      </header>

      <section aria-labelledby="print-filters" className="break-inside-avoid">
        <h2 id="print-filters" className="mb-2 text-lg font-semibold text-ink-strong">
          Filters
        </h2>
        <table className="w-full border-collapse text-sm">
          <tbody>
            {doc.filters.map((f) => (
              <tr key={f.label} className="border-b border-border">
                <th scope="row" className="w-48 py-1.5 pr-4 text-left font-semibold">
                  {f.label}
                </th>
                <td className="py-1.5">{f.value}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section aria-labelledby="print-summary">
        <h2 id="print-summary" className="text-lg font-semibold text-ink-strong">
          Summary
        </h2>
        <p className="mb-2 text-xs text-muted">{doc.summary_label}</p>
        <ul className="list-disc pl-6 text-sm">
          {doc.summary.map((s) => (
            <li key={s}>{s}</li>
          ))}
        </ul>
        {doc.caveats.map((c) => (
          <p key={c} className="mt-1 text-sm italic">
            {c}
          </p>
        ))}
      </section>

      {doc.sections.map((section, index) => (
        <section
          key={section.key}
          aria-labelledby={`print-${section.key}`}
          className={cn(
            'flex flex-col gap-3',
            index > 0 && section.key === 'records' && 'print:break-before-page',
          )}
        >
          <h2
            id={`print-${section.key}`}
            className="text-xl font-semibold text-ink-strong break-after-avoid"
          >
            {section.title}
          </h2>
          {section.intro && <p className="text-sm">{section.intro}</p>}

          {section.kpis.length > 0 && (
            <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-4 print:grid-cols-4">
              {section.kpis.map((k) => (
                <div key={k.label} className="flex flex-col gap-1 bg-surface p-3">
                  <dt className="text-xs text-muted">{k.label}</dt>
                  <dd className="text-2xl font-semibold tabular-nums text-ink-strong">{k.value}</dd>
                  {k.detail && <dd className="text-xs">{k.detail}</dd>}
                </div>
              ))}
            </dl>
          )}

          {section.charts.map((chart) =>
            chart.image ? (
              <figure key={chart.title} className="flex flex-col gap-1 break-inside-avoid">
                <h3 className="text-base font-semibold text-ink-strong">{chart.title}</h3>
                <img
                  src={chart.image}
                  width={680}
                  height={300}
                  alt={chart.description}
                  className="h-auto w-full max-w-[680px]"
                />
                <figcaption className="text-xs text-muted">{chart.description}</figcaption>
              </figure>
            ) : null,
          )}

          {section.tables
            .filter((t) => !(section.kpis.length > 0 && t.key === section.key))
            .map((table) => (
              <PrintTable key={table.key} table={table} />
            ))}

          {section.notes.map((n) => (
            <p key={n} className="text-xs text-muted">
              {n}
            </p>
          ))}
        </section>
      ))}

      <section aria-labelledby="print-definitions" className="print:break-before-page">
        <h2 id="print-definitions" className="mb-2 text-xl font-semibold text-ink-strong">
          How the figures are counted
        </h2>
        <table className="w-full border-collapse text-sm">
          <tbody>
            {doc.definitions.map((d) => (
              <tr key={d.term} className="border-b border-border align-top">
                <th scope="row" className="w-48 py-1.5 pr-4 text-left font-semibold">
                  {d.term}
                </th>
                <td className="py-1.5">{d.definition}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section aria-labelledby="print-limitations">
        <h2 id="print-limitations" className="mb-2 text-xl font-semibold text-ink-strong">
          Limitations
        </h2>
        <ul className="list-disc pl-6 text-sm">
          {doc.limitations.map((l) => (
            <li key={l}>{l}</li>
          ))}
        </ul>
      </section>
    </article>
  )
}

function PrintTable({ table }: { table: DocumentTable }) {
  const numeric = (type: string) => type === 'number' || type === 'percent'

  return (
    <div className="flex flex-col gap-1">
      <h3 className="text-base font-semibold text-ink-strong break-after-avoid">{table.title}</h3>
      <div className="overflow-x-auto print:overflow-visible">
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b-2 border-ink">
              {table.columns.map((c) => (
                <th
                  key={c.key}
                  scope="col"
                  className={cn(
                    'px-2 py-1.5 text-left font-semibold',
                    numeric(c.type) && 'text-right',
                  )}
                >
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {table.rows.map((row, r) => (
              <tr key={r} className="border-b border-border align-top break-inside-avoid">
                {table.columns.map((c, i) => {
                  const value = row[i]
                  return (
                    <td
                      key={c.key}
                      className={cn(
                        'px-2 py-1.5',
                        numeric(c.type) && 'text-right tabular-nums whitespace-nowrap',
                        c.type === 'code' && 'font-mono whitespace-nowrap',
                      )}
                    >
                      {typeof value === 'number' ? value.toLocaleString('en-GB') : (value ?? '')}
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {[table.note, table.truncation_note].filter(Boolean).map((n) => (
        <p key={n as string} className="text-xs italic">
          {n}
        </p>
      ))}
    </div>
  )
}
