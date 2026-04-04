<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useUiDialogStore } from '@/stores/uiDialog'

const dialog = useUiDialogStore()
const inputRef = ref(null)
const confirmBtnRef = ref(null)

function onBackdropClick() {
  if (dialog.mode === 'alert') return
  if (dialog.mode === 'confirm') dialog.cancelConfirm()
  if (dialog.mode === 'prompt') dialog.cancelPrompt()
}

function onKeydown(ev) {
  if (!dialog.open) return
  if (ev.key === 'Escape') {
    ev.preventDefault()
    if (dialog.mode === 'alert') dialog.closeAlert()
    else if (dialog.mode === 'confirm') dialog.cancelConfirm()
    else if (dialog.mode === 'prompt') dialog.cancelPrompt()
  }
}

watch(
  () => dialog.open,
  async (open) => {
    if (!open) return
    await nextTick()
    if (dialog.mode === 'prompt') inputRef.value?.focus()
    else confirmBtnRef.value?.focus()
  }
)

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <Transition name="ui-dlg">
      <div
        v-if="dialog.open"
        class="ui-dlg-backdrop"
        role="presentation"
        @click.self="onBackdropClick"
      >
        <div
          class="ui-dlg-panel"
          role="dialog"
          :aria-labelledby="'ui-dlg-title'"
          aria-modal="true"
        >
          <h2 id="ui-dlg-title" class="ui-dlg-title">{{ dialog.title }}</h2>
          <p class="ui-dlg-message">{{ dialog.message }}</p>

          <div v-if="dialog.mode === 'prompt'" class="ui-dlg-field">
            <input
              ref="inputRef"
              v-model="dialog.inputValue"
              type="text"
              class="ui-dlg-input"
              :placeholder="dialog.placeholder"
              autocomplete="off"
              @keydown.enter.prevent="dialog.submitPrompt()"
            />
          </div>

          <div class="ui-dlg-actions">
            <template v-if="dialog.mode === 'confirm'">
              <button type="button" class="ui-dlg-btn ui-dlg-btn--ghost" @click="dialog.cancelConfirm()">
                {{ dialog.cancelLabel }}
              </button>
              <button
                ref="confirmBtnRef"
                type="button"
                class="ui-dlg-btn"
                :class="dialog.danger ? 'ui-dlg-btn--danger' : 'ui-dlg-btn--primary'"
                @click="dialog.submitConfirm()"
              >
                {{ dialog.confirmLabel }}
              </button>
            </template>
            <template v-else-if="dialog.mode === 'alert'">
              <button ref="confirmBtnRef" type="button" class="ui-dlg-btn ui-dlg-btn--primary" @click="dialog.closeAlert()">
                {{ dialog.confirmLabel }}
              </button>
            </template>
            <template v-else-if="dialog.mode === 'prompt'">
              <button type="button" class="ui-dlg-btn ui-dlg-btn--ghost" @click="dialog.cancelPrompt()">
                {{ dialog.cancelLabel }}
              </button>
              <button
                ref="confirmBtnRef"
                type="button"
                class="ui-dlg-btn"
                :class="dialog.danger ? 'ui-dlg-btn--danger' : 'ui-dlg-btn--primary'"
                @click="dialog.submitPrompt()"
              >
                {{ dialog.confirmLabel }}
              </button>
            </template>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ui-dlg-backdrop {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(4px);
}

.ui-dlg-panel {
  width: min(100%, 420px);
  max-height: min(90vh, 520px);
  overflow: auto;
  border-radius: 16px;
  border: 1px solid rgba(148, 163, 184, 0.25);
  background: linear-gradient(165deg, #1e293b 0%, #0f172a 100%);
  padding: 1.35rem 1.5rem;
  box-shadow: 0 24px 48px rgba(0, 0, 0, 0.45);
}

.ui-dlg-title {
  margin: 0 0 0.65rem;
  font-size: 1.1rem;
  font-weight: 700;
  color: #f8fafc;
  line-height: 1.3;
}

.ui-dlg-message {
  margin: 0 0 1rem;
  font-size: 0.9rem;
  line-height: 1.5;
  color: #cbd5e1;
  white-space: pre-wrap;
}

.ui-dlg-field {
  margin-bottom: 1.1rem;
}

.ui-dlg-input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(15, 23, 42, 0.8);
  color: #f1f5f9;
  padding: 0.6rem 0.75rem;
  font: inherit;
  font-size: 0.95rem;
}

.ui-dlg-input:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.25);
}

.ui-dlg-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.5rem;
}

.ui-dlg-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.55rem 1.1rem;
  border-radius: 10px;
  font-weight: 600;
  font-size: 0.9rem;
  cursor: pointer;
  border: 1px solid transparent;
  transition: background 0.15s ease, opacity 0.15s ease;
}

.ui-dlg-btn--ghost {
  background: transparent;
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
}

.ui-dlg-btn--ghost:hover {
  background: rgba(148, 163, 184, 0.1);
}

.ui-dlg-btn--primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  color: #fff;
  border: none;
}

.ui-dlg-btn--primary:hover {
  filter: brightness(1.06);
}

.ui-dlg-btn--danger {
  background: rgba(185, 28, 28, 0.85);
  color: #fecaca;
  border: 1px solid rgba(248, 113, 113, 0.4);
}

.ui-dlg-btn--danger:hover {
  background: rgba(153, 27, 27, 0.95);
}

.ui-dlg-enter-active,
.ui-dlg-leave-active {
  transition: opacity 0.2s ease;
}

.ui-dlg-enter-active .ui-dlg-panel,
.ui-dlg-leave-active .ui-dlg-panel {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.ui-dlg-enter-from,
.ui-dlg-leave-to {
  opacity: 0;
}

.ui-dlg-enter-from .ui-dlg-panel,
.ui-dlg-leave-to .ui-dlg-panel {
  opacity: 0;
  transform: scale(0.96) translateY(8px);
}
</style>
