import { isAxiosError } from 'axios'
import { ArrowLeft, History, MessageSquarePlus, Send, Trash2, X } from 'lucide-react'
import { type FormEvent, type KeyboardEvent, useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Button, Spinner, Textarea } from '@/components/ui'
import { formatRelative } from '@/lib/datetime'
import {
  useAssistantConversation,
  useAssistantConversations,
  useDeleteAssistantConversation,
  useSendAssistantMessage,
} from '../hooks/assistant'
import type { AssistantCitation, AssistantMessage } from '../types'

interface AssistantPanelProps {
  onClose: () => void
}

/** The server's own sentence for a refusal; it never carries provider detail. */
function errorText(error: unknown): string {
  if (isAxiosError(error)) {
    const message = (error.response?.data as { message?: string } | undefined)?.message
    if (message) return message
    if (error.response?.status === 429)
      return 'The assistant is busy right now. Please try again in a moment.'
  }
  return 'The assistant could not answer just now. You can still report the problem as a ticket.'
}

/**
 * Phase 2.9 — the AI assistant, as a non-modal panel anchored bottom-left.
 *
 * **Non-modal on purpose:** a person asks a question *while* looking at a
 * ticket or a page, so the page stays usable behind it. Escape closes it and
 * focus returns to the launcher.
 *
 * **Advice, never action.** The assistant has no tools: it cannot change a
 * ticket, assign anyone or mark anything fixed. What it may draw on is decided
 * on the server by role — a teacher's own tickets, a technician's assigned
 * ones, published knowledge — so this panel shows whatever the server sends
 * and nothing else. Answers are labelled AI-generated.
 *
 * No streaming (the backend answers whole); a status line says it is working.
 */
