<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="close">
    <div class="bg-black border-2 border-ops-orange rounded-lg w-full max-w-md mx-4 p-6">
      <h3 class="text-ops-orange text-lg font-bold mb-4">Merge People</h3>

      <p class="text-ops-text mb-2">
        Merge <span class="text-ops-peach font-semibold">"{{ sourceName }}"</span>
        with <span class="text-ops-peach font-semibold">"{{ targetName }}"</span>?
      </p>

      <p class="text-ops-text-muted text-sm mb-6">
        This will combine {{ sourceCount }} + {{ targetCount }} photos under "{{ targetName }}".
      </p>

      <div class="flex justify-end gap-3">
        <button @click="close" class="px-4 py-2 text-sm text-ops-text-muted hover:text-ops-text border border-ops-plum/40 rounded">
          No
        </button>
        <button @click="$emit('confirm')" class="px-4 py-2 text-sm bg-ops-orange text-black rounded font-semibold hover:bg-ops-peach">
          Yes, Merge
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  visible: Boolean,
  sourceName: String,
  targetName: String,
  sourceCount: { type: Number, default: 0 },
  targetCount: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'confirm'])

function close() {
  emit('close')
}
</script>
