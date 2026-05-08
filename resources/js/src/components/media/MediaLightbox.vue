<template>
  <div
    class="fixed inset-0 z-50 bg-black/95 flex flex-col"
    @keydown.left="$emit('prev')"
    @keydown.right="$emit('next')"
    @keydown.escape="$emit('close')"
    tabindex="0"
    ref="lightboxEl"
  >
    <!-- Image Editor Overlay -->
    <ImageEditor
      v-if="showEditor"
      :uuid="item.asset_uuid"
      :filename="item.filename"
      @close="showEditor = false"
      @saved="$emit('refresh')"
    />

    <!-- Two-panel layout: Media left, Info sidebar right -->
    <div class="flex-1 flex overflow-hidden">
      <!-- LEFT: Media viewer -->
      <div class="flex-1 flex flex-col min-w-0 relative">
        <!-- Header bar -->
        <div class="flex items-center justify-between px-4 py-2 bg-black/50 shrink-0">
          <div class="text-white min-w-0 flex-1 mr-4">
            <div v-if="renaming" class="flex items-center gap-2">
              <input ref="renameInput" v-model="renameValue" @keydown.enter="doRename" @keydown.escape="renaming = false"
                class="bg-gray-800 border border-gray-500 rounded px-2 py-1 text-white text-sm flex-1 min-w-0" />
              <button @click="doRename" :disabled="renameSaving" class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 disabled:opacity-50">
                {{ renameSaving ? '...' : 'Save' }}
              </button>
              <button @click="renaming = false" class="px-2 py-1 text-gray-400 text-xs hover:text-white">Cancel</button>
            </div>
            <div v-else class="font-medium text-sm truncate cursor-pointer hover:text-blue-400" @click="startRename" title="Click to rename">{{ item.filename }}</div>
            <div class="text-xs text-gray-500 truncate">{{ displayMediaPath(item.current_path) }}</div>
          </div>
          <div class="flex items-center gap-1">
            <button v-if="isImage" @click="showEditor = true" class="text-white hover:text-blue-400 p-1.5" title="Edit image">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            </button>
            <button @click="showDeleteConfirm = true" class="text-white hover:text-red-400 p-1.5" title="Delete">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
            <!-- Toggle info panel (mobile: shows/hides; desktop: always visible) -->
            <button @click="showInfoPanel = !showInfoPanel" class="text-white hover:text-blue-400 p-1.5 lg:hidden" title="Toggle info">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </button>
            <button @click="$emit('close')" class="text-white hover:text-gray-300 p-1.5">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>

        <!-- Media display area -->
        <div class="flex-1 flex items-center justify-center relative overflow-hidden">
          <!-- Navigation Arrows -->
          <button @click="$emit('prev')" class="absolute left-3 top-1/2 -translate-y-1/2 text-white/60 hover:text-white p-2 bg-black/30 rounded-full z-10">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </button>
          <button @click="$emit('next')" class="absolute right-3 top-1/2 -translate-y-1/2 text-white/60 hover:text-white p-2 bg-black/30 rounded-full z-10">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </button>

          <!-- Media content -->
          <div class="relative max-w-full max-h-full p-4">
            <img v-if="isImage && !imageFailed" :src="`/api/media/${item.asset_uuid}/thumbnail/large`" :alt="item.filename"
              class="max-w-full max-h-[calc(100vh-4rem)] object-contain" ref="imageEl" @error="onImageError" />
            <img v-else-if="isImage && imageFailed" :src="`/api/media/${item.asset_uuid}/stream`" :alt="item.filename"
              class="max-w-full max-h-[calc(100vh-4rem)] object-contain" ref="imageEl" />
            <div v-else-if="isVideo" class="relative">
              <video v-if="!videoFailed" :src="`/api/media/${item.asset_uuid}/stream`" controls class="max-w-full max-h-[calc(100vh-4rem)]"
                @error="onVideoError" ref="videoEl" />
              <div v-else class="bg-gray-800 p-8 rounded-lg text-center">
                <img v-if="item.thumbnail_url" :src="item.thumbnail_url" :alt="item.filename"
                  class="max-w-md max-h-64 object-contain mx-auto mb-4 rounded opacity-60" />
                <svg v-else class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="text-ops-peach text-lg mb-1">{{ item.filename }}</div>
                <div class="text-ops-text-muted text-sm mb-4">This video format is not supported by your browser</div>
                <a :href="`/api/media/${item.asset_uuid}/stream`" download
                  class="inline-block px-5 py-2 bg-ops-violet text-white rounded hover:bg-ops-violet/80 transition-colors">
                  Download Video
                </a>
              </div>
            </div>
            <div v-else-if="isAudio" class="bg-gray-800 p-8 rounded-lg">
              <div class="text-white text-center mb-4">
                <svg class="w-16 h-16 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/></svg>
                {{ item.filename }}
              </div>
              <audio :src="`/api/media/${item.asset_uuid}/stream`" controls class="w-full" />
            </div>
            <div v-else class="bg-gray-800 p-8 rounded-lg text-center">
              <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
              <div class="text-white">{{ item.filename }}</div>
              <div class="text-gray-400 text-sm mt-2 uppercase">{{ itemExtension }}</div>
              <a :href="`/api/media/${item.asset_uuid}/stream`" download class="inline-block mt-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Download</a>
            </div>
            <!-- Face Regions Overlay -->
            <template v-if="isImage && showFaceRegions">
              <div v-for="face in sortedFaces" :key="face.id" class="absolute border-2 cursor-pointer group transition-all"
                :class="[
                  face.genealogy_person_id ? 'border-green-500' : 'border-yellow-500',
                  hoveredFaceId === face.id || selectedFace?.id === face.id ? 'border-4 shadow-lg shadow-blue-500/30' : ''
                ]"
                :style="getFaceRegionStyle(face)"
                @click="selectFace(face)"
                @mouseenter="hoveredFaceId = face.id"
                @mouseleave="hoveredFaceId = null">
                <!-- Number badge -->
                <div class="absolute -top-3 -left-3 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold text-white"
                  :class="face.genealogy_person_id ? 'bg-green-600' : 'bg-yellow-600'">
                  {{ faceIndex(face) }}
                </div>
                <div class="absolute -bottom-6 left-1/2 -translate-x-1/2 bg-black/70 text-white text-xs px-2 py-0.5 rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity">
                  {{ face.person_name || face.genealogy_name || 'Unknown' }}
                  <span v-if="face.genealogy_person_id" class="text-green-400">(linked)</span>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- RIGHT: Info sidebar (always visible on lg+, toggleable overlay on smaller) -->
      <transition name="slide-panel">
        <div v-if="showInfoPanel" class="info-sidebar w-full lg:w-[420px] xl:w-[480px] shrink-0 bg-gray-900 border-l border-gray-700 flex flex-col overflow-hidden
                    fixed inset-0 z-[55] lg:static lg:z-auto">
          <!-- Sidebar header -->
          <div class="flex items-center justify-between px-4 py-2 border-b border-gray-700 bg-gray-900 shrink-0">
            <!-- Tab buttons -->
            <div class="flex gap-1">
              <button v-for="tab in infoPanelTabs" :key="tab.id" @click="activeTab = tab.id"
                class="px-2.5 py-1.5 text-xs rounded transition-colors"
                :class="activeTab === tab.id ? tab.activeClass : 'text-gray-400 hover:text-white hover:bg-gray-800'">
                {{ tab.label }}
              </button>
            </div>
            <div class="flex items-center gap-2">
              <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer">
                <input type="checkbox" v-model="showFaceRegions" class="rounded w-3 h-3" />
                Faces
              </label>
              <button @click="showInfoPanel = false" class="text-gray-400 hover:text-white p-1 lg:hidden">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>
          </div>

          <!-- Scrollable content -->
          <div class="flex-1 overflow-y-auto">

      <!-- Faces Tab -->
      <div v-if="activeTab === 'faces'" class="p-4">
        <div v-if="loadingMeta" class="text-gray-500 text-center py-4">Loading...</div>
        <div v-else-if="faces.length === 0 && linkedPersons.length === 0" class="text-gray-500 text-center py-4">
          No faces detected. <button @click="showAddPerson = true" class="text-blue-400 hover:underline">Add person link</button>
        </div>
        <div v-else class="space-y-3">
          <!-- Detected faces -->
          <div v-if="faces.length > 0" class="flex flex-wrap gap-3">
            <div
              v-for="face in sortedFaces"
              :key="face.id"
              class="flex items-center gap-3 bg-gray-800 rounded-lg p-3 transition-all cursor-pointer"
              :class="[
                selectedFace?.id === face.id ? 'ring-2 ring-blue-500' : '',
                hoveredFaceId === face.id ? 'ring-2 ring-yellow-400/60 bg-gray-750' : ''
              ]"
              @mouseenter="hoveredFaceId = face.id"
              @mouseleave="hoveredFaceId = null"
              @click="selectFace(face)"
            >
              <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white shrink-0"
                :class="face.genealogy_person_id ? 'bg-green-600' : 'bg-yellow-600'">
                {{ faceIndex(face) }}
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-white text-sm truncate">{{ face.person_name || face.genealogy_name || 'Unknown' }}</div>
                <div v-if="face.genealogy_person_id" class="text-green-400 text-xs">Linked</div>
                <div v-else class="text-yellow-400 text-xs">Not linked</div>
              </div>
              <!-- Actions for linked faces -->
              <div v-if="face.genealogy_person_id" class="flex gap-1">
                <button @click="startReassign(face)" class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700" title="Reassign">Reassign</button>
                <button @click="doUnlinkFace(face)" class="px-2 py-1 bg-red-600/70 text-white text-xs rounded hover:bg-red-700" title="Unlink">Unlink</button>
              </div>
              <!-- Actions for unlinked faces -->
              <div v-else class="relative flex-1 max-w-[220px]">
                <input type="text" v-model="personSearch[face.id]"
                  @input="onPersonSearch(face.id)" @blur="onPersonBlur(face.id)"
                  @keydown.down.prevent="highlightNext(face.id)"
                  @keydown.up.prevent="highlightPrev(face.id)"
                  @keydown.enter.prevent="selectHighlighted(face.id)"
                  @keydown.escape="closeDropdown(face.id)"
                  placeholder="Type name to link..."
                  class="w-full text-sm bg-gray-700 border border-gray-600 rounded text-white px-3 py-1.5 placeholder-gray-500" />
                <div v-if="personResults[face.id]?.length > 0 || (personSearch[face.id]?.length >= 2 && personResults[face.id])"
                  class="absolute top-full left-0 right-0 mt-1 bg-gray-800 border border-gray-600 rounded-lg shadow-xl z-50 max-h-48 overflow-y-auto">
                  <button v-for="(p, idx) in (personResults[face.id] || [])" :key="p.id || p.name"
                    @mousedown.prevent="selectPersonForFace(face, p)"
                    class="w-full text-left px-3 py-2 text-sm hover:bg-gray-700 flex justify-between items-center"
                    :class="idx === personHighlight[face.id] ? 'bg-gray-700' : ''">
                    <span class="text-white">{{ p.given_name || '' }} {{ p.surname || p.name }}</span>
                    <span v-if="p.media_count" class="text-gray-500 text-xs">{{ p.media_count }} photos</span>
                  </button>
                  <div v-if="personSearch[face.id]?.length >= 2" class="border-t border-gray-700">
                    <button @mousedown.prevent="useNameOnly(face.id)"
                      class="w-full text-left px-3 py-2 text-sm text-blue-400 hover:bg-blue-900/30 flex items-center gap-2">
                      <span>+ Use "{{ personSearch[face.id] }}"</span>
                      <span class="text-gray-500 text-xs">(no tree link)</span>
                    </button>
                    <button @mousedown.prevent="createAndSelect(face.id)"
                      class="w-full text-left px-3 py-2 text-sm text-green-400 hover:bg-green-900/30 flex items-center gap-2">
                      <span>+ Create "{{ personSearch[face.id] }}" in tree</span>
                    </button>
                  </div>
                  <div v-if="personResults[face.id]?.length === 0 && personSearch[face.id]?.length >= 2"
                    class="px-3 py-2 text-gray-500 text-xs italic">No existing matches</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Linked persons (via genealogy_person_media, not faces) -->
          <div v-if="linkedPersons.length > 0">
            <div class="text-gray-500 text-xs mb-1 uppercase tracking-wide">Linked Persons</div>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="p in linkedPersons"
                :key="p.id"
                class="inline-flex items-center gap-1 bg-gray-800 text-white text-sm rounded px-3 py-1"
              >
                {{ p.name }}
                <button @click="doRemovePersonLink(p.id)" class="text-red-400 hover:text-red-300 ml-1" title="Remove link">x</button>
              </span>
            </div>
          </div>

          <!-- Add person link -->
          <button v-if="!showAddPerson" @click="showAddPerson = true" class="text-blue-400 text-xs hover:underline">+ Add person link</button>
        </div>

        <!-- Reassign typeahead (shown when reassigning) -->
        <div v-if="reassigningFace" class="mt-3 bg-gray-800 p-3 rounded-lg">
          <div class="flex items-center gap-2 mb-2">
            <span class="text-white text-sm">Reassign "{{ reassigningFace.person_name || 'face' }}" to:</span>
            <button @click="reassigningFace = null; reassignTargetId = ''" class="px-2 py-1 text-gray-400 text-xs hover:text-white ml-auto">Cancel</button>
          </div>
          <div class="relative">
            <input type="text" v-model="personSearch['reassign']"
              @input="onPersonSearch('reassign')" @blur="onPersonBlur('reassign')"
              @keydown.down.prevent="highlightNext('reassign')"
              @keydown.up.prevent="highlightPrev('reassign')"
              @keydown.enter.prevent="selectHighlighted('reassign')"
              @keydown.escape="closeDropdown('reassign')"
              placeholder="Type name to search..."
              class="w-full text-sm bg-gray-700 border border-gray-600 rounded text-white px-3 py-1.5 placeholder-gray-500" />
            <div v-if="personResults['reassign']?.length > 0 || (personSearch['reassign']?.length >= 2 && personResults['reassign'])"
              class="absolute top-full left-0 right-0 mt-1 bg-gray-800 border border-gray-600 rounded-lg shadow-xl z-50 max-h-48 overflow-y-auto">
              <button v-for="(p, idx) in (personResults['reassign'] || [])" :key="p.id || p.name"
                @mousedown.prevent="selectPersonForReassign(p)"
                class="w-full text-left px-3 py-2 text-sm hover:bg-gray-700 flex justify-between items-center"
                :class="idx === personHighlight['reassign'] ? 'bg-gray-700' : ''">
                <span class="text-white">{{ p.given_name || '' }} {{ p.surname || p.name }}</span>
                <span v-if="p.media_count" class="text-gray-500 text-xs">{{ p.media_count }} photos</span>
              </button>
              <div v-if="personResults['reassign']?.length === 0 && personSearch['reassign']?.length >= 2"
                class="px-3 py-2 text-gray-500 text-xs italic">No matches found</div>
            </div>
          </div>
        </div>

        <!-- Add person link form -->
        <div v-if="showAddPerson" class="mt-3 bg-gray-800 p-3 rounded-lg">
          <div class="flex items-center gap-2 mb-2">
            <span class="text-white text-sm">Add person:</span>
            <button @click="showAddPerson = false; addPersonId = ''" class="px-2 py-1 text-gray-400 text-xs hover:text-white ml-auto">Cancel</button>
          </div>
          <div class="relative">
            <input type="text" v-model="personSearch['addPerson']"
              @input="onPersonSearch('addPerson')" @blur="onPersonBlur('addPerson')"
              @keydown.down.prevent="highlightNext('addPerson')"
              @keydown.up.prevent="highlightPrev('addPerson')"
              @keydown.enter.prevent="selectHighlighted('addPerson')"
              @keydown.escape="closeDropdown('addPerson')"
              placeholder="Type name to search..."
              class="w-full text-sm bg-gray-700 border border-gray-600 rounded text-white px-3 py-1.5 placeholder-gray-500" />
            <div v-if="personResults['addPerson']?.length > 0 || (personSearch['addPerson']?.length >= 2 && personResults['addPerson'])"
              class="absolute top-full left-0 right-0 mt-1 bg-gray-800 border border-gray-600 rounded-lg shadow-xl z-50 max-h-48 overflow-y-auto">
              <button v-for="(p, idx) in (personResults['addPerson'] || [])" :key="p.id || p.name"
                @mousedown.prevent="selectPersonForAdd(p)"
                class="w-full text-left px-3 py-2 text-sm hover:bg-gray-700 flex justify-between items-center"
                :class="idx === personHighlight['addPerson'] ? 'bg-gray-700' : ''">
                <span class="text-white">{{ p.given_name || '' }} {{ p.surname || p.name }}</span>
                <span v-if="p.media_count" class="text-gray-500 text-xs">{{ p.media_count }} photos</span>
              </button>
              <div v-if="personSearch['addPerson']?.length >= 2" class="border-t border-gray-700">
                <button @mousedown.prevent="useNameOnly('addPerson')"
                  class="w-full text-left px-3 py-2 text-sm text-blue-400 hover:bg-blue-900/30 flex items-center gap-2">
                  <span>+ Use "{{ personSearch['addPerson'] }}"</span>
                  <span class="text-gray-500 text-xs">(no tree link)</span>
                </button>
                <button @mousedown.prevent="createAndSelect('addPerson')"
                  class="w-full text-left px-3 py-2 text-sm text-green-400 hover:bg-green-900/30 flex items-center gap-2">
                  <span>+ Create "{{ personSearch['addPerson'] }}" in tree</span>
                </button>
              </div>
              <div v-if="personResults['addPerson']?.length === 0 && personSearch['addPerson']?.length >= 2"
                class="px-3 py-2 text-gray-500 text-xs italic">No existing matches</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Metadata Tab (read-only, comprehensive) -->
      <div v-if="activeTab === 'metadata'" class="p-4">
        <!-- Section nav pills -->
        <div class="flex flex-wrap gap-1.5 mb-3">
          <button v-for="sec in metaSections" :key="sec.id"
            @click="activeMetaSection = sec.id"
            class="px-2.5 py-1 text-xs rounded-full transition-colors"
            :class="activeMetaSection === sec.id
              ? 'bg-blue-600 text-white'
              : 'bg-gray-800 text-gray-400 hover:bg-gray-700 hover:text-white'"
          >
            {{ sec.label }}
            <span v-if="sec.badge" class="ml-1 opacity-60">{{ sec.badge }}</span>
          </button>
        </div>

        <!-- EXIF / RAW METADATA section -->
        <div v-if="activeMetaSection === 'exif'" class="text-sm">
          <div v-if="Object.keys(exifGroups).length === 0" class="text-center py-6 text-gray-500">
            <div class="text-xs">No EXIF data available</div>
            <div class="text-xs text-gray-600 mt-1">File may not contain embedded metadata</div>
          </div>
          <div v-else class="space-y-3">
            <div v-for="(fields, group) in exifGroups" :key="group" class="bg-gray-800/30 rounded-lg overflow-hidden">
              <!-- Group header -->
              <button @click="toggleExifGroup(group)" class="w-full flex items-center justify-between px-3 py-2 hover:bg-gray-800/50 transition-colors">
                <div class="flex items-center gap-2">
                  <span class="text-xs font-semibold uppercase tracking-wider" :class="exifGroupColor(group)">{{ group }}</span>
                  <span class="text-gray-600 text-xs">{{ Object.keys(fields).length }}</span>
                </div>
                <svg class="w-3.5 h-3.5 text-gray-500 transition-transform" :class="expandedExifGroups[group] ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </button>
              <!-- Group fields -->
              <div v-if="expandedExifGroups[group]" class="px-3 pb-2">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-0.5">
                  <div v-for="(val, key) in fields" :key="key" class="flex gap-2 py-0.5 border-b border-gray-800/50 last:border-0">
                    <span class="text-gray-400 text-xs font-medium shrink-0 w-44 truncate cursor-help" :title="key">{{ friendlyName(key) }}</span>
                    <span class="text-gray-200 text-sm flex-1 break-words" :title="formatExifValue(val, key)">{{ formatExifValue(val, key) }}</span>
                  </div>
                </div>
                <!-- GPS map link -->
                <div v-if="group === 'GPS' && gpsCoords" class="mt-2 pt-1 border-t border-gray-800/50">
                  <a :href="'https://www.google.com/maps?q=' + gpsCoords.lat + ',' + gpsCoords.lng" target="_blank"
                    class="inline-flex items-center gap-1.5 text-xs text-blue-400 hover:text-blue-300">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    View on Google Maps ({{ gpsCoords.lat.toFixed(6) }}, {{ gpsCoords.lng.toFixed(6) }})
                  </a>
                </div>
                <!-- Face regions detail -->
                <div v-if="group === 'Faces' && fields.RegionList" class="mt-2 pt-1 border-t border-gray-800/50">
                  <div class="text-xs text-gray-500 mb-1">Face Regions</div>
                  <div v-for="(region, i) in parseFaceRegions(fields.RegionList)" :key="i"
                    class="flex items-center gap-3 py-1 text-xs">
                    <span class="text-white font-medium">{{ region.name || 'Unknown' }}</span>
                    <span class="text-gray-500">{{ region.type || 'Face' }}</span>
                    <span v-if="region.area" class="text-gray-600 font-mono">{{ region.area }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- FILE INFO section -->
        <div v-if="activeMetaSection === 'file'" class="space-y-2 text-sm">
          <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <MetaField label="Filename" :value="fileData.filename" />
            <MetaField label="Extension" :value="(fileData.extension || '').toUpperCase()" />
            <MetaField label="MIME Type" :value="fileData.mime_type" />
            <MetaField label="Size" :value="formatBytes(fileData.file_size)" />
            <MetaField label="Status" :value="fileData.status" :color="fileData.status === 'active' ? 'green' : 'yellow'" />
            <MetaField label="Original Source" :value="formatSource(fileData.original_source)" />
            <MetaField label="Content Hash" :value="fileData.content_hash" mono />
            <MetaField label="Nextcloud File ID" :value="fileData.nextcloud_fileid" />
            <MetaField label="Created" :value="formatDate(fileData.created_at)" class="col-span-1" />
            <MetaField label="Modified" :value="formatDate(fileData.nextcloud_modified_at || fileData.updated_at)" />
            <MetaField label="Path Updated" :value="formatDate(fileData.path_updated_at)" />
            <MetaField label="Last Verified" :value="formatDate(fileData.last_verified_at)" />
          </div>
          <div class="grid grid-cols-1 gap-y-2 mt-2">
            <MetaField label="Current Path" :value="displayMediaPath(fileData.current_path)" mono full />
            <MetaField v-if="fileData.original_path && fileData.original_path !== fileData.current_path" label="Original Path" :value="displayMediaPath(fileData.original_path)" mono full />
          </div>
          <div v-if="perceptualHash" class="grid grid-cols-2 gap-x-4 gap-y-3 mt-2 pt-2 border-t border-gray-800">
            <MetaField label="Perceptual Hash" :value="perceptualHash.phash" mono />
            <MetaField label="Difference Hash" :value="perceptualHash.dhash" mono />
            <MetaField label="Hash Generated" :value="formatDate(perceptualHash.computed_at)" />
          </div>
        </div>

        <!-- DATES section -->
        <div v-if="activeMetaSection === 'dates'" class="space-y-2 text-sm">
          <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <MetaField label="Date Taken" :value="formatDate(fileData.date_taken)" :color="fileData.date_taken ? 'white' : 'muted'" />
            <MetaField label="Source" :value="formatDateSource(fileData.date_taken_source)" />
            <MetaField label="Confidence" :value="fileData.date_taken_confidence != null ? (parseFloat(fileData.date_taken_confidence) * 100).toFixed(0) + '%' : null"
              :color="parseFloat(fileData.date_taken_confidence) >= 0.8 ? 'green' : parseFloat(fileData.date_taken_confidence) >= 0.5 ? 'yellow' : 'red'" />
            <MetaField label="Extracted At" :value="formatDate(fileData.date_extracted_at)" />
          </div>
          <div v-if="fileData.date_taken_reasoning" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">Reasoning</div>
            <div class="text-gray-300 bg-gray-800/50 rounded px-3 py-2 text-xs leading-relaxed">{{ fileData.date_taken_reasoning }}</div>
          </div>
        </div>

        <!-- AI ANALYSIS section -->
        <div v-if="activeMetaSection === 'ai'" class="space-y-2 text-sm">
          <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <MetaField label="Document Type" :value="fileData.ai_document_type" />
            <MetaField label="Analyzed At" :value="formatDate(fileData.ai_analyzed_at)" />
            <MetaField label="Analysis Version" :value="fileData.ai_analysis_version" />
            <MetaField label="Category" :value="fileData.category" />
          </div>
          <div v-if="fileData.ai_description" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">AI Description</div>
            <div class="text-gray-200 bg-gray-800/50 rounded px-3 py-2 text-sm leading-relaxed">{{ fileData.ai_description }}</div>
          </div>
          <div v-if="fileData.title" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">Title</div>
            <div class="text-gray-200">{{ fileData.title }}</div>
          </div>
          <div v-if="fileData.description" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">Description</div>
            <div class="text-gray-200 bg-gray-800/50 rounded px-3 py-2 text-sm leading-relaxed">{{ fileData.description }}</div>
          </div>
          <div v-if="displayTags.length > 0" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">Tags</div>
            <div class="flex flex-wrap gap-1.5">
              <span v-for="tag in displayTags" :key="tag" class="px-2 py-0.5 bg-blue-900/40 text-blue-300 text-xs rounded-full border border-blue-800/50">{{ tag }}</span>
            </div>
          </div>
          <div v-if="displayAiTags.length > 0" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">AI Tags</div>
            <div class="flex flex-wrap gap-1.5">
              <span v-for="tag in displayAiTags" :key="tag" class="px-2 py-0.5 bg-purple-900/40 text-purple-300 text-xs rounded-full border border-purple-800/50">{{ tag }}</span>
            </div>
          </div>
          <div v-if="fileData.ai_detected_text" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">Detected Text (OCR)</div>
            <div class="text-gray-300 bg-gray-800/50 rounded px-3 py-2 text-xs leading-relaxed max-h-40 overflow-y-auto font-mono whitespace-pre-wrap">{{ fileData.ai_detected_text }}</div>
          </div>
          <div v-if="fileData.search_keywords" class="mt-2">
            <div class="text-gray-500 text-xs mb-1">Search Keywords</div>
            <div class="text-gray-400 text-xs">{{ fileData.search_keywords }}</div>
          </div>
        </div>

        <!-- RAG CONTENT section -->
        <div v-if="activeMetaSection === 'rag'" class="space-y-3 text-sm">
          <div v-if="!rag.indexed" class="text-center py-6 text-gray-500">
            <svg class="w-8 h-8 mx-auto mb-2 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Not indexed in RAG
          </div>
          <template v-else>
            <!-- RAG status bar -->
            <div class="flex flex-wrap gap-4 items-center bg-gray-800/50 rounded-lg px-3 py-2">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-green-500"></div>
                <span class="text-green-400 text-xs font-medium">Indexed</span>
              </div>
              <MetaField label="Type" :value="rag.source_type" inline />
              <MetaField label="Doc Type" :value="rag.document_type" inline />
              <MetaField label="Chunks" :value="rag.chunk_count" inline />
              <MetaField label="Size" :value="formatBytes(rag.content_bytes)" inline />
              <MetaField label="Indexed" :value="formatDate(rag.indexed_at)" inline />
              <MetaField v-if="rag.contextualized_at" label="Contextualized" :value="formatDate(rag.contextualized_at)" inline />
              <MetaField v-if="rag.raptor_indexed_at" label="RAPTOR" :value="formatDate(rag.raptor_indexed_at)" inline />
            </div>

            <!-- RAG metadata -->
            <div v-if="rag.metadata && Object.keys(rag.metadata).length > 0" class="bg-gray-800/30 rounded-lg px-3 py-2">
              <div class="text-gray-500 text-xs mb-1.5 font-medium">Document Metadata</div>
              <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-1">
                <div v-for="(val, key) in rag.metadata" :key="key" class="flex gap-2 text-xs">
                  <span class="text-gray-500 shrink-0">{{ key }}:</span>
                  <span class="text-gray-300 truncate">{{ formatMetadataDisplayValue(val) }}</span>
                </div>
              </div>
            </div>

            <!-- RAG content (the actual extracted text) -->
            <div v-if="rag.content">
              <div class="flex items-center justify-between mb-1.5">
                <div class="text-gray-500 text-xs font-medium">Extracted Content</div>
                <div class="flex items-center gap-2">
                  <span class="text-gray-600 text-xs">{{ formatBytes(rag.content_bytes) }}</span>
                  <button @click="ragContentExpanded = !ragContentExpanded" class="text-xs text-blue-400 hover:text-blue-300">
                    {{ ragContentExpanded ? 'Show less' : 'Show all' }}
                  </button>
                </div>
              </div>
              <div
                class="bg-gray-950 border border-gray-800 rounded-lg px-4 py-3 text-gray-300 text-sm leading-relaxed whitespace-pre-wrap overflow-y-auto transition-all"
                :class="ragContentExpanded ? 'max-h-[50vh]' : 'max-h-48'"
              >{{ rag.content }}</div>
            </div>

            <!-- Context prefix -->
            <div v-if="rag.context_prefix" class="mt-1">
              <div class="text-gray-500 text-xs mb-1 font-medium">Context Prefix</div>
              <div class="text-gray-400 bg-gray-800/30 rounded px-3 py-2 text-xs italic">{{ rag.context_prefix }}</div>
            </div>

            <!-- RAG chunks preview -->
            <div v-if="rag.chunks && rag.chunks.length > 0">
              <button @click="showRagChunks = !showRagChunks" class="flex items-center gap-1.5 text-gray-500 text-xs hover:text-gray-300">
                <svg class="w-3 h-3 transition-transform" :class="showRagChunks ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
                {{ rag.chunks.length }} Embedding Chunks
              </button>
              <div v-if="showRagChunks" class="mt-2 space-y-1.5 max-h-48 overflow-y-auto">
                <div v-for="(chunk, i) in rag.chunks" :key="chunk.id" class="flex gap-2 text-xs bg-gray-800/40 rounded px-2.5 py-1.5">
                  <span class="text-gray-600 shrink-0 w-6 text-right">#{{ i + 1 }}</span>
                  <span class="text-gray-400 truncate flex-1">{{ chunk.preview }}</span>
                  <span class="text-gray-600 shrink-0">{{ formatBytes(chunk.bytes) }}</span>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- PROCESSING STATUS section -->
        <div v-if="activeMetaSection === 'status'" class="space-y-2 text-sm">
          <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <MetaField label="EXIF Date Written" :value="writebackStatus(fileData.exif_written)" :color="writebackColor(fileData.exif_written)" />
            <MetaField label="EXIF Faces Written" :value="writebackStatus(fileData.exif_faces_written)" :color="writebackColor(fileData.exif_faces_written)" />
            <MetaField label="EXIF Tags Written" :value="writebackStatus(fileData.exif_tags_written)" :color="writebackColor(fileData.exif_tags_written)" />
            <MetaField label="Face Count" :value="fileData.face_count" />
            <MetaField label="Face Scanned" :value="formatDate(fileData.face_scan_at)" />
            <MetaField label="RAG Indexed" :value="formatDate(fileData.rag_indexed_at)" />
            <MetaField label="Thumbnail Generated" :value="formatDate(fileData.thumbnail_generated_at)" />
            <MetaField label="Thumbnail Sizes" :value="fileData.thumbnail_sizes ? Object.keys(fileData.thumbnail_sizes).join(', ') : null" />
          </div>
          <div v-if="fileData.thumbnail_error" class="mt-2">
            <MetaField label="Thumbnail Error" :value="fileData.thumbnail_error" color="red" full />
          </div>
          <div class="grid grid-cols-2 gap-x-4 gap-y-3 mt-2 pt-2 border-t border-gray-800">
            <MetaField label="Chunks" :value="fileData.chunk_count" />
            <MetaField label="Chunk Algorithm" :value="fileData.chunk_algorithm" />
            <MetaField label="Chunked At" :value="formatDate(fileData.chunked_at)" />
            <MetaField label="Semantic Chunks" :value="fileData.semantic_chunk_count" />
            <MetaField label="Semantic Indexed" :value="formatDate(fileData.semantic_indexed_at)" />
            <MetaField label="Hash Verified" :value="formatDate(fileData.content_hash_verified_at)" />
            <MetaField label="Verify Failures" :value="fileData.verification_failures" :color="fileData.verification_failures > 0 ? 'yellow' : 'muted'" />
          </div>
          <div v-if="fileData.quarantine_status" class="mt-2 pt-2 border-t border-gray-800">
            <div class="text-xs text-red-400 font-medium mb-1">Quarantine</div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
              <MetaField label="Status" :value="fileData.quarantine_status" color="red" />
              <MetaField label="Reason" :value="fileData.quarantine_reason" color="red" />
              <MetaField label="Quarantined" :value="formatDate(fileData.quarantined_at)" />
              <MetaField label="Reviewed" :value="formatDate(fileData.quarantine_reviewed_at)" />
            </div>
            <div v-if="fileData.quarantine_details" class="mt-1">
              <MetaField label="Details" :value="fileData.quarantine_details" full />
            </div>
          </div>
        </div>
      </div>

      <!-- Edit Metadata Tab -->
      <div v-if="activeTab === 'edit'" class="p-4">
        <!-- Edit section pills -->
        <div class="flex flex-wrap gap-1.5 mb-3">
          <button v-for="sec in editSections" :key="sec.id"
            @click="activeEditSection = sec.id"
            class="px-2.5 py-1 text-xs rounded-full transition-colors"
            :class="activeEditSection === sec.id
              ? 'bg-orange-600 text-white'
              : 'bg-gray-800 text-gray-400 hover:bg-gray-700 hover:text-white'"
          >{{ sec.label }}</button>
        </div>

        <!-- EXIF/Dates edit -->
        <div v-if="activeEditSection === 'core'" class="space-y-3 text-sm">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label class="text-gray-500 text-xs block mb-1">Date Taken</label>
              <input type="datetime-local" v-model="editForm.date_taken"
                class="w-full bg-gray-800 border border-gray-600 rounded text-white px-2 py-1.5 text-sm" />
            </div>
            <div>
              <label class="text-gray-500 text-xs block mb-1">Title</label>
              <input type="text" v-model="editForm.title"
                class="w-full bg-gray-800 border border-gray-600 rounded text-white px-2 py-1.5 text-sm"
                placeholder="File title..." />
            </div>
            <div>
              <label class="text-gray-500 text-xs block mb-1">Copyright</label>
              <input type="text" v-model="editForm.copyright"
                class="w-full bg-gray-800 border border-gray-600 rounded text-white px-2 py-1.5 text-sm"
                placeholder="Copyright holder..." />
            </div>
          </div>
          <div>
            <label class="text-gray-500 text-xs block mb-1">Description</label>
            <textarea v-model="editForm.description" rows="2"
              class="w-full bg-gray-800 border border-gray-600 rounded text-white px-2 py-1.5 text-sm resize-none"
              placeholder="Enter description..." />
          </div>
          <div>
            <label class="text-gray-500 text-xs block mb-1">Tags (comma-separated)</label>
            <input type="text" v-model="editForm.tags"
              class="w-full bg-gray-800 border border-gray-600 rounded text-white px-2 py-1.5 text-sm"
              placeholder="tag1, tag2, tag3" />
          </div>
        </div>

        <!-- Custom EXIF fields edit -->
        <div v-if="activeEditSection === 'exif'" class="space-y-3 text-sm">
          <div class="text-gray-500 text-xs mb-1">Write arbitrary EXIF/XMP/IPTC fields directly to file via exiftool</div>
          <div v-for="(field, i) in editForm.exifFields" :key="i" class="flex gap-2 items-center">
            <input type="text" v-model="field.tag" placeholder="Tag (e.g. EXIF:Artist)"
              class="flex-1 bg-gray-800 border border-gray-600 rounded text-white px-2 py-1.5 text-xs font-mono" />
            <input type="text" v-model="field.value" placeholder="Value"
              class="flex-1 bg-gray-800 border border-gray-600 rounded text-white px-2 py-1.5 text-xs" />
            <button @click="editForm.exifFields.splice(i, 1)" class="text-red-400 hover:text-red-300 text-xs px-1">X</button>
          </div>
          <button @click="editForm.exifFields.push({ tag: '', value: '' })"
            class="text-blue-400 text-xs hover:text-blue-300">+ Add field</button>
          <div class="text-gray-600 text-xs mt-1">
            Common tags: EXIF:Artist, IPTC:Caption-Abstract, XMP:Creator, EXIF:UserComment, IPTC:City, IPTC:Country-PrimaryLocationName
          </div>
        </div>

        <!-- RAG content edit -->
        <div v-if="activeEditSection === 'rag'" class="space-y-3 text-sm">
          <div v-if="!rag.indexed" class="text-center py-4 text-gray-500 text-xs">No RAG document to edit</div>
          <template v-else>
            <div class="flex items-center justify-between mb-1">
              <label class="text-gray-500 text-xs">Extracted Content (editable)</label>
              <span class="text-gray-600 text-xs">{{ formatBytes((editForm.ragContent || '').length) }}</span>
            </div>
            <textarea v-model="editForm.ragContent" rows="8"
              class="w-full bg-gray-950 border border-gray-700 rounded text-gray-300 px-3 py-2 text-sm font-mono resize-y leading-relaxed"
              placeholder="RAG extracted content..." />
          </template>
        </div>

        <!-- Save bar -->
        <div class="flex items-center gap-3 mt-4 pt-3 border-t border-gray-800">
          <button @click="saveMetadata(false)" :disabled="saving"
            class="px-4 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 disabled:opacity-50">
            {{ saving ? 'Saving...' : 'Save to DB' }}
          </button>
          <button @click="saveMetadata(true)" :disabled="saving"
            class="px-4 py-1.5 bg-orange-600 text-white text-sm rounded hover:bg-orange-700 disabled:opacity-50"
            title="Save to database AND write EXIF changes to physical file">
            {{ saving ? 'Saving...' : 'Save & Write to File' }}
          </button>
          <span class="text-gray-600 text-xs flex-1">
            "Save to DB" queues writeback for next scheduled job. "Save & Write to File" writes immediately.
          </span>
        </div>
      </div><!-- /edit tab -->
          </div><!-- /scrollable content -->
        </div><!-- /info-sidebar -->
      </transition>
    </div><!-- /two-panel layout -->

    <!-- Toast notification -->
    <transition name="fade">
      <div v-if="toast" class="fixed top-16 right-6 z-[60] bg-gray-800 border border-gray-600 rounded-lg px-4 py-3 shadow-lg flex items-center gap-3 max-w-sm">
        <span class="text-white text-sm">{{ toast.message }}</span>
        <button v-if="toast.action" @click="toast.action.handler(); toast = null"
          class="px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 whitespace-nowrap">{{ toast.action.label }}</button>
        <button @click="toast = null" class="text-gray-400 hover:text-white text-xs ml-1">X</button>
      </div>
    </transition>

    <!-- Delete Confirmation Dialog -->
    <div v-if="showDeleteConfirm" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/70" @click.self="showDeleteConfirm = false">
      <div class="bg-gray-800 border border-gray-600 rounded-lg p-6 max-w-md mx-4 shadow-2xl">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-full bg-red-600/20 flex items-center justify-center">
            <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
          </div>
          <h3 class="text-white text-lg font-semibold">Delete File Permanently?</h3>
        </div>
        <p class="text-gray-300 text-sm mb-2">This will permanently delete the physical file from disk:</p>
        <p class="text-white text-sm font-mono bg-gray-900 px-3 py-2 rounded mb-4 break-all">{{ item.filename }}</p>
        <p class="text-red-400 text-xs mb-6">This action cannot be undone.</p>
        <div class="flex justify-end gap-3">
          <button @click="showDeleteConfirm = false" class="px-4 py-2 bg-gray-700 text-white text-sm rounded hover:bg-gray-600">Cancel</button>
          <button @click="doDeleteFile" :disabled="deleting" class="px-4 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700 disabled:opacity-50">
            {{ deleting ? 'Deleting...' : 'Delete Forever' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, reactive, onMounted, onUnmounted, watch, nextTick } from 'vue'
import axios from 'axios'
import ImageEditor from './ImageEditor.vue'

// Inline MetaField component
const MetaField = {
  props: {
    label: String,
    value: [String, Number],
    color: { type: String, default: 'white' },
    mono: Boolean,
    full: Boolean,
    inline: Boolean,
  },
  template: `
    <div :class="[inline ? 'flex items-center gap-1.5' : '', full ? 'col-span-full' : '', 'min-w-0']">
      <div :class="inline ? 'text-gray-400 text-xs font-medium' : 'text-gray-400 text-xs font-medium mb-0.5'">{{ label }}</div>
      <div
        :class="[
          colorClass,
          mono ? 'font-mono text-xs break-all' : 'text-sm',
          inline ? 'text-xs' : '',
          full ? 'break-all' : 'truncate',
          (value == null || value === '') ? 'italic' : ''
        ]"
        :title="String(displayValue)"
      >{{ displayValue }}</div>
    </div>
  `,
  computed: {
    displayValue() { return this.value != null && this.value !== '' ? this.value : 'N/A' },
    colorClass() {
      const c = this.color
      if (this.value == null || this.value === '') return 'text-gray-600'
      if (c === 'green') return 'text-green-400'
      if (c === 'yellow') return 'text-yellow-400'
      if (c === 'red') return 'text-red-400'
      if (c === 'muted') return 'text-gray-500'
      return 'text-white'
    }
  }
}

const props = defineProps({
  item: { type: Object, required: true },
})

const emit = defineEmits(['close', 'next', 'prev', 'refresh', 'deleted'])

const showEditor = ref(false)
const lightboxEl = ref(null)
const imageEl = ref(null)
const activeTab = ref('metadata')
const showFaceRegions = ref(true)
const showInfoPanel = ref(true)

const infoPanelTabs = [
  { id: 'metadata', label: 'Info', activeClass: 'bg-blue-600 text-white' },
  { id: 'faces', label: 'Faces', activeClass: 'bg-blue-600 text-white' },
  { id: 'edit', label: 'Edit', activeClass: 'bg-orange-600 text-white' },
]
const selectedFace = ref(null)
const hoveredFaceId = ref(null)
const linkPersonId = ref({})
const loadingMeta = ref(false)
const toast = ref(null)

// Rename
const renaming = ref(false)
const renameValue = ref('')
const renameSaving = ref(false)
const renameInput = ref(null)

// Data fetched from API
const faces = ref([])
const linkedPersons = ref([])
const persons = ref([])
const rag = reactive({ indexed: false, chunk_count: 0, content_bytes: 0, indexed_at: null, source_type: null, document_type: null, title: null, designation: null, content: null, context_prefix: null, metadata: null, chunks: [], contextualized_at: null, raptor_indexed_at: null, created_at: null })
const fileData = reactive({}) // All file_registry columns
const perceptualHash = ref(null)
const meta = reactive({
  date_taken: null,
  date_taken_source: null,
  date_taken_confidence: null,
  ai_description: null,
  tags: null,
  exif_written: null,
  exif_faces_written: null,
  exif_tags_written: null,
})

// Metadata tab state
const metaPanelExpanded = ref(false)
const activeMetaSection = ref('exif')
const ragContentExpanded = ref(false)
const showRagChunks = ref(false)
const rawExif = ref({})

// EXIF display groups - organize raw exiftool output into logical sections
const exifGroupOrder = ['Camera', 'Lens', 'Exposure', 'GPS', 'Faces', 'Dates', 'Image', 'Tags/Keywords', 'File', 'Other']
const exifGroups = computed(() => {
  const data = rawExif.value
  if (!data || Object.keys(data).length === 0) return {}

  const groups = {}
  const groupMap = {
    // Camera
    'EXIF:Make': 'Camera', 'EXIF:Model': 'Camera', 'ExifIFD:Make': 'Camera', 'ExifIFD:Model': 'Camera',
    'EXIF:Software': 'Camera', 'ExifIFD:Software': 'Camera',
    'MakerNotes': 'Camera',
    // Lens
    'ExifIFD:LensInfo': 'Lens', 'ExifIFD:LensMake': 'Lens', 'ExifIFD:LensModel': 'Lens',
    'EXIF:LensInfo': 'Lens', 'EXIF:LensMake': 'Lens', 'EXIF:LensModel': 'Lens',
    'Composite:LensID': 'Lens', 'Composite:Lens': 'Lens',
    // Exposure
    'ExifIFD:ExposureTime': 'Exposure', 'ExifIFD:FNumber': 'Exposure', 'ExifIFD:ISO': 'Exposure',
    'ExifIFD:ExposureProgram': 'Exposure', 'ExifIFD:MeteringMode': 'Exposure',
    'ExifIFD:Flash': 'Exposure', 'ExifIFD:FocalLength': 'Exposure',
    'ExifIFD:WhiteBalance': 'Exposure', 'ExifIFD:DigitalZoomRatio': 'Exposure',
    'ExifIFD:SceneCaptureType': 'Exposure', 'ExifIFD:BrightnessValue': 'Exposure',
    'ExifIFD:ExposureCompensation': 'Exposure', 'ExifIFD:MaxApertureValue': 'Exposure',
    'EXIF:ExposureTime': 'Exposure', 'EXIF:FNumber': 'Exposure', 'EXIF:ISO': 'Exposure',
    'EXIF:FocalLength': 'Exposure', 'EXIF:Flash': 'Exposure',
    'Composite:ShutterSpeed': 'Exposure', 'Composite:Aperture': 'Exposure',
    'Composite:FOV': 'Exposure', 'Composite:FocalLength35efl': 'Exposure',
    'Composite:LightValue': 'Exposure', 'Composite:ScaleFactor35efl': 'Exposure',
    'Composite:CircleOfConfusion': 'Exposure', 'Composite:HyperfocalDistance': 'Exposure',
    // GPS
    'GPS:GPSLatitude': 'GPS', 'GPS:GPSLongitude': 'GPS', 'GPS:GPSAltitude': 'GPS',
    'GPS:GPSLatitudeRef': 'GPS', 'GPS:GPSLongitudeRef': 'GPS', 'GPS:GPSAltitudeRef': 'GPS',
    'GPS:GPSTimeStamp': 'GPS', 'GPS:GPSDateStamp': 'GPS', 'GPS:GPSVersionID': 'GPS',
    'Composite:GPSLatitude': 'GPS', 'Composite:GPSLongitude': 'GPS',
    'Composite:GPSAltitude': 'GPS', 'Composite:GPSPosition': 'GPS',
    // Dates
    'ExifIFD:DateTimeOriginal': 'Dates', 'ExifIFD:CreateDate': 'Dates',
    'ExifIFD:ModifyDate': 'Dates', 'ExifIFD:OffsetTime': 'Dates',
    'ExifIFD:OffsetTimeOriginal': 'Dates', 'ExifIFD:OffsetTimeDigitized': 'Dates',
    'EXIF:DateTimeOriginal': 'Dates', 'EXIF:CreateDate': 'Dates', 'EXIF:ModifyDate': 'Dates',
    'IFD0:ModifyDate': 'Dates',
    'XMP:DateCreated': 'Dates', 'XMP:CreateDate': 'Dates', 'XMP:ModifyDate': 'Dates',
    'XMP:MetadataDate': 'Dates',
    'Composite:DateTimeCreated': 'Dates', 'Composite:SubSecCreateDate': 'Dates',
    'Composite:SubSecDateTimeOriginal': 'Dates', 'Composite:SubSecModifyDate': 'Dates',
    // Image
    'File:ImageWidth': 'Image', 'File:ImageHeight': 'Image',
    'EXIF:ImageWidth': 'Image', 'EXIF:ImageHeight': 'Image',
    'ExifIFD:ExifImageWidth': 'Image', 'ExifIFD:ExifImageHeight': 'Image',
    'EXIF:Orientation': 'Image', 'IFD0:Orientation': 'Image',
    'EXIF:XResolution': 'Image', 'EXIF:YResolution': 'Image',
    'EXIF:ResolutionUnit': 'Image', 'IFD0:XResolution': 'Image', 'IFD0:YResolution': 'Image',
    'EXIF:ColorSpace': 'Image', 'ExifIFD:ColorSpace': 'Image',
    'EXIF:Compression': 'Image', 'IFD0:Compression': 'Image',
    'Composite:ImageSize': 'Image', 'Composite:Megapixels': 'Image',
    'ICC_Profile': 'Image',
    // File
    'System:FileName': 'File', 'System:Directory': 'File', 'System:FileSize': 'File',
    'System:FileModifyDate': 'File', 'System:FileAccessDate': 'File',
    'System:FileInodeChangeDate': 'File', 'System:FilePermissions': 'File',
    'File:FileType': 'File', 'File:FileTypeExtension': 'File', 'File:MIMEType': 'File',
    'File:ExifByteOrder': 'File', 'File:CurrentIPTCDigest': 'File',
    'File:BitsPerSample': 'File', 'File:ColorComponents': 'File',
    'File:EncodingProcess': 'File', 'File:YCbCrSubSampling': 'File',
  }

  for (const [key, val] of Object.entries(data)) {
    if (val === null || val === undefined || val === '') continue
    // Skip binary/thumbnail data
    if (key.includes('ThumbnailImage') || key.includes('PreviewImage')) continue

    let group = groupMap[key]
    if (!group) {
      // Auto-group by prefix
      if (key.startsWith('XMP-mwg-rs:') || key.startsWith('XMP:Region') || key.includes('Region')) group = 'Faces'
      else if (key.includes('GPS')) group = 'GPS'
      else if (key.includes('Date') || key.includes('Time')) group = 'Dates'
      else if (key.includes('Keyword') || key.includes('Subject') || key.includes('Tag')) group = 'Tags/Keywords'
      else if (key.startsWith('XMP')) group = 'Tags/Keywords'
      else if (key.startsWith('IPTC')) group = 'Tags/Keywords'
      else if (key.startsWith('Composite')) group = 'Image'
      else if (key.startsWith('System') || key.startsWith('File:')) group = 'File'
      else group = 'Other'
    }

    if (!groups[group]) groups[group] = {}
    // Simplify key: remove group prefix for display
    const displayKey = key.includes(':') ? key.split(':').pop() : key
    groups[group][displayKey] = val
  }

  // Sort groups by defined order
  const sorted = {}
  for (const g of exifGroupOrder) {
    if (groups[g] && Object.keys(groups[g]).length > 0) sorted[g] = groups[g]
  }
  // Add any remaining
  for (const g of Object.keys(groups)) {
    if (!sorted[g] && Object.keys(groups[g]).length > 0) sorted[g] = groups[g]
  }
  return sorted
})

const metaSections = computed(() => {
  const exifCount = Object.keys(exifGroups.value).reduce((sum, g) => sum + Object.keys(exifGroups.value[g]).length, 0)
  return [
    { id: 'exif', label: 'EXIF / Raw', badge: exifCount || null },
    { id: 'file', label: 'File Info' },
    { id: 'dates', label: 'Dates' },
    { id: 'ai', label: 'AI Analysis', badge: fileData.ai_description ? '1' : null },
    { id: 'rag', label: 'RAG Content', badge: rag.indexed ? rag.chunk_count : null },
    { id: 'status', label: 'Processing' },
  ]
})

const expandedExifGroups = reactive({
  Camera: true, Lens: true, Exposure: true, GPS: true, Faces: true, Dates: true,
  Image: false, 'Tags/Keywords': true, File: false, Other: false,
})

function toggleExifGroup(group) {
  expandedExifGroups[group] = !expandedExifGroups[group]
}

function exifGroupColor(group) {
  const colors = {
    Camera: 'text-blue-400', Lens: 'text-blue-300', Exposure: 'text-amber-400',
    GPS: 'text-green-400', Faces: 'text-pink-400', Dates: 'text-cyan-400',
    Image: 'text-purple-400', 'Tags/Keywords': 'text-orange-400',
    File: 'text-gray-400', Other: 'text-gray-500',
  }
  return colors[group] || 'text-gray-400'
}

const exifFriendlyNames = {
  ExposureTime: 'Shutter Speed', FNumber: 'Aperture', ISO: 'ISO',
  FocalLength: 'Focal Length', FocalLength35efl: 'Focal Length (35mm eq.)',
  Make: 'Camera Make', Model: 'Camera Model', LensMake: 'Lens Make',
  LensModel: 'Lens Model', LensInfo: 'Lens Info', Software: 'Software',
  ExposureProgram: 'Exposure Program', MeteringMode: 'Metering Mode',
  Flash: 'Flash', WhiteBalance: 'White Balance', ColorSpace: 'Color Space',
  ExifImageWidth: 'Width', ExifImageHeight: 'Height', ImageWidth: 'Width', ImageHeight: 'Height',
  Orientation: 'Orientation', XResolution: 'X Resolution', YResolution: 'Y Resolution',
  DateTimeOriginal: 'Date Original', CreateDate: 'Date Created', ModifyDate: 'Date Modified',
  GPSLatitude: 'Latitude', GPSLongitude: 'Longitude', GPSAltitude: 'Altitude',
  Copyright: 'Copyright', Artist: 'Artist', ImageDescription: 'Description',
  ExposureCompensation: 'Exposure Comp.', BrightnessValue: 'Brightness',
  MaxApertureValue: 'Max Aperture', SceneCaptureType: 'Scene Type',
  ShutterSpeed: 'Shutter Speed', Aperture: 'Aperture', FOV: 'Field of View',
  ImageSize: 'Dimensions', Megapixels: 'Megapixels', LightValue: 'Light Value',
  CircleOfConfusion: 'Circle of Confusion', HyperfocalDistance: 'Hyperfocal Distance',
  ScaleFactor35efl: '35mm Scale Factor', DigitalZoomRatio: 'Digital Zoom',
  Compression: 'Compression', ResolutionUnit: 'Resolution Unit',
  FileType: 'File Type', FileTypeExtension: 'Extension', MIMEType: 'MIME Type',
  FileName: 'File Name', Directory: 'Directory', FileSize: 'File Size',
  FileModifyDate: 'File Modified', FileAccessDate: 'File Accessed',
  BitsPerSample: 'Bits Per Sample', ColorComponents: 'Color Components',
  EncodingProcess: 'Encoding', YCbCrSubSampling: 'Chroma Subsampling',
  ExifByteOrder: 'Byte Order', CurrentIPTCDigest: 'IPTC Digest',
  FileInodeChangeDate: 'Inode Changed', FilePermissions: 'Permissions',
  GPSLatitudeRef: 'Lat Ref', GPSLongitudeRef: 'Long Ref',
  GPSAltitudeRef: 'Alt Ref', GPSTimeStamp: 'GPS Time', GPSDateStamp: 'GPS Date',
  GPSVersionID: 'GPS Version', GPSPosition: 'GPS Position',
  DateCreated: 'XMP Date Created', MetadataDate: 'Metadata Date',
  DateTimeCreated: 'Date/Time Created', SubSecCreateDate: 'Create Date (SubSec)',
  SubSecDateTimeOriginal: 'Original Date (SubSec)', SubSecModifyDate: 'Modified (SubSec)',
  OffsetTime: 'Time Offset', OffsetTimeOriginal: 'Original Time Offset',
  OffsetTimeDigitized: 'Digitized Time Offset', LensID: 'Lens ID', Lens: 'Lens',
}

function friendlyName(key) {
  return exifFriendlyNames[key] || key.replace(/([A-Z])/g, ' $1').trim()
}

function formatExifValue(val, key) {
  if (val === null || val === undefined) return 'N/A'
  if (typeof val === 'object') return formatMetadataDisplayValue(val)
  const s = String(val)
  // Contextual formatting based on key
  if (key) {
    if (key === 'ExposureTime' && s.match(/^[\d.\/]+$/) && !s.endsWith('s')) return s + 's'
    if (key === 'FNumber' && s.match(/^[\d.]+$/) && !s.startsWith('f/')) return 'f/' + s
    if ((key === 'FocalLength' || key === 'FocalLength35efl') && s.match(/^[\d.]+$/) && !s.endsWith('mm')) return s + 'mm'
    if (key === 'ISO' && s.match(/^[\d]+$/)) return 'ISO ' + s
    if (key === 'GPSAltitude' && s.match(/^[\d.]+$/) && !s.endsWith('m')) return s + 'm'
    if (key === 'Megapixels' && s.match(/^[\d.]+$/)) return s + ' MP'
  }
  return s
}

function formatMetadataDisplayValue(val) {
  if (val === null || val === undefined || val === '') return 'N/A'
  if (Array.isArray(val)) return val.length ? `Structured metadata (${val.length} items)` : 'No structured metadata'
  if (typeof val === 'object') {
    const keys = Object.keys(val)
    return keys.length ? `Structured metadata (${keys.length} fields)` : 'Structured metadata recorded'
  }
  return String(val)
}

function parseFaceRegions(regionList) {
  if (!regionList) return []
  if (typeof regionList === 'string') {
    try { regionList = JSON.parse(regionList) } catch { return [] }
  }
  if (!Array.isArray(regionList)) return []
  return regionList.map(r => ({
    name: r.Name || r.name || null,
    type: r.Type || r.type || 'Face',
    area: r.Area ? `x:${r.Area.X?.toFixed(3)} y:${r.Area.Y?.toFixed(3)} w:${r.Area.W?.toFixed(3)} h:${r.Area.H?.toFixed(3)}` : null,
  }))
}

const gpsCoords = computed(() => {
  const data = rawExif.value
  if (!data) return null
  // exiftool -n outputs numeric GPS
  const lat = data['Composite:GPSLatitude'] ?? data['GPS:GPSLatitude']
  const lng = data['Composite:GPSLongitude'] ?? data['GPS:GPSLongitude']
  if (lat != null && lng != null && !isNaN(lat) && !isNaN(lng)) {
    return { lat: parseFloat(lat), lng: parseFloat(lng) }
  }
  return null
})

// Faces sorted left-to-right by region_x for consistent numbering
const sortedFaces = computed(() => {
  return [...faces.value].sort((a, b) => (a.region_x || 0) - (b.region_x || 0))
})

function faceIndex(face) {
  return sortedFaces.value.findIndex(f => f.id === face.id) + 1
}

const displayTags = computed(() => {
  const t = fileData.tags
  if (!t) return []
  if (Array.isArray(t)) return t
  if (typeof t === 'string') return t.split(',').map(s => s.trim()).filter(Boolean)
  return []
})

const displayAiTags = computed(() => {
  const t = fileData.ai_tags
  if (!t) return []
  if (Array.isArray(t)) return t
  if (typeof t === 'string') return t.split(',').map(s => s.trim()).filter(Boolean)
  return []
})

// Delete
const showDeleteConfirm = ref(false)
const deleting = ref(false)

// Face reassignment
const reassigningFace = ref(null)
const reassignTargetId = ref('')

// Add person link
const showAddPerson = ref(false)
const addPersonId = ref('')

// Person typeahead search state
const personSearch = reactive({})
const personResults = reactive({})
const personHighlight = reactive({})
let personSearchTimers = {}

// Edit form
const editForm = reactive({
  date_taken: '',
  description: '',
  tags: '',
  title: '',
  copyright: '',
  exifFields: [],
  ragContent: '',
})
const saving = ref(false)
const activeEditSection = ref('core')
const editSections = [
  { id: 'core', label: 'Date / Tags / Description' },
  { id: 'exif', label: 'Custom EXIF Fields' },
  { id: 'rag', label: 'RAG Content' },
]

// Derive extension from multiple sources (extension prop, filename, mime_type)
const itemExtension = computed(() => {
  // Try explicit extension first
  const ext = (props.item.extension || '').toLowerCase().trim()
  if (ext) return ext
  // Derive from filename
  const fname = props.item.filename || ''
  const dotIdx = fname.lastIndexOf('.')
  if (dotIdx > 0) return fname.substring(dotIdx + 1).toLowerCase()
  // Derive from mime_type
  const mime = (props.item.mime_type || '').toLowerCase()
  if (mime.startsWith('image/')) return mime === 'image/jpeg' ? 'jpg' : mime.split('/')[1]
  if (mime.startsWith('video/')) return mime.split('/')[1]
  if (mime.startsWith('audio/')) return mime.split('/')[1]
  return ''
})

const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tiff', 'tif', 'bmp']
const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'm4v']
const audioExtensions = ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'wma']

