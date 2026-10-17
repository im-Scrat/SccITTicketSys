import { keepPreviousData } from '@tanstack/react-query'
import { Monitor, Package } from 'lucide-react'
import {
  type KeyboardEvent,
  type ReactNode,
  type Ref,
  type RefObject,
  useEffect,
  useId,
  useRef,
  useState,
} from 'react'
import { Alert, Button, Field, Input, Select, Skeleton } from '@/components/ui'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useRoomEquipmentLookup } from '@/hooks/useEquipmentLookup'
import { useBuildingLookup, useRoomLookup } from '@/hooks/useLocationLookup'
import { cn } from '@/lib/cn'
import type { EquipmentLookupOption, RoomLookupOption } from '@/services/lookups'

export interface WhereValue {
  building: string
  room: string
  /** '' — no specific item; otherwise `pc:<uuid>` or `asset:<uuid>`. */
  item: string
}

interface WhereIsItPickerProps {
  value: WhereValue
  /** Receives the whole next value: the cascade's resets are already applied. */
  onChange: (next: WhereValue) => void
  errors?: Partial<Record<keyof WhereValue, string>>
  disabled?: boolean
  /**
   * react-hook-form's ref for each field. The building's goes on its select.
   * The room's and the item's go on **one radio only** — the checked row, or
   * the "Not sure" / "Not about" row when nothing listed is checked — so a
   * refused room or item puts focus on the refused row, not on the top of the
   * list. Pass a controller's ref for those two (`useController`): a
   * `register()` ref collects every radio it is ever given and focuses the
   * first.
   */
  fieldRefs?: Partial<Record<keyof WhereValue, (element: HTMLElement | null) => void>>
}

/** The room lookup's ceiling; a list this long may have been cut short. */
const ROOM_LIMIT = 200
/** Lists up to this long are read, not searched — a filter box would be clutter. */
const SEARCH_FROM = 8
const EQUIPMENT_DEBOUNCE_MS = 250

const LIST = 'overflow-hidden rounded-md border border-border sm:max-h-[26rem] sm:overflow-y-auto'

const ROW =
  'flex min-h-15 cursor-pointer items-center gap-3 border-2 border-transparent px-4 py-2.5 transition-colors duration-150 [transition-timing-function:var(--ease-standard)] hover:bg-surface-sunken has-[:focus-visible]:bg-surface-sunken has-[:checked]:border-primary has-[:disabled]:cursor-not-allowed has-[:disabled]:hover:bg-transparent'

function floorName(room: RoomLookupOption): string | null {
  if (!room.floor) return null
  return room.floor.name || `Floor ${room.floor.floor_number}`
}

function roomMatches(room: RoomLookupOption, term: string): boolean {
  const needle = term.toLowerCase()
  return [room.name, room.code, room.room_type_label, floorName(room)]
    .filter((part): part is string => Boolean(part))
    .some((part) => part.toLowerCase().includes(needle))
}

function itemMatches(item: EquipmentLookupOption, term: string): boolean {
  const needle = term.toLowerCase()
  return [item.label, item.identifier]
    .filter((part): part is string => Boolean(part))
    .some((part) => part.toLowerCase().includes(needle))
}

function plural(count: number, one: string, many: string): string {
  return `${count} ${count === 1 ? one : many}`
}

/**
 * Enter in a filter box must not send the report. The box sits inside the
 * report form, and Enter — or a phone keyboard's Search key — in any text
 * field submits its form: with the first section filled in, that would file
 * the report before the room or item was chosen, and a ticket's location
 * cannot be added afterwards. Filtering is already live, so Enter has nothing
 * to do here. An input method still composing text keeps its Enter.
 */
function holdEnter(event: KeyboardEvent<HTMLInputElement>) {
  if (event.key === 'Enter' && !event.nativeEvent.isComposing) event.preventDefault()
}

