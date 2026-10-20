import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import {
  ChevronDown,
  Copy,
  Grid3x3,
  HelpCircle,
  LayoutGrid,
  Magnet,
  Maximize,
  Minus,
  Plus,
  Redo2,
  RotateCw,
  Settings2,
  Trash2,
  Undo2,
} from 'lucide-react'
import { Tooltip } from '@/components/ui'
import { cn } from '@/lib/cn'
import { startDrag } from '../lib/dragItem'
import { FIXTURES } from '../lib/fixtures'
import { useEditor, useEditorStore } from '../stores/useEditor'
import { FIXTURE_KINDS, type FixtureKind } from '../types'

export function ToolButton({
  label,
  shortcut,
  onClick,
  disabled,
  pressed,
  children,
}: {
  label: string
  shortcut?: string
  onClick: () => void
  disabled?: boolean
  pressed?: boolean
  children: ReactNode
}) {
  return (
    <Tooltip content={shortcut ? `${label} (${shortcut})` : label} describe={false}>
      <button
        type="button"
        aria-label={label}
        aria-keyshortcuts={shortcut}
        aria-pressed={pressed}
        disabled={disabled}
        onClick={onClick}
        className={cn(
          'grid size-11 shrink-0 place-items-center rounded-md text-ink transition-colors duration-150',
          'hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
          'disabled:cursor-not-allowed disabled:text-faint disabled:hover:bg-transparent',
          pressed && 'bg-primary-subtle text-primary-strong hover:bg-primary-subtle',
        )}
      >
        {children}
      </button>
    </Tooltip>
  )
}

function Divider() {
  return <span aria-hidden="true" className="mx-1 h-7 w-px shrink-0 bg-border" />
}

/**
 * The fixture palette behind one "Add fixture" button, so the toolbar stays a
 * single row and the canvas keeps the height. Each item still adds on click
 * and can still be dragged onto the canvas.
 *
 * Non-modal, like the shared `Popover`: focus moves into the panel, Escape (in
 * the capture phase, ahead of the editor's own shortcuts) or a press outside
 * closes it. While an item is being dragged the panel turns transparent to the
 * pointer instead of unmounting — removing a drag source mid-drag can cancel
 * the drag — and it closes when the drag ends.
 */
