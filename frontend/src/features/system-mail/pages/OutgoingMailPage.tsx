import { PlugZap, Send } from 'lucide-react'
import { Alert, Badge, Button, Skeleton, Surface } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { useCheckMailConnection, useMailConfiguration, useSendMailTest } from '../hooks/queries'
import type { MailConfiguration } from '../types'

/**
 * Outgoing email — the administrator's view of the mail relay (2026-10-20).
 *
 * The in-app twin of `php artisan mail:check`, for hosts with no shell. It
 * shows how mail leaves the system without ever showing a credential, can
 * test the connection without sending, and can send one fixed message — only
 * to the signed-in administrator. "Accepted" is the furthest the application
 * can see: delivery, bounces and spam placement live in the provider's log.
 */
export default function OutgoingMailPage() {
  useDocumentMeta({ title: 'Outgoing email' })
  const configuration = useMailConfiguration()

  return (
    <div className="flex flex-col gap-6">
      <header className="measure">
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
          Outgoing email
        </h1>
        <p className="mt-1 text-sm text-muted">
          How notifications, password resets and emailed reports leave the system. The relay
          username and password are server secrets and are never shown here.
        </p>
      </header>

      {configuration.isError ? (
        <Alert tone="error">
          The mail configuration could not be loaded. Refresh the page to retry.
        </Alert>
      ) : configuration.isLoading || !configuration.data ? (
        <Skeleton className="h-80" />
      ) : (
        <>
          <RelayStatus config={configuration.data} />
          <DeliveryNote />
        </>
      )}
    </div>
  )
}

const OUTCOMES: Record<string, string> = {
  ok: 'Connected. No message was sent.',
  accepted: 'The relay accepted the message.',
  not_smtp: 'Mail is not using an SMTP relay here, so there is nothing to connect to.',
  smtp_auth_failed: 'The relay refused the username or password.',
  smtp_rejected: 'The relay refused the message permanently.',
  smtp_deferred: 'The relay asked to try again later (rate limit or busy). Queued mail retries.',
  tls_unavailable: 'An encrypted connection could not be established, so nothing was sent.',
  connection_failed: 'The relay could not be reached (host, port or network).',
  transport_error: 'The connection failed before the relay answered.',
}

function outcomeText(outcome: string, smtpCode: number | null): string {
  const text = OUTCOMES[outcome] ?? outcome
  return smtpCode === null ? text : `${text} (SMTP ${smtpCode})`
}

function RelayStatus({ config }: { config: MailConfiguration }) {
  const check = useCheckMailConnection()
  const test = useSendMailTest()
  const isSmtp = config.transport === 'smtp'
  const encrypted = config.require_tls || config.scheme === 'smtps'

  return (
    <Surface className="flex flex-col gap-4 p-5">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="text-base font-semibold text-ink-strong">Relay</h2>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <Badge tone={isSmtp ? 'neutral' : 'warning'}>
              {isSmtp
                ? `${config.host ?? '—'}:${config.port ?? '—'}`
                : `Transport: ${config.transport}`}
            </Badge>
            {isSmtp && (
              <Badge tone={encrypted ? 'success' : 'warning'}>
                {encrypted ? 'Encryption required' : 'Encryption not required'}
              </Badge>
            )}
            {isSmtp && (
              <Badge tone={config.username_set && config.password_set ? 'success' : 'neutral'}>
                {config.username_set && config.password_set
                  ? 'Credentials present on the server'
                  : 'No credentials configured'}
              </Badge>
            )}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            leftIcon={<PlugZap size={20} aria-hidden="true" />}
            loading={check.isPending}
            onClick={() => check.mutate()}
          >
            Test the connection
          </Button>
          <Button
            variant="secondary"
            leftIcon={<Send size={20} aria-hidden="true" />}
            loading={test.isPending}
            onClick={() => test.mutate()}
          >
            Send a test email to me
          </Button>
        </div>
      </div>

      {/* Always rendered: a live region that appears with its content is not
          reliably announced. */}
      <div aria-live="polite" className="flex flex-col gap-3">
        {check.isError && <Alert tone="error">{getErrorMessage(check.error)}</Alert>}
        {check.data && (
          <Alert tone={check.data.ok ? 'success' : 'warning'} title="Connection test">
            {check.data.ok && check.data.summary
              ? check.data.summary
              : outcomeText(check.data.outcome, check.data.smtp_code)}
          </Alert>
        )}
        {test.isError && <Alert tone="error">{getErrorMessage(test.error)}</Alert>}
        {test.data && (
          <Alert
            tone={test.data.ok ? 'success' : 'warning'}
            title={`Test email to ${test.data.sent_to}`}
          >
            {outcomeText(test.data.outcome, test.data.smtp_code)}
            {test.data.ok &&
              ' Accepted is not delivered: check your inbox, and the provider’s activity log if it does not arrive.'}
          </Alert>
        )}
      </div>

      {config.redirect === 'active' && (
        <Alert tone="warning" title="Test redirect is on">
          Every message goes to {config.redirect_to}; nobody else receives mail. Use this only in a
          test environment.
        </Alert>
      )}
      {config.redirect === 'ignored_in_production' && (
        <Alert tone="error" title="Test redirect is set but ignored">
          MAIL_TEST_REDIRECT_TO has no effect in production, so recipients still receive their mail.
          Remove the variable.
        </Alert>
      )}

      {config.warnings.length > 0 && (
        <Alert tone="warning" title="Check the configuration">
          <ul className="list-disc space-y-1 pl-5">
            {config.warnings.map((warning) => (
              <li key={warning}>{warning}</li>
            ))}
          </ul>
        </Alert>
      )}

      <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <dt className="text-muted">Sender</dt>
          <dd className="mt-0.5 break-words text-ink-strong">
            {config.from_name} &lt;{config.from_address}&gt;
          </dd>
        </div>
        <div>
          <dt className="text-muted">Links in emails open</dt>
          <dd className="mt-0.5 break-words text-ink-strong">{config.frontend_url || '—'}</dd>
        </div>
        <div>
          <dt className="text-muted">Reports to outside addresses</dt>
          <dd className="mt-0.5 text-ink-strong">
            {/* A configuration switch, not a delivery result: "Delivered" here
                read as proof of arrival while outside inboxes were binning the mail. */}
            {config.reports_deliver_externally
              ? 'Handed to the relay (see “What sent means”)'
              : 'Kept in a test sink'}
          </dd>
        </div>
        <div>
          <dt className="text-muted">Relay timeout</dt>
          <dd className="mt-0.5 text-ink-strong tnum">
            {config.timeout_seconds === null ? 'Default' : `${config.timeout_seconds} s`}
          </dd>
        </div>
      </dl>
    </Surface>
  )
}

function DeliveryNote() {
  return (
    <section className="measure text-sm text-muted">
      <h2 className="text-base font-semibold text-ink-strong">What “sent” means</h2>
      <p className="mt-1">
        The system records a message as sent when the relay accepts it. Whether it then reached the
        inbox, bounced, or was filed as spam is decided by the recipient’s mail server and is shown
        only in the provider’s activity log.
      </p>
      <p className="mt-2">
        If a test email to yourself arrives but mail to outside addresses does not, the sender is
        usually not authenticated for its domain: verify a sender domain you control with the relay
        (SPF and DKIM), and send from an address on it rather than a personal mailbox.
      </p>
    </section>
  )
}
