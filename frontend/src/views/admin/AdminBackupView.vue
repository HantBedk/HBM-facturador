<script setup>
import { onMounted, ref } from 'vue'
import {
  deleteBackup, downloadBackup, downloadFullExport,
  getDbStatus, listBackups, restoreBackup, restoreUpload, restoreZip,
  triggerBackup, wipeDatabase,
} from '@/services/adminBackupApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const uiDialog = useUiDialogStore()

const error   = ref('')
const success = ref('')

const dbStatus      = ref(null)
const loadingStatus = ref(false)
const wipePwd       = ref('')
const wiping        = ref(false)
const showWipeForm  = ref(false)

const showDownloadModal = ref(false)
const downloadingFmt    = ref('')

const restoreFile      = ref(null)
const uploadingRestore = ref(false)

const backups      = ref([])
const loadingList  = ref(false)
const restoringRow = ref('')

function clearMessages() { error.value = ''; success.value = '' }

function formatBytes(b) {
  if (!b) return '0 B'
  const k = 1024, s = ['B', 'KB', 'MB', 'GB'], i = Math.floor(Math.log(b) / Math.log(k))
  return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + s[i]
}

function saveBlob({ blob, filename }) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = filename
  document.body.appendChild(a); a.click()
  document.body.removeChild(a); URL.revokeObjectURL(url)
}

async function loadStatus() {
  loadingStatus.value = true
  try { dbStatus.value = await getDbStatus() } catch { /**/ }
  finally { loadingStatus.value = false }
}

async function loadBackups() {
  loadingList.value = true
  try { backups.value = (await listBackups()).backups ?? [] }
  catch (e) { error.value = e.message }
  finally { loadingList.value = false }
}

async function onWipe() {
  if (!wipePwd.value) return
  clearMessages(); wiping.value = true
  try {
    const res = await wipeDatabase(wipePwd.value)
    success.value = res.message; wipePwd.value = ''; showWipeForm.value = false
    await loadStatus()
  } catch (e) { error.value = e.data?.message || e.message }
  finally { wiping.value = false }
}

async function onDownloadFormat(fmt) {
  downloadingFmt.value = fmt
  try {
    if (fmt === 'sql') {
      const res = await triggerBackup()
      if (res.filename) saveBlob(await downloadBackup(res.filename))
      await loadBackups()
    } else {
      saveBlob(await downloadFullExport())
    }
    showDownloadModal.value = false
  } catch (e) { error.value = e.data?.message || e.message }
  finally { downloadingFmt.value = '' }
}

function onFileChange(e) { restoreFile.value = e.target.files[0] ?? null; clearMessages() }
function clearFile(el)    { restoreFile.value = null; if (el) el.value = '' }

async function onRestoreFile(inputEl) {
  if (!restoreFile.value) return
  const ok1 = await uiDialog.confirm({ title: 'Restaurar base de datos', message: `«${restoreFile.value.name}» reemplazará TODA la base de datos. Irreversible.`, danger: true, confirmLabel: 'Continuar' })
  if (!ok1) return
  const ok2 = await uiDialog.confirm({ title: '¿Seguro?', message: 'Se sobrescribirán todos los datos en producción.', danger: true, confirmLabel: 'Sí, restaurar' })
  if (!ok2) return
  clearMessages(); uploadingRestore.value = true
  try {
    const isZip = restoreFile.value.name.toLowerCase().endsWith('.zip')
    const res   = isZip ? await restoreZip(restoreFile.value) : await restoreUpload(restoreFile.value)
    success.value = res.message; clearFile(inputEl)
    await Promise.all([loadStatus(), loadBackups()])
  } catch (e) { error.value = e.data?.message || e.message }
  finally { uploadingRestore.value = false }
}

async function onDownloadRow(filename) {
  try { saveBlob(await downloadBackup(filename)) }
  catch (e) { error.value = e.message }
}