const isImage = computed(() => {
  if (imageExtensions.includes(itemExtension.value)) return true
  const mime = (props.item.mime_type || '').toLowerCase()
  if (mime.startsWith('image/')) return true
  // Check type field from search results
  const type = (props.item.type || '').toLowerCase()
  return type === 'photo' || type === 'image'
})

const isVideo = computed(() => {
  if (videoExtensions.includes(itemExtension.value)) return true
  const mime = (props.item.mime_type || '').toLowerCase()
  return mime.startsWith('video/')
})

const isAudio = computed(() => {
  if (audioExtensions.includes(itemExtension.value)) return true
  const mime = (props.item.mime_type || '').toLowerCase()
  return mime.startsWith('audio/')
})

const imageFailed = ref(false)
const videoFailed = ref(false)
const videoEl = ref(null)

function onVideoError() {
  // Only fail if the video truly can't play — check the error code
  const mediaErr = videoEl.value?.error
  if (mediaErr) {
    // MEDIA_ERR_SRC_NOT_SUPPORTED (4) or MEDIA_ERR_DECODE (3) = truly unsupported
    // MEDIA_ERR_NETWORK (2) = transient, don't give up immediately
    if (mediaErr.code === 4 || mediaErr.code === 3) {
      videoFailed.value = true
    }
  } else {
    videoFailed.value = true
  }
}

