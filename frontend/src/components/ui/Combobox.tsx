import { Check, ChevronDown, X } from 'lucide-react'
import {
  type ChangeEvent,
  type KeyboardEvent,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
} from 'react'
import { createPortal } from 'react-dom'
import { cn } from '@/lib/cn'
import { useOverlayPosition } from '@/hooks/useOverlayPosition'
import { LiveRegion } from './LiveRegion'
import { useOverlayRoot } from './overlayRoot'

export interface ComboboxOption {
  value: string
  label: string
  /** A second line ("Building A · Floor 2"); also searched. */
  description?: string
  /** Options sharing a group are listed under its heading, in first-seen order. */
  group?: string
  disabled?: boolean
}

interface ComboboxProps {
  label: string
  options: ComboboxOption[]
  value: string | null
  onChange: (value: string | null) => void
  /**
   * The selected option's label when it may be missing from `options` (a
   * server search whose current results do not include it).
   */
  selectedLabel?: string
  /** Server-side search: called with the typed text, and `options` are shown as given. */
  onSearch?: (query: string) => void
  loading?: boolean
  placeholder?: string
  hint?: string
  error?: string
  emptyText?: string
  disabled?: boolean
  /** Offer a control to clear the selection (default true). */
  clearable?: boolean
  /** Options rendered at once; the rest are reached by typing. */
  maxResults?: number
  className?: string
}

