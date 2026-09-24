import { Megaphone, Pin } from 'lucide-react'
import { useState } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { Skeleton } from '@/components/ui/Skeleton'
import { Surface } from '@/components/ui/Surface'
import { Tabs } from '@/components/ui/Tabs'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDateTime } from '@/lib/datetime'
import { AnnouncementForm } from '../components/AnnouncementForm'
import {
  useCreateAnnouncement,
  useDeleteAnnouncement,
  useNotifyAgain,
  usePublishAnnouncement,
  useUnpublishAnnouncement,
  useUpdateAnnouncement,
} from '../hooks/mutations'
import { useManagedAnnouncements } from '../hooks/queries'
import type { AnnouncementForm as FormValues } from '../schemas'
import type { Announcement } from '../types'

const AUDIENCE_LABELS: Record<string, string> = {
  all: 'Everyone',
  teachers: 'Teachers',
  technicians: 'Technicians',
  admins: 'Administrators',
}

/** Empty date inputs mean "no bound" and must be sent as null, not "". */
function normalize(values: FormValues) {
  return {
    ...values,
    starts_at: values.starts_at ? new Date(values.starts_at).toISOString() : null,
    ends_at: values.ends_at ? new Date(values.ends_at).toISOString() : null,
  }
}

/**
 * Announcement management (SRS FR-NOT-010, UC-13).
 *
 * Administrator-only, behind `system.announcements.manage` — the permission the
 * Client's §8.4 matrix already seeded, not a new one (decision D5).
 *
 * ── Publishing is the only thing that notifies ────────────────────────────
 *
 * The list separates the two acts deliberately. **Save** changes the text and
 * tells nobody; **Publish** makes it live and notifies the audience; **Notify
 * again** repeats that on purpose, and is confirmed because it is the one
 * button here that can put a message in front of the whole school twice
 * (decision D7).
 */
