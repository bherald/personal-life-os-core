<template>
  <div>
    <div
      @click="handleClick"
      class="flex items-center gap-2 px-4 py-1.5 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
      :class="[
        isSelected ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300'
      ]"
      :style="{ paddingLeft: `${16 + depth * 16}px` }"
    >
      <!-- Expand/Collapse Arrow -->
      <button
        v-if="hasChildren"
        @click.stop="expanded = !expanded"
        class="w-4 h-4 flex items-center justify-center"
      >
        <svg
          class="w-3 h-3 transition-transform"
          :class="expanded ? 'rotate-90' : ''"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
      </button>
      <div v-else class="w-4"></div>

      <!-- Folder Icon -->
      <svg class="w-5 h-5 flex-shrink-0" :class="expanded ? 'text-yellow-500' : 'text-gray-400'" fill="currentColor" viewBox="0 0 20 20">
        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
      </svg>

      <!-- Folder Name -->
      <span class="text-sm truncate flex-1">{{ folder.name }}</span>

      <!-- Count Badge -->
      <span v-if="folder.count" class="text-xs text-gray-400 dark:text-gray-500">
        {{ folder.count }}
      </span>
    </div>

    <!-- Children -->
    <div v-if="expanded && hasChildren">
      <FolderNode
        v-for="child in folder.children"
        :key="child.path"
        :folder="child"
        :selected-path="selectedPath"
        :depth="depth + 1"
        @select="$emit('select', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  folder: { type: Object, required: true },
  selectedPath: { type: String, default: '' },
  depth: { type: Number, default: 0 }
})

const emit = defineEmits(['select'])

const expanded = ref(props.depth < 2) // Auto-expand first 2 levels

const hasChildren = computed(() => props.folder.children && props.folder.children.length > 0)

const isSelected = computed(() => props.selectedPath === props.folder.path)

function handleClick() {
  emit('select', props.folder)
}
</script>
