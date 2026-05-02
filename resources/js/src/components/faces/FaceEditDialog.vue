<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="close">
    <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-md mx-4 p-6">
      <h3 class="text-ops-peach text-lg font-bold mb-4">Edit Person</h3>

      <div class="mb-4">
        <label class="block text-ops-text-muted text-sm mb-1">Name</label>
        <input
          ref="nameInput"
          v-model="editName"
          type="text"
          maxlength="160"
          class="w-full bg-black/50 border border-ops-peach/40 rounded px-3 py-2 text-ops-text focus:border-ops-peach focus:outline-none"
          @keydown.enter="confirm"
          @keydown.escape="close"
        />
      </div>

      <div class="flex items-center gap-4 mb-6">
        <label class="flex items-center gap-2 cursor-pointer text-ops-text-muted text-sm">
          <input type="checkbox" v-model="editFavorite" class="accent-ops-gold" />
          Favorite
        </label>
        <label class="flex items-center gap-2 cursor-pointer text-ops-text-muted text-sm">
          <input type="checkbox" v-model="editHidden" class="accent-ops-peach" />
          Hidden
        </label>
      </div>

      <div class="flex justify-end gap-3">
        <button @click="close" class="px-4 py-2 text-sm text-ops-text-muted hover:text-ops-text border border-ops-plum/40 rounded">
          Cancel
        </button>
        <button @click="confirm" :disabled="!editName.trim()" class="px-4 py-2 text-sm bg-ops-peach text-black rounded font-semibold hover:bg-ops-orange disabled:opacity-40 disabled:cursor-not-allowed">
          Save
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'

const props = defineProps({
  visible: Boolean,
  person: Object, // { person_name, favorite, hidden, face_count }
})

const emit = defineEmits(['close', 'confirm'])

const editName = ref('')
const editFavorite = ref(false)
const editHidden = ref(false)
const nameInput = ref(null)

watch(() => props.visible, (val) => {
  if (val && props.person) {
    editName.value = props.person.person_name || ''
    editFavorite.value = !!props.person.favorite
    editHidden.value = !!props.person.hidden
    nextTick(() => nameInput.value?.select())
  }
})

function close() {
  emit('close')
}

function confirm() {
  if (!editName.value.trim()) return
  emit('confirm', {
    person_name: editName.value.trim(),
    favorite: editFavorite.value,
    hidden: editHidden.value,
    originalName: props.person?.person_name,
  })
}
</script>