function FixtureMenu({ onAdd }: { onAdd: (kind: FixtureKind) => void }) {
  const [open, setOpen] = useState(false)
  const [dragging, setDragging] = useState(false)
  const id = useId()
  const wrapper = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)
  const panel = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    panel.current?.querySelector<HTMLElement>('button')?.focus()

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setOpen(false)
        button.current?.focus()
      }
    }
    const onPointer = (event: PointerEvent) => {
      if (!wrapper.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('keydown', onKey, true)
    document.addEventListener('pointerdown', onPointer)
    return () => {
      document.removeEventListener('keydown', onKey, true)
      document.removeEventListener('pointerdown', onPointer)
    }
  }, [open])

  return (
    <div ref={wrapper} className="relative">
      <button
        ref={button}
        type="button"
        aria-expanded={open}
        aria-controls={`${id}-panel`}
        onClick={() => setOpen((current) => !current)}
        className={cn(
          'inline-flex h-11 shrink-0 items-center gap-2 rounded-md border px-3 text-sm font-semibold text-ink transition-colors duration-150',
          'hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
          open ? 'border-primary bg-primary-subtle' : 'border-control-border bg-surface',
        )}
      >
        <Plus className="size-4" aria-hidden="true" />
        Add fixture
        <ChevronDown
          className={cn('size-4 text-muted transition-transform', open && 'rotate-180')}
          aria-hidden="true"
        />
      </button>
      {open && (
        <div
          ref={panel}
          id={`${id}-panel`}
          role="group"
          aria-label="Add a fixture"
          className={cn(
            'absolute top-full left-0 z-30 mt-2 grid w-max grid-cols-2 gap-1 rounded-md border-2 border-border bg-surface p-2 shadow-md',
            dragging && 'pointer-events-none opacity-0',
          )}
        >
          <p className="col-span-2 px-2 pb-1 text-xs text-muted">
            Click to add, or drag onto the plan
          </p>
          {FIXTURE_KINDS.map((kind) => {
            const Icon = FIXTURES[kind].icon
            return (
              <button
                key={kind}
                type="button"
                draggable
                onDragStart={(e) => {
                  startDrag(e, { type: 'fixture', kind })
                  // After the browser has taken the drag image.
                  window.setTimeout(() => setDragging(true), 0)
                }}
                onDragEnd={() => {
                  setDragging(false)
                  setOpen(false)
                }}
                onClick={() => {
                  onAdd(kind)
                  setOpen(false)
                }}
                className="inline-flex h-11 cursor-grab items-center gap-2 rounded-md px-3 text-left text-sm font-medium text-ink transition-colors hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus active:cursor-grabbing"
              >
                <Icon className="size-4 text-muted" aria-hidden="true" />
                {FIXTURES[kind].label}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}

interface EditorToolbarProps {
  editing: boolean
  hasSelection: boolean
  hasFixtureSelection: boolean
  unplacedCount: number
  /** "PC", or "item" once confirmed equipment (DD-115) is waiting too. */
  unplacedNoun?: string
  onAddFixture: (kind: FixtureKind) => void
  onRotate: () => void
  onDelete: () => void
  onDuplicate: () => void
  onArrange: () => void
  onSettings: () => void
  onShortcuts: () => void
  /** Rendered at the row's far end (the panel buttons below `xl`). */
  end?: ReactNode
}

/**
 * The editor's controls (Phase 2.11). Every control has a visible tooltip and
 * an accessible name, and the keyboard shortcut beside it. Edit-only tools are
 * not rendered in view mode rather than shown disabled — a viewer is not
 * offered what the server would refuse. One row, so that at 1280×720 the
 * canvas starts above the fold.
 */
export function EditorToolbar(props: EditorToolbarProps) {
  const zoom = useEditor((s) => s.viewport.zoom)
  const snap = useEditor((s) => s.snap)
  const showGrid = useEditor((s) => s.showGrid)
  const canUndo = useEditor((s) => s.past.length > 0)
  const canRedo = useEditor((s) => s.future.length > 0)
  // Actions are stable; reading them from the store avoids re-rendering on every edit.
  const { undo, redo, zoomIn, zoomOut, fit, setSnap, setShowGrid } = useEditorStore().getState()

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
      <div
        role="toolbar"
        aria-label="Floorplan tools"
        className="flex flex-wrap items-center gap-1"
      >
        {props.editing && (
          <>
            <FixtureMenu onAdd={props.onAddFixture} />
            <Divider />
            <ToolButton label="Undo" shortcut="Control+Z" onClick={undo} disabled={!canUndo}>
              <Undo2 className="size-5" aria-hidden="true" />
            </ToolButton>
            <ToolButton label="Redo" shortcut="Control+Shift+Z" onClick={redo} disabled={!canRedo}>
              <Redo2 className="size-5" aria-hidden="true" />
            </ToolButton>
            <Divider />
            <ToolButton
              label="Turn selection"
              shortcut="R"
              onClick={props.onRotate}
              disabled={!props.hasSelection}
            >
              <RotateCw className="size-5" aria-hidden="true" />
            </ToolButton>
            <ToolButton
              label="Duplicate fixture"
              shortcut="Control+D"
              onClick={props.onDuplicate}
              disabled={!props.hasFixtureSelection}
            >
              <Copy className="size-5" aria-hidden="true" />
            </ToolButton>
            <ToolButton
              label="Remove selection"
              shortcut="Delete"
              onClick={props.onDelete}
              disabled={!props.hasSelection}
            >
              <Trash2 className="size-5" aria-hidden="true" />
            </ToolButton>
            <Divider />
            <ToolButton
              label={`Arrange ${props.unplacedCount} unplaced ${props.unplacedNoun ?? 'PC'}s`}
              onClick={props.onArrange}
              disabled={props.unplacedCount === 0}
            >
              <LayoutGrid className="size-5" aria-hidden="true" />
            </ToolButton>
            <ToolButton label="Room size and grid" onClick={props.onSettings}>
              <Settings2 className="size-5" aria-hidden="true" />
            </ToolButton>
            <Divider />
          </>
        )}

        <ToolButton label="Zoom out" shortcut="-" onClick={() => zoomOut()}>
          <Minus className="size-5" aria-hidden="true" />
        </ToolButton>
        <output
          aria-label="Zoom"
          className="w-14 text-center text-sm font-medium tabular-nums text-muted"
        >
          {Math.round(zoom * 100)}%
        </output>
        <ToolButton label="Zoom in" shortcut="+" onClick={() => zoomIn()}>
          <Plus className="size-5" aria-hidden="true" />
        </ToolButton>
        <ToolButton label="Fit the room" shortcut="F" onClick={fit}>
          <Maximize className="size-5" aria-hidden="true" />
        </ToolButton>
        <Divider />
        <ToolButton
          label="Show grid"
          shortcut="G"
          pressed={showGrid}
          onClick={() => setShowGrid(!showGrid)}
        >
          <Grid3x3 className="size-5" aria-hidden="true" />
        </ToolButton>
        {props.editing && (
          <ToolButton
            label="Snap to grid"
            shortcut="S"
            pressed={snap}
            onClick={() => setSnap(!snap)}
          >
            <Magnet className="size-5" aria-hidden="true" />
          </ToolButton>
        )}
        <ToolButton label="Keyboard shortcuts" shortcut="?" onClick={props.onShortcuts}>
          <HelpCircle className="size-5" aria-hidden="true" />
        </ToolButton>
      </div>
      {props.end && <div className="ml-auto flex flex-wrap items-center gap-2">{props.end}</div>}
    </div>
  )
}