onMounted(() => {
  lightboxEl.value?.focus()
  document.body.style.overflow = 'hidden'
  fetchMetadata()
  fetchPersons()
})

onUnmounted(() => {
  document.body.style.overflow = ''
})

async function fetchMetadata() {
  if (!props.item.asset_uuid) return
  loadingMeta.value = true
  try {
    const { data } = await axios.get(`/api/media/${props.item.asset_uuid}/metadata`)
    if (data.success) {
      faces.value = data.data.faces || []
      linkedPersons.value = data.data.linked_persons || []

      // Populate all file data
      const file = data.data.file || {}
      Object.keys(fileData).forEach(k => delete fileData[k])
      Object.assign(fileData, file)

      // Legacy meta for edit form
      Object.assign(meta, {
        date_taken: file.date_taken,
        date_taken_source: file.date_taken_source,
        date_taken_confidence: file.date_taken_confidence,
        ai_description: file.ai_description,
        tags: file.tags,
        exif_written: file.exif_written,
        exif_faces_written: file.exif_faces_written,
        exif_tags_written: file.exif_tags_written,
      })

      // Perceptual hash
      perceptualHash.value = data.data.perceptual_hash || null

      // Raw EXIF from exiftool
      rawExif.value = data.data.exif || {}

      // RAG data
      const ragData = data.data.rag || {}
      Object.assign(rag, {
        indexed: ragData.indexed || false,
        source_type: ragData.source_type || null,
        document_type: ragData.document_type || null,
        title: ragData.title || null,
        designation: ragData.designation || null,
        content: ragData.content || null,
        content_bytes: ragData.content_bytes || 0,
        context_prefix: ragData.context_prefix || null,
        metadata: ragData.metadata || null,
        chunk_count: ragData.chunk_count || 0,
        chunks: ragData.chunks || [],
        indexed_at: ragData.indexed_at || null,
        created_at: ragData.created_at || null,
        contextualized_at: ragData.contextualized_at || null,
        raptor_indexed_at: ragData.raptor_indexed_at || null,
      })

      // Pre-fill edit form
      const tagsStr = Array.isArray(file.tags) ? file.tags.join(', ') : (file.tags || '')
      editForm.date_taken = file.date_taken ? file.date_taken.replace(' ', 'T').slice(0, 16) : ''
      editForm.description = file.ai_description || ''
      editForm.tags = tagsStr
      editForm.title = file.title || ''
      editForm.copyright = ''
      editForm.exifFields = []
      editForm.ragContent = ragData.content || ''

      // Pre-fill copyright from EXIF if available
      const exifRaw = data.data.exif || {}
      editForm.copyright = exifRaw['IFD0:Copyright'] || exifRaw['IPTC:CopyrightNotice'] || exifRaw['XMP-xmpRights:UsageTerms'] || ''
    }
  } catch (err) {
    console.error('Failed to fetch metadata:', err)
  } finally {
    loadingMeta.value = false
  }
}

