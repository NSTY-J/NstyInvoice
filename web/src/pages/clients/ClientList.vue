<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client } from '@/api/clients'
import { formatMoney, formatDate } from '@/composables/useFormat'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()

const router = useRouter()
const items = ref<Client[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const showArchived = ref(false)
const sort = ref<'name' | 'revenue' | 'last_activity'>('name')
let searchTimeout: ReturnType<typeof setTimeout> | null = null

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await clientsApi.list({
      q: search.value,
      archived: showArchived.value,
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

onMounted(() => load(true))
watch(showArchived, () => load(true))
watch(sort, () => load(true))
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

function openClient(c: Client) {
  router.push(`/clients/${c.id}`)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('client.title') }}</h1>
      <RouterLink
        to="/clients/new"
        class="inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('client.new') }}
      </RouterLink>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center gap-3">
        <input
          v-model="search"
          type="search"
          :placeholder="t('common.search')"
          class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('client.show_archived') }}
        </label>
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white"
          :title="t('common.sort_by')">
          <option value="name">{{ t('common.sort_name') }}</option>
          <option value="revenue">{{ t('common.sort_revenue') }}</option>
          <option value="last_activity">{{ t('common.sort_last_activity') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="6" />

      <EmptyState v-else-if="!items.length"
        :title="t('client.no_data')"
        :cta="t('client.create_first')"
        to="/clients/new" />

      <table v-else class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.company') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.ic') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.email') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('nav.projects') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('common.revenue') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.last_activity') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('common.currency') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="c in items"
            :key="c.id"
            @click="openClient(c)"
            class="cursor-pointer hover:bg-neutral-50"
          >
            <td class="px-4 py-3">
              <div class="font-medium text-neutral-900">{{ c.company_name }}</div>
              <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
            </td>
            <td class="px-4 py-3 font-mono text-xs text-neutral-600">{{ c.ic || '—' }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ c.main_email }}</td>
            <td class="px-4 py-3 text-center">
              <span v-if="c.active_projects_count" class="inline-block px-2 py-0.5 text-xs bg-primary-50 text-primary-700 rounded">
                {{ c.active_projects_count }}
              </span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-right font-mono">
              <span v-if="c.revenue && c.revenue > 0">{{ formatMoney(c.revenue, c.currency_default) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-neutral-600 text-xs">
              <span v-if="c.last_invoice_date">{{ formatDate(c.last_invoice_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-center text-neutral-600 font-mono text-xs">{{ c.currency_default }}</td>
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
  </div>
</template>
