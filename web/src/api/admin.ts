import { api } from './client'

export interface ActivityLogEntry {
  id: number
  user_id: number | null
  user_email: string | null
  user_name: string | null
  action: string
  entity_type: string | null
  entity_id: number | null
  payload: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

export interface ActivityLogResponse {
  data: ActivityLogEntry[]
  total: number
  limit: number
  offset: number
  actions: Array<{ action: string; cnt: number }>
}

export interface AdminUser {
  id: number
  email: string
  name: string
  role: 'admin' | 'accountant' | 'readonly'
  locale: 'cs' | 'en'
  is_active: boolean
  created_at: string
  last_login_at: string | null
}

export const adminApi = {
  activityLog: (params: { action?: string; user_id?: number; entity_type?: string; entity_id?: number; limit?: number; offset?: number } = {}) =>
    api.get<ActivityLogResponse>('/admin/activity-log', { params }).then(r => r.data),

  // Users
  listUsers: () => api.get<AdminUser[]>('/admin/users').then(r => r.data),
  createUser: (payload: { email: string; name: string; role: AdminUser['role']; locale?: 'cs' | 'en'; password: string }) =>
    api.post<AdminUser>('/admin/users', payload).then(r => r.data),
  updateUser: (id: number, payload: Partial<{ name: string; role: AdminUser['role']; locale: 'cs' | 'en'; is_active: boolean; password: string }>) =>
    api.put<AdminUser>(`/admin/users/${id}`, payload).then(r => r.data),
  deleteUser: (id: number) => api.delete(`/admin/users/${id}`),

  // Email templates
  listEmailTemplates: () =>
    api.get<{ data: EmailTemplateListItem[] }>('/admin/email-templates').then(r => r.data.data),
  getEmailTemplate: (code: string, locale: string) =>
    api.get<EmailTemplate>(`/admin/email-templates/${code}/${locale}`).then(r => r.data),
  saveEmailTemplate: (code: string, locale: string, payload: { subject: string; body_html: string; body_text: string }) =>
    api.put(`/admin/email-templates/${code}/${locale}`, payload),
  resetEmailTemplate: (code: string, locale: string) =>
    api.delete(`/admin/email-templates/${code}/${locale}`),
}

export interface EmailTemplateListItem {
  code: string
  locale: 'cs' | 'en'
  has_override: boolean
  updated_at: string | null
}

export interface EmailTemplate {
  code: string
  locale: 'cs' | 'en'
  subject: string
  body_html: string
  body_text: string
  has_override: boolean
  updated_at: string | null
  defaults: { subject: string; body_html: string; body_text: string }
}