/**
 * Scroll `box`, and only `box`, so its checked row is in view. From `sm` up a
 * list is capped in height, and a room chosen far down — restored from a
 * draft, or refused by the server — would otherwise sit out of sight while the
 * visible rows show nothing checked. Not `scrollIntoView`: that also scrolls
 * the page, and a restored list loads after the page has settled on the title.
 * On a phone the list is not capped, so there is nothing to scroll.
 */
function revealChecked(box: HTMLElement | null) {
  const row = box?.querySelector('input:checked')?.closest('label')
  if (!box || !row) return

  const outer = box.getBoundingClientRect()
  const inner = row.getBoundingClientRect()
  if (inner.top < outer.top) box.scrollTop -= outer.top - inner.top
  else if (inner.bottom > outer.bottom) box.scrollTop += inner.bottom - outer.bottom
}

/**
 * {@link revealChecked} once there is a choice and a loaded list to show it
 * in, and again whenever an error is put on the list.
 */
function useRevealChecked(
  box: RefObject<HTMLElement | null>,
  ready: boolean,
  chosen: string,
  error?: string,
) {
  const revealed = useRef(false)

  useEffect(() => {
    if (!ready || !chosen) return
    if (revealed.current && !error) return
    revealed.current = true
    revealChecked(box.current)
  }, [box, ready, chosen, error])
}

/**
 * "Where is it?" — building, then room, then the item with the problem (SRS
 * FR-TKT-001, FR-LOC-011, FR-AST-013; SDD DD-78).
 *
 * **Every step is optional, and says what not choosing means.** A ticket
 * stores a room, never a building on its own, so "Not sure which room" asks
 * for the place in the description instead of pretending the building alone
 * was recorded. "Not about a specific item" is just as legitimate: the Wi-Fi is
 * not one machine.
 *
 * **The lists are the narrow lookups, and the server checks the same thing.**
 * Rooms come from `/lookups/rooms` (only rooms in use), the items from the
 * room-scoped `/lookups/equipment` (PCs that are not retired, other equipment
 * that is in service). Filing against anything else is refused with the same
 * rules, so this picker is a convenience, never the control.
 *
 * Decisions worth naming:
 *
 *  - **Radio lists, not dropdowns.** A teacher reads "Lab A · Laboratory ·
 *    Ground floor" in a row they can touch, instead of opening a menu of
 *    look-alike names. Native radios grouped by name, inside fieldsets exposed
 *    as radiogroups, so arrow keys and screen readers behave as they do
 *    everywhere else.
 *  - **One item answer, `pc:` or `asset:`.** A PC and another asset cannot be
 *    chosen together because they are values of one radio group.
 *  - **The cascade resets downstream.** A new building clears the room and
 *    the item; a new room clears the item — an item from another room would be
 *    refused anyway.
 *  - **One building is chosen for you, once.** Most sites have one; asking
 *    would be a pointless step. It is not re-chosen if the person clears it.
 *  - **The equipment request waits for the room to settle** (250 ms) and is
 *    cancelled if the room changes first, so arrowing through rooms does not
 *    fire a request per room passed.
 *  - **A filter appears only for a long list**, filtering what is already on
 *    screen. Only when the room list was cut short at {@link ROOM_LIMIT} does
 *    the server search too, and then its answer is shown as it came: the
 *    server already matched it, and filtering it again here by other fields
 *    would hide rooms it found. Enter in a filter never sends the report.
 *  - **The chosen row never disappears.** If a filter would hide it, it stays
 *    at the top of the list; in a capped list it is scrolled into view; and a
 *    refused room or item takes focus on its own row, with the message above
 *    the list rather than under a long one.
 *  - The list caps its height only from the `sm` breakpoint up: on a phone an
 *    inner scroll area fights the page's own scrolling.
 *  - **Loading is said in one place.** One polite status line, present from
 *    the start and outside the lists, says "Loading rooms…" and then how many
 *    there are — a line that appears already holding its text is not reliably
 *    read out, and one inside a busy container may not be read at all.
 */
