import { PlugZap } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Checkbox,
  Field,
  Input,
  Select,
  Skeleton,
  Surface,
} from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { useAiSettings, useSaveAiSettings, useTestAiConnection } from '../hooks/aiSettings'
import type { AiSettingsPayload, AiSettingsValues } from '../types'

/**
 * Phase 2.9 — the administrator's AI settings (the backend has existed since
 * WP-O; this is its screen).
 *
 * What it can change: which model answers, the confidence threshold, and the
 * switches — including **AI may raise ticket priority** (D-B), which is off
 * until an administrator has reviewed real recommendations. What it can never
 * do: see or set the provider key. The key is a server secret; this page shows
 * only whether the server has one.
 */
export default function AiSettingsPage() {
  useDocumentMeta({ title: 'AI settings' })
  const settings = useAiSettings()
  const [saved, setSaved] = useState(false)

  return (
    <div className="flex flex-col gap-6">
      <header className="measure">
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">AI settings</h1>
        <p className="mt-1 text-sm text-muted">
          Which models the AI features use, how confident they must be, and what they may do on
          their own. The provider key is a server secret and is never shown or set here.
        </p>
      </header>

      {settings.isError ? (
        <Alert tone="error">AI settings could not be loaded. Refresh the page to retry.</Alert>
      ) : settings.isLoading || !settings.data ? (
        <Skeleton className="h-96" />
      ) : (
        <>
          <ProviderStatus payload={settings.data} />
          {/* Keyed on what the server stored: a save re-seeds the form from
              the returned payload, not from what was typed. */}
          <SettingsForm
            key={JSON.stringify(settings.data.settings)}
            payload={settings.data}
            saved={saved}
            onSavedChange={setSaved}
          />
          <DataDisclosure payload={settings.data} />
        </>
      )}
    </div>
  )
}

function ProviderStatus({ payload }: { payload: AiSettingsPayload }) {
  const test = useTestAiConnection()

  return (
    <Surface className="flex flex-col gap-4 p-5">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="text-base font-semibold text-ink-strong">Provider</h2>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <Badge tone={payload.provider_configured ? 'success' : 'warning'}>
              {payload.provider_configured ? 'Key present on the server' : 'No key configured'}
            </Badge>
            <Badge tone={payload.ai_enabled ? 'success' : 'neutral'}>
              {payload.ai_enabled ? 'AI features on' : 'AI features off — no chat model selected'}
            </Badge>
          </div>
        </div>
        <Button
          variant="secondary"
          leftIcon={<PlugZap size={20} aria-hidden="true" />}
          loading={test.isPending}
          onClick={() => test.mutate()}
        >
          Test the connection
        </Button>
      </div>

      <div aria-live="polite">
        {test.isError && <Alert tone="error">{getErrorMessage(test.error)}</Alert>}
        {test.data && (
          <Alert tone={test.data.ok ? 'success' : 'warning'} title={test.data.message}>
            {test.data.chat && (
              <span className="block">
                Chat:{' '}
                {test.data.chat.ok
                  ? `answered in ${test.data.chat.latency_ms ?? '?'} ms`
                  : test.data.chat.message}
              </span>
            )}
            {test.data.embedding && (
              <span className="block">
                Embeddings:{' '}
                {test.data.embedding.ok
                  ? `${test.data.embedding.dimensions ?? '?'} dimensions`
                  : test.data.embedding.message}
              </span>
            )}
          </Alert>
        )}
      </div>

      <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
        <div>
          <dt className="text-muted">Knowledge indexed</dt>
          <dd className="text-lg font-semibold text-ink-strong tnum">{payload.index.indexed}</dd>
        </div>
        <div>
          <dt className="text-muted">Waiting to index</dt>
          <dd className="text-lg font-semibold text-ink-strong tnum">{payload.index.waiting}</dd>
        </div>
        <div>
          <dt className="text-muted">Index failures</dt>
          <dd className="text-lg font-semibold text-ink-strong tnum">{payload.index.failed}</dd>
        </div>
        <div>
          <dt className="text-muted">Tickets analysed</dt>
          <dd className="text-lg font-semibold text-ink-strong tnum">{payload.quality.analyses}</dd>
        </div>
      </dl>
    </Surface>
  )
}

interface SettingsFormProps {
  payload: AiSettingsPayload
  /** Held by the page: a save re-keys (remounts) this form. */
  saved: boolean
  onSavedChange: (saved: boolean) => void
}

