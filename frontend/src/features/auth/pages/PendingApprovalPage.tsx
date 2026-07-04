import { Clock } from 'lucide-react'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ButtonLink } from '@/components/ui/Button'
import { AuthLayout } from '@/layouts/AuthLayout'

export default function PendingApprovalPage() {
  useDocumentMeta({ title: 'Awaiting approval' })

  return (
    <AuthLayout
      icon={<Clock size={22} aria-hidden="true" />}
      title="Awaiting administrator approval"
      subtitle="Your credentials are correct, but your account hasn’t been approved yet. An administrator needs to review your registration before you can sign in. You’ll receive an email once your account is activated."
    >
      <ButtonLink to="/sign-in" variant="secondary" size="lg" className="w-full">
        Back to sign in
      </ButtonLink>
    </AuthLayout>
  )
}
