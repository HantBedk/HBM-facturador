import { computed, ref, unref } from 'vue'
import { sortRowsCopy, tableAriaSort, tableSortIndicator } from '@/utils/tableSort.js'

/**
 * Ordenación en memoria de filas ya cargadas.
 * @template T
 * @param {import('vue').Ref<T[]> | import('vue').ComputedRef<T[]>} source
 * @param {Record<string, (row: T) => string|number|null|undefined>} accessors
 * @param {{ initialKey?: string, initialDir?: 'asc'|'desc' }} [options]
 */
export function useClientSortedRows(source, accessors, options = {}) {
  const keys = Object.keys(accessors)
  const initialKey = options.initialKey ?? keys[0] ?? ''
  const initialDir = options.initialDir ?? 'asc'

  const sortKey = ref(initialKey)
  const sortDir = ref(initialDir)

  function toggleSort(key, firstDir = 'asc') {
    if (sortKey.value === key) {
      sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
      sortKey.value = key
      sortDir.value = firstDir
    }
  }

  const sortedRows = computed(() => {
    const rows = unref(source)
    const list = Array.isArray(rows) ? rows : []
    const k = sortKey.value
    if (!k || !accessors[k]) return list
    return sortRowsCopy(list, k, sortDir.value, accessors)
  })

  function sortIndicator(k) {
    return tableSortIndicator(sortKey.value, sortDir.value, k)
  }

  function ariaSort(k) {
    return tableAriaSort(sortKey.value, sortDir.value, k)
  }

  return { sortKey, sortDir, toggleSort, sortedRows, sortIndicator, ariaSort }
}
