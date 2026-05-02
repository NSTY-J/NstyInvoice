import { api } from './client'

export interface DashboardKpi {
  per_currency: Array<{
    currency: string
    this_year: number
    prev_year: number
    change_pct: number | null
  }>
  issued_count_ytd: number
  overdue_count: number
  overdue_per_currency: Array<{ currency: string; count: number; total: number }>
  avg_payment_days: number | null
  status_counts_ytd?: Record<string, number>
}

export interface DashboardInvoiceItem {
  id: number
  varsymbol: string | null
  invoice_type: string
  client_id: number
  client_company_name: string
  currency: string
  issue_date: string
  due_date: string
  amount_to_pay: number
  status: string
  days_overdue: number | null
}

export interface TopClient {
  client_id: number
  company_name: string
  currency: string
  total: number
  invoice_count: number
}

export interface RevenueByMonth {
  currency: string
  this_year: number[]
  prev_year: number[]
}

export interface DashboardSummary {
  kpi: DashboardKpi
  overdue: DashboardInvoiceItem[]
  unpaid_upcoming: DashboardInvoiceItem[]
  top_clients_ytd: TopClient[]
  top_clients_prev_year: TopClient[]
  revenue_by_month: RevenueByMonth[]
  today: string
  year: number
  prev_year: number
}

export const dashboardApi = {
  summary: () => api.get<DashboardSummary>('/dashboard/summary').then(r => r.data),
}
