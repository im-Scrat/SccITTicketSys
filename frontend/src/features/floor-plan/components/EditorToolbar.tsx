import type { ReactNode } from 'react'
import {
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

interface EditorToolbarProps {
  editing: boolean
  hasSelection: boolean
  hasFixtureSelection: boolean
  unplacedCount: number
  onAddFixture: (kind: FixtureKind) => void
  onRotate: () => void
  onDelete: () => void
  onDuplicate: () => void
  onArrange: () => void
  onSettings: () => void
  onShortcuts: () => void
}

/**
 * The editor's controls (Phase 2.11). Every control has a visible tooltip and
 * an accessible name, and the keyboard shortcut beside it. Edit-only tools are
 * not rendered in view mode rather than shown disabled — a viewer is not
 * offered what the server would refuse.
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
    <div className="flex flex-col gap-2">
      <div
        role="toolbar"
        aria-label="Floorplan tools"
        className="flex flex-wrap items-center gap-1"
      >
        {props.editing && (
          <>
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
              label={`Arrange ${props.unplacedCount} unplaced PCs`}
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

      {props.editing && (
        <div
          role="group"
          aria-label="Add a fixture"
          className="flex items-center gap-2 overflow-x-auto pb-1"
        >
          <span className="shrink-0 text-sm font-semibold text-muted">Add</span>
          {FIXTURE_KINDS.map((kind) => {
            const Icon = FIXTURES[kind].icon
            return (
              <button
                key={kind}
                type="button"
                draggable
                onDragStart={(e) => startDrag(e, { type: 'fixture', kind })}
                onClick={() => props.onAddFixture(kind)}
                className="inline-flex h-10 shrink-0 cursor-grab items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-ink transition-colors hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus active:cursor-grabbing"
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
