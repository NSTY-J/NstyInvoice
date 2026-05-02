<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { projectsApi, type Project, type ProjectStats } from '@/api/projects'
import { clientsApi, type Client } from '@/api/clients'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TopProjectsBarChart from '@/components/charts/TopProjectsBarChart.vue'
import ProjectStatusChart from '@/components/charts/ProjectStatusChart.vue'
import { formatMoney, formatDate } from '@/composables/useFormat'

const { t } = useI18n()

const router = useRouter()
const route = useRoute()
const items = ref<Project[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const status = ref<'' | 'active' | 'paused' | 'closed'>('active')
const clientId = ref<number | ''>('')
const sort = ref<'name' | 'revenue' | 'last_activity'>('name')
const clients = ref<Client[]>([])

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await projectsApi.list({
      status: status.value || undefined,
      client_id: clientId.value === '' ? undefined : Number(clientId.value),
      sort: sort.value,
      page: page.value,
    })
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

async function loadClients() {
  const r = await clientsApi.list({ archived: false, per_page: 200 })
  clients.value = r.data
}

const stats = ref<ProjectStats | null>(null)
async function loadStats() {
  try { stats.value = await projectsApi.stats() } catch { /* tichý fallback */ }
}

import { computed } from 'vue'

const statusCounts = computed<Record<string, number>>(() => {
  const m: Record<string, number> = {}
  for (const r of stats.value?.status_breakdown ?? []) m[r.status] = r.count
  return m
})

function topChart(year: 'this' | 'prev') {
  const block = year === 'this' ? stats.value?.top_this_year : stats.value?.top_prev_year
  if (!block) return { labels: [], values: [], greyed: [] as number[] }
  const labels = block.top.map(p => `${p.name} — ${p.client_company_name}`)
  const values = block.top.map(p => p.revenue)
  const greyed: number[] = []
  if (block.others.count > 0) {
    labels.push(t('project.others_label', { n: block.others.count }))
    values.push(block.others.revenue)
    greyed.push(values.length - 1)
  }
  return { labels, values, greyed }
}

const totalThisYear = computed(() =>
  (stats.value?.totals_per_year ?? []).filter(r => r.year === stats.value?.this_year)
)
const totalPrevYear = computed(() =>
  (stats.value?.totals_per_year ?? []).filter(r => r.year === stats.value?.prev_year)
)

// Status chart spans 2 sloupce jen pokud jsou všechny 3 charty viditelné (lichý počet → status alone v row 2).
// Při 2 chartech (např. top_this + status) → každý 1 sloupec, side-by-side.
const statusFullWidth = computed(() =>
  !!(stats.value?.top_this_year.top.length && stats.value?.top_prev_year.top.length)
)

onMounted(async () => {
  // Pre-fill from query
  if (route.query.client_id) clientId.value = Number(route.query.client_id)
  await Promise.all([loadClients(), loadStats(), load(true)])
})

watch([status, clientId, sort], () => load(true))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('project.title') }}</h1>
    </div>

    <div class="mb-4 rounded-md border border-primary-500/30 bg-primary-50 px-4 py-2.5 text-sm text-primary-700 flex items-start gap-2">
      <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
      <i18n-t keypath="project.info_create_in_client" tag="div">
        <template #default><RouterLink to="/clients" class="underline font-medium hover:text-primary-800">{{ t('nav.clients') }}</RouterLink></template>
      </i18n-t>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-wrap items-center gap-2">
        <select v-model="status"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white">
          <option value="">{{ t('invoice.all_statuses') }}</option>
          <option value="active">{{ t('common.active') }}</option>
          <option value="paused">{{ t('project.status_paused') }}</option>
          <option value="closed">{{ t('project.status_closed') }}</option>
        </select>
        <select v-model="clientId"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white min-w-48">
          <option value="">{{ t('project.all_clients') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.company_name }}</option>
        </select>
        <button v-if="clientId !== ''" @click="clientId = ''"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">
          {{ t('project.clear_filter') }}
        </button>
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white ml-auto"
          :title="t('common.sort_by')">
          <option value="name">{{ t('common.sort_name') }}</option>
          <option value="revenue">{{ t('common.sort_revenue') }}</option>
          <option value="last_activity">{{ t('common.sort_last_activity') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="5" />

      <EmptyState v-else-if="!items.length" :title="t('project.no_data')" />

      <table v-else class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('project.name') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('nav.clients') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('project.status') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('common.revenue') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.last_activity') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('project.rate') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="p in items" :key="p.id" class="cursor-pointer hover:bg-neutral-50"
              @click="router.push(`/projects/${p.id}`)">
            <td class="px-4 py-3 font-medium">{{ p.name }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ p.client_company_name }}</td>
            <td class="px-4 py-3">
              <span class="text-xs px-2 py-0.5 rounded"
                :class="{
                  'bg-emerald-50 text-emerald-700': p.status === 'active',
                  'bg-amber-50 text-amber-700': p.status === 'paused',
                  'bg-neutral-100 text-neutral-600': p.status === 'closed',
                }">
                {{ p.status === 'active' ? t('common.active') : p.status === 'paused' ? t('project.status_paused') : t('project.status_closed') }}
              </span>
            </td>
            <td class="px-4 py-3 text-right font-mono">
              <span v-if="p.revenue && p.revenue > 0">{{ formatMoney(p.revenue, p.currency) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-neutral-600 text-xs">
              <span v-if="p.last_invoice_date">{{ formatDate(p.last_invoice_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-right font-mono">{{ p.hourly_rate.toLocaleString('cs') }} {{ p.currency }}/h</td>
          </tr>
        </tbody>
      </table>

      <div v-if="items.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: items.length, total }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>

    <!-- Statistiky — top zakázek + status (pod tabulkou) -->
    <div v-if="stats" class="mt-6 space-y-4">
      <!-- KPI tile řádek -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('project.stats_total_year', { year: stats.this_year }) }}</h3>
          <div v-if="totalThisYear.length" class="space-y-0.5">
            <div v-for="r in totalThisYear" :key="`t-${r.currency}`" class="flex items-baseline justify-between">
              <span class="text-2xl font-semibold font-mono text-neutral-900">{{ formatMoney(r.total, r.currency) }}</span>
              <span class="text-xs text-neutral-500 ml-3">{{ r.invoice_count }} ks</span>
            </div>
          </div>
          <div v-else class="text-neutral-400 text-sm">—</div>
        </div>
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('project.stats_total_year', { year: stats.prev_year }) }}</h3>
          <div v-if="totalPrevYear.length" class="space-y-0.5">
            <div v-for="r in totalPrevYear" :key="`p-${r.currency}`" class="flex items-baseline justify-between">
              <span class="text-2xl font-semibold font-mono text-neutral-700">{{ formatMoney(r.total, r.currency) }}</span>
              <span class="text-xs text-neutral-500 ml-3">{{ r.invoice_count }} ks</span>
            </div>
          </div>
          <div v-else class="text-neutral-400 text-sm">—</div>
        </div>
      </div>

      <!-- Top zakázek + status — layout dle počtu visible chartů -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div v-if="stats.top_this_year.top.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('project.stats_top_this_year', { year: stats.this_year }) }}</h3>
            <span class="text-xs font-mono text-neutral-500">{{ stats.primary_currency }}</span>
          </div>
          <TopProjectsBarChart
            :labels="topChart('this').labels"
            :values="topChart('this').values"
            :greyed-indexes="topChart('this').greyed"
            :currency="stats.primary_currency" />
        </div>
        <div v-if="stats.top_prev_year.top.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('project.stats_top_prev_year', { year: stats.prev_year }) }}</h3>
            <span class="text-xs font-mono text-neutral-500">{{ stats.primary_currency }}</span>
          </div>
          <TopProjectsBarChart
            :labels="topChart('prev').labels"
            :values="topChart('prev').values"
            :greyed-indexes="topChart('prev').greyed"
            :currency="stats.primary_currency" />
        </div>
        <div v-if="stats.status_breakdown.length"
          :class="['bg-white border border-neutral-200 rounded-lg p-5 shadow-sm', statusFullWidth ? 'md:col-span-2' : '']">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.stats_status_breakdown') }}</h3>
          <ProjectStatusChart :counts="statusCounts" />
        </div>
      </div>
    </div>
  </div>
</template>