async function fetchPersons() {
  try {
    const { data } = await axios.get('/api/media/genealogy-persons', { params: { limit: 200 } })
    if (data.success) {
      persons.value = data.data
    }
  } catch (err) {
    console.error('Failed to fetch persons:', err)
  }
}

function getFaceRegionStyle(face) {
  return {
    left: `${face.region_x * 100}%`,
    top: `${face.region_y * 100}%`,
    width: `${face.region_w * 100}%`,
    height: `${face.region_h * 100}%`
  }
}

function onImageError() {
  imageFailed.value = true
}

// Rename
function startRename() {
  renameValue.value = props.item.filename
  renaming.value = true
  nextTick(() => {
    renameInput.value?.focus()
    // Select filename without extension
    const dotIdx = renameValue.value.lastIndexOf('.')
    if (dotIdx > 0) renameInput.value?.setSelectionRange(0, dotIdx)
  })
}

async function doRename() {
  if (!renameValue.value.trim() || renameValue.value === props.item.filename) {
    renaming.value = false
    return
  }
  renameSaving.value = true
  try {
    const { data } = await axios.post(`/api/media/${props.item.asset_uuid}/rename`, {
      filename: renameValue.value.trim(),
    })
    if (data.success) {
      renaming.value = false
      showToast('File renamed')
      emit('refresh')
    } else {
      showToast('Rename failed: ' + (data.error || 'unknown'))
    }
  } catch (err) {
    showToast('Rename failed: ' + (err.response?.data?.error || err.message))
  } finally {
    renameSaving.value = false
  }
}

