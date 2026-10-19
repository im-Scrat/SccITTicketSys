/** GET /api/admin/system/mail — configuration, never credentials. */
export interface MailConfiguration {
  mailer: string
  transport: string
  host: string | null
  port: number | null
  scheme: string | null
  require_tls: boolean
  timeout_seconds: number | null
  ehlo_domain: string | null
  username_set: boolean
  password_set: boolean
  from_address: string
  from_name: string
  redirect: 'none' | 'active' | 'ignored_in_production'
  /** Masked: first letter and domain only. */
  redirect_to: string | null
  frontend_url: string
  reports_deliver_externally: boolean
  warnings: string[]
}

/** POST /api/admin/system/mail/check — connect, TLS, authenticate; nothing is sent. */
export interface MailConnectionCheck {
  ok: boolean
  outcome: string
  smtp_code: number | null
}

/** POST /api/admin/system/mail/test — one fixed message to the caller. */
export interface MailTestResult extends MailConnectionCheck {
  message_id: string | null
  sent_to: string
}
