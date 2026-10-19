import { KeyRound } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Button } from '@/components/ui/Button'
import { AuthLayout } from '@/layouts/AuthLayout'
import { ChangePasswordFields } from '../components/ChangePasswordFields'
import { useLogout } from '../hooks/useAuthMutations'

/**
 * Where an account under an administrator's "force password reset" lands
 * (SRS FR-USER admin action; enforced server-side by EnsurePasswordIsCurrent).
 *
 * Deliberately outside AppLayout: the shell mounts the bell, the realtime
 * channel, the assistant launcher and role navigation, every one of which calls
 * a `password.current` route and would only collect 403s. Only `/user`,
 * `PUT /password` and `/logout` are open to this user, and this page uses
 * exactly those.
 *
 * The page does not navigate on success. A successful change re-reads the
 * principal (useChangePassword), the flag is gone, and ProtectedRoute moves the
 * user on to the page they originally asked for — one owner for that decision.
 */
export default function ForcePasswordChangePage() {
  useDocumentMeta({ title: 'Change your password' })

  const navigate = useNavigate()
  const logout = useLogout()

  const signOut = async () => {
    await logout.mutateAsync().catch(() => undefined)
    navigate('/sign-in', { replace: true })
  }

  return (
    <AuthLayout
      icon={<KeyRound className="size-5" aria-hidden />}
      title="Change your password"
      subtitle="An administrator requires you to set a new password before you continue. Your other active sessions will be signed out."
      footer={
        <Button variant="ghost" onClick={() => void signOut()} loading={logout.isPending}>
          Sign out instead
        </Button>
      }
    >
      <ChangePasswordFields submitLabel="Set new password and continue" fullWidth />
    </AuthLayout>
  )
}