function normalise(text: string) {
  return text.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

/**
 * A text field that filters a list to choose one value from — for lists too
 * long for a native select (every room in the estate). Follows the WAI-ARIA
 * combobox pattern with list autocomplete: focus stays in the input, and the
 * highlighted option is conveyed by `aria-activedescendant`.
 *
 * Typing never selects by itself; an option is chosen only by Enter or a
 * click, and leaving the field restores the current choice's label. Escape
 * closes the list first, then clears the typed text, and only then reaches an
 * enclosing dialog. The list is portalled (see overlayRoot.ts) so a scrolling
 * table or dialog body cannot clip it.
 */
export function Combobox({
  label,
  options,
  value,
  onChange,
  selectedLabel,
  onSearch,
  loading = false,
  placeholder,
  hint,
  error,
  emptyText = 'No matches',
  disabled = false,
  clearable = true,
  maxResults = 50,
  className,
}: ComboboxProps) {
  const id = useId()
  const listId = `${id}-list`
  const field = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const popup = useRef<HTMLDivElement>(null)
  const portalTarget = useOverlayRoot()

  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [editing, setEditing] = useState(false)
  const [active, setActive] = useState(-1)

  const selected = options.find((option) => option.value === value)
  const display = selected?.label ?? (value !== null ? (selectedLabel ?? '') : '')

  const matches = useMemo(() => {
    if (onSearch || !editing || query.trim() === '') return options
    const terms = normalise(query).split(/\s+/).filter(Boolean)
    return options.filter((option) => {
      const haystack = normalise(
        [option.label, option.description, option.group].filter(Boolean).join(' '),
      )
      return terms.every((term) => haystack.includes(term))
    })
  }, [options, query, editing, onSearch])

  const visible = useMemo(() => matches.slice(0, maxResults), [matches, maxResults])
  const optionId = (index: number) => `${id}-opt-${index}`

  const groups = useMemo(() => {
    const order: {
      name: string | undefined
      items: { option: ComboboxOption; index: number }[]
    }[] = []
    visible.forEach((option, index) => {
      let group = order.find((entry) => entry.name === option.group)
      if (!group) {
        group = { name: option.group, items: [] }
        order.push(group)
      }
      group.items.push({ option, index })
    })
    return order
  }, [visible])

  useOverlayPosition(field, popup, open, { matchWidth: true, fitHeight: true })

  useEffect(() => {
    if (!open || active < 0) return
    document.getElementById(optionId(active))?.scrollIntoView?.({ block: 'nearest' })
    // optionId is derived from the stable useId value.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, active])

  const close = () => {
    setOpen(false)
    setActive(-1)
  }

  const openList = () => {
    if (disabled) return
    setOpen(true)
    const current = visible.findIndex((option) => option.value === value)
    setActive(current >= 0 ? current : firstEnabled(0, 1))
  }

  const firstEnabled = (from: number, step: 1 | -1) => {
    for (let index = from; index >= 0 && index < visible.length; index += step) {
      if (!visible[index].disabled) return index
    }
    return -1
  }

  const choose = (option: ComboboxOption) => {
    if (option.disabled) return
    onChange(option.value)
    setEditing(false)
    setQuery('')
    close()
  }

  const onInput = (event: ChangeEvent<HTMLInputElement>) => {
    const next = event.target.value
    setQuery(next)
    setEditing(true)
    setOpen(true)
    setActive(next.trim() === '' ? -1 : 0)
    onSearch?.(next)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    switch (event.key) {
      case 'ArrowDown': {
        event.preventDefault()
        if (!open) return openList()
        const next = firstEnabled(active + 1, 1)
        if (next >= 0) setActive(next)
        return
      }
      case 'ArrowUp': {
        event.preventDefault()
        if (!open) return openList()
        const previous = firstEnabled(active - 1, -1)
        if (previous >= 0) setActive(previous)
        return
      }
      case 'Enter': {
        if (!open) return
        // Keep a form around the field from submitting while the list is open.
        event.preventDefault()
        const option = visible[active]
        if (option) choose(option)
        return
      }
      case 'Escape': {
        if (open) {
          event.stopPropagation()
          close()
        } else if (editing && query !== '') {
          event.stopPropagation()
          setQuery('')
          setEditing(false)
        }
        return
      }
      case 'Tab':
        close()
        return
    }
  }

  const describedBy = [hint || error ? `${id}-help` : null].filter(Boolean).join(' ') || undefined
  const activeOption = open && active >= 0 ? visible[active] : undefined
  const announcement =
    open && editing
      ? visible.length === 0
        ? loading
          ? 'Searching'
          : emptyText
        : `${matches.length} ${matches.length === 1 ? 'result' : 'results'} available`
      : ''

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <label htmlFor={`${id}-input`} className="text-sm font-semibold text-ink">
        {label}
      </label>

      <div ref={field} className="relative">
        <input
          ref={input}
          id={`${id}-input`}
          type="text"
          role="combobox"
          autoComplete="off"
          spellCheck={false}
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={activeOption ? optionId(active) : undefined}
          aria-describedby={describedBy}
          aria-invalid={error ? true : undefined}
          disabled={disabled}
          placeholder={placeholder}
          value={editing ? query : display}
          onChange={onInput}
          onKeyDown={onKeyDown}
          onClick={() => (open ? undefined : openList())}
          onBlur={() => {
            close()
            setEditing(false)
            setQuery('')
          }}
          className={cn(
            'h-15 w-full rounded-md border-2 border-control-border bg-surface pl-4 text-sm font-medium text-ink',
            'placeholder:text-muted transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
            'focus:border-primary disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-faint',
            'aria-[invalid=true]:border-danger',
            clearable && value !== null ? 'pr-24' : 'pr-12',
          )}
        />
        <div className="absolute inset-y-0 right-2 flex items-center gap-1">
          {clearable && value !== null && !disabled && (
            <button
              type="button"
              aria-label={`Clear ${label}`}
              onClick={() => {
                onChange(null)
                input.current?.focus()
              }}
              className="flex size-10 items-center justify-center rounded-sm text-muted hover:bg-surface-sunken hover:text-ink"
            >
              <X size={18} aria-hidden="true" />
            </button>
          )}
          <button
            type="button"
            // Off the Tab order (the input already opens the list from the
            // keyboard); a pointer convenience, so hidden from assistive tech.
            tabIndex={-1}
            aria-hidden="true"
            disabled={disabled}
            onPointerDown={(event) => event.preventDefault()}
            onClick={() => {
              input.current?.focus()
              if (open) close()
              else openList()
            }}
            className="flex size-10 items-center justify-center rounded-sm text-muted hover:bg-surface-sunken"
          >
            <ChevronDown
              size={18}
              className={cn('transition-transform duration-150', open && 'rotate-180')}
            />
          </button>
        </div>
      </div>

      {(hint || error) && (
        <p
          id={`${id}-help`}
          className={cn('text-xs', error ? 'font-medium text-danger' : 'text-muted')}
        >
          {error ?? hint}
        </p>
      )}

      {open &&
        createPortal(
          <div
            ref={popup}
            // Keep focus in the input while the pointer works the list.
            onPointerDown={(event) => event.preventDefault()}
            className="fixed left-0 top-0 z-60 flex max-w-[calc(100vw-1rem)] flex-col overflow-hidden rounded-md border-2 border-border bg-surface shadow-[var(--shadow-overlay-sm)]"
          >
            <ul
              id={listId}
              role="listbox"
              aria-label={label}
              className="min-h-0 flex-1 overflow-y-auto py-1"
            >
              {groups.map((group) => {
                const optionsList = group.items.map(({ option, index }) => {
                  const isSelected = option.value === value
                  return (
                    <li
                      key={option.value}
                      id={optionId(index)}
                      role="option"
                      aria-selected={isSelected}
                      aria-disabled={option.disabled || undefined}
                      onClick={() => choose(option)}
                      onPointerMove={() => !option.disabled && active !== index && setActive(index)}
                      className={cn(
                        'flex cursor-pointer items-start gap-3 px-3 py-2.5 text-sm',
                        index === active && 'bg-surface-sunken',
                        option.disabled && 'cursor-not-allowed text-faint',
                      )}
                    >
                      <Check
                        size={18}
                        aria-hidden="true"
                        className={cn('mt-0.5 shrink-0', isSelected ? 'text-primary' : 'invisible')}
                      />
                      <span className="min-w-0">
                        <span
                          className={cn(
                            'block break-words',
                            isSelected ? 'font-semibold text-ink-strong' : 'text-ink',
                          )}
                        >
                          {option.label}
                        </span>
                        {option.description && (
                          <span className="block break-words text-xs text-muted">
                            {option.description}
                          </span>
                        )}
                      </span>
                    </li>
                  )
                })

                if (!group.name) return optionsList
                const headingId = `${id}-group-${group.items[0].index}`
                return (
                  <li key={`group-${group.name}`} role="presentation">
                    <div
                      id={headingId}
                      role="presentation"
                      className="px-3 pb-1 pt-3 text-xs font-semibold text-muted"
                    >
                      {group.name}
                    </div>
                    <ul role="group" aria-labelledby={headingId}>
                      {optionsList}
                    </ul>
                  </li>
                )
              })}
            </ul>

            {visible.length === 0 && (
              <p className="px-3 py-3 text-sm text-muted">{loading ? 'Searching…' : emptyText}</p>
            )}
            {matches.length > visible.length && (
              <p className="border-t border-border px-3 py-2 text-xs text-muted">
                Showing {visible.length} of {matches.length}. Type to narrow the list.
              </p>
            )}
          </div>,
          portalTarget,
        )}

      <LiveRegion message={announcement} />
    </div>
  )
}
