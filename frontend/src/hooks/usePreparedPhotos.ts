import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { photoKind, preparePhoto, UnsupportedPhotoError } from '@/lib/images'

export type PhotoDraftStatus = 'preparing' | 'ready' | 'rejected'

export interface PhotoDraft {
  /** A counter, not `crypto.randomUUID()` — that is missing on a plain-HTTP LAN origin. */
  id: number
  /** The name the person picked, for display; the upload may carry a corrected one. */
  name: string
  status: PhotoDraftStatus
  /** The prepared file, once ready. */
  file: File | null
  /** An object URL of the prepared file, for the thumbnail. */
  previewUrl: string | null
  error: string | null
}

/** A ready photo as the list showed it: the file to send, and the number and name the person saw. */
export interface ReadyPhoto {
  file: File
  /** Its position in the list, from 1. Refused photos stay listed, so they are counted too. */
  index: number
  /** The name as picked — not the prepared file's, which a redraw renames to `.jpg`. */
  name: string
}

/** A photo that was still being prepared when the caller started waiting, and was then refused. */
export interface RefusedPhoto {
  index: number
  name: string
  reason: string
}

export interface ReadyPhotos {
  ready: ReadyPhoto[]
  /**
   * Refused while the caller waited. The person pressed "send" before this
   * refusal appeared on screen, so the caller has to tell them some other way.
   */
  refusedWhileWaiting: RefusedPhoto[]
}

/** The latest thing a screen reader should hear about the list; `id` changes even when the text does not. */
export interface PhotoAnnouncement {
  id: number
  text: string
}

interface Options {
  /** How many photos this surface takes; refused ones do not count. */
  max: number
  /** The server's per-file ceiling in KB (`uploads.max_kb`); unchecked until known. */
  maxKb?: number
  /** Where focus goes when the last remaining remove button disappears. */
  fallbackFocus?: () => HTMLElement | null
}

function megabytes(kilobytes: number): string {
  const mb = kilobytes / 1024
  return Number.isInteger(mb) ? `${mb} MB` : `${mb.toFixed(1)} MB`
}

function reasonFor(caught: unknown): string {
  if (caught instanceof UnsupportedPhotoError) return caught.message
  return 'This photo could not be read. Take it again, or choose another.'
}

/** "Photo 2 (image.jpg)" — how the list names a draft right now; `null` once it is gone. */
function labelIn(list: PhotoDraft[], id: number): string | null {
  const index = list.findIndex((draft) => draft.id === id)
  return index === -1 ? null : `Photo ${index + 1} (${list[index].name})`
}

/**
 * Photos waiting to be uploaded — picked, prepared one at a time, previewed,
 * removable (SDD DD-80). Shared by the report form and the ticket's "Photos &
 * files" tab so both refuse and shrink a photo the same way.
 *
 * ── One at a time, on purpose ──────────────────────────────────────────────
 *
 * Decoding a 12-megapixel photo takes ~50 MB of memory. Preparing five at once
 * on an older phone is how a tab gets killed mid-report, so the queue is a
 * single promise chain: the next photo starts when the previous has finished.
 * {@link whenReady} resolves when the chain drains — the form's submit waits on
 * it rather than refusing to submit while photos are still being prepared.
 *
 * ── Object URLs are tracked, not trusted to React ──────────────────────────
 *
 * Every preview URL lives in a ref as well as in state, and is revoked on
 * remove, on {@link clear} and on unmount. A photo whose preparation finishes
 * after it was removed, or after the surface was left, never gets one. A
 * `[]`-effect cleanup that read state would only ever see the first render's
 * empty list.
 *
 * ── Leaving does not drop a photo ──────────────────────────────────────────
 *
 * The ticket's "Photos & files" panel is unmounted on every tab switch, while
 * its upload chain is still waiting on the photos it was given. So leaving the
 * surface does not stop the queue: a photo still finishes preparing and is
 * marked ready (without a preview — nothing would show it), and the chain
 * sends it. Stopping instead would drop exactly the photos still being
 * prepared, without a word, while the ones already prepared went up. On the
 * report form nobody sends what is finished after the page is left; the cost
 * there is a few bounded decodes, one at a time.
 *
 * ── Said, not only shown ───────────────────────────────────────────────────
 *
 * A refusal appears as red text beside the photo, which a screen-reader user
 * would never come across — the count they hear simply drops by one. So every
 * refusal ("Photo 2 (image.jpg) was not added: …") and every upload a caller
 * reports through {@link sent} is also put in {@link announcement}, which the
 * list renders into an always-present polite live region.
 *
 * ── Focus after a remove ───────────────────────────────────────────────────
 *
 * The remove button that was pressed disappears with its photo; without help,
 * focus would fall to the page body. It moves to the next photo's remove
 * button, else the previous one's, else `fallbackFocus` (the picker).
 */
