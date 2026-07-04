import { MailCheck } from 'lucide-react'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ButtonLink } from '@/components/ui/Button'
import { AuthLayout } from '@/layouts/AuthLayout'

export default function RegistrationSubmittedPage() {
  useDocumentMeta({ title: 'Request submitted' })

  return (
    <AuthLayout
      icon={<MailCheck size={22} aria-hidden="true" />}
      title="Request submitted"
      subtitle="Thanks — your registration request has been received and is awaiting administrator approval. You’ll get an email once it’s reviewed, and you can sign in as soon as your account is activated."
    >
      <ButtonLink to="/sign-in" variant="secondary" size="lg" className="w-full">
        Back to sign in
      </ButtonLink>
    </AuthLayout>
  )
}
