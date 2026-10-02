/** WP-Q assistant API (`/api/ai/assistant/*`), consumed by the Phase 2.9 UI. */
export interface AssistantCitation {
  ref: number | null
  type: 'knowledge_article' | 'ticket' | string
  title: string
  label: string
  uuid: string
  url: string
}

export interface AssistantReply {
  conversation_id: string
  answer: string
  citations: AssistantCitation[]
  ai_generated: true
  advisory: true
}

export interface AssistantMessage {
  sender: 'user' | 'assistant' | 'system'
  text: string
  citations: AssistantCitation[]
  at: string | null
}

export interface AssistantConversationSummary {
  id: string
  title: string
  turns: number
  last_at: string | null
}

export interface AssistantConversation {
  id: string
  messages: AssistantMessage[]
}