export function AssistantPanel({ onClose }: AssistantPanelProps) {
  const headingId = useId()
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const scrollRef = useRef<HTMLDivElement>(null)

  const [view, setView] = useState<'chat' | 'history'>('chat')
  const [conversationId, setConversationId] = useState<string | null>(null)
  const [draft, setDraft] = useState('')
  // The question in flight, shown immediately rather than after the round trip.
  const [pendingQuestion, setPendingQuestion] = useState<string | null>(null)

  const conversation = useAssistantConversation(conversationId)
  const conversations = useAssistantConversations(view === 'history')
  const send = useSendAssistantMessage()
  const remove = useDeleteAssistantConversation()

  const messages: AssistantMessage[] = conversation.data?.messages ?? []

  useEffect(() => {
    if (view === 'chat') inputRef.current?.focus()
  }, [view])

  // Keep the newest turn in view.
  useEffect(() => {
    const element = scrollRef.current
    if (element) element.scrollTop = element.scrollHeight
  }, [messages.length, pendingQuestion])

  function submit(event?: FormEvent) {
    event?.preventDefault()
    const message = draft.trim()
    if (message.length < 2 || send.isPending) return

    setPendingQuestion(message)
    setDraft('')
    send.mutate(
      { message, conversation_id: conversationId },
      {
        onSuccess: (reply) => setConversationId(reply.conversation_id),
        onError: () => setDraft(message),
        onSettled: () => setPendingQuestion(null),
      },
    )
  }

  function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    // Enter sends; Shift+Enter keeps the newline people expect.
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault()
      submit()
    }
  }

  function startNew() {
    setConversationId(null)
    send.reset()
    setView('chat')
  }

  return (
    <section
      role="dialog"
      aria-modal="false"
      aria-labelledby={headingId}
      onKeyDown={(event) => {
        if (event.key === 'Escape') onClose()
      }}
      className="fixed bottom-24 left-4 z-30 flex h-[min(38rem,calc(100dvh-8rem))] w-[min(28rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-[var(--shadow-overlay-md)]"
    >
      <header className="flex items-center gap-2 border-b border-border px-4 py-3">
        {view === 'history' ? (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setView('chat')}
            aria-label="Back to the conversation"
          >
            <ArrowLeft size={18} aria-hidden="true" />
          </Button>
        ) : null}
        <div className="min-w-0 flex-1">
          <h2 id={headingId} className="text-base font-semibold text-ink-strong">
            {view === 'history' ? 'Past conversations' : 'AI assistant'}
          </h2>
          {view === 'chat' && (
            <p className="text-xs text-muted">
              Answers are AI-generated advice. It cannot change anything.
            </p>
          )}
        </div>
        {view === 'chat' && (
          <>
            <Button variant="ghost" size="sm" onClick={startNew} aria-label="New conversation">
              <MessageSquarePlus size={18} aria-hidden="true" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setView('history')}
              aria-label="Past conversations"
            >
              <History size={18} aria-hidden="true" />
            </Button>
          </>
        )}
        <Button variant="ghost" size="sm" onClick={onClose} aria-label="Close the assistant">
          <X size={18} aria-hidden="true" />
        </Button>
      </header>

      {view === 'history' ? (
        <div className="flex-1 overflow-y-auto p-2">
          {conversations.isLoading ? (
            <div className="flex justify-center p-6">
              <Spinner label="Loading conversations" />
            </div>
          ) : (conversations.data ?? []).length === 0 ? (
            <p className="p-4 text-sm text-muted">No past conversations.</p>
          ) : (
            <ul className="flex flex-col divide-y divide-border">
              {(conversations.data ?? []).map((item) => (
                <li key={item.id} className="flex items-center gap-2 px-2 py-2">
                  <button
                    type="button"
                    className="min-w-0 flex-1 rounded-md px-2 py-2 text-left hover:bg-surface-sunken"
                    onClick={() => {
                      setConversationId(item.id)
                      setView('chat')
                    }}
                  >
                    <span className="block truncate text-sm font-semibold text-ink-strong">
                      {item.title || 'Untitled conversation'}
                    </span>
                    <span className="block text-xs text-muted">
                      {item.last_at ? formatRelative(item.last_at) : ''}
                    </span>
                  </button>
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Delete “${item.title || 'Untitled conversation'}”`}
                    loading={remove.isPending && remove.variables === item.id}
                    onClick={() =>
                      remove.mutate(item.id, {
                        onSuccess: () => {
                          if (conversationId === item.id) setConversationId(null)
                        },
                      })
                    }
                  >
                    <Trash2 size={18} aria-hidden="true" />
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : (
        <>
          <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-4">
            {messages.length === 0 && !pendingQuestion && !conversation.isLoading && (
              <div className="text-sm text-muted">
                <p>Ask about a problem you are having, or about one of your tickets.</p>
                <p className="mt-2">
                  For example: “The projector says no signal — what should I check?”
                </p>
              </div>
            )}

            <ol className="flex flex-col gap-4" aria-live="polite" aria-relevant="additions">
              {messages.map((message, index) => (
                <Turn key={`${message.at ?? ''}-${index}`} message={message} />
              ))}
              {pendingQuestion && (
                <>
                  <Turn
                    message={{ sender: 'user', text: pendingQuestion, citations: [], at: null }}
                  />
                  <li className="flex items-center gap-2 text-sm text-muted" role="status">
                    <Spinner size={16} />
                    Thinking…
                  </li>
                </>
              )}
            </ol>

            {send.isError && (
              <div className="mt-4">
                <Alert tone="warning">{errorText(send.error)}</Alert>
              </div>
            )}
          </div>

          <form onSubmit={submit} className="flex items-end gap-2 border-t border-border p-3">
            <label htmlFor={`${headingId}-input`} className="sr-only">
              Your question
            </label>
            <Textarea
              id={`${headingId}-input`}
              ref={inputRef}
              rows={2}
              maxLength={2000}
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={onKeyDown}
              placeholder="Ask a question…"
              className="min-h-0 flex-1 resize-none"
            />
            <Button
              type="submit"
              size="sm"
              loading={send.isPending}
              disabled={draft.trim().length < 2}
              aria-label="Send"
            >
              <Send size={18} aria-hidden="true" />
            </Button>
          </form>
        </>
      )}
    </section>
  )
}

function Turn({ message }: { message: AssistantMessage }) {
  const mine = message.sender === 'user'

  return (
    <li className={mine ? 'flex justify-end' : 'flex flex-col gap-2'}>
      <div
        className={
          mine
            ? 'max-w-[85%] rounded-lg bg-primary-subtle px-3 py-2 text-sm text-ink-strong'
            : 'text-sm text-ink'
        }
      >
        {!mine && <span className="sr-only">Assistant: </span>}
        <p className="whitespace-pre-line">{message.text}</p>
      </div>
      {!mine && message.citations.length > 0 && <Citations citations={message.citations} />}
    </li>
  )
}

function Citations({ citations }: { citations: AssistantCitation[] }) {
  return (
    <ul className="flex flex-wrap gap-2" aria-label="Sources">
      {citations.map((citation) => (
        <li key={`${citation.type}-${citation.uuid}`}>
          {citation.type === 'ticket' ? (
            <Link
              to={citation.url}
              className="inline-flex max-w-full items-center gap-1 rounded-full border border-border px-2.5 py-1 text-xs text-ink hover:bg-surface-sunken"
            >
              <span className="font-semibold tnum">{citation.label}</span>
              <span className="truncate">{citation.title}</span>
            </Link>
          ) : (
            // Knowledge articles have no reader page in this app yet, so the
            // source is named rather than linked to a page that does not exist.
            <span className="inline-flex max-w-full items-center gap-1 rounded-full border border-border px-2.5 py-1 text-xs text-muted">
              {citation.ref !== null && <span className="font-semibold">[{citation.ref}]</span>}
              <span className="truncate">{citation.title}</span>
            </span>
          )}
        </li>
      ))}
    </ul>
  )
}
