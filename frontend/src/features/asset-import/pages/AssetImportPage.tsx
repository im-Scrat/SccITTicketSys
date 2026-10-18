import { useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import {
  Alert,
  Button,
  ButtonLink,
  ConfirmDialog,
  ErrorState,
  Skeleton,
  Spinner,
  Stepper,
} from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ConfirmStep } from '../components/ConfirmStep'
import { ImportOutcomeSummary } from '../components/ImportOutcomeSummary'
import { ImportPageHeader } from '../components/ImportPageHeader'
import { ImportPanel } from '../components/ImportPanel'
import { ImportProgressPanel } from '../components/ImportProgressPanel'
import { ImportStatusBadge } from '../components/ImportStatusBadge'
import { MappingStep } from '../components/MappingStep'
import { ImportReviewSlideshow } from '../components/review/ImportReviewSlideshow'
import { ReviewStep } from '../components/ReviewStep'
import { useCancelImport } from '../hooks/mutations'
import { useAssetImport } from '../hooks/queries'
import {
  blockingCount,
  formatBytes,
  reachableSteps,
  stepFor,
  WIZARD_STEPS,
  type WizardStep,
} from '../lib/steps'

/** Every surface an import changes, refreshed once it finishes. */
const CHANGED_BY_IMPORT = [
  ['assets'],
  ['locations'],
  ['floor-plan'],
  ['dashboard'],
  ['lookups'],
  ['asset-imports'],
]

/**
 * One import, from its file to its outcome (Phase 2.10 R6, SRS FR-AST-016,
 * plan §3.4) — a persistent page: the URL is the import, so leaving and coming
 * back, or another administrator opening it, lands on the same step.
 *
 * The server's status decides which steps are possible; `?step=` chooses
 * between them where there is a choice (back to the mapping, fixing rows, or
 * confirming).
 */
