import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft } from 'lucide-react'
import {
  type SubmitEvent,
  type ReactNode,
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
} from 'react'
import { Controller, type Path, useController, useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { Alert, Button, Field, Input, Select, Textarea } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { applyMappedServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { usePreparedPhotos } from '@/hooks/usePreparedPhotos'
import type { TicketPayload } from '../api/ticketsApi'
import { CategoryOptions } from '../components/CategoryOptions'
import { DuplicateSuggestions } from '../components/DuplicateSuggestions'
import { PcAlreadyReported } from '../components/PcAlreadyReported'
import { PriorityChoice } from '../components/PriorityChoice'
import { REPORT_PHOTO_LIMIT, ReportPhotos } from '../components/ReportPhotos'
import { type WhereValue, WhereIsItPicker } from '../components/WhereIsItPicker'
import { type ReportProgress, useReportTicket } from '../hooks/mutations'
import { useTicketOptions } from '../hooks/queries'
import { type ReportDraftValues, useReportDraft } from '../hooks/useReportDraft'
import type { ReportOutcomeState } from '../reportOutcome'
import { CATEGORY_DETAIL_MAX, type ReportForm, reportSchema } from '../schemas'

const EMPTY: ReportForm = {
  title: '',
  description: '',
  category: '',
  category_detail: '',
  priority: '',
  building: '',
  room: '',
  item: '',
}

/**
 * Where each server error key belongs on this form. `pc_unit` and `asset` are
 * one answer here ("Which item has the problem?"), so both land on `item`;
 * anything not listed — or listed but not on screen — goes to the form alert.
 */
const SERVER_FIELDS: Partial<Record<string, Path<ReportForm>>> = {
  title: 'title',
  description: 'description',
  category: 'category',
  category_detail: 'category_detail',
  priority: 'priority',
  room: 'room',
  pc_unit: 'item',
  asset: 'item',
}

type Phase = { phase: 'preparing' } | ReportProgress

function statusText(phase: Phase | null): string {
  if (!phase) return ''
  switch (phase.phase) {
    case 'preparing':
      return 'Preparing photos…'
    case 'creating':
      return 'Sending your report…'
    case 'uploading':
      return `Attaching photo ${phase.current} of ${phase.total}…`
  }
}

function toPayload(values: ReportForm, detailSlugs: ReadonlySet<string>): TicketPayload {
  const separator = values.item.indexOf(':')
  const kind = separator > 0 ? values.item.slice(0, separator) : ''
  const id = separator > 0 ? values.item.slice(separator + 1) : ''

  return {
    title: values.title,
    description: values.description,
    category: values.category,
    ...(detailSlugs.has(values.category) ? { category_detail: values.category_detail.trim() } : {}),
    ...(values.priority ? { priority: values.priority } : {}),
    room: values.room || null,
    pc_unit: kind === 'pc' ? id : null,
    asset: kind === 'asset' ? id : null,
  }
}

function toDraft(values: Partial<Record<keyof ReportForm, string | undefined>>): ReportDraftValues {
  return {
    title: values.title ?? '',
    description: values.description ?? '',
    category: values.category ?? '',
    category_detail: values.category_detail ?? '',
    priority: values.priority ?? '',
    building: values.building ?? '',
    room: values.room ?? '',
    item: values.item ?? '',
  }
}

/**
 * Reporting a problem (SRS FR-TKT-001/003/008, UCS-02; SDD DD-75..DD-80).
 *
 * This is the one screen in the product a teacher must be able to use while
 * frustrated, standing in a lab with a phone in hand and a class waiting. It is
 * therefore one column of large plain fields in three sections — what's wrong,
 * photos, where it is — with no wizard, no rich text and no drag-and-drop, and
 * everything after the first section optional.
 *
 * Decisions worth naming:
 *
 *  - **Similar reports appear as you type the title**, inside the first section
 *    where a phone screen still shows them — not after submitting, and not
 *    below the button (FR-TKT-011).
 *  - **The reporter may choose a priority** (owner decision 2026-10-16,
 *    DD-75). "Not sure — let the IT team decide" is preselected and keeps the
 *    category's default; a choice can still be raised by the room's minimum or
 *    the AI, and only IT staff's choice is final. Staff filing on someone's
 *    behalf see the same control in their own words.
 *  - **"Other" categories ask what kind of problem it is** (DD-77). Which
 *    categories do is data from the options endpoint, not a list kept here.
 *  - **Building → room → item** (DD-78/79): one item answer, a PC or another
 *    asset, so the two can never both be sent.
 *  - **Photos are taken here, uploaded after the report is created** (DD-80):
 *    a photo that fails never costs the report. The ticket page then says
 *    which photos arrived, once.
 *  - **Submitting cannot happen twice.** Creating and then uploading several
 *    photos takes long enough on school Wi-Fi for Enter to be pressed again; a
 *    re-entry guard and a locked form mean exactly one report is filed. The
 *    status line says what is happening while the button cannot be pressed.
 *  - **What was typed survives the camera.** Android may discard the tab while
 *    the camera app is open; the text and choices are kept in this tab's
 *    session storage and offered back (`useReportDraft`).
 */
export default function CreateTicketPage() {
  useDocumentMeta({ title: 'Report a problem' })
  const navigate = useNavigate()
  const { user, hasRole } = useAuth()
  const isStaff = hasRole('administrator') || hasRole('technician')

  const options = useTicketOptions()
  const report = useReportTicket()
  const {
    restored,
    noteVisible,
    dismissNote,
    save: saveDraft,
    clear: clearDraft,
  } = useReportDraft(user?.id ?? null)

  const pickerId = useId()
  const submitId = useId()
  const photos = usePreparedPhotos({
    max: REPORT_PHOTO_LIMIT,
    maxKb: options.data?.uploads?.max_kb,
    fallbackFocus: () => document.getElementById(pickerId),
  })

  const categories = options.data?.categories
  const priorities = useMemo(() => options.data?.priorities ?? [], [options.data?.priorities])
  const detailSlugs = useMemo(
    () =>
      new Set<string>(
        (categories ?? [])
          .filter((category) => category.requires_detail)
          .map((category) => category.value),
      ),
    [categories],
  )
  // React Hook Form reads its options on every render, so the resolver follows
  // the flagged categories once the options arrive.
  const resolver = useMemo(() => zodResolver(reportSchema(detailSlugs)), [detailSlugs])

  const {
    register,
    control,
    handleSubmit,
    watch,
    getValues,
    setValue,
    setError,
    clearErrors,
    setFocus,
    formState: { errors },
  } = useForm<ReportForm>({ resolver, defaultValues: { ...EMPTY, ...restored } })

  // The room and item answers are radio lists whose checked row moves. A
  // `register()` ref keeps every radio it was ever given and focuses the
  // first ("Not sure…"), so these take a controller's ref instead: it holds
  // whichever element was handed it last, and the picker hands it only the
  // checked row — so a refused room or item puts focus on the refused row.
  const { field: roomField } = useController({ control, name: 'room' })
  const { field: itemField } = useController({ control, name: 'item' })

  const title = watch('title')
  const category = watch('category')
  const building = watch('building')
  const room = watch('room')
  const item = watch('item')
  const flagged = detailSlugs.has(category)

  // Arrowing through the item list must not query the feed and the duplicate
  // check for every PC passed on the way.
  const pcUnit = item.startsWith('pc:') ? item.slice(3) : ''
  const settledPc = useDebouncedValue(pcUnit, 300)
  // Nor typing the title search for similar reports on every keystroke: the
  // suggestions sit between the description and the category, and a block
  // that reloads per letter shifts everything below it.
  const settledTitle = useDebouncedValue(title ?? '', 400)

  useEffect(() => {
    const subscription = watch((values) => saveDraft(toDraft(values)))
    return () => subscription.unsubscribe()
  }, [watch, saveDraft])

  // Once the options arrive, a restored draft is checked against them — the
  // same rule the picker applies to a restored room or item:
  //  - A category still offered is set again: it was set before its option
  //    existed, and a native select cannot show a value it has no option for
  //    (it would read "Choose one…" while the form still held the category).
  //  - A category or a priority no longer offered (deactivated since the
  //    draft was saved) is let go rather than sent and refused — an unmatched
  //    category would show "Choose one…" yet be sent, and an unmatched
  //    priority would leave no radio checked, not even "Not sure".
  useEffect(() => {
    if (!categories) return

    const current = getValues('category')
    if (current && !categories.some((option) => option.value === current)) {
      setValue('category', '')
      setValue('category_detail', '')
    } else if (current) {
      setValue('category', current)
    }

    const priority = getValues('priority')
    if (priority && !priorities.some((option) => option.value === priority)) {
      setValue('priority', '')
    }
  }, [categories, priorities, getValues, setValue])

  const onWhereChange = useCallback(
    (next: WhereValue) => {
      for (const field of ['building', 'room', 'item'] as const) {
        if (getValues(field) !== next[field]) {
          setValue(field, next[field], { shouldDirty: true })
          clearErrors(field)
        }
      }
    },
    [getValues, setValue, clearErrors],
  )

  /* ------------------------------------------------------------- submitting */

  const [phase, setPhase] = useState<Phase | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const submitting = useRef(false)
  const mounted = useRef(true)
  const pendingFocus = useRef<Path<ReportForm> | 'submit' | null>(null)
  const [focusRequest, setFocusRequest] = useState(0)
  const locked = phase !== null

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  // Focus moves only once the form is unlocked again: a disabled control
  // cannot take it.
  useEffect(() => {
    if (phase !== null || pendingFocus.current === null) return
    const target = pendingFocus.current
    pendingFocus.current = null
    if (target === 'submit') document.getElementById(submitId)?.focus()
    else setFocus(target)
  }, [phase, focusRequest, setFocus, submitId])

  const isRendered = (field: Path<ReportForm>): boolean => {
    switch (field) {
      case 'category_detail':
        return detailSlugs.has(getValues('category'))
      case 'priority':
        return priorities.length > 0
      case 'room':
        return getValues('building') !== ''
      case 'item':
        return getValues('room') !== ''
      default:
        return true
    }
  }

  const send = async (values: ReportForm) => {
    // The re-entry guard: a second submit (Enter in a field, a double tap)
    // while the first is still creating or uploading does nothing at all.
    if (submitting.current) return
    submitting.current = true
    setFormError(null)

    try {
      if (photos.preparing) setPhase({ phase: 'preparing' })
      const { ready, refusedWhileWaiting } = await photos.whenReady()
      if (!mounted.current) return

      setPhase({ phase: 'creating' })
      const outcome = await report.mutateAsync({
        payload: toPayload(values, detailSlugs),
        photos: ready,
        // Refused after "send" was pressed, so the reporter has not seen why:
        // the report still goes, and the ticket page names these with any
        // failed uploads.
        refused: refusedWhileWaiting.map(({ index, name, reason }) => ({
          index,
          name,
          message: reason,
        })),
        onProgress: (progress) => {
          if (mounted.current) setPhase(progress)
        },
      })

      // Filed: the draft has done its job, wherever the reporter is now.
      clearDraft()
      if (!mounted.current) return

      const state: ReportOutcomeState = { reportOutcome: outcome }
      navigate(`/app/tickets/${outcome.ticketId}`, { replace: true, state })
    } catch (caught) {
      // Only creating can fail — nothing was filed, so everything stays.
      submitting.current = false
      if (!mounted.current) return
      setPhase(null)

      const mapped = applyMappedServerErrors(caught, setError, SERVER_FIELDS, isRendered)
      if (mapped === null) {
        setFormError(getErrorMessage(caught))
        pendingFocus.current = 'submit'
      } else {
        if (mapped.unplaced.length > 0) setFormError(mapped.unplaced.join(' '))
        pendingFocus.current = mapped.placed[0] ?? 'submit'
      }
      setFocusRequest((count) => count + 1)
    }
  }

  const onSubmit = (event: SubmitEvent<HTMLFormElement>) => {
    if (submitting.current) {
      event.preventDefault()
      return
    }
    void handleSubmit(send)(event)
  }

  // Cancel means "I'm not reporting this": the draft goes. Any other way out —
  // the back link, the navigation — keeps it, so a reporter who goes to check
  // whether the fault is already listed finds their words waiting on return.
  const cancel = () => {
    clearDraft()
    navigate('/app/tickets')
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate('/app/tickets')}
          disabled={locked}
        >
          <ArrowLeft size={26} aria-hidden="true" />
          All tickets
        </Button>
      </div>

      <header className="measure">
        <h1 className="text-2xl font-bold text-ink-strong">Report a problem</h1>
        <p className="mt-2 text-base text-muted">
          Describe what is not working. You do not need to know what is wrong with it — say what you
          were doing and what happened, and the IT team will take it from there.
        </p>
      </header>

      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-8">
        {noteVisible && (
          <Alert tone="info" title="Restored your unsent report" className="max-w-3xl">
            <p>Photos need adding again — they are not kept while you are away from the page.</p>
            {/* The note, and the button with it, goes; focus goes to where the
                report starts rather than falling to the page body. */}
            <Button
              variant="secondary"
              size="sm"
              className="mt-3"
              onClick={() => {
                dismissNote()
                setFocus('title')
              }}
            >
              Dismiss
            </Button>
          </Alert>
        )}

        {options.isError && (
          <Alert tone="error" title="The form’s choices could not be loaded" className="max-w-3xl">
            <p>The kinds of problem did not arrive. Check the connection, then try again.</p>
            <Button
              variant="secondary"
              size="sm"
              className="mt-3"
              onClick={() => void options.refetch()}
            >
              Try again
            </Button>
          </Alert>
        )}

        {/* Everything a reporter can change is locked while the report is sent. */}
        <fieldset disabled={locked} className="m-0 flex min-w-0 flex-col gap-8 border-0 p-0">
          <Section title="What’s wrong">
            <Field
              label="What is the problem?"
              required
              error={errors.title?.message}
              hint="A short line someone else would recognise — “Projector in Room 204 will not turn on”."
            >
              <Input {...register('title')} maxLength={255} autoFocus />
            </Field>

            <Field
              label="What happened?"
              required
              error={errors.description?.message}
              hint="What you were doing, what you expected, and what happened instead. Any error message helps."
            >
              <Textarea {...register('description')} rows={7} maxLength={10000} />
            </Field>

            <DuplicateSuggestions search={settledTitle} pcUnit={settledPc || undefined} />

            <Field
              label="What kind of problem is it?"
              required
              error={errors.category?.message}
              hint="A rough guess is fine. If none fit, choose an Other option and describe it."
            >
              <Select {...register('category', { onChange: () => clearErrors('category_detail') })}>
                <option value="">Choose one…</option>
                <CategoryOptions categories={categories ?? []} />
              </Select>
            </Field>

            {flagged && (
              <Field
                label="Describe the kind of problem"
                required
                error={errors.category_detail?.message}
                hint="A few words, such as “Interactive whiteboard pen not working”."
              >
                <Input {...register('category_detail')} maxLength={CATEGORY_DETAIL_MAX} />
              </Field>
            )}

            {priorities.length > 0 && (
              <Controller
                control={control}
                name="priority"
                render={({ field }) => (
                  <PriorityChoice
                    priorities={priorities}
                    value={field.value}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                    name={field.name}
                    inputRef={field.ref}
                    error={errors.priority?.message}
                    staff={isStaff}
                  />
                )}
              />
            )}
          </Section>

          <Section title="Photos">
            <ReportPhotos photos={photos} pickerId={pickerId} disabled={locked} />
          </Section>

          <Section
            title="Where is it?"
            intro="Naming the machine is the most useful thing you can do — it links your report to that machine’s history, so whoever picks it up already knows what has been done to it before. Every step is optional."
          >
            <WhereIsItPicker
              value={{ building, room, item }}
              onChange={onWhereChange}
              errors={{ room: errors.room?.message, item: errors.item?.message }}
              fieldRefs={{
                building: register('building').ref,
                room: roomField.ref,
                item: itemField.ref,
              }}
            />

            {/* Phase 2.9 — the feed refuses technicians (DD-40), so they are not asked. */}
            {settledPc && settledPc === pcUnit && !hasRole('technician') && (
              <PcAlreadyReported pcUnit={settledPc} />
            )}
          </Section>
        </fieldset>

        {/* Next to the button, where the eye already is when a report fails. */}
        <div className="flex max-w-3xl flex-col gap-4">
          {formError && (
            <Alert tone="error" title="Your report was not sent">
              {formError}
            </Alert>
          )}

          <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <Button
              id={submitId}
              type="submit"
              size="lg"
              className="w-full sm:w-auto"
              loading={locked}
              disabled={locked}
            >
              Report this problem
            </Button>
            <Button
              type="button"
              variant="secondary"
              size="lg"
              className="w-full sm:w-auto"
              onClick={cancel}
              disabled={locked}
            >
              Cancel
            </Button>
          </div>

          <p role="status" className="text-sm font-medium text-ink">
            {statusText(phase)}
          </p>
        </div>
      </form>
    </div>
  )
}

function Section({
  title,
  intro,
  children,
}: {
  title: string
  intro?: string
  children: ReactNode
}) {
  const headingId = useId()

  return (
    <section
      aria-labelledby={headingId}
      className="flex max-w-3xl flex-col gap-6 rounded-lg border-2 border-border bg-surface p-5 sm:p-8"
    >
      <div className="measure">
        <h2 id={headingId} className="text-base font-bold text-ink-strong">
          {title}
        </h2>
        {intro && <p className="mt-2 text-sm text-muted">{intro}</p>}
      </div>
      {children}
    </section>
  )
}
