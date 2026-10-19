import { FileSpreadsheet, Upload, X } from 'lucide-react'
import { type DragEvent, useId, useRef, useState } from 'react'
import { cn } from '@/lib/cn'
import { Button } from './Button'
import { buttonVariants } from './buttonVariants'

export type DropzoneRejection = 'type' | 'size' | 'multiple'

interface DropzoneProps {
  /** Lower-case extensions with the dot, e.g. ['.xlsx', '.csv']. */
  accept: string[]
  /** Largest file accepted, in bytes. */
  maxBytes: number
  /** The chosen file, owned by the caller (null = nothing chosen). */
  file: File | null
  onFile: (file: File | null) => void
  /** Told why a file was refused; the message is already shown here. */
  onReject?: (reason: DropzoneRejection, file?: File) => void
  label: string
  /** What is accepted, in words ("Excel .xlsx or CSV, up to 10 MB"). */
  hint: string
  disabled?: boolean
  /** A server-side problem with the chosen file, shown in place of the hint. */
  error?: string
  className?: string
}

const MESSAGES: Record<DropzoneRejection, (accept: string[], maxBytes: number) => string> = {
  type: (accept) =>
    `That file type isn't accepted. Choose a file ending in ${listExtensions(accept)}.`,
  size: (_accept, maxBytes) => `That file is larger than ${formatBytes(maxBytes)}.`,
  multiple: () => 'Drop one file at a time.',
}

function listExtensions(accept: string[]) {
  return accept.length <= 1
    ? accept.join('')
    : `${accept.slice(0, -1).join(', ')} or ${accept.at(-1)}`
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} bytes`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`
}

/**
 * A single-file picker that also takes a dropped file. The native file input
 * does the real work — it is the keyboard and screen-reader path, and the drop
 * area is a convenience on top of it.
 *
 * The extension and size checks here only spare the user a wasted upload; the
 * server re-checks both and inspects the content itself (AttachmentSecurity),
 * so nothing here is a security boundary.
 */
export function Dropzone({
  accept,
  maxBytes,
  file,
  onFile,
  onReject,
  label,
  hint,
  disabled = false,
  error,
  className,
}: DropzoneProps) {
  const id = useId()
  const input = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const [rejection, setRejection] = useState<string | null>(null)
  // dragenter/dragleave also fire for every child crossed; count to know when
  // the pointer has really left the zone.
  const depth = useRef(0)

  const take = (files: FileList | null) => {
    if (!files || files.length === 0) return
    if (files.length > 1) return refuse('multiple')

    const chosen = files[0]
    const name = chosen.name.toLowerCase()
    if (!accept.some((extension) => name.endsWith(extension))) return refuse('type', chosen)
    if (chosen.size > maxBytes) return refuse('size', chosen)

    setRejection(null)
    onFile(chosen)
  }

  const refuse = (reason: DropzoneRejection, chosen?: File) => {
    setRejection(MESSAGES[reason](accept, maxBytes))
    onReject?.(reason, chosen)
  }

  const onDragEnter = (event: DragEvent) => {
    event.preventDefault()
    if (disabled) return
    depth.current += 1
    setDragging(true)
  }
  const onDragLeave = (event: DragEvent) => {
    event.preventDefault()
    depth.current = Math.max(depth.current - 1, 0)
    if (depth.current === 0) setDragging(false)
  }
  const onDrop = (event: DragEvent) => {
    event.preventDefault()
    depth.current = 0
    setDragging(false)
    if (!disabled) take(event.dataTransfer.files)
  }

  const message = error ?? rejection

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <span id={`${id}-label`} className="text-sm font-semibold text-ink">
        {label}
      </span>

      <div
        onDragEnter={onDragEnter}
        onDragOver={(event) => event.preventDefault()}
        onDragLeave={onDragLeave}
        onDrop={onDrop}
        className={cn(
          'flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed px-6 py-8 text-center',
          'transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
          dragging ? 'border-primary bg-primary-subtle' : 'border-control-border bg-surface',
          message && !dragging && 'border-danger',
          disabled && 'cursor-not-allowed bg-surface-sunken',
        )}
      >
        {file ? (
          <div className="flex w-full max-w-lg items-center gap-3 text-left">
            <FileSpreadsheet size={28} aria-hidden="true" className="shrink-0 text-muted" />
            <div className="min-w-0 flex-1">
              <p className="break-words text-sm font-semibold text-ink">{file.name}</p>
              <p className="text-xs text-muted">{formatBytes(file.size)}</p>
            </div>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                onFile(null)
                if (input.current) input.current.value = ''
                input.current?.focus()
              }}
              disabled={disabled}
              aria-label={`Remove ${file.name}`}
            >
              <X size={18} aria-hidden="true" />
            </Button>
          </div>
        ) : (
          <>
            <Upload size={28} aria-hidden="true" className="text-muted" />
            <p className="text-sm text-ink">
              {dragging ? 'Drop the file to choose it' : 'Drag a file here, or'}
            </p>
          </>
        )}

        <label
          className={cn(
            buttonVariants('secondary', 'sm'),
            'cursor-pointer',
            // The input is visually hidden, so its keyboard focus is drawn on
            // the label that stands in for it — the same ring as everywhere.
            'has-[:focus-visible]:outline-3 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-[var(--focus)] has-[:focus-visible]:outline-solid',
            disabled &&
              'pointer-events-none border-border bg-surface-sunken text-faint shadow-none',
          )}
        >
          {file ? 'Choose a different file' : 'Choose a file'}
          <input
            ref={input}
            type="file"
            accept={accept.join(',')}
            disabled={disabled}
            aria-labelledby={`${id}-label`}
            aria-describedby={`${id}-hint`}
            aria-invalid={message ? true : undefined}
            onChange={(event) => take(event.target.files)}
            className="sr-only"
          />
        </label>
      </div>

      <p
        id={`${id}-hint`}
        role={message ? 'alert' : undefined}
        className={cn('text-xs', message ? 'font-medium text-danger' : 'text-muted')}
      >
        {message ?? hint}
      </p>
    </div>
  )
}