function selectFace(face) {
  selectedFace.value = face
  activeTab.value = 'faces'
}

// Face link (for unlinked faces)
async function doLinkFace(face) {
  const personId = linkPersonId.value[face.id]
  if (!personId) return
  try {
    await axios.post('/api/media/faces/link', { face_id: face.id, person_id: personId })
    linkPersonId.value[face.id] = ''
    fetchMetadata()
    showToast('Face linked successfully', { label: 'Write to file now', handler: () => triggerWriteback() })
  } catch (err) {
    console.error('Link failed:', err)
  }
}

// Face reassign
function startReassign(face) {
  reassigningFace.value = face
  reassignTargetId.value = ''
}

async function doReassignFace() {
  if (!reassigningFace.value || !reassignTargetId.value) return
  try {
    await axios.post(`/api/media/faces/${reassigningFace.value.id}/reassign`, {
      person_id: reassignTargetId.value,
    })
    reassigningFace.value = null
    reassignTargetId.value = ''
    fetchMetadata()
    showToast('Face reassigned. Writeback flags reset.', { label: 'Write to file now', handler: () => triggerWriteback() })
  } catch (err) {
    console.error('Reassign failed:', err)
  }
}

// Face unlink
async function doUnlinkFace(face) {
  try {
    await axios.post(`/api/media/faces/${face.id}/unlink`)
    fetchMetadata()
    showToast('Face unlinked. Writeback flags reset.', { label: 'Write to file now', handler: () => triggerWriteback() })
  } catch (err) {
    console.error('Unlink failed:', err)
  }
}