export default function AssetImportPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const imported = useAssetImport(id)
  const cancel = useCancelImport(id)
  const [cancelling, setCancelling] = useState(false)
  const item = imported.data
  useDocumentMeta({ title: item ? `Import · ${item.file.name}` : 'Import' })

  // A template file with nothing to fix lands on Confirm on first arrival.
  const fastPathChecked = useRef(false)
  useEffect(() => {
    if (!item || fastPathChecked.current || item.status !== 'review') return
    fastPathChecked.current = true
    if (item.source.template_match && blockingCount(item) === 0 && !searchParams.get('step')) {
      const params = new URLSearchParams(searchParams)
      params.set('step', 'confirm')
      setSearchParams(params, { replace: true })
    }
  }, [item, searchParams, setSearchParams])

  // Once the save finishes, every page that shows assets is out of date.
  const previous = useRef(item?.status)
  useEffect(() => {
    if (previous.current && previous.current !== 'finished' && item?.status === 'finished') {
      for (const key of CHANGED_BY_IMPORT) void client.invalidateQueries({ queryKey: key })
      // The review opens by itself when this save created records (plan §3.5).
      if ((item.result?.created ?? 0) > 0) {
        const params = new URLSearchParams(searchParams)
        params.set('review', '1')
        setSearchParams(params, { replace: true })
      }
    }
    previous.current = item?.status
    // Only the status transition matters here.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [item?.status, client])

  if (imported.isLoading) return <Skeleton className="h-96 w-full" />
  if (imported.isError || !item) {
    const notFound = isAxiosError(imported.error) && imported.error.response?.status === 404
    return notFound ? (
      <Alert tone="info" title="No such import">
        It may have been cancelled and removed.{' '}
        <ButtonLink to="/app/assets/imports" variant="ghost" size="sm">
          Import history
        </ButtonLink>
      </Alert>
    ) : (
      <ErrorState title="The import could not be loaded" onRetry={() => void imported.refetch()} />
    )
  }

  const step: WizardStep = stepFor(item.status, searchParams.get('step'))
  const go = (next: WizardStep) => {
    const params = new URLSearchParams(searchParams)
    params.set('step', next)
    if (next !== 'fix') params.delete('tab')
    setSearchParams(params)
    window.scrollTo({ top: 0 })
  }
  const reachable = reachableSteps(item.status)

  return (
    <div className="mx-auto flex w-full max-w-[96rem] flex-col gap-8">
      <ImportPageHeader
        title={item.file.name}
        description={`${item.entity_mode_label} · ${formatBytes(item.file.size_bytes)}${item.total_rows ? ` · ${item.total_rows.toLocaleString()} rows` : ''}`}
        meta={
          <>
            <ImportStatusBadge
              status={item.status}
              statusLabel={item.status_label}
              outcome={item.outcome}
              outcomeLabel={item.outcome_label}
            />
            {item.created_by && <span>Started by {item.created_by}</span>}
            {item.confirmed_by && <span>· confirmed by {item.confirmed_by}</span>}
          </>
        }
      >
        <ButtonLink to="/app/assets/imports" variant="secondary">
          Import history
        </ButtonLink>
        {item.can.cancel && (
          <Button variant="ghost" onClick={() => setCancelling(true)}>
            Cancel import
          </Button>
        )}
      </ImportPageHeader>

      <Stepper
        label="Import steps"
        steps={WIZARD_STEPS}
        current={step}
        onStepSelect={(next) => reachable.includes(next as WizardStep) && go(next as WizardStep)}
        lockedSteps={WIZARD_STEPS.map((s) => s.id).filter(
          (s) => !reachable.includes(s as WizardStep),
        )}
      />

      {item.duplicate_of && item.status !== 'finished' && (
        <Alert tone="info" title="This file was uploaded before">
          The same file was uploaded as “{item.duplicate_of.name}”. Rows already in the register are
          found and skipped either way.
        </Alert>
      )}

      {(item.status === 'reading' || item.status === 'validating') && (
        <ImportPanel
          id="import-working"
          title={item.status === 'reading' ? 'Reading the file' : 'Checking the rows'}
        >
          <p className="flex items-center gap-3 text-sm text-ink" role="status">
            <Spinner />
            {item.status === 'reading'
              ? 'Finding the header row and the columns…'
              : `Checking ${item.total_rows.toLocaleString()} rows against the register…`}
          </p>
        </ImportPanel>
      )}

      {item.status === 'unreadable' && (
        <Alert tone="warning" title="This file could not be read">
          <span className="flex flex-col items-start gap-3">
            {item.source.error}
            <ButtonLink to="/app/assets/import" variant="secondary" size="sm">
              Upload another file
            </ButtonLink>
          </span>
        </Alert>
      )}

      {step === 'mapping' && item.status !== 'validating' && (
        <MappingStep key={item.revision} item={item} />
      )}
      {step === 'fix' && <ReviewStep item={item} onContinue={() => go('confirm')} />}
      {step === 'confirm' && item.plan && <ConfirmStep item={item} onBack={() => go('fix')} />}
      {step === 'importing' && <ImportProgressPanel item={item} />}
      {step === 'summary' && <ImportOutcomeSummary item={item} />}
      {item.status === 'finished' && (item.result?.created ?? 0) > 0 && (
        <ImportReviewSlideshow item={item} />
      )}

      <ConfirmDialog
        open={cancelling}
        onClose={() => setCancelling(false)}
        onConfirm={() =>
          cancel.mutate(undefined, {
            onSuccess: (next) => {
              setCancelling(false)
              if (next.status === 'cancelled') void navigate('/app/assets/imports')
            },
          })
        }
        title="Cancel this import?"
        description={
          item.result
            ? 'The rows you reopened go back to how the last save left them. Records already created stay.'
            : 'Its rows, your decisions and the uploaded file are deleted. Nothing was added to the register.'
        }
        confirmLabel="Cancel import"
        cancelLabel="Keep it"
        loading={cancel.isPending}
      />
    </div>
  )
}