export function WhereIsItPicker({
  value,
  onChange,
  errors = {},
  disabled = false,
  fieldRefs = {},
}: WhereIsItPickerProps) {
  const [roomTerm, setRoomTerm] = useState('')
  const [itemTerm, setItemTerm] = useState('')
  const [status, setStatus] = useState('')

  const buildings = useBuildingLookup()
  const settledRoom = useDebouncedValue(value.room, EQUIPMENT_DEBOUNCE_MS)

  // Chosen for the person when the site has exactly one building — decided
  // once, so clearing it afterwards is respected rather than undone.
  const autoChose = useRef(false)
  useEffect(() => {
    if (autoChose.current || !buildings.data) return
    autoChose.current = true
    const only = buildings.data.length === 1 ? buildings.data[0] : null
    if (only && value.building === '') onChange({ building: only.id, room: '', item: '' })
  }, [buildings.data, value.building, onChange])

  const chooseBuilding = (building: string) => {
    setRoomTerm('')
    setItemTerm('')
    onChange({ building, room: '', item: '' })
  }

  const chooseRoom = (room: string) => {
    setItemTerm('')
    onChange({ ...value, room, item: '' })
  }

  const chooseItem = (item: string) => onChange({ ...value, item })

  return (
    <>
      <p aria-live="polite" className="sr-only">
        {status}
      </p>

      <BuildingField
        value={value.building}
        onChoose={chooseBuilding}
        buildings={buildings}
        disabled={disabled}
        error={errors.building}
        selectRef={fieldRefs.building}
      />

      {value.building && (
        <RoomList
          building={value.building}
          room={value.room}
          term={roomTerm}
          onTerm={setRoomTerm}
          onChoose={chooseRoom}
          onStatus={setStatus}
          disabled={disabled}
          error={errors.room}
          inputRef={fieldRefs.room}
        />
      )}

      {value.room && (
        <ItemList
          room={value.room}
          settled={settledRoom}
          item={value.item}
          term={itemTerm}
          onTerm={setItemTerm}
          onChoose={chooseItem}
          onStatus={setStatus}
          disabled={disabled}
          error={errors.item}
          inputRef={fieldRefs.item}
        />
      )}
    </>
  )
}

/* ------------------------------------------------------------------ building */

function BuildingField({
  value,
  onChoose,
  buildings,
  disabled,
  error,
  selectRef,
}: {
  value: string
  onChoose: (building: string) => void
  buildings: ReturnType<typeof useBuildingLookup>
  disabled: boolean
  error?: string
  selectRef?: Ref<HTMLSelectElement>
}) {
  if (buildings.isError) {
    return (
      <LookupError
        message="The list of buildings could not be loaded."
        onRetry={() => void buildings.refetch()}
        retrying={buildings.isFetching}
      />
    )
  }

  if (buildings.isLoading) {
    return (
      <Field label="Building">
        <Select disabled aria-busy="true" value="">
          <option value="">Loading buildings…</option>
        </Select>
      </Field>
    )
  }

  const list = buildings.data ?? []
  if (list.length === 0) {
    return (
      <p className="measure text-sm text-muted">
        No buildings have been set up yet, so a place cannot be chosen from a list. Say where the
        problem is in your description instead.
      </p>
    )
  }

  return (
    <Field
      label="Building"
      error={error}
      hint={value ? undefined : 'Choose a building to see its rooms.'}
    >
      <Select
        ref={selectRef}
        value={value}
        disabled={disabled}
        onChange={(event) => onChoose(event.target.value)}
      >
        <option value="">Choose a building…</option>
        {list.map((building) => (
          <option key={building.id} value={building.id}>
            {building.name}
          </option>
        ))}
      </Select>
    </Field>
  )
}

/* --------------------------------------------------------------------- rooms */