export default function AnnouncementManagementPage() {
  useDocumentMeta({ title: 'Announcement management' })

  const [tab, setTab] = useState<'all' | 'published' | 'drafts'>('all')
  const [page, setPage] = useState(1)
  const [composing, setComposing] = useState(false)
  const [editing, setEditing] = useState<Announcement | null>(null)
  const [confirmNotify, setConfirmNotify] = useState<Announcement | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<Announcement | null>(null)

  const active = tab === 'all' ? null : tab === 'published'
  const announcements = useManagedAnnouncements({ active, page })

  const create = useCreateAnnouncement()
  const update = useUpdateAnnouncement()
  const publish = usePublishAnnouncement()
  const unpublish = useUnpublishAnnouncement()
  const notify = useNotifyAgain()
  const remove = useDeleteAnnouncement()

  const rows = announcements.data?.data ?? []
  const meta = announcements.data?.meta

  const changeTab = (next: string) => {
    setTab(next as 'all' | 'published' | 'drafts')
    setPage(1)
  }

  const submitNew = async (values: FormValues) => {
    await create.mutateAsync(normalize(values))
    setComposing(false)
  }

  const submitEdit = async (values: FormValues) => {
    if (!editing) return
    await update.mutateAsync({ id: editing.id, ...normalize(values) })
    setEditing(null)
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
            Announcement management
          </h1>
          <p className="mt-1 text-sm text-muted">
            Compose notices, choose who they reach, and decide when the audience is told.
          </p>
        </div>

        {!composing && !editing && (
          <Button variant="primary" size="sm" onClick={() => setComposing(true)}>
            New announcement
          </Button>
        )}
      </div>

      {(composing || editing) && (
        <Surface className="p-5">
          <h2 className="text-sm font-semibold text-ink-strong">
            {editing ? 'Edit announcement' : 'New announcement'}
          </h2>
          <div className="mt-4">
            <AnnouncementForm
              announcement={editing ?? undefined}
              onSubmit={editing ? submitEdit : submitNew}
              onCancel={() => {
                setComposing(false)
                setEditing(null)
              }}
              submitting={create.isPending || update.isPending}
            />
          </div>
        </Surface>
      )}

      <Tabs
        tabs={[
          { value: 'all', label: 'All' },
          { value: 'published', label: 'Published' },
          { value: 'drafts', label: 'Drafts' },
        ]}
        value={tab}
        onChange={changeTab}
      />

      {announcements.isError && (
        <Alert tone="error" title="Announcements could not be loaded">
          <p>Nothing has been lost — this is a display problem.</p>
        </Alert>
      )}

      {announcements.isPending && (
        <div className="flex flex-col gap-3" role="status" aria-label="Loading announcements">
          {Array.from({ length: 3 }).map((_, index) => (
            <Surface key={index} className="p-4">
              <Skeleton height={12} width="40%" />
              <Skeleton height={10} width="70%" className="mt-2" />
            </Surface>
          ))}
        </div>
      )}

      {!announcements.isPending && !announcements.isError && rows.length === 0 && (
        <EmptyState
          icon={<Megaphone size={22} />}
          title="No announcements yet"
          description="Create one to tell a group of people something. It stays a draft until you publish it."
          action={
            <Button variant="primary" size="sm" onClick={() => setComposing(true)}>
              New announcement
            </Button>
          }
        />
      )}

      {rows.length > 0 && (
        <ul className="flex flex-col gap-3">
          {rows.map((announcement) => (
            <li key={announcement.id}>
              <Surface className="p-4">
                <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-ink-strong">{announcement.title}</p>
                    <p className="mt-1 line-clamp-2 max-w-[70ch] text-sm text-muted">
                      {announcement.content}
                    </p>
                  </div>

                  <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {announcement.is_pinned && (
                      <Badge tone="primary" icon={<Pin size={13} aria-hidden="true" />}>
                        Pinned
                      </Badge>
                    )}
                    <Badge tone="neutral">
                      {AUDIENCE_LABELS[announcement.audience] ?? announcement.audience}
                    </Badge>
                    {/* State in words, never colour alone (NFR-ACC-004). */}
                    <Badge tone={announcement.is_active ? 'success' : 'outline'}>
                      {announcement.is_active ? 'Published' : 'Draft'}
                    </Badge>
                  </div>
                </div>

                <p className="mt-2 text-xs text-muted">
                  Created {formatDateTime(announcement.created_at)}
                  {announcement.created_by ? ` by ${announcement.created_by.name}` : ''}
                </p>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => {
                      setEditing(announcement)
                      setComposing(false)
                    }}
                  >
                    Edit
                  </Button>

                  {announcement.is_active ? (
                    <>
                      <Button
                        variant="secondary"
                        size="sm"
                        loading={unpublish.isPending}
                        onClick={() => unpublish.mutate(announcement.id)}
                      >
                        Unpublish
                      </Button>
                      <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => setConfirmNotify(announcement)}
                      >
                        Notify again
                      </Button>
                    </>
                  ) : (
                    <Button
                      variant="primary"
                      size="sm"
                      loading={publish.isPending}
                      onClick={() => publish.mutate(announcement.id)}
                    >
                      Publish
                    </Button>
                  )}

                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => setConfirmDelete(announcement)}
                  >
                    Delete
                  </Button>
                </div>
              </Surface>
            </li>
          ))}
        </ul>
      )}

      {meta && rows.length > 0 && (
        <Surface className="overflow-hidden">
          <Pagination
            page={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            from={meta.from}
            to={meta.to}
            onPage={setPage}
          />
        </Surface>
      )}

      {/*
        Confirmed because it is the one action here that can put the same
        message in front of an entire audience a second time (decision D7).
      */}
      <ConfirmDialog
        open={confirmNotify !== null}
        onClose={() => setConfirmNotify(null)}
        onConfirm={() => {
          if (confirmNotify) notify.mutate(confirmNotify.id)
          setConfirmNotify(null)
        }}
        title="Notify the audience again?"
        description={`Everyone in the "${
          AUDIENCE_LABELS[confirmNotify?.audience ?? 'all'] ?? confirmNotify?.audience
        }" audience receives a second notification about this announcement. No email is sent.`}
        confirmLabel="Notify again"
        loading={notify.isPending}
      />

      <ConfirmDialog
        open={confirmDelete !== null}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => {
          if (confirmDelete) remove.mutate(confirmDelete.id)
          setConfirmDelete(null)
        }}
        title="Delete this announcement?"
        description="It disappears from every reader's list. Notifications already sent about it are not withdrawn."
        confirmLabel="Delete announcement"
        tone="danger"
        loading={remove.isPending}
      />
    </div>
  )
}
