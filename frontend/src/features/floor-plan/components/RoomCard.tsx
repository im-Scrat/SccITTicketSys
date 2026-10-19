import { ArrowRight, LayoutTemplate } from 'lucide-react'
import { Link } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { PC_STATUS, PC_STATUS_TONE_CLASSES } from '@/lib/pcStatus'
import type { FloorPlanRoomSummary } from '../types'
import { RoomThumbnail } from './RoomThumbnail'

/**
 * One room on the Floorplan overview (Phase 2.11). The whole card is one link
 * to the room's editor; its name is the link text, the rest describes it.
 * The thumbnail is decorative — the status bar and counts say it in words.
 */
export function RoomCard({ room }: { room: FloorPlanRoomSummary }) {
  const { total, placed, by_status } = room.pcs
  const place = [room.building?.name, room.floor?.name].filter(Boolean).join(' · ')
  const attention = by_status
    .filter((s) => s.value !== 'available')
    .reduce((n, s) => n + s.count, 0)

  return (
    <li className="group relative flex flex-col overflow-hidden rounded-lg border border-border bg-surface transition-colors hover:border-primary focus-within:border-primary">
      <div className="relative aspect-[16/9] border-b border-border bg-surface-sunken p-3">
        {room.layout && room.preview ? (
          <RoomThumbnail layout={room.layout} preview={room.preview} />
        ) : (
          <div className="grid size-full place-items-center text-center">
            <span className="flex flex-col items-center gap-2 text-sm text-muted">
              <LayoutTemplate className="size-6" aria-hidden="true" />
              No Floorplan yet
            </span>
          </div>
        )}
      </div>

      <div className="flex flex-1 flex-col gap-3 p-4">
        <div className="min-w-0">
          <h3 className="text-base font-bold text-ink-strong">
            <Link
              to={`/app/floor-plan/rooms/${room.id}`}
              className="outline-none after:absolute after:inset-0 after:rounded-lg focus-visible:after:outline-2 focus-visible:after:outline-offset-2 focus-visible:after:outline-focus"
            >
              {room.name}
            </Link>
          </h3>
          <p className="text-sm text-muted">
            {place || room.code} · {room.room_type.label}
            {!room.is_active && ' · Not in use'}
          </p>
        </div>

        {total === 0 ? (
          <p className="text-sm text-muted">No PCs registered to this room.</p>
        ) : (
          <div className="flex flex-col gap-2">
            <div
              className="flex h-2 overflow-hidden rounded-full bg-surface-sunken"
              aria-hidden="true"
            >
              {by_status.map((s) =>
                s.count > 0 ? (
                  <span
                    key={s.value}
                    className={PC_STATUS_TONE_CLASSES[PC_STATUS[s.value].tone].fill}
                    style={{ width: `${(s.count / total) * 100}%` }}
                  />
                ) : null,
              )}
            </div>
            <ul className="flex flex-wrap gap-x-3 gap-y-1 text-sm">
              {by_status.map((s) => {
                const Icon = PC_STATUS[s.value].icon
                return (
                  <li
                    key={s.value}
                    className={cn(
                      'inline-flex items-center gap-1.5',
                      s.count === 0
                        ? 'text-faint'
                        : PC_STATUS_TONE_CLASSES[PC_STATUS[s.value].tone].text,
                    )}
                  >
                    <Icon className="size-4" aria-hidden="true" />
                    <span className="tabular-nums">{s.count}</span>
                    <span className={s.count === 0 ? 'text-faint' : 'text-ink'}>{s.label}</span>
                  </li>
                )
              })}
            </ul>
          </div>
        )}

        <div className="mt-auto flex items-center justify-between gap-3 border-t border-border pt-3 text-sm">
          <span className="text-muted">
            {room.layout ? (
              <>
                <span className="tabular-nums text-ink">
                  {placed} of {total}
                </span>{' '}
                placed
                {attention > 0 ? ` · ${attention} need${attention === 1 ? 's' : ''} attention` : ''}
              </>
            ) : (
              'Set up its Floorplan'
            )}
          </span>
          <ArrowRight
            className="size-4 shrink-0 text-faint transition-transform group-hover:translate-x-0.5 group-hover:text-primary-strong motion-reduce:transition-none"
            aria-hidden="true"
          />
        </div>
      </div>
    </li>
  )
}
