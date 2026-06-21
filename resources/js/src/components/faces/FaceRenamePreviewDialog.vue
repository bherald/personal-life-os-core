<template>
  <div v-if="visible" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" @click.self="close">
    <div class="bg-black border-2 border-ops-orange rounded-lg w-full max-w-2xl mx-4 p-6 max-h-[88vh] overflow-y-auto">
      <h3 class="text-ops-orange text-lg font-bold mb-4">Confirm Face Rename</h3>

      <div v-if="loading" class="py-8 text-center text-ops-text-muted">
        Loading rename preview...
      </div>

      <div v-else-if="preview" class="space-y-4">
        <div class="border border-ops-peach/25 bg-ops-plum/20 px-3 py-2">
          <div class="text-xs uppercase tracking-wider text-ops-text-muted">Rename Scope</div>
          <div class="mt-1 text-sm text-ops-text">
            <span class="text-ops-peach font-semibold">{{ preview.source_name }}</span>
            <span class="text-ops-text-muted mx-2">to</span>
            <span class="text-ops-peach font-semibold">{{ preview.new_name }}</span>
          </div>
          <div class="mt-1 text-xs text-ops-text-muted">
            Scope: {{ preview.scope_type === 'genealogy_person' ? 'FT-linked face group' : 'media-only face label' }}
          </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
          <div class="border border-ops-plum/40 px-3 py-2">
            <div class="text-ops-text-muted">Faces</div>
            <div class="text-ops-text font-semibold">{{ formatCount(preview.face_count) }}</div>
          </div>
          <div class="border border-ops-plum/40 px-3 py-2">
            <div class="text-ops-text-muted">Photos</div>
            <div class="text-ops-text font-semibold">{{ formatCount(preview.photo_count) }}</div>
          </div>
          <div class="border border-ops-plum/40 px-3 py-2">
            <div class="text-ops-text-muted">Target Faces</div>
            <div class="text-ops-text font-semibold">{{ formatCount(preview.target_face_count) }}</div>
          </div>
          <div class="border border-ops-plum/40 px-3 py-2">
            <div class="text-ops-text-muted">FT Changes</div>
            <div class="text-ops-text font-semibold">{{ formatCount(preview.ft_record_changes) }}</div>
          </div>
        </div>

        <div v-if="preview.warnings?.length" class="border border-ops-gold/40 bg-ops-gold/10 px-3 py-2">
          <div class="text-xs uppercase tracking-wider text-ops-gold mb-1">Warnings</div>
          <div v-for="warning in preview.warnings" :key="warning" class="text-xs text-ops-text-muted py-0.5">
            {{ warning }}
          </div>
        </div>

        <div v-if="preview.source_names?.length > 1" class="border border-ops-plum/40 px-3 py-2">
          <div class="text-xs uppercase tracking-wider text-ops-text-muted mb-1">Source Labels</div>
          <div class="text-xs text-ops-text truncate" :title="preview.source_names.join(', ')">
            {{ preview.source_names.join(', ') }}
          </div>
        </div>

        <div v-if="preview.ft_people?.length" class="border border-ops-peach/25 px-3 py-2">
          <div class="text-xs uppercase tracking-wider text-ops-text-muted mb-1">Linked FT Names</div>
          <div v-for="person in preview.ft_people" :key="person.id" class="flex items-center justify-between gap-3 text-xs py-0.5">
            <span class="text-ops-text truncate">{{ person.name || `Person ${person.id}` }}</span>
            <span class="text-ops-text-muted whitespace-nowrap">{{ person.gedcom_id || `ID ${person.id}` }} - {{ formatCount(person.face_count) }} faces</span>
          </div>
        </div>

        <div class="border border-ops-plum/40 px-3 py-2">
          <div class="text-xs uppercase tracking-wider text-ops-text-muted mb-1">Cascade</div>
          <div v-for="item in preview.cascade" :key="item.label" class="flex items-center justify-between gap-3 text-xs py-0.5">
            <span class="text-ops-text-muted">{{ item.label }}</span>
            <span class="text-ops-text font-semibold">{{ formatCount(item.count) }}</span>
          </div>
        </div>

        <div v-if="preview.current_names?.length" class="border border-ops-plum/40 px-3 py-2">
          <div class="text-xs uppercase tracking-wider text-ops-text-muted mb-1">Current Face Labels</div>
          <div class="text-xs text-ops-text truncate" :title="preview.current_names.join(', ')">
            {{ preview.current_names.join(', ') }}
          </div>
        </div>

        <div v-if="preview.sample_files?.length" class="border border-ops-plum/40 px-3 py-2">
          <div class="text-xs uppercase tracking-wider text-ops-text-muted mb-1">Sample Photos</div>
          <div v-for="file in preview.sample_files" :key="file.id" class="text-xs text-ops-text-muted truncate py-0.5" :title="displayFile(file)">
            {{ displayFile(file) }}
          </div>
        </div>
      </div>

      <div v-else class="py-8 text-center text-ops-orange">
        Preview unavailable. Cancel and try again.
      </div>

      <div class="flex justify-end gap-3 mt-6">
        <button @click="close" class="px-4 py-2 text-sm text-ops-text-muted hover:text-ops-text border border-ops-plum/40 rounded">
          Cancel
        </button>
        <button
          @click="$emit('confirm')"
          :disabled="loading || !preview || preview.face_count < 1"
          class="px-4 py-2 text-sm bg-ops-orange text-black rounded font-semibold hover:bg-ops-peach disabled:opacity-40 disabled:cursor-not-allowed"
        >
          Apply Rename
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  visible: Boolean,
  preview: Object,
  loading: Boolean,
})

const emit = defineEmits(['close', 'confirm'])

function close() {
  emit('close')
}

function formatCount(value) {
  return Number(value || 0).toLocaleString()
}

function displayFile(file) {
  return file.current_path || file.filename || `File ${file.id}`
}
</script>