// Person link (genealogy_person_media)
async function doAddPersonLink() {
  if (!addPersonId.value) return
  try {
    await axios.post(`/api/media/${props.item.asset_uuid}/person-link`, {
      person_id: addPersonId.value,
    })
    showAddPerson.value = false
    addPersonId.value = ''
    fetchMetadata()
    showToast('Person linked to media')
  } catch (err) {
    console.error('Add person link failed:', err)
  }
}

async function doRemovePersonLink(personId) {
  try {
    await axios.delete(`/api/media/${props.item.asset_uuid}/person-link/${personId}`)
    fetchMetadata()
    showToast('Person link removed')
  } catch (err) {
    console.error('Remove person link failed:', err)
  }
}

// Person typeahead helpers
function onPersonSearch(key) {
  clearTimeout(personSearchTimers[key])
  const q = personSearch[key]
  if (!q || q.length < 2) { personResults[key] = null; return }
  personSearchTimers[key] = setTimeout(async () => {
    try {
      const { data } = await axios.get('/api/media/genealogy-persons', { params: { search: q, limit: 15 } })
      if (data.success) personResults[key] = data.data
    } catch (err) {
      console.error('Person search failed:', err)
    }
  }, 200)
}

function onPersonBlur(key) {
  setTimeout(() => { personResults[key] = null }, 200)
}

