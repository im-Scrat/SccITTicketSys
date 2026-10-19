import { beforeEach, describe, expect, it, vi } from 'vitest'

type EchoStub = { options: Record<string, unknown>; disconnect: ReturnType<typeof vi.fn> }

const { get, post, instances } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  instances: [] as EchoStub[],
}))

vi.mock('@/services/api', () => ({ api: { get, post } }))
vi.mock('pusher-js', () => ({ default: class PusherStub {} }))
vi.mock('laravel-echo', () => ({
  default: class EchoMock {
    options: Record<string, unknown>
    disconnect = vi.fn()
    constructor(options: Record<string, unknown>) {
      this.options = options
      instances.push(this)
    }
  },
}))

/**
 * The real-time client (WP-A).
 *
 * Two properties matter more than the rest, and both fail silently:
 *
 *  1. **Same origin, key at runtime.** The socket must dial this page's own
 *     host through /reverb — Reverb has no public port — with a key fetched
 *     from the server, never one baked into the bundle.
 *  2. **The socket dies with the sign-in.** Private channels are authorized
 *     once, at subscribe time. A socket that survived a sign-out would keep
 *     delivering the previous user's channels to whoever signs in next in the
 *     same tab — nothing on screen would look wrong.
 *
 * Each test re-imports the module so its connection state starts empty.
 */
async function load() {
  vi.resetModules()
  const echo = await import('./echo')
  const { queryClient } = await import('@/services/queryClient')
  return { ...echo, queryClient }
}

const ADMIN = { id: 'uuid-admin' }

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  instances.length = 0
  get.mockResolvedValue({ data: { key: 'public-key' } })
})

describe('connection', () => {
  it('dials the page’s own origin through /reverb with the key from the server', async () => {
    const { getEcho, queryClient } = await load()
    queryClient.setQueryData(['auth', 'user'], ADMIN)

    await getEcho()

    expect(get).toHaveBeenCalledWith('/broadcasting/config')
    expect(instances[0].options).toMatchObject({
      broadcaster: 'reverb',
      key: 'public-key',
      wsHost: window.location.hostname,
      wsPort: Number(window.location.port) || 80,
      wsPath: '/reverb',
      forceTLS: false,
      enabledTransports: ['ws', 'wss'],
      withoutInterceptors: true,
    })
  })

  it('opens one socket however many callers ask for it', async () => {
    const { getEcho } = await load()

    const [first, second] = await Promise.all([getEcho(), getEcho()])

    expect(first).toBe(second)
    expect(instances).toHaveLength(1)
    expect(get).toHaveBeenCalledTimes(1)
  })
})

describe('channel authorization', () => {
  function handler() {
    const auth = instances[0].options.channelAuthorization as {
      customHandler: (
        params: { socketId: string; channelName: string },
        callback: (error: Error | null, data: unknown) => void,
      ) => void
    }
    return auth.customHandler
  }

  it('goes through the app’s HTTP client to /broadcasting/auth, outside /api', async () => {
    const { getEcho } = await load()
    await getEcho()
    post.mockResolvedValue({ data: { auth: 'public-key:signature' } })
    const callback = vi.fn()

    handler()({ socketId: '1.2', channelName: 'private-floor' }, callback)

    await vi.waitFor(() => expect(callback).toHaveBeenCalled())
    expect(post).toHaveBeenCalledWith(
      '/broadcasting/auth',
      { socket_id: '1.2', channel_name: 'private-floor' },
      { baseURL: '' },
    )
    expect(callback).toHaveBeenCalledWith(null, { auth: 'public-key:signature' })
  })

  it('hands a refusal back to the socket as an error, not a signature', async () => {
    const { getEcho } = await load()
    await getEcho()
    const refusal = new Error('Request failed with status code 403')
    post.mockRejectedValue(refusal)
    const callback = vi.fn()

    handler()({ socketId: '1.2', channelName: 'private-floor' }, callback)

    await vi.waitFor(() => expect(callback).toHaveBeenCalledWith(refusal, null))
  })
})

describe('teardown follows the principal', () => {
  it('closes the socket when the user signs out', async () => {
    const { getEcho, queryClient } = await load()
    queryClient.setQueryData(['auth', 'user'], ADMIN)
    await getEcho()

    queryClient.setQueryData(['auth', 'user'], null)

    expect(instances[0].disconnect).toHaveBeenCalledTimes(1)
  })

  it('closes the socket when a different user becomes the principal', async () => {
    const { getEcho, queryClient } = await load()
    queryClient.setQueryData(['auth', 'user'], ADMIN)
    await getEcho()

    queryClient.setQueryData(['auth', 'user'], { id: 'uuid-someone-else' })

    expect(instances[0].disconnect).toHaveBeenCalledTimes(1)
  })

  it('keeps the socket when the same user is merely refetched', async () => {
    const { getEcho, queryClient } = await load()
    queryClient.setQueryData(['auth', 'user'], ADMIN)
    await getEcho()

    queryClient.setQueryData(['auth', 'user'], { ...ADMIN })

    expect(instances[0].disconnect).not.toHaveBeenCalled()
  })

  it('opens a fresh socket for the next sign-in rather than reusing the old one', async () => {
    const { getEcho, queryClient } = await load()
    queryClient.setQueryData(['auth', 'user'], ADMIN)
    const first = await getEcho()
    queryClient.setQueryData(['auth', 'user'], null)

    queryClient.setQueryData(['auth', 'user'], ADMIN)
    const second = await getEcho()

    expect(second).not.toBe(first)
    expect(instances).toHaveLength(2)
  })

  it('discards a socket whose connect finished after the user signed out', async () => {
    const { getEcho, queryClient } = await load()
    queryClient.setQueryData(['auth', 'user'], ADMIN)
    let release: (value: unknown) => void = () => {}
    get.mockReturnValue(new Promise((resolve) => (release = resolve)))

    const connecting = getEcho()
    queryClient.setQueryData(['auth', 'user'], null)
    release({ data: { key: 'public-key' } })

    await expect(connecting).rejects.toThrow(/superseded/)
    expect(instances[0].disconnect).toHaveBeenCalledTimes(1)
  })
})
