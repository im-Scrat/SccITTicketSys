import { create } from 'zustand'

/**
 * Phase 2.9 — whether this tab currently holds a live subscription to the
 * signed-in user's own channel (`users.{uuid}`).
 *
 * Read by the notification badge: while the channel is live, a new
 * notification arrives as a push and the 60-second poll is unnecessary; the
 * moment it is not (Reverb down, offline, refused), polling resumes. The poll
 * is the fallback, never removed.
 */
interface RealtimeState {
  userChannelLive: boolean
  setUserChannelLive: (live: boolean) => void
}

export const useRealtimeStore = create<RealtimeState>((set) => ({
  userChannelLive: false,
  setUserChannelLive: (live) => set({ userChannelLive: live }),
}))
