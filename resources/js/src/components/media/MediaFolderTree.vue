<template>
  <div class="bg-white dark:bg-gray-800 h-full flex flex-col">
    <!-- Header -->
    <div class="p-4 border-b border-gray-200 dark:border-gray-700">
      <h3 class="font-semibold text-gray-900 dark:text-white">Folders</h3>
    </div>

    <!-- All Media Option -->
    <div
      @click="$emit('select', null)"
      class="px-4 py-2 cursor-pointer flex items-center gap-2 hover:bg-gray-100 dark:hover:bg-gray-700"
      :class="!selectedFolder ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300'"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
      </svg>
      <span class="text-sm font-medium">All Media</span>
    </div>

    <!-- Folder Tree -->
    <div class="flex-1 overflow-y-auto">
      <FolderNode
        v-for="folder in rootFolders"
        :key="folder.path"
        :folder="folder"
        :selected-path="selectedFolder?.path"
        :depth="0"
        @select="$emit('select', $event)"
      />
    </div>

    <!-- Quick Filters -->
    <div class="border-t border-gray-200 dark:border-gray-700 p-4">
      <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2">Quick Filters</h4>
      <div class="space-y-1">
        <button
          @click="$emit('select', { path: '', filter: 'with_faces' })"
          class="w-full text-left px-2 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded flex items-center gap-2"
        >
          <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          With Faces
        </button>
        <button
          @click="$emit('select', { path: '', filter: 'unlinked_faces' })"
          class="w-full text-left px-2 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded flex items-center gap-2"
        >
          <svg class="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
          Unlinked Faces
        </button>
        <button
          @click="$emit('select', { path: '', filter: 'recent' })"
          class="w-full text-left px-2 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded flex items-center gap-2"
        >
          <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Recently Added
        </button>
        <button
          @click="$emit('select', { path: '', filter: 'videos' })"
          class="w-full text-left px-2 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded flex items-center gap-2"
        >
          <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Videos
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import FolderNode from './FolderNode.vue'

const props = defineProps({
  folders: { type: Array, default: () => [] },
  selectedFolder: { type: Object, default: null }
})

defineEmits(['select'])

const rootFolders = computed(() => {
  // Build tree structure from flat folder list
  const folderMap = new Map()
  const roots = []

  // First pass: create all folder objects
  props.folders.forEach(f => {
    folderMap.set(f.path, { ...f, children: [] })
  })

  // Second pass: build parent-child relationships
  props.folders.forEach(f => {
    const folder = folderMap.get(f.path)
    const parentPath = f.path.substring(0, f.path.lastIndexOf('/'))

    if (parentPath && folderMap.has(parentPath)) {
      folderMap.get(parentPath).children.push(folder)
    } else {
      roots.push(folder)
    }
  })

  // Sort folders alphabetically
  const sortFolders = (folders) => {
    folders.sort((a, b) => a.name.localeCompare(b.name))
    folders.forEach(f => {
      if (f.children.length > 0) {
        sortFolders(f.children)
      }
    })
    return folders
  }

  return sortFolders(roots)
})
</script>
