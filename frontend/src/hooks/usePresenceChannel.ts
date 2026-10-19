import { useCallback, useEffect, useRef, useState } from 'react'
import { useIsPreview } from '@/contexts/PreviewModeContext'
import { getEcho } from '@/services/echo'

/** A member as the server introduces them: a public uuid and a display name — nothing else. */
export interface PresenceMember {
  id: string
  name: string
}

interface PresenceChannel {
  here: (cb: (members: PresenceMember[]) => void) => PresenceChannel
  joining: (cb: (member: PresenceMember) => void) => PresenceChannel
  leaving: (cb: (member: PresenceMember) => void) => PresenceChannel
  whisper: (event: string, data: Record<string, unknown>) => PresenceChannel
  listenForWhisper: (event: string, cb: (data: never) => void) => PresenceChannel
  error?: (cb: () => void) => PresenceChannel
}

/**
 * Join one presence channel for as long as the caller is mounted (Phase 2.11,
 * SDD DD-103) — who else is here, plus small client "whispers" between members
 * (accepted from members only, per `reverb.php`).
 *
 * Degrades like `usePrivateChannel`: no name or a preview → no socket; a
 * refused or failed join leaves `members` empty and the page fully working.
 */
export function usePresenceChannel<W extends Record<string, unknown>>(
  name: string | undefined,
  whisperEvent: string,
  onWhisper: (from: W) => void,
) {
  const [members, setMembers] = useState<PresenceMember[]>([])
  const channelRef = useRef<PresenceChannel | null>(null)
  const onWhisperRef = useRef(onWhisper)
  onWhisperRef.current = onWhisper
  const preview = useIsPreview()

  useEffect(() => {
    if (!name || preview) return

    let cancelled = false
    let joined: string | null = null

    getEcho()
      .then((echo) => {
        if (cancelled) return
        joined = name
        const channel = echo.join(name) as unknown as PresenceChannel
        channelRef.current = channel

        channel
          .here((all) => setMembers(all))
          .joining((member) =>
            setMembers((current) => [...current.filter((m) => m.id !== member.id), member]),
          )
          .leaving((member) => setMembers((current) => current.filter((m) => m.id !== member.id)))
          .listenForWhisper(whisperEvent, (data: never) => onWhisperRef.current(data as W))
        channel.error?.(() => setMembers([]))
      })
      .catch(() => setMembers([]))

    return () => {
      cancelled = true
      channelRef.current = null
      setMembers([])
      if (joined) void getEcho().then((echo) => echo.leave(joined!))
    }
  }, [name, preview, whisperEvent])

  const whisper = useCallback(
    (data: W) => {
      channelRef.current?.whisper(whisperEvent, data)
    },
    [whisperEvent],
  )

  return { members, whisper }
}