function SettingsForm({ payload, saved, onSavedChange: setSaved }: SettingsFormProps) {
  const save = useSaveAiSettings()
  const [values, setValues] = useState<AiSettingsValues>(payload.settings)

  const textModels = payload.models.filter(
    (model) => model.modality !== 'embedding' && model.is_active,
  )
  const embeddingModels = payload.models.filter(
    (model) => model.modality === 'embedding' && model.is_active,
  )

  function set<K extends keyof AiSettingsValues>(key: K, value: AiSettingsValues[K]) {
    setSaved(false)
    setValues((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    save.mutate(values, { onSuccess: () => setSaved(true) })
  }

  const thresholdInvalid =
    Number.isNaN(values.confidence_threshold) ||
    values.confidence_threshold < 0 ||
    values.confidence_threshold > 1

  return (
    <form
      className="flex flex-col gap-6 rounded-md border border-border bg-surface p-5"
      onSubmit={submit}
      noValidate
    >
      <div>
        <h2 className="text-base font-semibold text-ink-strong">Models and behaviour</h2>
        <p className="mt-1 text-sm text-muted">Changes apply to the next AI call.</p>
      </div>

      <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
        <Field
          label="Chat model"
          hint="Answers the assistant and pre-screens tickets. None turns AI off."
        >
          <Select
            value={values.active_model ?? ''}
            onChange={(event) => set('active_model', event.target.value || null)}
          >
            <option value="">None — AI off</option>
            {textModels.map((model) => (
              <option key={model.id} value={model.id}>
                {model.name} ({model.model_identifier})
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Embedding model" hint="Indexes knowledge articles for the assistant.">
          <Select
            value={values.embedding_model ?? ''}
            onChange={(event) => set('embedding_model', event.target.value || null)}
          >
            <option value="">None</option>
            {embeddingModels.map((model) => (
              <option key={model.id} value={model.id}>
                {model.name} ({model.model_identifier})
              </option>
            ))}
          </Select>
        </Field>

        <Field
          label="Confidence threshold"
          hint="0 to 1. Below it, AI answers are marked low-confidence and never act on their own."
          error={thresholdInvalid ? 'Enter a number from 0 to 1.' : undefined}
        >
          <Input
            type="number"
            inputMode="decimal"
            min={0}
            max={1}
            step={0.05}
            value={Number.isNaN(values.confidence_threshold) ? '' : values.confidence_threshold}
            onChange={(event) => set('confidence_threshold', event.target.valueAsNumber)}
          />
        </Field>
      </div>

      <fieldset className="flex flex-col gap-3">
        <legend className="mb-2 text-sm font-semibold text-ink">Switches</legend>
        <Checkbox
          label="AI assistant available to all users"
          checked={values.assistant_enabled}
          onChange={(event) => set('assistant_enabled', event.target.checked)}
        />
        <Checkbox
          // A reporter's own choice may be raised (SDD DD-75); only a priority
          // IT staff set is final.
          label="AI may raise ticket priority (never lowers it, never overrides a choice made by IT staff)"
          checked={values.priority_auto_raise}
          onChange={(event) => set('priority_auto_raise', event.target.checked)}
        />
        <p className="-mt-1 pl-9 text-sm text-muted">
          Only when its confidence meets the threshold above. Recommendations are recorded on each
          ticket either way, so you can review them before switching this on.
        </p>
        <Checkbox
          label="AI interpretations of reports (on request, by administrators)"
          checked={values.report_narratives_enabled}
          onChange={(event) => set('report_narratives_enabled', event.target.checked)}
        />
        <p className="-mt-1 pl-9 text-sm text-muted">
          Sends aggregated report figures with equipment and room codes — no names, ticket text,
          notes or costs. Every statement is checked against the figures before it is shown.
        </p>
        <Checkbox
          label="Predictive maintenance findings"
          checked={values.enable_predictions}
          onChange={(event) => set('enable_predictions', event.target.checked)}
        />
        <Checkbox
          label="Learn from completed maintenance"
          checked={values.enable_learning}
          onChange={(event) => set('enable_learning', event.target.checked)}
        />
        <Checkbox
          label="Draft knowledge articles automatically"
          checked={values.auto_generate_articles}
          onChange={(event) => set('auto_generate_articles', event.target.checked)}
        />
      </fieldset>

      <div aria-live="polite">
        {save.isError && <Alert tone="error">{getErrorMessage(save.error)}</Alert>}
        {saved && !save.isPending && <Alert tone="success">AI settings saved.</Alert>}
      </div>

      <div>
        <Button type="submit" loading={save.isPending} disabled={thresholdInvalid}>
          Save settings
        </Button>
      </div>
    </form>
  )
}

function DataDisclosure({ payload }: { payload: AiSettingsPayload }) {
  return (
    <Surface className="grid grid-cols-1 gap-6 p-5 md:grid-cols-2">
      <div>
        <h2 className="text-base font-semibold text-ink-strong">Sent to the AI provider</h2>
        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-ink">
          {payload.data_sent_to_provider.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      </div>
      <div>
        <h2 className="text-base font-semibold text-ink-strong">Never sent</h2>
        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-ink">
          {payload.data_never_sent.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      </div>
    </Surface>
  )
}