export function usePreparedPhotos({ max, maxKb, fallbackFocus }: Options) {
  const [drafts, setDrafts] = useState<PhotoDraft[]>([])
  const [notice, setNotice] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState<PhotoAnnouncement | null>(null)

  // `draftsRef` is the source of truth; state mirrors it for rendering. Every
  // write goes through `commit`, so code awaiting the queue reads the current
  // list rather than the one its closure captured.
  const draftsRef = useRef<PhotoDraft[]>([])
  const urls = useRef(new Map<number, string>())
  const removed = useRef(new Set<number>())
  const removeButtons = useRef(new Map<number, HTMLButtonElement>())
  const queue = useRef<Promise<void>>(Promise.resolve())
  const counter = useRef(0)
  const mounted = useRef(true)
  const pendingFocus = useRef<number | 'fallback' | null>(null)
  const maxKbRef = useRef(maxKb)
  const fallbackRef = useRef(fallbackFocus)

  useEffect(() => {
    maxKbRef.current = maxKb
    fallbackRef.current = fallbackFocus
  })

  const commit = useCallback((next: (current: PhotoDraft[]) => PhotoDraft[]) => {
    draftsRef.current = next(draftsRef.current)
    if (mounted.current) setDrafts(draftsRef.current)
  }, [])

  const patch = useCallback(
    (id: number, changes: Partial<PhotoDraft>) =>
      commit((current) =>
        current.map((draft) => (draft.id === id ? { ...draft, ...changes } : draft)),
      ),
    [commit],
  )

  useEffect(() => {
    mounted.current = true
    const tracked = urls.current
    return () => {
      mounted.current = false
      for (const url of tracked.values()) URL.revokeObjectURL(url)
      tracked.clear()
    }
  }, [])

  // A fresh `id` every time, so the same sentence twice ("Photo 1 (image.jpg)
  // uploaded." for each of two iPhone photos) is still a change to announce.
  const say = useCallback((text: string) => {
    if (mounted.current) setAnnouncement((previous) => ({ id: (previous?.id ?? 0) + 1, text }))
  }, [])

  const refuse = useCallback(
    (id: number, error: string) => {
      patch(id, { status: 'rejected', error })
      const label = labelIn(draftsRef.current, id)
      if (label) say(`${label} was not added: ${error}`)
    },
    [patch, say],
  )

  const prepareOne = useCallback(
    async (id: number, original: File) => {
      // Removed while waiting its turn. Having left the surface is *not* a
      // reason to stop — see "Leaving does not drop a photo" above.
      if (removed.current.has(id)) return

      try {
        const { file } = await preparePhoto(original)
        const limit = maxKbRef.current

        if (limit !== undefined && file.size > limit * 1024) {
          refuse(
            id,
            `This photo is larger than ${megabytes(limit)}, even after shrinking it. Choose a smaller one.`,
          )
          return
        }

        // Removed while it was being prepared: nothing to show or send.
        if (removed.current.has(id)) return

        // Left while it was being prepared: still ready, so a waiting upload
        // chain sends it, but with no preview — nothing would show or revoke it.
        if (!mounted.current) {
          patch(id, { status: 'ready', file, previewUrl: null })
          return
        }

        const previewUrl = URL.createObjectURL(file)
        urls.current.set(id, previewUrl)
        patch(id, { status: 'ready', file, previewUrl })
      } catch (caught) {
        refuse(id, reasonFor(caught))
      }
    },
    [patch, refuse],
  )

  /**
   * Queue picked files. Over the cap, the extras are not added and a notice
   * says how many were left out. Resolves with this batch's drafts once each
   * has been prepared or refused.
   */
  const add = useCallback(
    (files: File[]): Promise<PhotoDraft[]> => {
      setNotice(null)

      const counted = draftsRef.current.filter((draft) => draft.status !== 'rejected').length
      const room = Math.max(0, max - counted)
      const accepted = files.slice(0, room)
      const leftOut = files.length - accepted.length

      if (leftOut > 0) {
        setNotice(
          room === 0
            ? `You can add up to ${max} photos. Remove one to add another.`
            : `You can add up to ${max} photos, so ${leftOut} ${leftOut === 1 ? 'was' : 'were'} not added.`,
        )
      }

      const batch: PhotoDraft[] = accepted.map((file) => {
        counter.current += 1
        const kind = photoKind(file)
        return {
          id: counter.current,
          name: file.name || `Photo ${counter.current}`,
          status: kind === 'not-image' ? 'rejected' : 'preparing',
          file: null,
          previewUrl: null,
          error:
            kind === 'not-image'
              ? 'That file is not a photo. Choose a JPEG, PNG, WebP or GIF image.'
              : null,
        }
      })

      commit((current) => [...current, ...batch])

      // Refused on sight (not a photo at all): one announcement for the pick,
      // since several in a row would each replace the last before being heard.
      const refusedNow = batch
        .filter((draft) => draft.status === 'rejected')
        .map((draft) => `${labelIn(draftsRef.current, draft.id)} was not added: ${draft.error}`)
      if (refusedNow.length > 0) say(refusedNow.join(' '))

      accepted.forEach((file, index) => {
        const draft = batch[index]
        if (draft.status !== 'preparing') return
        queue.current = queue.current.then(() => prepareOne(draft.id, file))
      })

      const ids = new Set(batch.map((draft) => draft.id))
      return queue.current.then(() => draftsRef.current.filter((draft) => ids.has(draft.id)))
    },
    [commit, max, prepareOne, say],
  )

  /** Forget one photo and revoke its preview. `focus: false` when nothing was pressed. */
  const remove = useCallback(
    (id: number, { focus = true }: { focus?: boolean } = {}) => {
      const current = draftsRef.current
      const index = current.findIndex((draft) => draft.id === id)
      if (index === -1) return

      if (focus) {
        const next = current[index + 1] ?? current[index - 1]
        pendingFocus.current = next ? next.id : 'fallback'
      }

      removed.current.add(id)
      const url = urls.current.get(id)
      if (url) {
        URL.revokeObjectURL(url)
        urls.current.delete(id)
      }
      setNotice(null)
      commit((list) => list.filter((draft) => draft.id !== id))
    },
    [commit],
  )

  /** Mark a ready photo as refused after the fact — an upload the server turned down. */
  const reject = refuse

  /** A photo the caller has uploaded: it leaves the list, and a screen reader hears that it arrived. */
  const sent = useCallback(
    (id: number) => {
      const label = labelIn(draftsRef.current, id)
      remove(id, { focus: false })
      if (label) say(`${label} uploaded.`)
    },
    [remove, say],
  )

  /** Drop everything (and every preview URL). */
  const clear = useCallback(() => {
    for (const draft of draftsRef.current) removed.current.add(draft.id)
    for (const url of urls.current.values()) URL.revokeObjectURL(url)
    urls.current.clear()
    setNotice(null)
    commit(() => [])
  }, [commit])

  /**
   * The ready photos, in the order picked, once nothing is still being
   * prepared — each with the number and name the list showed, so whatever is
   * said about it later matches what the person saw. Photos that were still
   * being prepared when this was called, and were refused while it waited,
   * come back separately.
   */
  const whenReady = useCallback((): Promise<ReadyPhotos> => {
    const waitingOn = new Set(
      draftsRef.current.filter((draft) => draft.status === 'preparing').map((draft) => draft.id),
    )

    return queue.current.then(() => {
      const ready: ReadyPhoto[] = []
      const refusedWhileWaiting: RefusedPhoto[] = []

      draftsRef.current.forEach((draft, position) => {
        const index = position + 1
        if (draft.status === 'ready' && draft.file) {
          ready.push({ file: draft.file, index, name: draft.name })
        } else if (draft.status === 'rejected' && waitingOn.has(draft.id)) {
          refusedWhileWaiting.push({
            index,
            name: draft.name,
            reason: draft.error ?? 'This photo could not be used.',
          })
        }
      })

      return { ready, refusedWhileWaiting }
    })
  }, [])

  /** Whether a draft is still listed — false once it has been removed. */
  const has = useCallback((id: number) => draftsRef.current.some((draft) => draft.id === id), [])

  /** Ref callback for a draft's remove button, so focus can be moved to it. */
  const bindRemoveButton = useCallback(
    (id: number) => (element: HTMLButtonElement | null) => {
      if (element) removeButtons.current.set(id, element)
      else removeButtons.current.delete(id)
    },
    [],
  )

  useEffect(() => {
    const target = pendingFocus.current
    if (target === null) return
    pendingFocus.current = null

    if (target === 'fallback') fallbackRef.current?.()?.focus()
    else removeButtons.current.get(target)?.focus()
  }, [drafts])

  const counted = useMemo(
    () => drafts.filter((draft) => draft.status !== 'rejected').length,
    [drafts],
  )

  return {
    drafts,
    notice,
    /** What to say in the list's polite live region; see "Said, not only shown". */
    announcement,
    /** Photos that count against the cap: preparing or ready. */
    count: counted,
    atLimit: counted >= max,
    preparing: drafts.some((draft) => draft.status === 'preparing'),
    add,
    remove,
    reject,
    sent,
    clear,
    whenReady,
    has,
    bindRemoveButton,
  }
}

export type PreparedPhotos = ReturnType<typeof usePreparedPhotos>
