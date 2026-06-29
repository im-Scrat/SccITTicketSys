/** Shared application types. Domain-specific types live under each feature. */

export interface HealthResponse {
  status: 'healthy' | 'degraded' | string
  checks: Record<string, string>
  laravel: string
}