function RoomList({
  building,
  room,
  term,
  onTerm,
  onChoose,
  onStatus,
  disabled,
  error,
  inputRef,
}: {
  building: string
  room: string
  term: string
  onTerm: (term: string) => void
  onChoose: (room: string) => void
  /** What the picker's one status line should say about this list. */
  onStatus: (status: string) => void
  disabled: boolean
  error?: string
  inputRef?: Ref<HTMLInputElement>
}) {
  const id = useId()
  const legendId = `${id}-legend`
  const errorId = `${id}-error`
  const listRef = useRef<HTMLUListElement>(null)

  const base = { building, sort: 'type' as const, limit: ROOM_LIMIT }
  const rooms = useRoomLookup(base)
  const list = rooms.data ?? []
  const truncated = list.length >= ROOM_LIMIT

  const trimmed = term.trim()
  const debounced = useDebouncedValue(trimmed, 300)
  // Only a list cut short at the limit needs the server's help: anything
  // shorter is complete, and filtering it here is instant and offline-proof.
  const searched = useRoomLookup({ ...base, search: debounced }, truncated && debounced !== '', {
    placeholderData: keepPreviousData,
  })

  // The chosen room as an object, kept from the click, so it can stay pinned on
  // screen even when a server search returns a list without it.
  const [picked, setPicked] = useState<RoomLookupOption | null>(null)
  const selected = room
    ? (list.find((option) => option.id === room) ??
      searched.data?.find((option) => option.id === room) ??
      (picked?.id === room ? picked : null))
    : null

  // The server's answer for exactly this term, shown as it came: it matched
  // name, code and room number, and filtering it again here by name, code,
  // type and floor would hide rooms it found by number. Until it arrives, the
  // rooms already on screen are filtered as usual, so typing never blanks the
  // list.
  const answered =
    truncated && trimmed !== '' && trimmed === debounced && !searched.isPlaceholderData
      ? (searched.data ?? null)
      : null
  const shown = answered ?? (trimmed ? list.filter((option) => roomMatches(option, trimmed)) : list)
  const pinned = selected && !shown.some((option) => option.id === selected.id) ? selected : null

  const loading = rooms.isLoading
  const searching = truncated && trimmed !== '' && (trimmed !== debounced || searched.isFetching)
  const offerSearch = list.length > SEARCH_FROM

  // The refused row takes focus: the checked one if it is on screen, else "Not sure".
  const focusRoom = room && (pinned || shown.some((option) => option.id === room)) ? room : ''

  useEffect(() => {
    if (rooms.isError) return
    onStatus(
      loading ? 'Loading rooms…' : `${plural(list.length, 'room', 'rooms')} in this building`,
    )
  }, [rooms.isError, loading, list.length, onStatus])

  useRevealChecked(listRef, !loading, room, error)

  // A room remembered from an earlier visit (the restored draft) that is no
  // longer offered — archived, or taken out of use — is let go here rather
  // than sent and refused. Only a complete list can prove a room is gone.
  useEffect(() => {
    if (!rooms.isSuccess || truncated || !room) return
    if (!rooms.data.some((option) => option.id === room)) onChoose('')
  }, [rooms.isSuccess, rooms.data, truncated, room, onChoose])

  const choose = (option: RoomLookupOption | null) => {
    setPicked(option)
    onChoose(option?.id ?? '')
  }

  if (rooms.isError) {
    return (
      <LookupError
        message="The rooms in this building could not be loaded."
        onRetry={() => void rooms.refetch()}
        retrying={rooms.isFetching}
      />
    )
  }

  return (
    <fieldset
      role="radiogroup"
      aria-labelledby={legendId}
      aria-describedby={error ? errorId : undefined}
      className="flex min-w-0 flex-col gap-3"
    >
      {/* A rendered legend sits outside the fieldset's flex box: `gap` does not reach it. */}
      <legend id={legendId} className="mb-3 text-sm font-semibold text-ink">
        Which room?
      </legend>

      {error && (
        <p id={errorId} role="alert" className="text-sm font-medium text-danger-strong">
          {error}
        </p>
      )}

      {offerSearch && (
        <Field
          label="Find a room"
          hint={
            truncated
              ? 'Type part of its name, code or number.'
              : 'Type part of its name, code, type or floor.'
          }
        >
          <Input
            type="search"
            value={term}
            onChange={(event) => onTerm(event.target.value)}
            onKeyDown={holdEnter}
            enterKeyHint="search"
            disabled={disabled}
            autoComplete="off"
          />
        </Field>
      )}

      <p aria-live="polite" className={cn('text-xs text-muted', !trimmed && 'sr-only')}>
        {!trimmed || loading
          ? ''
          : searching
            ? 'Searching every room in this building…'
            : plural(shown.length, 'room matches', 'rooms match')}
      </p>

      <ul
        ref={listRef}
        aria-busy={loading || undefined}
        className={cn(LIST, 'divide-y divide-border')}
      >
        <li>
          <OptionRow
            name="room"
            value=""
            checked={room === ''}
            onSelect={() => choose(null)}
            disabled={disabled}
            inputRef={focusRoom === '' ? inputRef : undefined}
          >
            <span className="text-sm font-semibold text-ink">Not sure which room</span>
            <span className="text-xs text-muted">Say where it is in your description.</span>
          </OptionRow>
        </li>

        {pinned && (
          <li>
            <RoomRow
              room={pinned}
              checked
              onSelect={choose}
              disabled={disabled}
              inputRef={focusRoom === pinned.id ? inputRef : undefined}
            />
          </li>
        )}

        {loading
          ? Array.from({ length: 3 }, (_, index) => (
              <li key={`skeleton-${index}`} className="flex min-h-15 items-center gap-3 px-4">
                <Skeleton circle width={20} height={20} />
                <span className="flex flex-1 flex-col gap-2">
                  <Skeleton height={14} width="45%" />
                  <Skeleton height={10} width="30%" />
                </span>
              </li>
            ))
          : shown.map((option) => (
              <li key={option.id}>
                <RoomRow
                  room={option}
                  checked={room === option.id}
                  onSelect={choose}
                  disabled={disabled}
                  inputRef={focusRoom === option.id ? inputRef : undefined}
                />
              </li>
            ))}
      </ul>

      {!loading && list.length === 0 && (
        <p className="measure text-sm text-muted">
          No rooms are set up in this building yet. Choose “Not sure which room” and say where it is
          in your description.
        </p>
      )}
      {!loading && !searching && list.length > 0 && trimmed && shown.length === 0 && (
        <p className="measure text-sm text-muted">
          No room matches “{trimmed}”. Try another part of its name or code, or choose “Not sure
          which room”.
        </p>
      )}
    </fieldset>
  )
}