function closeDropdown(key) { personResults[key] = null }

function highlightNext(key) {
  const results = personResults[key] || []
  const cur = personHighlight[key] ?? -1
  personHighlight[key] = Math.min(cur + 1, results.length - 1)
}

function highlightPrev(key) {
  const cur = personHighlight[key] ?? 0
  personHighlight[key] = Math.max(cur - 1, 0)
}

function selectHighlighted(key) {
  const results = personResults[key] || []
  const idx = personHighlight[key] ?? 0
  if (results[idx]) {
    if (key === 'reassign') selectPersonForReassign(results[idx])
    else if (key === 'addPerson') selectPersonForAdd(results[idx])
    else selectPersonForFace({ id: key }, results[idx])
  }
}

async function selectPersonForFace(face, person) {
  personResults[face.id] = null
  personSearch[face.id] = ''
  // If no genealogy ID, just set the name on the face (same as "Use name only")
  if (!person.id) {
    const name = person.name || person.person_name || `${person.given_name || ''} ${person.surname || ''}`.trim()
    if (!name) return
    try {
      await axios.post(`/api/media/faces/${face.id}/name`, { person_name: name })
      fetchMetadata()
      showToast(`Named face: ${name}`, { label: 'Write to file now', handler: () => triggerWriteback() })
    } catch (err) {
      showToast('Failed: ' + (err.response?.data?.error || err.message))
    }
    return
  }
  try {
    await axios.post('/api/media/faces/link', { face_id: face.id, person_id: person.id })
    fetchMetadata()
    showToast('Face linked successfully', { label: 'Write to file now', handler: () => triggerWriteback() })
  } catch (err) {
    showToast('Link failed: ' + (err.response?.data?.error || err.message))
  }
}

