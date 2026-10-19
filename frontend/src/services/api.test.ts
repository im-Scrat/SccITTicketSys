import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, describe, expect, it } from 'vitest'
import type { AuthUser } from '@/features/auth/types'
import { api } from './api'
import { queryClient } from './queryClient'

/**
 * The response interceptor's forced-reset branch: an administrator can set the
 * flag after this tab cached `/user`, so the first refused feature request has
 * to be enough for ProtectedRoute to move the user to the change screen.
 */

const KEY = ['auth', 'user']

function refuse(status: number, data: unknown) {
  const original = api.defaults.adapter
  api.defaults.adapter = (config: InternalAxiosRequestConfig) => {
    const response = { status, data, headers: {}, config, statusText: '' } as AxiosResponse

    return Promise.reject(new AxiosError('refused', String(status), config, null, response))
  }

  return () => {
    api.defaults.adapter = original
  }
}

let restore: (() => void) | null = null

afterEach(() => {
  restore?.()
  queryClient.clear()
})

describe('api interceptor — password_reset_required', () => {
  it('marks the cached principal as forced on that 403 code', async () => {
    queryClient.setQueryData(KEY, { force_password_reset: false } as AuthUser)
    restore = refuse(403, { code: 'password_reset_required', message: 'x' })

    await expect(api.get('/tickets/assigned')).rejects.toBeInstanceOf(AxiosError)

    expect(queryClient.getQueryData<AuthUser>(KEY)?.force_password_reset).toBe(true)
  })

  it('leaves the principal alone on an ordinary 403', async () => {
    queryClient.setQueryData(KEY, { force_password_reset: false } as AuthUser)
    restore = refuse(403, { message: 'This action is unauthorized.' })

    await expect(api.get('/admin/users')).rejects.toBeInstanceOf(AxiosError)

    expect(queryClient.getQueryData<AuthUser>(KEY)?.force_password_reset).toBe(false)
  })
})