function RoomRow({
  room,
  checked,
  onSelect,
  disabled,
  inputRef,
}: {
  room: RoomLookupOption
  checked: boolean
  onSelect: (room: RoomLookupOption) => void
  disabled: boolean
  inputRef?: Ref<HTMLInputElement>
}) {
  const details = [room.code, room.room_type_label, floorName(room)].filter(Boolean).join(' · ')

  return (
    <OptionRow
      name="room"
      value={room.id}
      checked={checked}
      onSelect={() => onSelect(room)}
      disabled={disabled}
      inputRef={inputRef}
    >
      <span className="text-sm font-semibold text-ink">{room.name}</span>
      {details && <span className="text-xs text-muted">{details}</span>}
    </OptionRow>
  )
}

/* --------------------------------------------------------------------- items */

function ItemList({
  room,
  settled,
  item,
  term,
  onTerm,
  onChoose,
  onStatus,
  disabled,
  error,
  inputRef,
}: {
  room: string
  /** The room once it has stopped changing — what the equipment request uses. */
  settled: string
  item: string
  term: string
  onTerm: (term: string) => void
  onChoose: (item: string) => void
  /** What the picker's one status line should say about this list. */
  onStatus: (status: string) => void
  disabled: boolean
  error?: string
  inputRef?: Ref<HTMLInputElement>
}) {
  const id = useId()
  const legendId = `${id}-legend`
  const errorId = `${id}-error`
  const listRef = useRef<HTMLDivElement>(null)

  const equipment = useRoomEquipmentLookup(settled || undefined)
  const settling = settled !== room
  const loading = settling || equipment.isLoading
  const failed = !settling && equipment.isError

  const pcs = settling ? [] : (equipment.data?.pc_units ?? [])
  const others = settling ? [] : (equipment.data?.assets ?? [])
  const total = pcs.length + others.length

  useEffect(() => {
    if (failed) return
    onStatus(
      loading
        ? 'Loading the equipment in this room…'
        : `${plural(pcs.length, 'computer', 'computers')}, ${plural(others.length, 'other item', 'other items')} in this room`,
    )
  }, [failed, loading, pcs.length, others.length, onStatus])

  useRevealChecked(listRef, !loading, item, error)

  // An item remembered from the restored draft that this room no longer lists
  // (retired, moved, taken out of service) is let go rather than sent and refused.
  useEffect(() => {
    if (settling || !equipment.isSuccess || !item) return
    const [kind, uuid] = [item.slice(0, item.indexOf(':')), item.slice(item.indexOf(':') + 1)]
    const list = kind === 'pc' ? equipment.data.pc_units : equipment.data.assets
    if (!list.some((option) => option.id === uuid)) onChoose('')
  }, [settling, equipment.isSuccess, equipment.data, item, onChoose])

  const trimmed = term.trim()
  const filter = (list: EquipmentLookupOption[], prefix: 'pc' | 'asset') =>
    list.filter(
      (option) => !trimmed || itemMatches(option, trimmed) || item === `${prefix}:${option.id}`,
    )
  const shownPcs = filter(pcs, 'pc')
  const shownOthers = filter(others, 'asset')
  const matched = shownPcs.length + shownOthers.length

  // The refused row takes focus: the checked one if it is listed, else "Not about a specific item".
  const listed = (prefix: 'pc' | 'asset', options: EquipmentLookupOption[]) =>
    options.some((option) => item === `${prefix}:${option.id}`)
  const focusItem =
    !loading && item && (listed('pc', shownPcs) || listed('asset', shownOthers)) ? item : ''

  if (failed) {
    return (
      <LookupError
        message="The equipment in this room could not be loaded."
        onRetry={() => void equipment.refetch()}
        retrying={equipment.isFetching}
      />
    )
  }

  return (
    <fieldset
      role="radiogroup"
      aria-labelledby={legendId}
      aria-describedby={error ? errorId : undefined}
      className="flex min-w-0 flex-col gap-3"
    >
      {/* A rendered legend sits outside the fieldset's flex box: `gap` does not reach it. */}
      <legend id={legendId} className="mb-3 text-sm font-semibold text-ink">
        Which item has the problem?
      </legend>

      {error && (
        <p id={errorId} role="alert" className="text-sm font-medium text-danger-strong">
          {error}
        </p>
      )}

      {total > SEARCH_FROM && (
        <Field label="Find an item" hint="Type part of its name or its tag.">
          <Input
            type="search"
            value={term}
            onChange={(event) => onTerm(event.target.value)}
            onKeyDown={holdEnter}
            enterKeyHint="search"
            disabled={disabled}
            autoComplete="off"
          />
        </Field>
      )}

      <p aria-live="polite" className={cn('text-xs text-muted', !trimmed && 'sr-only')}>
        {trimmed && !loading ? plural(matched, 'item matches', 'items match') : ''}
      </p>

      <div
        ref={listRef}
        aria-busy={loading || undefined}
        className={cn(LIST, 'divide-y divide-border')}
      >
        <OptionRow
          name="item"
          value=""
          checked={item === ''}
          onSelect={() => onChoose('')}
          disabled={disabled}
          inputRef={focusItem === '' ? inputRef : undefined}
        >
          <span className="text-sm font-semibold text-ink">Not about a specific item</span>
          <span className="text-xs text-muted">
            The Wi-Fi, an account, or something not listed.
          </span>
        </OptionRow>

        {loading ? (
          <ul aria-hidden="true">
            {Array.from({ length: 3 }, (_, index) => (
              <li key={index} className="flex min-h-15 items-center gap-3 px-4">
                <Skeleton circle width={20} height={20} />
                <span className="flex flex-1 flex-col gap-2">
                  <Skeleton height={14} width="40%" />
                  <Skeleton height={10} width="25%" />
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <>
            {shownPcs.length > 0 && (
              <ItemGroup
                heading="Computers"
                icon={<Monitor size={18} aria-hidden="true" />}
                items={shownPcs}
                prefix="pc"
                item={item}
                focusItem={focusItem}
                onChoose={onChoose}
                disabled={disabled}
                inputRef={inputRef}
              />
            )}
            {shownOthers.length > 0 && (
              <ItemGroup
                heading="Other equipment"
                icon={<Package size={18} aria-hidden="true" />}
                items={shownOthers}
                prefix="asset"
                item={item}
                focusItem={focusItem}
                onChoose={onChoose}
                disabled={disabled}
                inputRef={inputRef}
              />
            )}
          </>
        )}
      </div>

      {!loading && total === 0 && (
        <p className="measure text-sm text-muted">
          No computers or other equipment are listed for this room. Choose “Not about a specific
          item” and describe the equipment in your report.
        </p>
      )}
      {!loading && total > 0 && trimmed && matched === 0 && (
        <p className="measure text-sm text-muted">
          Nothing here matches “{trimmed}”. Try its tag, or choose “Not about a specific item”.
        </p>
      )}
    </fieldset>
  )
}

/**
 * One kind of equipment. A row is the server's label alone: it already ends
 * with the tag or unit code — "PC 01 (PC-LA-01)" — so printing the identifier
 * again underneath would only say it twice. The filter still matches either.
 */
function ItemGroup({
  heading,
  icon,
  items,
  prefix,
  item,
  focusItem,
  onChoose,
  disabled,
  inputRef,
}: {
  heading: string
  icon: ReactNode
  items: EquipmentLookupOption[]
  prefix: 'pc' | 'asset'
  item: string
  /** The one row that takes the field's ref, if it is in this group. */
  focusItem: string
  onChoose: (item: string) => void
  disabled: boolean
  inputRef?: Ref<HTMLInputElement>
}) {
  const headingId = useId()

  return (
    <div role="group" aria-labelledby={headingId}>
      <p
        id={headingId}
        className="flex items-center gap-2 border-b border-border bg-surface-sunken px-4 py-2 text-xs font-semibold text-ink"
      >
        {icon}
        {heading}
        <span className="font-medium text-muted tnum">· {items.length}</span>
      </p>
      <ul className="divide-y divide-border">
        {items.map((option) => {
          const value = `${prefix}:${option.id}`
          return (
            <li key={value}>
              <OptionRow
                name="item"
                value={value}
                checked={item === value}
                onSelect={() => onChoose(value)}
                disabled={disabled}
                inputRef={focusItem === value ? inputRef : undefined}
              >
                <span className="text-sm font-semibold text-ink">{option.label}</span>
              </OptionRow>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/* ------------------------------------------------------------------- shared */

function OptionRow({
  name,
  value,
  checked,
  onSelect,
  disabled,
  inputRef,
  children,
}: {
  name: string
  value: string
  checked: boolean
  onSelect: () => void
  disabled: boolean
  inputRef?: Ref<HTMLInputElement>
  children: ReactNode
}) {
  return (
    <label className={ROW}>
      <input
        ref={inputRef}
        type="radio"
        name={name}
        value={value}
        checked={checked}
        onChange={onSelect}
        disabled={disabled}
        className="size-5 shrink-0 accent-primary"
      />
      <span className="flex min-w-0 flex-col">{children}</span>
    </label>
  )
}

function LookupError({
  message,
  onRetry,
  retrying,
}: {
  message: string
  onRetry: () => void
  retrying: boolean
}) {
  return (
    <Alert tone="error" title={message}>
      <p>Check the connection, then try again. You can also say where it is in your description.</p>
      <Button
        variant="secondary"
        size="sm"
        className="mt-3"
        onClick={onRetry}
        loading={retrying}
        disabled={retrying}
      >
        Try again
      </Button>
    </Alert>
  )
}