async function selectPersonForReassign(person) {
  personResults['reassign'] = null
  personSearch['reassign'] = ''
  if (!person.id || !reassigningFace.value) return
  try {
    await axios.post(`/api/media/faces/${reassigningFace.value.id}/reassign`, { person_id: person.id })
    reassigningFace.value = null
    reassignTargetId.value = ''
    fetchMetadata()
    showToast('Face reassigned.', { label: 'Write to file now', handler: () => triggerWriteback() })
  } catch (err) {
    showToast('Reassign failed: ' + (err.response?.data?.error || err.message))
  }
}

async function selectPersonForAdd(person) {
  personResults['addPerson'] = null
  personSearch['addPerson'] = ''
  if (!person.id) return
  try {
    await axios.post(`/api/media/${props.item.asset_uuid}/person-link`, { person_id: person.id })
    showAddPerson.value = false
    addPersonId.value = ''
    fetchMetadata()
    showToast('Person linked to media')
  } catch (err) {
    showToast('Add person failed: ' + (err.response?.data?.error || err.message))
  }
}

async function useNameOnly(key) {
  const name = personSearch[key]?.trim()
  if (!name) return
  personResults[key] = null
  personSearch[key] = ''

  // For face linking: set person_name on the face record
  if (key !== 'reassign' && key !== 'addPerson') {
    try {
      await axios.post(`/api/media/faces/${key}/name`, { person_name: name })
      fetchMetadata()
      showToast(`Named face: ${name}`)
    } catch (err) {
      showToast('Failed: ' + (err.response?.data?.error || err.message))
    }
    return
  }

  // For addPerson: create face record with name only (no genealogy link)
  if (key === 'addPerson') {
    try {
      await axios.post(`/api/media/${props.item.asset_uuid}/person-link`, { person_name: name })
      showAddPerson.value = false
      fetchMetadata()
      showToast(`Person "${name}" linked to media`)
    } catch (err) {
      showToast('Failed: ' + (err.response?.data?.error || err.message))
    }
  }
}

async function createAndSelect(key) {
  const text = personSearch[key]?.trim()
  if (!text) return
  personResults[key] = null

  const parts = text.split(/\s+/)
  const surname = parts.length > 1 ? parts.pop() : ''
  const givenName = parts.join(' ') || text

  try {
    const { data } = await axios.post('/api/genealogy/trees/4/persons', {
      given_name: givenName, surname: surname,
    })
    if (data.success) {
      const newPerson = data.data
      showToast(`Created person: ${text}`)
      // Now link via the appropriate flow
      if (key === 'addPerson') {
        await selectPersonForAdd(newPerson)
      } else if (key === 'reassign') {
        await selectPersonForReassign(newPerson)
      } else {
        // Face ID key
        await selectPersonForFace({ id: key }, newPerson)
      }
    }
  } catch (err) {
    showToast('Failed to create person: ' + (err.response?.data?.error || err.message))
  }
}

// Save metadata
async function saveMetadata(writeToFile = false) {
  saving.value = true
  try {
    const payload = { write_to_file: writeToFile }
    if (editForm.date_taken) payload.date_taken = editForm.date_taken.replace('T', ' ') + ':00'
    if (editForm.date_taken === '' && meta.date_taken) payload.date_taken = null
    payload.description = editForm.description
    payload.tags = editForm.tags
    if (editForm.title) payload.title = editForm.title
    if (editForm.copyright) payload.copyright = editForm.copyright

    // Custom EXIF fields
    const validExifFields = editForm.exifFields.filter(f => f.tag && f.value !== undefined)
    if (validExifFields.length > 0) payload.exif_fields = validExifFields

    // RAG content
    if (rag.indexed && editForm.ragContent !== (rag.content || '')) {
      payload.rag_content = editForm.ragContent
    }

    const { data } = await axios.post(`/api/media/${props.item.asset_uuid}/metadata`, payload)
    if (data.success) {
      const writeInfo = data.file_written
      fetchMetadata()
      if (writeToFile && writeInfo) {
        if (writeInfo.success) {
          showToast('Saved & written to file. DB synced from EXIF.')
        } else {
          showToast('Saved to DB but file write failed: ' + (writeInfo.error || writeInfo.output))
        }
      } else {
        showToast('Metadata saved to DB.', { label: 'Write to file now', handler: () => triggerWriteback() })
      }
    }
  } catch (err) {
    console.error('Save metadata failed:', err)
    showToast('Save failed: ' + (err.response?.data?.error || err.message))
  } finally {
    saving.value = false
  }
}

// Trigger EXIF writeback
async function triggerWriteback() {
  try {
    const { data } = await axios.post(`/api/media/writeback/${props.item.asset_uuid}`)
    if (data.success) {
      showToast('EXIF writeback complete')
      fetchMetadata()
    } else {
      showToast('Writeback failed: ' + (data.error || 'unknown'))
    }
  } catch (err) {
    showToast('Writeback failed: ' + (err.response?.data?.error || err.message))
  }
}

// Delete file permanently
async function doDeleteFile() {
  deleting.value = true
  try {
    const { data } = await axios.delete(`/api/media/${props.item.asset_uuid}`)
    if (data.success) {
      showDeleteConfirm.value = false
      emit('deleted', props.item.id)
      emit('close')
    } else {
      showToast('Delete failed: ' + (data.error || 'unknown'))
      showDeleteConfirm.value = false
    }
  } catch (err) {
    showToast('Delete failed: ' + (err.response?.data?.error || err.message))
    showDeleteConfirm.value = false
  } finally {
    deleting.value = false
  }
}

function showToast(message, action = null) {
  toast.value = { message, action }
  if (!action) {
    setTimeout(() => { if (toast.value?.message === message) toast.value = null }, 4000)
  }
  // Action toasts stay visible until user clicks action or dismisses with X
}

function formatSource(src) {
  if (!src) return null
  return src.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function formatDateSource(src) {
  if (!src) return null
  const map = {
    exif_original: 'EXIF Original',
    exif_digitized: 'EXIF Digitized',
    exif_modified: 'EXIF Modified',
    path_extracted: 'Path Pattern',
    filename_extracted: 'Filename Pattern',
    ai_estimated: 'AI Estimated',
    user_manual: 'Manual Entry',
    file_modified: 'File Modified Date',
  }
  return map[src] || formatSource(src)
}

function displayMediaPath(path) {
  if (!path) return ''
  let value = String(path).replace(/\\/g, '/').replace(/^\/+/, '')
  value = value.replace(/^[A-Za-z]:\//, '')
  value = value.replace(/^(home|users)\/[^/]+\//i, '')
  value = value.replace(/^mnt\/[^/]+\//i, '')
  const lastSlash = value.lastIndexOf('/')
  if (lastSlash > 0) value = value.substring(0, lastSlash)
  const parts = value.split('/').filter(Boolean)
  if (parts.length === 0) return 'Configured media location'
  return parts.slice(Math.max(0, parts.length - 3)).join('/')
}

function writebackStatus(val) {
  if (val === 1) return 'Written'
  if (val === 0) return 'Pending'
  if (val === -1) return 'Error'
  return 'N/A'
}

function writebackColor(val) {
  if (val === 1) return 'green'
  if (val === 0) return 'yellow'
  if (val === -1) return 'red'
  return 'muted'
}

function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function formatDate(date) {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString()
}

// Reset state when item changes
watch(() => props.item, () => {
  linkPersonId.value = {}
  selectedFace.value = null
  hoveredFaceId.value = null
  reassigningFace.value = null
  showAddPerson.value = false
  showDeleteConfirm.value = false
  toast.value = null
  imageFailed.value = false
  videoFailed.value = false
  ragContentExpanded.value = false
  showRagChunks.value = false
  rawExif.value = {}
  activeMetaSection.value = 'exif'
  // Clear typeahead state
  Object.keys(personSearch).forEach(k => delete personSearch[k])
  Object.keys(personResults).forEach(k => delete personResults[k])
  Object.keys(personHighlight).forEach(k => delete personHighlight[k])
  fetchMetadata()
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* Slide-panel transition for mobile sidebar overlay */
.slide-panel-enter-active, .slide-panel-leave-active {
  transition: transform 0.3s ease, opacity 0.3s ease;
}
.slide-panel-enter-from, .slide-panel-leave-to {
  transform: translateX(100%);
  opacity: 0;
}
@media (min-width: 1024px) {
  .slide-panel-enter-from, .slide-panel-leave-to {
    transform: none;
    opacity: 0;
  }
}
</style>
