/** `GET /api/admin/ai/settings` (WP-O; Phase 2.9 adds `priority_auto_raise`). */
export interface AiModelRow {
  id: string
  name: string
  provider: string
  model_identifier: string
  modality: 'text' | 'embedding' | string
  embedding_dimensions: number | null
  is_active: boolean
}

export interface AiSettingsValues {
  active_model: string | null
  embedding_model: string | null
  confidence_threshold: number
  enable_predictions: boolean
  enable_learning: boolean
  auto_generate_articles: boolean
  assistant_enabled: boolean
  priority_auto_raise: boolean
}

export interface AiSettingsPayload {
  ai_enabled: boolean
  provider_configured: boolean
  credential: {
    source: string | null
    path: string | null
    file_exists: boolean | null
    file_readable: boolean | null
    file_empty: boolean | null
  }
  settings: AiSettingsValues
  models: AiModelRow[]
  index: { indexed: number; waiting: number; failed: number }
  quality: { analyses: number; feedback_helpful: number; feedback_total: number }
  data_sent_to_provider: string[]
  data_never_sent: string[]
}

export interface AiConnectionCheck {
  ok: boolean
  message: string
  chat: { ok: boolean; model?: string; latency_ms?: number; message?: string } | null
  embedding: { ok: boolean; dimensions?: number; message?: string } | null
}
