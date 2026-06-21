<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="close">
    <div class="bg-black border-2 border-ops-peach rounded-lg w-full max-w-md mx-4 p-6">
      <h3 class="text-ops-peach text-lg font-bold mb-4">Rename Face Label</h3>

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

      <div v-if="!person?.genealogy_person_id" class="mb-4">
        <label class="block text-ops-text-muted text-sm mb-1">Source labels</label>
        <textarea
          v-model="sourceLabels"
          rows="2"
          maxlength="500"
          placeholder="Additional labels to normalize"
          class="w-full bg-black/50 border border-ops-peach/40 rounded px-3 py-2 text-ops-text focus:border-ops-peach focus:outline-none"
          @keydown.escape="close"
        ></textarea>
      </div>

      <div v-if="person?.genealogy_person_id" class="mb-4 border border-ops-gold/30 bg-ops-gold/10 px-3 py-2 text-xs text-ops-text-muted">
        <div class="font-semibold text-ops-gold">FT record will not be renamed.</div>
        <div class="mt-1">FT name: <span class="text-ops-text">{{ person.genealogy_name || person.person_name }}</span></div>
        <div v-if="person.face_name_variants" class="mt-1 truncate" :title="person.face_name_variants">
          Current face labels: {{ person.face_name_variants }}
        </div>
      </div>

      <div v-else class="mb-4 border border-ops-peach/20 bg-ops-plum/20 px-3 py-2 text-xs text-ops-text-muted">
        This changes this media-only face label across matching photos. It does not create or edit an FT person.
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
const sourceLabels = ref('')
const nameInput = ref(null)

watch(() => props.visible, (val) => {
  if (val && props.person) {
    editName.value = props.person.person_name || ''
    sourceLabels.value = ''
    nextTick(() => nameInput.value?.select())
  }
})

function close() {
  emit('close')
}

function confirm() {
  if (!editName.value.trim()) return
  const sourceNames = sourceLabels.value
    .split(/[\n,]/)
    .map(label => label.trim())
    .filter(Boolean)

  emit('confirm', {
    person_name: editName.value.trim(),
    originalName: props.person?.scope_type === 'genealogy_person'
      ? props.person?.person_name
      : (props.person?.scope_name || props.person?.face_label_name || props.person?.person_name),
    displayName: props.person?.person_name,
    scope_type: props.person?.scope_type || (props.person?.genealogy_person_id ? 'genealogy_person' : 'name'),
    source_names: sourceNames,
    genealogy_person_id: props.person?.genealogy_person_id || null,
  })
}
</script>
