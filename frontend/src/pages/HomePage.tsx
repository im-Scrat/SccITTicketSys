import { useQuery } from '@tanstack/react-query'
import { Activity, CheckCircle2, XCircle, Server } from 'lucide-react'
import { getHealth } from '@/services/health'

function StatusRow({ label, value }: { label: string; value: string }) {
  const ok = value === 'ok'
  return (
    <div className="flex items-center justify-between border-b border-slate-200/60 py-2 last:border-0">
      <span className="font-medium capitalize text-slate-700">{label}</span>
      <span
        className={`inline-flex items-center gap-1.5 text-sm ${
          ok ? 'text-emerald-600' : 'text-rose-600'
        }`}
      >
        {ok ? <CheckCircle2 size={16} /> : <XCircle size={16} />}
        {value}
      </span>
    </div>
  )
}

export default function HomePage() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['health'],
    queryFn: getHealth,
  })

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <div className="mb-6 flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white">
            <Server size={20} />
          </span>
          <div>
            <h1 className="text-lg font-semibold text-slate-900">SccIT</h1>
            <p className="text-sm text-slate-500">
              School IT Asset &amp; Service Management
            </p>
          </div>
        </div>

        <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100">
          <div className="mb-2 flex items-center gap-2 text-slate-600">
            <Activity size={16} />
            <span className="text-sm font-medium">Backend health (/api/health)</span>
          </div>

          {isLoading && <p className="text-sm text-slate-500">Checking…</p>}
          {isError && (
            <p className="text-sm text-rose-600">
              Could not reach the API. Is the stack running?
            </p>
          )}
          {data && (
            <div>
              <StatusRow label="status" value={data.status === 'healthy' ? 'ok' : data.status} />
              {Object.entries(data.checks).map(([k, v]) => (
                <StatusRow key={k} label={k} value={v} />
              ))}
              <p className="pt-3 text-xs text-slate-400">
                Laravel {data.laravel}
              </p>
            </div>
          )}
        </div>

        <p className="mt-6 text-center text-xs text-slate-400">
          Environment bootstrap complete — ready for feature development.
        </p>
      </div>
    </main>
  )
}
