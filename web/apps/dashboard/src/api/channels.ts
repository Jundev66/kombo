import { api } from '@kombo/api-client'

/**
 * The tenant's channels.
 *
 * The token never comes back — not masked, not the last four digits. The screen
 * knows whether one is set and when it was last used; to change it you paste
 * another. That token writes to every customer in the tenant's name.
 */

export interface Channel {
  channel: 'whatsapp' | 'telegram'
  connected: boolean
  isActive: boolean
  label: string | null
  externalId: string | null
  lastMessageAt: string | null
  /** The address to paste into Meta's console or hand to Telegram. */
  webhookUrl: string
}

export interface Conversation {
  id: string
  channel: string
  customerName: string | null
  customerPhone: string | null
  isHumanTakeover: boolean
  lastMessageAt: string | null
}

export interface ConversationDetail {
  id: string
  channel: string
  customerName: string | null
  isHumanTakeover: boolean
  messages: { id: string; direction: 'in' | 'out'; content: string | null; at: string | null }[]
}

export const CHANNEL_LABELS: Record<string, string> = {
  whatsapp: 'WhatsApp',
  telegram: 'Telegram',
}

export const channels = {
  list: () => api.get<{ data: Channel[] }>('/channels').then((r) => r.data),

  connect: (
    channel: string,
    body: {
      external_id: string
      label?: string | null
      webhook_secret: string
      credentials: { access_token?: string; bot_token?: string }
    },
  ) => api.put<{ data: Channel }>(`/channels/${channel}`, body).then((r) => r.data),

  disconnect: (channel: string) => api.delete(`/channels/${channel}`),

  conversations: () => api.get<{ data: Conversation[] }>('/conversations').then((r) => r.data),

  conversation: (id: string) =>
    api.get<{ data: ConversationDetail }>(`/conversations/${id}`).then((r) => r.data),

  reply: (id: string, text: string) => api.post(`/conversations/${id}/reply`, { text }),

  release: (id: string) => api.post(`/conversations/${id}/release`),
}