async function onDelete(filename) {
  const ok = await uiDialog.confirm({ title: 'Eliminar respaldo', message: `¿Eliminar «${filename}»?`, danger: true, confirmLabel: 'Eliminar' })
  if (!ok) return
  try { await deleteBackup(filename); backups.value = backups.value.filter(b => b.filename !== filename) }
  catch (e) { error.value = e.data?.message || e.message }
}

async function onRestoreRow(filename) {
  const ok1 = await uiDialog.confirm({ title: 'Restaurar', message: `«${filename}» reemplazará TODA la base de datos.`, danger: true, confirmLabel: 'Continuar' })
  if (!ok1) return
  const ok2 = await uiDialog.confirm({ title: '¿Seguro?', message: 'Todos los datos de producción serán sobrescritos.', danger: true, confirmLabel: 'Sí, restaurar' })
  if (!ok2) return
  clearMessages(); restoringRow.value = filename
  try { const res = await restoreBackup(filename); success.value = res.message; await loadStatus() }
  catch (e) { error.value = e.data?.message || e.message }
  finally { restoringRow.value = '' }
}

onMounted(() => Promise.all([loadStatus(), loadBackups()]))
</script>

<template>
  <div class="space-y-4">

    <!-- Mensajes -->
    <p v-if="error"   class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-2.5 text-sm text-red-300">{{ error }}</p>
    <p v-if="success" class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-2.5 text-sm text-emerald-300">{{ success }}</p>

    <!-- ── Fila estado + acciones principales ── -->
    <div class="grid gap-3 sm:grid-cols-3">

      <!-- Estado de la BD -->
      <div
        class="rounded-2xl border p-4 sm:col-span-1"
        :class="dbStatus?.is_clean ? 'border-emerald-500/25 bg-emerald-500/5' : 'border-amber-500/25 bg-amber-500/5'"
      >
        <div class="flex items-center justify-between mb-3">
          <span class="text-xs font-semibold uppercase tracking-wide" :class="dbStatus?.is_clean ? 'text-emerald-400' : 'text-amber-400'">
            {{ dbStatus?.is_clean ? 'BD limpia' : 'BD con datos' }}
          </span>
          <span
            class="h-2 w-2 rounded-full"
            :class="dbStatus?.is_clean ? 'bg-emerald-400' : 'bg-amber-400'"
          />
        </div>

        <div v-if="loadingStatus" class="text-xs text-slate-500">Verificando…</div>
        <div v-else-if="dbStatus" class="space-y-0.5 max-h-44 overflow-y-auto pr-1">
          <div v-for="(count, key) in dbStatus.counts" :key="key" class="flex items-center justify-between">
            <span class="text-[0.68rem] text-slate-500 capitalize">{{ key }}</span>
            <span class="text-[0.68rem] font-semibold text-slate-300">{{ count.toLocaleString() }}</span>
          </div>
        </div>

        <button
          v-if="dbStatus && !dbStatus.is_clean"
          type="button"
          class="mt-3 w-full rounded-lg border border-amber-500/30 px-3 py-1.5 text-xs font-semibold text-amber-400 hover:bg-amber-500/10 transition-colors"
          @click="showWipeForm = !showWipeForm"
        >
          {{ showWipeForm ? 'Cancelar' : 'Limpiar BD…' }}
        </button>

        <!-- Wipe form -->
        <div v-if="showWipeForm && !dbStatus?.is_clean" class="mt-3 space-y-2">
          <p class="text-[0.68rem] text-red-300 leading-snug">Elimina servicios, facturas, empresas e inventario. Los usuarios se conservan.</p>
          <input
            v-model="wipePwd"
            type="password"
            placeholder="Tu contraseña"
            class="w-full rounded-lg border border-slate-600 bg-slate-800 px-2.5 py-1.5 text-xs text-slate-200 placeholder-slate-500 focus:border-red-400 focus:outline-none"
            @keydown.enter="onWipe"
          />
          <button
            type="button"
            class="w-full inline-flex items-center justify-center gap-1.5 rounded-lg bg-red-600 py-1.5 text-xs font-semibold text-white hover:bg-red-500 disabled:opacity-50 transition-colors"
            :disabled="!wipePwd || wiping"
            @click="onWipe"
          >
            <svg v-if="wiping" class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            {{ wiping ? 'Limpiando…' : 'Confirmar limpieza' }}
          </button>
        </div>
      </div>

      <!-- Descargar respaldo -->
      <button
        type="button"
        class="group flex flex-col items-center justify-center gap-3 rounded-2xl border border-sky-500/25 bg-sky-500/5 p-6 hover:border-sky-500/50 hover:bg-sky-500/10 transition-all sm:col-span-1"
        @click="showDownloadModal = true"
      >
        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/15 group-hover:bg-sky-500/25 transition-colors">
          <svg class="h-5 w-5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
        </div>
        <div class="text-center">
          <p class="text-sm font-semibold text-slate-200">Descargar respaldo</p>
          <p class="text-[0.68rem] text-slate-500 mt-0.5">SQL o ZIP completo</p>
        </div>
      </button>

      <!-- Cargar respaldo -->
      <div
        class="rounded-2xl border p-4 transition-opacity"
        :class="dbStatus?.is_clean
          ? 'border-slate-700/60 bg-slate-900/40'
          : 'border-slate-700/25 bg-slate-900/20 opacity-40 pointer-events-none select-none'"
      >
        <p class="text-xs font-semibold text-slate-300 mb-3">
          Cargar respaldo
          <span v-if="!dbStatus?.is_clean" class="text-amber-400 font-normal"> — limpia la BD primero</span>
        </p>

        <label class="flex flex-col items-center gap-2 rounded-xl border-2 border-dashed py-5 cursor-pointer transition-colors"
          :class="restoreFile ? 'border-sky-500/50 bg-sky-500/5' : 'border-slate-700 hover:border-slate-600 hover:bg-slate-800/30'"
        >
          <input ref="fileInputRef" type="file" accept=".sql,.zip" class="sr-only" @change="onFileChange"/>
          <svg class="h-6 w-6 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
          </svg>
          <span v-if="restoreFile" class="text-xs font-medium text-sky-300 text-center px-2 truncate max-w-full">{{ restoreFile.name }}</span>
          <span v-else class="text-xs text-slate-500">.sql o .zip</span>
        </label>

        <div class="mt-3 flex gap-2">
          <button
            type="button"
            class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg bg-sky-600 py-1.5 text-xs font-semibold text-white hover:bg-sky-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            :disabled="!restoreFile || uploadingRestore"
            @click="onRestoreFile($refs.fileInputRef)"
          >
            <svg v-if="uploadingRestore" class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            {{ uploadingRestore ? 'Restaurando…' : 'Restaurar' }}
          </button>
          <button v-if="restoreFile" type="button" class="px-2 text-xs text-slate-500 hover:text-slate-300 transition-colors" @click="clearFile($refs.fileInputRef)">✕</button>
        </div>
      </div>
    </div>

    <!-- ── Respaldos del servidor ── -->
    <div class="rounded-2xl border border-slate-700/60 bg-slate-900/40 p-4">
      <p class="text-xs font-semibold text-slate-300 mb-3">Historial de respaldos SQL</p>

      <div v-if="loadingList" class="py-8 text-center text-xs text-slate-500">Cargando…</div>
      <div v-else-if="backups.length === 0" class="rounded-xl border border-dashed border-slate-700/60 py-8 text-center text-xs text-slate-500">
        Aún no hay respaldos — descarga uno en SQL para que aparezca aquí
      </div>
      <ul v-else class="divide-y divide-slate-800/60">
        <li v-for="b in backups" :key="b.filename" class="flex items-center gap-3 py-2.5">
          <div class="min-w-0 flex-1">
            <p class="text-xs font-mono text-slate-300 truncate">{{ b.filename }}</p>
            <p class="text-[0.65rem] text-slate-500 mt-0.5">{{ formatBytes(b.size) }} · {{ b.created_at }}</p>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <button type="button" class="text-[0.7rem] font-semibold text-sky-400 hover:text-sky-300 transition-colors" @click="onDownloadRow(b.filename)">Descargar</button>
            <button
              type="button"
              class="inline-flex items-center gap-1 text-[0.7rem] font-semibold text-amber-400 hover:text-amber-300 transition-colors disabled:opacity-40"
              :disabled="!!restoringRow"
              @click="onRestoreRow(b.filename)"
            >
              <svg v-if="restoringRow === b.filename" class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
              </svg>
              {{ restoringRow === b.filename ? 'Restaurando…' : 'Restaurar' }}
            </button>
            <button type="button" class="text-[0.7rem] font-semibold text-red-400 hover:text-red-300 transition-colors" @click="onDelete(b.filename)">Eliminar</button>
          </div>
        </li>
      </ul>
    </div>

  </div>

  <!-- ── Modal de descarga ── -->
  <Teleport to="body">
    <div
      v-if="showDownloadModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="showDownloadModal = false"
    >
      <div class="w-full max-w-sm rounded-2xl border border-slate-700 bg-slate-900 p-5 shadow-2xl">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-semibold text-slate-100">Descargar respaldo</h3>
          <button type="button" class="text-slate-500 hover:text-slate-300 transition-colors" @click="showDownloadModal = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <!-- Conteos -->
        <div v-if="dbStatus" class="mb-4">
          <div class="grid grid-cols-2 gap-1.5 mb-3">
            <div
              v-for="(count, key) in dbStatus.counts"
              :key="key"
              class="flex items-center justify-between rounded-lg border border-slate-700/50 bg-slate-800/40 px-2.5 py-1.5"
            >
              <span class="text-[0.65rem] text-slate-400 capitalize">{{ key }}</span>
              <span class="text-xs font-bold text-slate-200 ml-2">{{ count.toLocaleString() }}</span>
            </div>
          </div>
          <p class="rounded-lg bg-sky-500/10 border border-sky-500/20 px-3 py-2 text-[0.65rem] text-sky-300 leading-relaxed">
            El respaldo <strong>SQL incluye todas las tablas</strong> de la base de datos — fotos, notificaciones, catálogo, configuración y más, no solo lo mostrado aquí.
          </p>
        </div>

        <p class="text-[0.7rem] text-slate-500 mb-3">Selecciona el formato:</p>

        <div class="grid grid-cols-2 gap-2 mb-3">
          <button
            type="button"
            class="flex flex-col items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/60 p-4 hover:border-sky-500/50 hover:bg-sky-500/5 disabled:opacity-50 transition-all"
            :disabled="!!downloadingFmt"
            @click="onDownloadFormat('sql')"
          >
            <svg v-if="downloadingFmt === 'sql'" class="h-5 w-5 text-sky-400 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            <svg v-else class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span class="text-xs font-semibold text-slate-200">SQL</span>
            <span class="text-[0.62rem] text-slate-500 text-center">Solo base de datos</span>
          </button>

          <button
            type="button"
            class="flex flex-col items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/60 p-4 hover:border-sky-500/50 hover:bg-sky-500/5 disabled:opacity-50 transition-all"
            :disabled="!!downloadingFmt"
            @click="onDownloadFormat('zip')"
          >
            <svg v-if="downloadingFmt === 'zip'" class="h-5 w-5 text-sky-400 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            <svg v-else class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            <span class="text-xs font-semibold text-slate-200">ZIP</span>
            <span class="text-[0.62rem] text-slate-500 text-center">SQL + CSVs</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
