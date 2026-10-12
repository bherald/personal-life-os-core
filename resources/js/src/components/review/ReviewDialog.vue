<template>
    <Teleport to="body">
        <dialog ref="dialog" class="desk-dialog" :class="{ 'desk-dialog-wide': wide }" :aria-labelledby="titleId" @cancel="cancel" @close="closed" @keydown="trapFocus">
            <header class="desk-dialog-heading"><h2 :id="titleId">{{ title }}</h2><button type="button" :disabled="busy" aria-label="Close dialog" @click="close">×</button></header>
            <slot />
        </dialog>
    </Teleport>
</template>

<script setup>
import { getCurrentInstance, nextTick, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({ modelValue: Boolean, title: { type: String, required: true }, wide: Boolean, busy: Boolean })
const emit = defineEmits(['update:modelValue', 'opened', 'closed'])
const dialog = ref(null)
const titleId = `review-dialog-${getCurrentInstance().uid}`
let opener = null

watch(() => props.modelValue, async (open) => {
    await nextTick()
    if (open && !dialog.value?.open) {
        opener = document.activeElement
        dialog.value.showModal()
        emit('opened')
    } else if (!open && dialog.value?.open) dialog.value.close()
}, { immediate: true })

function close() { if (!props.busy) emit('update:modelValue', false) }
function cancel(event) { event.preventDefault(); close() }
function closed() {
    emit('update:modelValue', false)
    emit('closed')
    if (opener?.isConnected) opener.focus({ preventScroll: true })
}
function trapFocus(event) {
    if (event.key !== 'Tab') return
    const controls = [...dialog.value.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),select:not(:disabled),a[href],[tabindex="0"]')].filter(element => element.getClientRects().length)
    if (!controls.length) { event.preventDefault(); return }
    if (event.shiftKey && document.activeElement === controls[0]) { event.preventDefault(); controls.at(-1).focus() }
    else if (!event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); controls[0].focus() }
}
onBeforeUnmount(() => { if (dialog.value?.open) dialog.value.close() })
</script>

<style scoped>
.desk-dialog { width: min(600px, calc(100vw - 24px)); max-height: 92dvh; border: 1px solid var(--color-border); border-radius: 14px; padding: 24px; background: var(--color-bg-secondary); color: var(--color-text-primary); box-shadow: 0 24px 90px #10273344; overflow: auto; }
.desk-dialog-wide { width: min(1100px, calc(100vw - 24px)); }
.desk-dialog::backdrop { background: #102733b8; backdrop-filter: blur(3px); }
.desk-dialog-heading { display: flex; justify-content: space-between; gap: 16px; align-items: start; margin-bottom: 18px; }
.desk-dialog-heading h2 { font-size: 20px; line-height: 1.4; margin: 0; text-transform: none; letter-spacing: -.3px; }
.desk-dialog-heading button { min-width: 44px; min-height: 44px; border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); background: var(--color-bg-secondary); font-size: 24px; }
@media (max-width: 600px) { .desk-dialog { padding: 16px; width: calc(100vw - 16px); max-height: 95dvh; } }
</style>
