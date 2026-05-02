import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import type { SupplierBrief } from '@/api/auth'

const STORAGE_KEY = 'myinvoice.current_supplier_id'

/**
 * Multi-supplier scope na frontendu.
 * - `currentSupplierId` se persistuje v localStorage
 * - axios interceptor (api/client.ts) z něj plní hlavičku `X-Supplier-Id`
 * - po /me se naplní `availableSuppliers` (pro switcher v hlavičce)
 *
 * Po přepnutí volej `reloadAfterSwitch()` (router push na /, který re-fetchne).
 */
export const useSupplierStore = defineStore('supplier', () => {
  const initial = (() => {
    const v = localStorage.getItem(STORAGE_KEY)
    return v && /^\d+$/.test(v) ? parseInt(v, 10) : 0
  })()

  const currentSupplierId = ref<number>(initial)
  const availableSuppliers = ref<SupplierBrief[]>([])

  const hasMultiple = computed(() => availableSuppliers.value.length > 1)
  const currentSupplier = computed<SupplierBrief | null>(() =>
    availableSuppliers.value.find(s => s.id === currentSupplierId.value) ?? null,
  )

  watch(currentSupplierId, (v) => {
    if (v > 0) localStorage.setItem(STORAGE_KEY, String(v))
    else localStorage.removeItem(STORAGE_KEY)
  })

  function setSupplier(id: number) {
    currentSupplierId.value = id
  }

  function setAvailable(list: SupplierBrief[], serverCurrent: number) {
    availableSuppliers.value = list
    // Pokud localStorage value není v dostupném listu, přejdi na server-side default
    if (!list.find(s => s.id === currentSupplierId.value)) {
      currentSupplierId.value = serverCurrent || (list[0]?.id ?? 0)
    }
  }

  return {
    currentSupplierId,
    availableSuppliers,
    hasMultiple,
    currentSupplier,
    setSupplier,
    setAvailable,
  }
})
