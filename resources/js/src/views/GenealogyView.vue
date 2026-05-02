<template>
  <div class="min-h-screen bg-theme-primary">
    <div class="max-w-full mx-auto px-6 py-6">
      <!-- Header -->
      <div class="flex justify-between items-center mb-6">
        <div>
          <h2 class="text-3xl font-bold text-theme-primary border-b-2 border-accent pb-2">Genealogy</h2>
          <p class="text-theme-secondary mt-1">Family Tree Management</p>
        </div>
        <div class="flex items-center gap-3">
          <!-- Tree Selector -->
          <select
            v-model="selectedTreeId"
            class="px-4 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
            @change="onTreeChange"
          >
            <option :value="null">Select a Family Tree</option>
            <option v-for="tree in trees" :key="tree.id" :value="tree.id">
              {{ tree.name }} ({{ tree.person_count }} persons)
            </option>
          </select>

          <!-- New Tree Button -->
          <button @click="showNewTreeModal = true" class="btn-primary flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Tree
          </button>

          <!-- Import GEDCOM Button -->
          <button @click="showImportModal = true" class="btn-secondary flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Import GEDCOM
          </button>

          <!-- Add Person Button (only when tree selected) -->
          <button v-if="selectedTreeId" @click="openCreatePerson" class="btn-primary flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
            </svg>
            Add Person
          </button>

          <!-- Add Family Button (only when tree selected) -->
          <button v-if="selectedTreeId" @click="openCreateFamily" class="btn-secondary flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            Add Family
          </button>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="card text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-accent"></div>
        <p class="mt-4 text-theme-secondary">Loading...</p>
      </div>

      <!-- No Tree Selected -->
      <div v-else-if="!selectedTreeId" class="card text-center py-12">
        <svg class="w-16 h-16 mx-auto text-theme-secondary mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
        <h3 class="text-xl font-semibold text-theme-primary mb-2">Select a Family Tree</h3>
        <p class="text-theme-secondary mb-4">Choose an existing tree or create a new one to get started.</p>
        <div class="flex justify-center gap-4">
          <button @click="showNewTreeModal = true" class="btn-primary">Create New Tree</button>
          <button @click="showImportModal = true" class="btn-secondary">Import GEDCOM File</button>
        </div>
      </div>

      <!-- Tree Content -->
      <div v-else class="space-y-6">
        <!-- Tree Stats -->
        <div class="grid grid-cols-4 gap-4">
          <div class="card">
            <div class="text-3xl font-bold text-accent">{{ stats.person_count || 0 }}</div>
            <div class="text-theme-secondary">Persons</div>
          </div>
          <div class="card">
            <div class="text-3xl font-bold text-green-500">{{ stats.family_count || 0 }}</div>
            <div class="text-theme-secondary">Families</div>
          </div>
          <div class="card">
            <div class="text-3xl font-bold text-purple-500">{{ stats.media_count || 0 }}</div>
            <div class="text-theme-secondary">Media Files</div>
          </div>
          <div class="card">
            <div class="text-3xl font-bold text-orange-500">{{ stats.source_count || 0 }}</div>
            <div class="text-theme-secondary">Sources</div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="card">
          <div class="flex border-b border-theme mb-4">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="activeTab = tab.id"
              :class="[
                'px-4 py-2 text-sm font-medium transition-colors relative',
                activeTab === tab.id
                  ? 'text-accent border-b-2 border-accent'
                  : 'text-theme-secondary hover:text-theme-primary'
              ]"
            >
              {{ tab.label }}
              <!-- Unconfirmed faces badge on Media tab -->
              <span
                v-if="tab.id === 'media' && unconfirmedFaceCount > 0"
                class="absolute -top-1 -right-1 bg-yellow-500 text-black text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"
                :title="`${unconfirmedFaceCount} unconfirmed face tags`"
              >
                {{ unconfirmedFaceCount > 99 ? '99+' : unconfirmedFaceCount }}
              </span>
            </button>
          </div>

          <!-- Tree View Tab - use v-show to preserve tree SVG state when switching tabs -->
          <div v-show="activeTab === 'tree'" class="space-y-4">
            <!-- Home Person Selector -->
            <div class="flex items-center gap-4">
              <div class="flex-1">
                <label class="text-sm text-theme-secondary mr-2">Home Person:</label>
                <select
                  v-model="homePersonId"
                  @change="onHomePersonChange"
                  class="px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent min-w-[300px]"
                >
                  <option :value="null">Select a person to start...</option>
                  <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                    {{ person.surname }}, {{ person.given_name }}
                    {{ person.birth_date ? `(${extractYear(person.birth_date)})` : '' }}
                  </option>
                </select>
              </div>

              <!-- View Mode Buttons -->
              <div class="flex gap-2">
                <button
                  v-for="mode in treeModes"
                  :key="mode.id"
                  @click="treeViewMode = mode.id"
                  :class="[
                    'px-3 py-2 rounded text-sm transition-colors',
                    treeViewMode === mode.id
                      ? 'bg-accent text-white'
                      : 'bg-theme-tertiary text-theme-primary hover:bg-theme-secondary'
                  ]"
                >
                  {{ mode.label }}
                </button>
              </div>

              <!-- Generations Control -->
              <div class="flex items-center gap-2">
                <label class="text-sm text-theme-secondary">Generations:</label>
                <input
                  type="number"
                  v-model.number="treeGenerations"
                  @change="onGenerationsChange"
                  min="1"
                  max="20"
                  step="1"
                  class="w-16 px-2 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary text-center focus:outline-none focus:ring-2 focus:ring-accent"
                  title="Number of generations to display (1-20). Higher values may affect performance."
                />
              </div>

              <!-- Renderer Mode -->
              <select
                v-model="rendererMode"
                @change="renderTree"
                class="px-2 py-1 bg-theme-tertiary border border-theme rounded text-theme-primary text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                title="Card display style"
              >
                <option value="detailed">Detailed</option>
                <option value="simple">Simple</option>
                <option value="circle">Circle</option>
              </select>

              <!-- Zoom Controls -->
              <div class="flex gap-2 items-center">
                <button @click="zoomIn" class="p-2 bg-theme-tertiary rounded hover:bg-theme-secondary" title="Zoom In">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                  </svg>
                </button>
                <span class="text-xs text-theme-secondary min-w-[40px] text-center">{{ zoomPercent }}%</span>
                <button @click="zoomOut" class="p-2 bg-theme-tertiary rounded hover:bg-theme-secondary" title="Zoom Out">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                  </svg>
                </button>
                <button @click="resetZoom" class="p-2 bg-theme-tertiary rounded hover:bg-theme-secondary" title="Reset View">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                  </svg>
                </button>
                <button @click="toggleFullscreen" class="p-2 bg-theme-tertiary rounded hover:bg-theme-secondary" :title="isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'">
                  <svg v-if="!isFullscreen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                  </svg>
                  <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V4m0 5H4m0 0l5-5m5 14v5m0-5h5m0 0l-5 5M9 15v5m0-5H4m0 0l5 5m5-14V4m0 5h5m0 0l-5-5"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Tree View Container -->
            <div v-if="homePersonId" class="tree-container relative bg-theme-tertiary rounded-lg border border-theme overflow-hidden" :class="{ 'tree-fullscreen': isFullscreen }" :style="isFullscreen ? '' : 'height: calc(100vh - 200px); min-height: 500px;'">
              <svg id="tree-svg" ref="treeSvg" class="w-full h-full"></svg>

              <!-- N147: Return Home button + focus indicator overlay -->
              <div v-if="isFocusedAway" class="absolute top-3 left-3 flex items-center gap-2 z-10">
                <button
                  @click="returnHome"
                  class="flex items-center gap-2 px-3 py-2 bg-accent text-white rounded-lg shadow-lg hover:bg-accent/80 transition-colors text-sm font-medium"
                  title="Return to home person"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                  </svg>
                  Return Home
                </button>
                <span class="px-2 py-1 bg-theme-secondary/90 text-theme-primary rounded text-xs">
                  Viewing: {{ getFocusPersonName() }}
                </span>
              </div>

              <!-- Instructions Overlay (shown when tree is empty) -->
              <div v-if="!treeLoaded" class="absolute inset-0 flex items-center justify-center">
                <div class="text-center text-theme-secondary">
                  <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent mx-auto mb-4"></div>
                  <p>Loading family tree...</p>
                </div>
              </div>
            </div>

            <!-- No Home Person Selected -->
            <div v-else class="text-center py-16 bg-theme-tertiary rounded-lg border border-theme">
              <svg class="w-20 h-20 mx-auto text-theme-secondary mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
              </svg>
              <h3 class="text-xl font-semibold text-theme-primary mb-2">Select a Home Person</h3>
              <p class="text-theme-secondary max-w-md mx-auto">
                Choose a person from the dropdown above to start building your family tree view.
                The tree will expand to show ancestors, descendants, and spouses.
              </p>
            </div>

            <!-- Person Quick Info Panel (floating) -->
            <div v-if="hoveredPerson" class="fixed z-50 bg-theme-secondary border border-theme rounded-lg shadow-xl p-4 max-w-xs" :style="hoverPanelStyle">
              <div class="flex items-start gap-3">
                <div v-if="hoveredPerson.photo" class="w-12 h-12 rounded-full overflow-hidden bg-theme-tertiary flex-shrink-0">
                  <img :src="hoveredPerson.photo" class="w-full h-full object-cover" />
                </div>
                <div v-else class="w-12 h-12 rounded-full bg-theme-tertiary flex-shrink-0 flex items-center justify-center">
                  <span class="text-lg font-bold text-theme-secondary">{{ getInitials(hoveredPerson) }}</span>
                </div>
                <div class="flex-1 min-w-0">
                  <h4 class="font-semibold text-theme-primary truncate">
                    {{ hoveredPerson.given_name }} {{ hoveredPerson.surname }}
                  </h4>
                  <p v-if="hoveredPerson.birth_date || hoveredPerson.death_date" class="text-sm text-theme-secondary">
                    {{ hoveredPerson.birth_date }} - {{ hoveredPerson.death_date || 'Living' }}
                  </p>
                  <p v-if="hoveredPerson.birth_place" class="text-xs text-theme-secondary truncate mt-1">
                    {{ hoveredPerson.birth_place }}
                  </p>
                </div>
              </div>
              <div class="mt-2 pt-2 border-t border-theme flex gap-2">
                <button @click="setHomePerson(hoveredPerson.id)" class="text-xs text-accent hover:underline">Set as Home</button>
                <button @click="selectPerson(hoveredPerson)" class="text-xs text-accent hover:underline">View Details</button>
              </div>
            </div>

            <!-- Person Context Menu (right-click) -->
            <div
              v-if="contextMenu.visible"
              class="fixed z-[60] bg-theme-secondary border border-theme rounded-lg shadow-xl py-1 min-w-[160px]"
              :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
              @click.stop
            >
              <button
                @click="setHomePersonFromContextMenu"
                class="w-full px-4 py-2 text-left text-sm text-theme-primary hover:bg-theme-tertiary flex items-center gap-2"
              >
                <span>🏠</span> Set as Home Person
              </button>
              <button
                @click="viewPersonFromContextMenu"
                class="w-full px-4 py-2 text-left text-sm text-theme-primary hover:bg-theme-tertiary flex items-center gap-2"
              >
                <span>👤</span> View Details
              </button>
            </div>
          </div>

          <!-- Search Tab -->
          <div v-if="activeTab === 'search'" class="space-y-4">
            <!-- Search Mode Toggle -->
            <div class="flex gap-2 mb-4">
              <button
                @click="searchMode = 'name'"
                :class="[
                  'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
                  searchMode === 'name'
                    ? 'bg-accent text-white'
                    : 'bg-theme-tertiary text-theme-primary hover:bg-theme-secondary'
                ]"
              >
                Name Search
              </button>
              <button
                @click="searchMode = 'natural'"
                :class="[
                  'px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2',
                  searchMode === 'natural'
                    ? 'bg-accent text-white'
                    : 'bg-theme-tertiary text-theme-primary hover:bg-theme-secondary'
                ]"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                </svg>
                AI Search
              </button>
            </div>

            <!-- Name Search Mode -->
            <div v-if="searchMode === 'name'">
              <div class="flex gap-4">
                <input
                  v-model="searchQuery"
                  type="text"
                  placeholder="Search by name (type 2+ chars)..."
                  class="flex-1 px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  @keyup.enter="searchPersons"
                />
                <button @click="searchPersons" class="btn-primary" :disabled="loading">
                  <span v-if="loading && searchQuery.length >= 2">...</span>
                  <span v-else>Search</span>
                </button>
              </div>

              <!-- Name Search Results -->
              <div v-if="searchResults.length > 0" class="space-y-2 mt-4">
                <div
                  v-for="person in searchResults"
                  :key="person.id"
                  @click="selectPerson(person)"
                  class="p-4 bg-theme-tertiary rounded-lg cursor-pointer hover:bg-theme-secondary transition-colors"
                >
                  <div class="flex gap-3">
                    <!-- Thumbnail Photo -->
                    <div class="flex-shrink-0">
                      <div v-if="person.primary_photo_url" class="w-12 h-12 rounded-full overflow-hidden border border-theme-border">
                        <img :src="person.primary_photo_url" :alt="person.given_name" class="w-full h-full object-cover" />
                      </div>
                      <div v-else class="w-12 h-12 rounded-full bg-theme-secondary flex items-center justify-center border border-theme-border">
                        <svg class="w-6 h-6 text-theme-secondary" fill="currentColor" viewBox="0 0 24 24">
                          <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                      </div>
                    </div>
                    <!-- Person Info -->
                    <div class="flex-1 min-w-0">
                      <div class="flex justify-between items-start">
                        <div>
                          <span class="font-semibold text-theme-primary">
                            {{ person.given_name }} {{ person.surname }}
                          </span>
                          <span v-if="person.suffix" class="text-theme-secondary ml-1">{{ person.suffix }}</span>
                        </div>
                        <span class="text-xs px-2 py-1 rounded flex-shrink-0" :class="person.sex === 'M' ? 'bg-blue-500/20 text-blue-400' : person.sex === 'F' ? 'bg-pink-500/20 text-pink-400' : 'bg-gray-500/20 text-gray-400'">
                          {{ person.sex === 'M' ? 'Male' : person.sex === 'F' ? 'Female' : 'Unknown' }}
                        </span>
                      </div>
                      <div class="text-sm text-theme-secondary mt-1">
                        <span v-if="person.birth_date">b. {{ person.birth_date }}</span>
                        <span v-if="person.birth_place"> - {{ person.birth_place }}</span>
                        <span v-if="person.death_date"> | d. {{ person.death_date }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div v-else-if="hasSearched && searchQuery && !loading" class="text-center text-theme-secondary py-8">
                No results found for "{{ searchQuery }}"
              </div>
            </div>

            <!-- Natural Language Search Mode (AI/RAG-powered) -->
            <div v-else-if="searchMode === 'natural'">
              <div class="bg-theme-tertiary rounded-lg p-4 mb-4">
                <p class="text-sm text-theme-secondary mb-2">
                  Ask questions in natural language, like:
                </p>
                <div class="flex flex-wrap gap-2 text-xs">
                  <span class="bg-theme-secondary px-2 py-1 rounded text-theme-primary">"Who were the Doe family members born in Pennsylvania?"</span>
                  <span class="bg-theme-secondary px-2 py-1 rounded text-theme-primary">"People who died in Lancaster County"</span>
                  <span class="bg-theme-secondary px-2 py-1 rounded text-theme-primary">"Farmers from the 1800s"</span>
                </div>
              </div>

              <div class="flex gap-4">
                <input
                  v-model="nlSearchQuery"
                  type="text"
                  placeholder="Ask a question about your family history..."
                  class="flex-1 px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  @keyup.enter="naturalLanguageSearch"
                  @input="hasNlSearched = false"
                />
                <button @click="naturalLanguageSearch" class="btn-primary flex items-center gap-2" :disabled="nlSearchLoading">
                  <span v-if="nlSearchLoading" class="inline-block animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></span>
                  <span>{{ nlSearchLoading ? 'Searching...' : 'Search' }}</span>
                </button>
              </div>

              <!-- Natural Language Search Results -->
              <div v-if="nlSearchResults.length > 0" class="space-y-2 mt-4">
                <p class="text-sm text-theme-secondary mb-2">Found {{ nlSearchResults.length }} matching persons:</p>
                <div
                  v-for="person in nlSearchResults"
                  :key="person.id"
                  @click="viewNlSearchPerson(person)"
                  class="p-4 bg-theme-tertiary rounded-lg cursor-pointer hover:bg-theme-secondary transition-colors"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1">
                      <div class="flex items-center gap-2">
                        <span class="font-semibold text-theme-primary">{{ person.name }}</span>
                        <span class="text-xs px-2 py-0.5 bg-green-500/20 text-green-400 rounded">
                          {{ person.similarity }}% match
                        </span>
                      </div>
                      <div class="text-sm text-theme-secondary mt-1">
                        <span v-if="person.birth_date">b. {{ person.birth_date }}</span>
                        <span v-if="person.birth_place"> in {{ person.birth_place }}</span>
                        <span v-if="person.death_date"> | d. {{ person.death_date }}</span>
                        <span v-if="person.death_place"> in {{ person.death_place }}</span>
                      </div>
                    </div>
                    <span v-if="person.sex" class="text-xs px-2 py-1 rounded" :class="person.sex === 'M' ? 'bg-blue-500/20 text-blue-400' : person.sex === 'F' ? 'bg-pink-500/20 text-pink-400' : 'bg-gray-500/20 text-gray-400'">
                      {{ person.sex === 'M' ? 'Male' : person.sex === 'F' ? 'Female' : 'Unknown' }}
                    </span>
                  </div>
                  <!-- Show content snippet on hover/expansion -->
                  <div v-if="person.content" class="mt-2 text-xs text-theme-secondary bg-theme-secondary rounded p-2 max-h-20 overflow-hidden">
                    {{ person.content.substring(0, 200) }}{{ person.content.length > 200 ? '...' : '' }}
                  </div>
                </div>
              </div>
              <div v-else-if="hasNlSearched && nlSearchQuery && !nlSearchLoading" class="text-center text-theme-secondary py-8">
                <p>No results found for "{{ nlSearchQuery }}"</p>
                <p class="text-sm mt-2">Try rephrasing your question or using different keywords.</p>
              </div>
            </div>
          </div>

          <!-- Surnames Tab -->
          <div v-if="activeTab === 'surnames'" class="space-y-4">
            <div class="grid grid-cols-4 gap-2">
              <button
                v-for="surname in surnames"
                :key="surname.surname"
                @click="loadSurname(surname.surname)"
                :class="[
                  'px-3 py-2 rounded text-left transition-colors',
                  selectedSurname === surname.surname
                    ? 'selected-item'
                    : 'bg-theme-tertiary text-theme-primary hover:bg-theme-secondary'
                ]"
              >
                <span class="font-medium">{{ surname.surname }}</span>
                <span class="text-xs ml-2 opacity-75">({{ surname.person_count }})</span>
              </button>
            </div>

            <!-- Persons by Surname -->
            <div v-if="surnamePersons.length > 0" class="mt-4 space-y-2">
              <h4 class="font-semibold text-theme-primary mb-2">{{ selectedSurname }} Family ({{ surnamePersons.length }})</h4>
              <div
                v-for="person in surnamePersons"
                :key="person.id"
                @click="selectPerson(person)"
                class="p-3 bg-theme-tertiary rounded-lg cursor-pointer hover:bg-theme-secondary transition-colors"
              >
                <span class="font-medium text-theme-primary">{{ person.given_name }}</span>
                <span class="text-theme-secondary ml-2">
                  {{ person.birth_date ? `(${extractYear(person.birth_date)}` : '' }}{{ person.death_date ? ` - ${extractYear(person.death_date)})` : person.birth_date ? ')' : '' }}
                </span>
              </div>
            </div>
          </div>

          <!-- Timeline Tab -->
          <div v-if="activeTab === 'timeline'" class="space-y-4">
            <!-- Controls -->
            <div class="bg-theme-tertiary rounded-lg p-4">
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Person Selector -->
                <div>
                  <label class="block text-sm font-medium text-theme-secondary mb-2">Select Person</label>
                  <select
                    v-model="timelineTabPersonId"
                    @change="loadTimelineForTab"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:ring-2 focus:ring-accent"
                  >
                    <option :value="null">Choose a person...</option>
                    <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                      {{ person.surname }}, {{ person.given_name }} {{ person.birth_year ? `(${person.birth_year})` : '' }}
                    </option>
                  </select>
                </div>

                <!-- Options -->
                <div class="space-y-2">
                  <label class="block text-sm font-medium text-theme-secondary">Include Family Members</label>
                  <div class="flex flex-wrap gap-3">
                    <label class="flex items-center gap-2 text-sm text-theme-primary cursor-pointer">
                      <input type="checkbox" v-model="timelineIncludeFamily" @change="loadTimelineForTab" class="rounded bg-theme-secondary border-theme text-accent focus:ring-accent">
                      Spouses/Children
                    </label>
                    <label class="flex items-center gap-2 text-sm text-theme-primary cursor-pointer">
                      <input type="checkbox" v-model="timelineIncludeParents" @change="loadTimelineForTab" class="rounded bg-theme-secondary border-theme text-accent focus:ring-accent">
                      Parents
                    </label>
                    <label class="flex items-center gap-2 text-sm text-theme-primary cursor-pointer">
                      <input type="checkbox" v-model="timelineIncludeSiblings" @change="loadTimelineForTab" class="rounded bg-theme-secondary border-theme text-accent focus:ring-accent">
                      Siblings
                    </label>
                  </div>
                </div>
              </div>

              <!-- Year Range Filter -->
              <div class="grid grid-cols-2 gap-4 mt-4">
                <div>
                  <label class="block text-sm font-medium text-theme-secondary mb-1">Start Year</label>
                  <input
                    type="number"
                    v-model.number="timelineStartYear"
                    @change="loadTimelineForTab"
                    placeholder="e.g. 1800"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:ring-2 focus:ring-accent"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-theme-secondary mb-1">End Year</label>
                  <input
                    type="number"
                    v-model.number="timelineEndYear"
                    @change="loadTimelineForTab"
                    placeholder="e.g. 1900"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:ring-2 focus:ring-accent"
                  />
                </div>
              </div>
            </div>

            <!-- Loading State -->
            <div v-if="loadingTimelineTab" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Loading timeline...</p>
            </div>

            <!-- No Person Selected -->
            <div v-else-if="!timelineTabPersonId" class="bg-theme-tertiary rounded-lg p-8 text-center">
              <svg class="w-16 h-16 mx-auto text-theme-secondary mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <h3 class="text-lg font-medium text-theme-primary mb-2">Select a Person</h3>
              <p class="text-theme-secondary">Choose a person from the dropdown to view their chronological timeline.</p>
            </div>

            <!-- Timeline Content -->
            <div v-else-if="timelineTabData">
              <!-- Person Header -->
              <div class="bg-theme-tertiary rounded-lg p-4 mb-4">
                <div class="flex items-center gap-4">
                  <div class="w-16 h-16 rounded-full bg-theme-secondary flex items-center justify-center text-2xl font-bold text-accent">
                    {{ timelineTabData.person?.given_name?.[0] || '?' }}{{ timelineTabData.person?.surname?.[0] || '' }}
                  </div>
                  <div>
                    <h3 class="text-xl font-semibold text-theme-primary">
                      {{ timelineTabData.person?.given_name }} {{ timelineTabData.person?.surname }}
                    </h3>
                    <p class="text-theme-secondary">
                      {{ timelineTabData.person?.birth_date || '?' }} - {{ timelineTabData.person?.death_date || (timelineTabData.person?.sex ? 'Living?' : '?') }}
                    </p>
                  </div>
                  <div class="ml-auto text-right">
                    <div class="text-2xl font-bold text-accent">{{ timelineTabData.event_count }}</div>
                    <div class="text-sm text-theme-secondary">Events</div>
                  </div>
                </div>

                <!-- Year Range Bar -->
                <div v-if="timelineTabData.year_range?.min && timelineTabData.year_range?.max" class="mt-4">
                  <div class="flex justify-between text-sm text-theme-secondary mb-1">
                    <span>{{ timelineTabData.year_range.min }}</span>
                    <span>{{ timelineTabData.year_range.max }}</span>
                  </div>
                  <div class="h-2 bg-theme-secondary rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-green-500 via-blue-500 to-gray-500 rounded-full"></div>
                  </div>
                </div>
              </div>

              <!-- Event Type Legend -->
              <div v-if="timelineEventConfig && Object.keys(timelineEventConfig).length > 0" class="bg-theme-tertiary rounded-lg p-3 mb-4">
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="(config, type) in timelineEventConfig"
                    :key="type"
                    class="px-2 py-1 rounded text-xs font-medium"
                    :style="{ backgroundColor: config.color + '20', color: config.color }"
                  >
                    {{ type }}
                  </span>
                </div>
              </div>

              <!-- Timeline Events by Year -->
              <div v-if="timelineTabData.events_by_year && Object.keys(timelineTabData.events_by_year).length > 0" class="space-y-4">
                <div
                  v-for="(events, year) in timelineTabData.events_by_year"
                  :key="year"
                  class="relative"
                >
                  <!-- Year Header -->
                  <div class="sticky top-0 z-10 bg-theme-primary py-2">
                    <div class="flex items-center gap-3">
                      <div class="w-16 h-8 bg-accent rounded-lg flex items-center justify-center text-white font-bold text-sm">
                        {{ year }}
                      </div>
                      <div class="flex-1 h-px bg-accent/30"></div>
                      <span class="text-xs text-theme-secondary">{{ events.length }} event{{ events.length > 1 ? 's' : '' }}</span>
                    </div>
                  </div>

                  <!-- Events for this year -->
                  <div class="pl-6 border-l-2 border-accent/30 ml-8 space-y-3 pt-2">
                    <div
                      v-for="event in events"
                      :key="event.id"
                      class="relative pl-4"
                    >
                      <!-- Event dot -->
                      <div
                        class="absolute -left-[9px] top-2 w-4 h-4 rounded-full border-2 border-white"
                        :style="{ backgroundColor: getTimelineEventColor(event.event_type) }"
                      ></div>

                      <!-- Event card -->
                      <div class="bg-theme-tertiary rounded-lg p-3 hover:bg-theme-secondary transition-colors">
                        <div class="flex items-start justify-between gap-2">
                          <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                              <span
                                class="px-2 py-0.5 rounded text-xs font-medium"
                                :style="{ backgroundColor: getTimelineEventColor(event.event_type) + '20', color: getTimelineEventColor(event.event_type) }"
                              >
                                {{ event.event_type }}
                              </span>
                              <span v-if="event.relationship && event.relationship !== 'self'" class="text-xs text-theme-secondary bg-theme-secondary px-2 py-0.5 rounded">
                                {{ event.relationship }}
                              </span>
                            </div>
                            <p class="text-theme-primary font-medium">
                              {{ event.given_name || '' }} {{ event.surname || '' }}
                              <span v-if="event.family_name" class="text-theme-secondary">{{ event.family_name }}</span>
                            </p>
                            <p v-if="event.event_date" class="text-sm text-theme-secondary">{{ event.event_date }}</p>
                            <p v-if="event.event_place" class="text-sm text-accent flex items-center gap-1 mt-1">
                              <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                              </svg>
                              {{ event.event_place }}
                            </p>
                            <p v-if="event.description" class="text-sm text-theme-secondary mt-1 italic">{{ event.description }}</p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- No Events -->
              <div v-else class="bg-theme-tertiary rounded-lg p-8 text-center">
                <p class="text-theme-secondary">No events found for this person with the current filters.</p>
              </div>
            </div>
          </div>

          <!-- Sources Tab (Phase 2.4) -->
          <div v-if="activeTab === 'sources'" class="space-y-4">
            <div class="flex justify-between items-center">
              <div class="flex items-center gap-4">
                <h3 class="text-lg font-semibold text-theme-primary">Sources</h3>
                <span class="text-theme-secondary text-sm">{{ sources.length }} records</span>
              </div>
              <button @click="openCreateSource" class="btn-primary">
                + Add Source
              </button>
            </div>

            <!-- Source Search -->
            <div class="flex gap-2">
              <input
                v-model="sourceSearchQuery"
                @keyup.enter="searchSources"
                type="text"
                placeholder="Search sources..."
                class="flex-1 px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              />
              <button @click="searchSources" class="btn-secondary" :disabled="!sourceSearchQuery">
                Search
              </button>
              <button v-if="sourceSearchResults.length > 0" @click="sourceSearchResults = []; sourceSearchQuery = ''" class="btn-secondary">
                Clear
              </button>
            </div>

            <!-- Search Results -->
            <div v-if="sourceSearchResults.length > 0" class="mb-4">
              <h4 class="text-sm font-medium text-theme-secondary mb-2">Search Results ({{ sourceSearchResults.length }})</h4>
              <div class="space-y-2">
                <div
                  v-for="source in sourceSearchResults"
                  :key="source.id"
                  @click="selectSource(source)"
                  class="p-3 bg-theme-tertiary rounded-lg cursor-pointer hover:ring-2 hover:ring-accent transition-all"
                >
                  <div class="font-medium text-theme-primary">{{ source.title }}</div>
                  <div v-if="source.author" class="text-sm text-theme-secondary">By: {{ source.author }}</div>
                </div>
              </div>
            </div>

            <!-- Sources List -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <!-- Source List Panel -->
              <div class="space-y-2 max-h-96 overflow-y-auto">
                <div v-if="sources.length === 0" class="text-theme-secondary text-center py-8">
                  No sources recorded yet. Add your first source!
                </div>
                <div
                  v-for="source in sources"
                  :key="source.id"
                  :data-source-id="source.id"
                  @click="selectSource(source)"
                  :class="[
                    'p-3 bg-theme-tertiary rounded-lg cursor-pointer transition-all',
                    selectedSource?.id === source.id ? 'ring-2 ring-accent' : 'hover:ring-1 hover:ring-theme'
                  ]"
                >
                  <div class="flex justify-between items-start">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium text-theme-primary truncate">{{ source.title }}</div>
                      <div v-if="source.author" class="text-xs text-theme-secondary mt-0.5">{{ source.author }}</div>
                      <div v-if="source.publication" class="text-xs text-theme-secondary/70 mt-0.5 truncate">{{ truncateText(source.publication, 60) }}</div>
                      <div v-if="source.repository" class="text-xs text-theme-secondary/70 mt-0.5">
                        <span class="text-theme-secondary/50">Repo:</span> {{ source.repository }}
                      </div>
                      <div class="flex flex-wrap gap-2 mt-1">
                        <span v-if="source.citation_count > 0" class="text-xs text-accent">{{ source.citation_count }} citations</span>
                        <span v-if="source.person_link_count > 0" class="text-xs text-blue-400">{{ source.person_link_count }} persons</span>
                        <span v-if="source.url" class="text-xs text-green-400">Has URL</span>
                      </div>
                    </div>
                    <div class="flex gap-1 flex-shrink-0">
                      <button @click.stop="openEditSource(source)" class="text-theme-secondary hover:text-accent p-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                      </button>
                      <button @click.stop="confirmDeleteSource(source)" class="text-theme-secondary hover:text-red-400 p-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Source Detail Panel -->
              <div v-if="selectedSource" class="bg-theme-tertiary rounded-lg p-4">
                <div class="flex justify-between items-start mb-4">
                  <h4 class="text-lg font-semibold text-theme-primary">{{ selectedSource.title }}</h4>
                  <button @click="selectedSource = null" class="text-theme-secondary hover:text-theme-primary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>

                <div class="space-y-3 text-sm">
                  <div v-if="selectedSource.author">
                    <label class="text-theme-secondary">Author</label>
                    <p class="text-theme-primary">{{ selectedSource.author }}</p>
                  </div>
                  <div v-if="selectedSource.publication">
                    <label class="text-theme-secondary">Publication</label>
                    <p class="text-theme-primary">{{ selectedSource.publication }}</p>
                  </div>
                  <div v-if="selectedSource.repository">
                    <label class="text-theme-secondary">Repository</label>
                    <p class="text-theme-primary">{{ selectedSource.repository }}</p>
                  </div>
                  <div v-if="selectedSource.call_number">
                    <label class="text-theme-secondary">Call Number</label>
                    <p class="text-theme-primary">{{ selectedSource.call_number }}</p>
                  </div>
                  <div v-if="selectedSource.url">
                    <label class="text-theme-secondary">URL</label>
                    <a :href="selectedSource.url" target="_blank" class="text-accent hover:underline">{{ selectedSource.url }}</a>
                  </div>
                  <div v-if="selectedSource.notes">
                    <label class="text-theme-secondary">Notes</label>
                    <p class="text-theme-primary whitespace-pre-wrap">{{ selectedSource.notes }}</p>
                  </div>

                  <!-- Linked Persons -->
                  <div v-if="selectedSource.linked_persons?.length > 0">
                    <label class="text-theme-secondary">Linked Persons ({{ selectedSource.linked_persons.length }})</label>
                    <div class="flex flex-wrap gap-1 mt-1">
                      <span
                        v-for="person in selectedSource.linked_persons"
                        :key="person.id"
                        class="px-2 py-1 bg-blue-500/20 text-blue-400 rounded text-xs"
                      >
                        {{ person.given_name }} {{ person.surname }}
                      </span>
                    </div>
                  </div>

                  <!-- Linked Families -->
                  <div v-if="selectedSource.linked_families?.length > 0">
                    <label class="text-theme-secondary">Linked Families ({{ selectedSource.linked_families.length }})</label>
                    <div class="flex flex-wrap gap-1 mt-1">
                      <span
                        v-for="family in selectedSource.linked_families"
                        :key="family.id"
                        class="px-2 py-1 bg-purple-500/20 text-purple-400 rounded text-xs"
                      >
                        {{ family.husband_surname || 'Unknown' }} / {{ family.wife_surname || 'Unknown' }}
                      </span>
                    </div>
                  </div>

                  <!-- Citations (Phase 2.5) -->
                  <div class="border-t border-theme pt-3 mt-3">
                    <div class="flex justify-between items-center mb-2">
                      <label class="text-theme-secondary">Citations ({{ selectedSource.citations?.length || 0 }})</label>
                      <button
                        @click="openAddCitation('source', selectedSource.id, selectedSource.id)"
                        class="text-xs text-accent hover:text-accent-blue"
                      >
                        + Add Citation
                      </button>
                    </div>
                    <div v-if="selectedSource.citations?.length > 0" class="space-y-2">
                      <div
                        v-for="citation in selectedSource.citations"
                        :key="citation.id"
                        class="bg-theme-secondary rounded p-2 text-xs"
                      >
                        <div class="flex justify-between items-start">
                          <div>
                            <span class="font-medium text-theme-primary">{{ getCitationFactTypeLabel(citation.fact_type) }}</span>
                            <span v-if="citation.given_name || citation.surname" class="text-theme-secondary ml-2">
                              → {{ citation.given_name }} {{ citation.surname }}
                            </span>
                          </div>
                          <div class="flex gap-1">
                            <button @click="openEditCitation(citation)" class="text-blue-400 hover:text-blue-300">
                              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteCitation(citation)" class="text-red-400 hover:text-red-300">
                              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                        <div v-if="citation.page" class="text-theme-secondary mt-1">Page: {{ citation.page }}</div>
                        <div v-if="citation.quality !== null" class="text-theme-secondary">Quality: {{ citation.quality }}</div>
                        <div v-if="citation.text" class="text-theme-secondary mt-1 italic">{{ citation.text }}</div>
                        <!-- Direct media attached to citation -->
                        <div v-if="citation.media" class="mt-2">
                          <div @click="viewMediaItem(citation.media)"
                               class="w-12 h-12 rounded border border-theme cursor-pointer hover:ring-2 hover:ring-accent overflow-hidden bg-theme-tertiary inline-block">
                            <img v-if="citation.media.thumbnail_url"
                                 :src="citation.media.thumbnail_url"
                                 :alt="citation.media.title"
                                 class="w-full h-full object-cover"
                                 @error="$event.target.style.display='none'">
                            <div v-else class="w-full h-full flex items-center justify-center text-theme-secondary">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                              </svg>
                            </div>
                          </div>
                        </div>
                        <!-- Related media from person-media connections -->
                        <div v-if="citation.related_media && citation.related_media.length > 0" class="mt-2 pt-2 border-t border-theme/50">
                          <div class="text-[10px] text-theme-secondary mb-1 flex items-center gap-1">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            Source Documents ({{ citation.related_media.length }})
                          </div>
                          <div class="flex flex-wrap gap-1">
                            <div v-for="media in getPaginatedCitationMedia(citation.related_media, citation.id)" :key="media.id"
                                 @click="viewMediaItem(media)"
                                 class="w-10 h-10 rounded border border-theme cursor-pointer hover:ring-2 hover:ring-accent overflow-hidden bg-theme-tertiary">
                              <img v-if="media.thumbnail_url"
                                   :src="media.thumbnail_url"
                                   :alt="media.title"
                                   class="w-full h-full object-cover"
                                   @error="$event.target.style.display='none'">
                              <div v-else class="w-full h-full flex items-center justify-center text-theme-secondary">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                              </div>
                            </div>
                          </div>
                          <!-- Pagination for citation media -->
                          <div v-if="getCitationMediaTotalPages(citation.related_media) > 1" class="flex items-center justify-center gap-1 mt-1">
                            <button @click="setCitationMediaPage(citation.id, 1)" :disabled="getCitationMediaPage(citation.id) === 1"
                              class="px-1.5 py-0.5 rounded bg-theme-tertiary text-theme-primary text-[10px] hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed">«</button>
                            <button @click="setCitationMediaPage(citation.id, getCitationMediaPage(citation.id) - 1)" :disabled="getCitationMediaPage(citation.id) === 1"
                              class="px-1.5 py-0.5 rounded bg-theme-tertiary text-theme-primary text-[10px] hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed">‹</button>
                            <span class="text-[10px] text-theme-secondary px-0.5">{{ getCitationMediaPage(citation.id) }}/{{ getCitationMediaTotalPages(citation.related_media) }}</span>
                            <button @click="setCitationMediaPage(citation.id, getCitationMediaPage(citation.id) + 1)" :disabled="getCitationMediaPage(citation.id) >= getCitationMediaTotalPages(citation.related_media)"
                              class="px-1.5 py-0.5 rounded bg-theme-tertiary text-theme-primary text-[10px] hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed">›</button>
                            <button @click="setCitationMediaPage(citation.id, getCitationMediaTotalPages(citation.related_media))" :disabled="getCitationMediaPage(citation.id) >= getCitationMediaTotalPages(citation.related_media)"
                              class="px-1.5 py-0.5 rounded bg-theme-tertiary text-theme-primary text-[10px] hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed">»</button>
                          </div>
                        </div>
                      </div>
                    </div>
                    <p v-else class="text-theme-secondary text-xs">No citations linked to this source</p>
                  </div>
                </div>

                <!-- Source Actions -->
                <div class="flex gap-2 mt-4 pt-4 border-t border-theme">
                  <button
                    @click="openEditSource(selectedSource)"
                    class="flex-1 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-500"
                  >
                    Edit Source
                  </button>
                  <button
                    @click="confirmDeleteSource(selectedSource)"
                    class="px-3 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-500"
                  >
                    Delete
                  </button>
                </div>
              </div>
              <div v-else class="bg-theme-tertiary rounded-lg p-4 flex items-center justify-center text-theme-secondary">
                Select a source to view details
              </div>
            </div>
          </div>

          <!-- Repositories Tab (Phase 2.6) -->
          <div v-if="activeTab === 'repositories'" class="space-y-4">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-lg font-semibold text-theme-primary">Repositories ({{ repositories.length }})</h3>
              <button
                @click="openAddRepository"
                class="px-3 py-2 bg-accent text-white rounded-lg text-sm hover:bg-accent-blue"
              >
                + Add Repository
              </button>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <!-- Repository List -->
              <div class="space-y-2 max-h-[600px] overflow-y-auto">
                <div v-if="repositories.length === 0" class="text-theme-secondary text-center py-8">
                  No repositories found. Add one to track where your sources are stored.
                </div>
                <div
                  v-for="repo in repositories"
                  :key="repo.id"
                  @click="selectRepository(repo)"
                  class="p-3 bg-theme-tertiary rounded-lg cursor-pointer hover:bg-theme-secondary transition-colors"
                  :class="{ 'ring-2 ring-accent': selectedRepository?.id === repo.id }"
                >
                  <div class="font-medium text-theme-primary">{{ repo.name }}</div>
                  <div v-if="repo.address" class="text-sm text-theme-secondary truncate">{{ repo.address }}</div>
                  <div class="text-xs text-theme-secondary mt-1">
                    {{ repo.source_count || 0 }} source(s)
                  </div>
                </div>
              </div>

              <!-- Repository Detail Panel -->
              <div v-if="selectedRepository" class="bg-theme-tertiary rounded-lg p-4">
                <div class="flex justify-between items-start mb-4">
                  <h4 class="text-lg font-semibold text-theme-primary">{{ selectedRepository.name }}</h4>
                  <button @click="selectedRepository = null" class="text-theme-secondary hover:text-theme-primary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>

                <div class="space-y-3 text-sm">
                  <div v-if="selectedRepository.address">
                    <label class="text-theme-secondary">Address</label>
                    <p class="text-theme-primary whitespace-pre-wrap">{{ selectedRepository.address }}</p>
                  </div>
                  <div v-if="selectedRepository.phone">
                    <label class="text-theme-secondary">Phone</label>
                    <p class="text-theme-primary">{{ selectedRepository.phone }}</p>
                  </div>
                  <div v-if="selectedRepository.email">
                    <label class="text-theme-secondary">Email</label>
                    <a :href="'mailto:' + selectedRepository.email" class="text-accent hover:underline">{{ selectedRepository.email }}</a>
                  </div>
                  <div v-if="selectedRepository.url">
                    <label class="text-theme-secondary">Website</label>
                    <a :href="selectedRepository.url" target="_blank" class="text-accent hover:underline">{{ selectedRepository.url }}</a>
                  </div>
                  <div v-if="selectedRepository.notes">
                    <label class="text-theme-secondary">Notes</label>
                    <p class="text-theme-primary whitespace-pre-wrap">{{ selectedRepository.notes }}</p>
                  </div>

                  <!-- Linked Sources -->
                  <div v-if="selectedRepository.linked_sources?.length > 0" class="border-t border-theme pt-3 mt-3">
                    <label class="text-theme-secondary">Sources at this Repository ({{ selectedRepository.linked_sources.length }})</label>
                    <div class="space-y-1 mt-2">
                      <div
                        v-for="source in selectedRepository.linked_sources"
                        :key="source.id"
                        class="px-2 py-1 bg-theme-secondary rounded text-xs text-theme-primary"
                      >
                        {{ source.title }}
                        <span v-if="source.author" class="text-theme-secondary">by {{ source.author }}</span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Repository Actions -->
                <div class="flex gap-2 mt-4 pt-4 border-t border-theme">
                  <button
                    @click="openEditRepository(selectedRepository)"
                    class="flex-1 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-500"
                  >
                    Edit Repository
                  </button>
                  <button
                    @click="confirmDeleteRepository(selectedRepository)"
                    class="px-3 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-500"
                  >
                    Delete
                  </button>
                </div>
              </div>
              <div v-else class="bg-theme-tertiary rounded-lg p-4 flex items-center justify-center text-theme-secondary">
                Select a repository to view details
              </div>
            </div>
          </div>

          <!-- Reports Tab (Phase 2.7) -->
          <div v-if="activeTab === 'reports'" class="space-y-4">
            <!-- Report Type Selector -->
            <div class="bg-theme-tertiary rounded-lg p-4 mb-4">
              <div class="flex items-center gap-4 flex-wrap">
                <label class="text-sm font-medium text-theme-secondary">Report Type:</label>
                <select v-model="selectedReportType" class="px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent">
                  <option value="missing_data">Missing Data Report</option>
                  <option value="ahnentafel">Ahnentafel Report</option>
                  <option value="descendant">Descendant Report</option>
                  <option value="pedigree">Pedigree Chart</option>
                  <option value="family_group">Family Group Sheet</option>
                </select>
                <!-- Person selector for person-specific reports -->
                <template v-if="selectedReportType !== 'missing_data'">
                  <select v-model="reportPersonId" class="flex-1 px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent">
                    <option :value="null">Select a person...</option>
                    <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                      {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }}){{ person.id === currentTreeRootPersonId ? ' ★ Root' : '' }}
                    </option>
                  </select>
                  <button
                    @click="generateReport"
                    :disabled="!reportPersonId || generatingReport"
                    class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    {{ generatingReport ? 'Generating...' : 'Generate' }}
                  </button>
                  <button
                    v-if="generatedReport"
                    @click="downloadReportPDF"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium"
                  >
                    Download PDF
                  </button>
                </template>
              </div>
            </div>

            <!-- Generated Report Display -->
            <div v-if="selectedReportType !== 'missing_data' && generatedReport" class="bg-theme-tertiary rounded-lg p-4 mb-4">
              <h3 class="text-lg font-semibold text-theme-primary mb-3">{{ generatedReport.title }}</h3>
              <div class="prose prose-invert max-w-none text-theme-primary text-sm" v-html="generatedReport.html"></div>
            </div>

            <!-- Missing Data Report (original) -->
            <div v-show="selectedReportType === 'missing_data'">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-lg font-semibold text-theme-primary">Missing Data Report</h3>
              <button @click="refreshMissingDataReport" class="btn-secondary" :disabled="loadingReport">
                <svg v-if="loadingReport" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ loadingReport ? 'Loading...' : 'Refresh Report' }}
              </button>
            </div>

            <!-- Summary Section -->
            <div v-if="missingDataSummary" class="bg-theme-tertiary rounded-lg p-4 mb-4">
              <div class="flex justify-between items-center mb-3">
                <span class="text-theme-primary font-medium">Tree Summary</span>
                <span class="text-theme-secondary">{{ missingDataSummary.total_persons }} total persons</span>
              </div>

              <!-- Category Summaries -->
              <div class="space-y-2">
                <div
                  v-for="(category, type) in missingDataSummary.categories"
                  :key="type"
                  class="flex items-center justify-between py-2 px-3 bg-theme-secondary rounded-lg cursor-pointer hover:bg-theme-hover transition-colors"
                  @click="toggleReportSection(type)"
                >
                  <div class="flex items-center space-x-3">
                    <svg
                      class="w-4 h-4 transition-transform"
                      :class="{ 'rotate-90': expandedReportSection === type }"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                    <span class="text-theme-primary">{{ category.label }}</span>
                  </div>
                  <div class="flex items-center space-x-3">
                    <span
                      class="px-2 py-0.5 rounded text-sm"
                      :class="category.count > 0 ? 'bg-yellow-500/20 text-yellow-400' : 'bg-green-500/20 text-green-400'"
                    >
                      {{ category.count }}
                    </span>
                    <span class="text-theme-secondary text-sm">{{ category.percentage }}%</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Initial load prompt -->
            <div v-else-if="!loadingReport" class="bg-theme-tertiary rounded-lg p-8 text-center">
              <svg class="w-12 h-12 mx-auto text-theme-secondary mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              <p class="text-theme-secondary mb-4">Generate a report to identify persons with missing data</p>
              <button @click="loadMissingDataSummary" class="btn-primary">
                Generate Report
              </button>
            </div>

            <!-- Loading state -->
            <div v-else class="bg-theme-tertiary rounded-lg p-8 text-center">
              <svg class="animate-spin w-8 h-8 mx-auto text-accent mb-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <p class="text-theme-secondary">Analyzing tree data...</p>
            </div>

            <!-- Expanded Section Details -->
            <div v-if="expandedReportSection && missingDataReport[expandedReportSection]" class="bg-theme-tertiary rounded-lg p-4">
              <h4 class="font-medium text-theme-primary mb-3">
                {{ missingDataReport[expandedReportSection].label }}
                <span class="text-theme-secondary font-normal">({{ missingDataReport[expandedReportSection].count }} persons)</span>
              </h4>

              <div v-if="missingDataReport[expandedReportSection].persons.length === 0" class="text-center py-4">
                <svg class="w-8 h-8 mx-auto text-green-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <p class="text-green-400">All persons have this data!</p>
              </div>

              <div v-else class="space-y-1 max-h-96 overflow-y-auto">
                <div
                  v-for="person in missingDataReport[expandedReportSection].persons"
                  :key="person.id"
                  class="flex items-center justify-between py-2 px-3 bg-theme-secondary rounded hover:bg-theme-hover cursor-pointer transition-colors"
                  @click="goToPersonFromReport(person)"
                >
                  <div class="flex items-center space-x-3">
                    <span
                      class="w-8 h-8 rounded-full flex items-center justify-center text-sm"
                      :class="person.sex === 'M' ? 'bg-blue-500/20 text-blue-400' : person.sex === 'F' ? 'bg-pink-500/20 text-pink-400' : 'bg-gray-500/20 text-gray-400'"
                    >
                      {{ person.sex === 'M' ? 'M' : person.sex === 'F' ? 'F' : '?' }}
                    </span>
                    <div>
                      <div class="text-theme-primary">{{ person.given_name }} {{ person.surname }}</div>
                      <div class="text-xs text-theme-secondary">
                        <span v-if="person.birth_date">b. {{ person.birth_date }}</span>
                        <span v-if="person.birth_date && person.death_date"> - </span>
                        <span v-if="person.death_date">d. {{ person.death_date }}</span>
                        <span v-if="!person.birth_date && !person.death_date">No dates</span>
                      </div>
                    </div>
                  </div>
                  <svg class="w-4 h-4 text-theme-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                  </svg>
                </div>
              </div>
            </div>
            </div>
          </div>

          <!-- Media Tab -->
          <div v-if="activeTab === 'media'" class="space-y-4">
            <!-- Media Status Panel -->
            <div class="bg-theme-tertiary rounded-lg p-4">
              <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-semibold text-theme-primary">Media Files</h3>
                <div class="flex space-x-2">
                  <button
                    @click="syncMediaPaths"
                    class="btn-secondary text-sm"
                    :disabled="syncingMediaPaths"
                  >
                    <svg v-if="syncingMediaPaths" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    {{ syncingMediaPaths ? 'Syncing...' : 'Sync Paths from GEDCOM' }}
                  </button>
                  <button
                    v-if="mediaStatus.pending > 0"
                    @click="importMedia"
                    class="btn-primary text-sm"
                    :disabled="importingMedia"
                  >
                    <svg v-if="importingMedia" class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    {{ importingMedia ? 'Importing...' : `Import ${mediaStatus.pending} Files` }}
                  </button>
                  <button
                    @click="openMediaUploadModal"
                    class="btn-primary text-sm flex items-center"
                  >
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Upload Media
                  </button>
                  <!-- SSH Windows Import removed 2026-01-10 - Use Nextcloud file browser instead -->
                </div>
              </div>

              <!-- Status Stats -->
              <div class="grid grid-cols-4 gap-4 mb-3">
                <div class="text-center">
                  <div class="text-2xl font-bold text-theme-primary">{{ mediaStatus.total }}</div>
                  <div class="text-xs text-theme-secondary">Total</div>
                </div>
                <div class="text-center">
                  <div class="text-2xl font-bold text-green-400">{{ mediaStatus.imported }}</div>
                  <div class="text-xs text-theme-secondary">Imported</div>
                </div>
                <div class="text-center">
                  <div class="text-2xl font-bold text-yellow-400">{{ mediaStatus.pending }}</div>
                  <div class="text-xs text-theme-secondary">Pending</div>
                </div>
                <div class="text-center">
                  <div class="text-2xl font-bold text-theme-primary">{{ mediaStatus.percent_complete }}%</div>
                  <div class="text-xs text-theme-secondary">Complete</div>
                </div>
              </div>

              <!-- Progress Bar -->
              <div v-if="mediaStatus.total > 0" class="w-full bg-theme-secondary rounded-full h-2">
                <div
                  class="bg-accent h-2 rounded-full transition-all"
                  :style="{ width: `${mediaStatus.percent_complete}%` }"
                ></div>
              </div>
            </div>

            <!-- Media Category Filter (Phase 3.6) -->
            <div class="flex flex-wrap gap-2">
              <button
                v-for="cat in mediaCategories"
                :key="cat.id"
                @click="filterMediaByCategory(cat.id)"
                :class="[
                  'px-3 py-1.5 rounded-full text-sm font-medium transition-colors flex items-center gap-1',
                  selectedMediaCategory === cat.id
                    ? 'bg-accent text-white'
                    : 'bg-theme-tertiary text-theme-secondary hover:bg-theme-primary hover:text-white'
                ]"
              >
                <span>{{ cat.icon }}</span>
                <span>{{ cat.label }}</span>
                <span
                  v-if="mediaCategoryCounts[cat.id] !== undefined"
                  class="ml-1 text-xs opacity-75"
                >
                  ({{ cat.id === 'all' ? mediaStatus.total : mediaCategoryCounts[cat.id] || 0 }})
                </span>
              </button>
            </div>

            <!-- Media Grid -->
            <div class="grid grid-cols-6 gap-4">
              <div
                v-for="item in paginatedMedia"
                :key="item.id"
                class="relative aspect-square bg-theme-tertiary rounded-lg overflow-hidden cursor-pointer hover:ring-2 hover:ring-accent transition-all group"
                @click="showMediaDetail(item)"
              >
                <img
                  v-if="getMediaUrl(item) && isImage(item.file_format)"
                  :src="getMediaUrl(item)"
                  :alt="item.title"
                  class="w-full h-full object-cover"
                />
                <div v-else class="w-full h-full flex flex-col items-center justify-center p-2">
                  <svg class="w-8 h-8 text-theme-secondary mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  <p class="text-theme-primary text-xs text-center line-clamp-2">{{ item.title || item.local_filename || 'Untitled' }}</p>
                  <span class="text-theme-secondary text-[10px] mt-1">{{ item.file_format?.toUpperCase() }}</span>
                </div>
                <!-- Category Badge (Phase 3.6) -->
                <span
                  v-if="item.media_type && item.media_type !== 'photo'"
                  class="absolute top-1 left-1 bg-black/60 text-white text-xs px-1.5 py-0.5 rounded"
                  :title="getMediaTypeLabel(item.media_type)"
                >
                  {{ mediaCategories.find(c => c.id === item.media_type)?.icon || '📎' }}
                </span>
                <!-- Title on hover for images, always visible for non-images -->
                <div
                  v-if="getMediaUrl(item) && isImage(item.file_format)"
                  class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-2 opacity-0 group-hover:opacity-100 transition-opacity"
                >
                  <p class="text-white text-xs truncate">{{ item.title || 'Untitled' }}</p>
                </div>
              </div>
            </div>

            <!-- Media Tab Pagination Controls -->
            <div v-if="mediaTabTotalPages > 1" class="flex items-center justify-center gap-2 mt-4">
              <button
                @click="mediaTabPage = 1"
                :disabled="mediaTabPage === 1"
                class="px-3 py-1 rounded bg-theme-tertiary text-theme-primary hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed"
                title="First page"
              >
                «
              </button>
              <button
                @click="mediaTabPage--"
                :disabled="mediaTabPage === 1"
                class="px-3 py-1 rounded bg-theme-tertiary text-theme-primary hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed"
                title="Previous page"
              >
                ‹
              </button>
              <span class="text-theme-secondary px-2">
                Page {{ mediaTabPage }} of {{ mediaTabTotalPages }}
              </span>
              <button
                @click="mediaTabPage++"
                :disabled="mediaTabPage >= mediaTabTotalPages"
                class="px-3 py-1 rounded bg-theme-tertiary text-theme-primary hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed"
                title="Next page"
              >
                ›
              </button>
              <button
                @click="mediaTabPage = mediaTabTotalPages"
                :disabled="mediaTabPage >= mediaTabTotalPages"
                class="px-3 py-1 rounded bg-theme-tertiary text-theme-primary hover:bg-theme-secondary disabled:opacity-50 disabled:cursor-not-allowed"
                title="Last page"
              >
                »
              </button>
            </div>
          </div>

          <!-- Tools Tab (Phase 4) -->
          <div v-if="activeTab === 'tools'" class="space-y-6">
            <h3 class="text-lg font-medium text-theme-primary">Data Management Tools</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <!-- Export GEDCOM -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-accent/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Export GEDCOM</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Export your family tree in GEDCOM 5.5.1 format for use in other genealogy software.
                </p>
                <button
                  @click="openExportModal"
                  class="w-full px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent/80 font-medium"
                >
                  Export
                </button>
              </div>

              <!-- Validate Data -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-green-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Validate Data</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Check your tree for data integrity issues like orphaned references and circular relationships.
                </p>
                <button
                  @click="openValidationModal"
                  class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium"
                >
                  Validate
                </button>
              </div>

              <!-- Statistics -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-purple-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Statistics</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  View detailed statistics about your family tree including demographics and trends.
                </p>
                <button
                  @click="openStatisticsModal"
                  class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-medium"
                >
                  View Statistics
                </button>
              </div>

              <!-- Import (link to existing) -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-blue-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Import GEDCOM</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Import a GEDCOM file to create a new tree or merge with existing data.
                </p>
                <button
                  @click="showImportModal = true"
                  class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium"
                >
                  Import
                </button>
              </div>

              <!-- SSH Windows Import removed 2026-01-10 - Files accessed via Nextcloud -->

              <!-- Missing Data Report -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-red-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Missing Data</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Review persons with incomplete records (missing dates, places, etc.).
                </p>
                <button
                  @click="activeTab = 'reports'"
                  class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium"
                >
                  View Report
                </button>
              </div>
            </div>

            <!-- Phase 5: Visualization & Analysis Tools -->
            <h3 class="text-lg font-medium text-theme-primary mt-8">Visualization & Analysis</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <!-- Relationship Calculator -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-pink-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Relationship Calculator</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Find how two people in your tree are related (cousin, uncle, etc.).
                </p>
                <button
                  @click="openRelationshipModal"
                  class="w-full px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 font-medium"
                >
                  Calculate
                </button>
              </div>

              <!-- Geographic Distribution -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-teal-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Places Analysis</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  See geographic distribution of births, deaths, and marriages in your tree.
                </p>
                <button
                  @click="openPlacesModal"
                  class="w-full px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 font-medium"
                >
                  View Places
                </button>
              </div>

              <!-- Person Timeline -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-orange-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Person Timeline</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-3">
                  View a chronological timeline of events for any person in your tree.
                </p>
                <select
                  v-model="toolsTimelinePersonId"
                  class="w-full px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent text-sm mb-3"
                >
                  <option :value="null">Select a person...</option>
                  <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                    {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }})
                  </option>
                </select>
                <button
                  @click="loadPersonTimeline(toolsTimelinePersonId)"
                  :disabled="!toolsTimelinePersonId"
                  class="w-full px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Timeline
                </button>
              </div>
            </div>

            <!-- Phase 6: Reports & Printing -->
            <h3 class="text-lg font-medium text-theme-primary mt-8">Reports & Printing</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <!-- Pedigree Chart -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-indigo-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Pedigree Chart</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-3">
                  View and print an ancestor chart for any person in the tree.
                </p>
                <select
                  v-model="toolsPedigreePersonId"
                  class="w-full px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent text-sm mb-3"
                >
                  <option :value="null">Select a person...</option>
                  <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                    {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }})
                  </option>
                </select>
                <button
                  @click="loadPedigreeChart(toolsPedigreePersonId)"
                  :disabled="!toolsPedigreePersonId"
                  class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Pedigree
                </button>
              </div>

              <!-- Descendant Report -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-emerald-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Descendant Report</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-3">
                  View and print a descendant tree for any person.
                </p>
                <select
                  v-model="toolsDescendantPersonId"
                  class="w-full px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent text-sm mb-3"
                >
                  <option :value="null">Select a person...</option>
                  <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                    {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }})
                  </option>
                </select>
                <button
                  @click="loadDescendantReport(toolsDescendantPersonId)"
                  :disabled="!toolsDescendantPersonId"
                  class="w-full px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Descendants
                </button>
              </div>

              <!-- Family Group Sheet -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Family Group Sheet</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-3">
                  Print-ready family details with parents, children, and events.
                </p>
                <select
                  v-model="toolsFamilyGroupSheetId"
                  class="w-full px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent text-sm mb-3"
                >
                  <option :value="null">Select a family...</option>
                  <option v-for="f in allFamilies" :key="f.id" :value="f.id">
                    {{ f.husband_given || f.husband_surname ? `${f.husband_given || ''} ${f.husband_surname || ''}`.trim() : '?' }} & {{ f.wife_given || f.wife_surname ? `${f.wife_given || ''} ${f.wife_surname || ''}`.trim() : '?' }}
                    <template v-if="f.marriage_date"> ({{ f.marriage_date }})</template>
                  </option>
                </select>
                <button
                  @click="loadFamilyGroupSheet(toolsFamilyGroupSheetId)"
                  :disabled="!toolsFamilyGroupSheetId"
                  class="w-full px-4 py-2 bg-cyan-600 text-white rounded-lg hover:bg-cyan-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Group Sheet
                </button>
              </div>

              <!-- Individual Summary -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-rose-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Individual Summary</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-3">
                  Comprehensive printable summary for a single person.
                </p>
                <select
                  v-model="toolsSummaryPersonId"
                  class="w-full px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent text-sm mb-3"
                >
                  <option :value="null">Select a person...</option>
                  <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                    {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }})
                  </option>
                </select>
                <button
                  @click="loadIndividualSummary(toolsSummaryPersonId)"
                  :disabled="!toolsSummaryPersonId"
                  class="w-full px-4 py-2 bg-rose-600 text-white rounded-lg hover:bg-rose-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Summary
                </button>
              </div>

              <!-- Ahnentafel Report (E.2 Advanced Reports) -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-purple-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Ahnentafel Report</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-3">
                  Numbered ancestor list using the standard Ahnentafel system.
                </p>
                <select
                  v-model="toolsAhnentafelPersonId"
                  class="w-full px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent text-sm mb-3"
                >
                  <option :value="null">Select a person...</option>
                  <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                    {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }})
                  </option>
                </select>
                <button
                  @click="loadAhnentafelReport(toolsAhnentafelPersonId)"
                  :disabled="!toolsAhnentafelPersonId"
                  class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Ahnentafel
                </button>
              </div>

              <!-- Place Authority -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-teal-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Place Authority</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-3">
                  Normalize place names to authority records. Creates standard place hierarchy (City > County > State > Country).
                </p>
                <div v-if="placeNormalizationStats" class="text-xs text-theme-secondary mb-2">
                  Last run: {{ placeNormalizationStats.processed }} processed, {{ placeNormalizationStats.linked }} linked
                </div>
                <button
                  @click="normalizePlaceNames"
                  :disabled="normalizingPlaces"
                  class="w-full px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {{ normalizingPlaces ? 'Normalizing...' : 'Normalize Places' }}
                </button>
              </div>
            </div>

            <!-- Phase 7: Privacy & Collaboration -->
            <h3 class="text-lg font-medium text-theme-primary mt-8">Privacy & Collaboration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
              <!-- Privacy Settings -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-emerald-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Privacy Settings</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Configure tree privacy, living person protection, and visibility settings.
                </p>
                <button
                  @click="openPrivacySettingsModal"
                  :disabled="!currentTreeId"
                  class="w-full px-4 py-2 bg-emerald-500/20 text-emerald-400 rounded-lg hover:bg-emerald-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Configure Privacy
                </button>
              </div>

              <!-- Auto-Detect Living -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Living Detection</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Automatically detect and mark living persons based on birth dates.
                </p>
                <button
                  @click="autoDetectLiving"
                  :disabled="!currentTreeId || detectingLiving"
                  class="w-full px-4 py-2 bg-cyan-500/20 text-cyan-400 rounded-lg hover:bg-cyan-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {{ detectingLiving ? 'Detecting...' : 'Auto-Detect Living' }}
                </button>
              </div>

              <!-- Collaborators -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-violet-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Collaborators</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Manage who can view, edit, or administer this tree.
                </p>
                <button
                  @click="openCollaboratorsModal"
                  :disabled="!currentTreeId"
                  class="w-full px-4 py-2 bg-violet-500/20 text-violet-400 rounded-lg hover:bg-violet-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Manage Access
                </button>
              </div>

              <!-- Activity Log -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-amber-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Activity Log</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  View recent changes and activity on this tree.
                </p>
                <button
                  @click="openActivityLogModal"
                  :disabled="!currentTreeId"
                  class="w-full px-4 py-2 bg-amber-500/20 text-amber-400 rounded-lg hover:bg-amber-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Activity
                </button>
              </div>

              <!-- Living Statistics -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-pink-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Living Statistics</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  View breakdown of living vs deceased persons.
                </p>
                <button
                  @click="openLivingStatsModal"
                  :disabled="!currentTreeId"
                  class="w-full px-4 py-2 bg-pink-500/20 text-pink-400 rounded-lg hover:bg-pink-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Statistics
                </button>
              </div>
            </div>

            <!-- Phase 8: AI-Assisted Research -->
            <h3 class="text-lg font-medium text-theme-primary mt-8">AI-Assisted Research</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <!-- Research Hints -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-amber-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Research Hints</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  AI-generated suggestions for improving your tree.
                </p>
                <button
                  @click="openResearchHintsModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-amber-500/20 text-amber-400 rounded-lg hover:bg-amber-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Hints
                </button>
              </div>

              <!-- Name Variations -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-violet-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Name Variations</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Manage alternate spellings for names in your tree.
                </p>
                <button
                  @click="openNameVariationsModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-violet-500/20 text-violet-400 rounded-lg hover:bg-violet-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Manage Variations
                </button>
              </div>

              <!-- Research Tasks -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Research Tasks</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Queue and track research tasks for your tree.
                </p>
                <button
                  @click="openResearchTasksModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-cyan-500/20 text-cyan-400 rounded-lg hover:bg-cyan-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Tasks
                </button>
              </div>

              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-fuchsia-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-fuchsia-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V9m-7-4h6m0 0v6m0-6L10 14"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Saved Intake Runs</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Reopen staged genealogy intake runs and inspect packet previews in the browser.
                </p>
                <button
                  @click="openIntakeRunsModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-fuchsia-500/20 text-fuchsia-400 rounded-lg hover:bg-fuchsia-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Runs
                </button>
              </div>

              <!-- Research Statistics -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-emerald-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Research Statistics</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Overview of AI research activity and results.
                </p>
                <button
                  @click="openResearchStatsModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-emerald-500/20 text-emerald-400 rounded-lg hover:bg-emerald-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Statistics
                </button>
              </div>

              <!-- Phase 9: External Connections -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">External Connections</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Manage optional external service credentials for supported providers.
                </p>
                <button
                  @click="openExternalConnectionsModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-cyan-500/20 text-cyan-400 rounded-lg hover:bg-cyan-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Manage Connections
                </button>
              </div>

              <!-- Phase 9: External Records -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-indigo-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">External Records</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Review and import records found from external services.
                </p>
                <button
                  @click="openExternalRecordsModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-indigo-500/20 text-indigo-400 rounded-lg hover:bg-indigo-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Records
                </button>
              </div>

              <!-- Phase 9: Person External Links -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-rose-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Person External Links</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Link persons to their records in external services.
                </p>
                <button
                  @click="openPersonExternalLinksModal"
                  :disabled="!selectedPersonId"
                  class="w-full px-4 py-2 bg-rose-500/20 text-rose-400 rounded-lg hover:bg-rose-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Manage Links
                </button>
              </div>

              <!-- Phase 9: Integration Statistics -->
              <div class="bg-theme-tertiary rounded-lg p-6">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-10 h-10 bg-teal-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                  </div>
                  <h4 class="font-medium text-theme-primary">Integration Statistics</h4>
                </div>
                <p class="text-sm text-theme-secondary mb-4">
                  Overview of external integrations and sync activity.
                </p>
                <button
                  @click="openExternalStatsModal"
                  :disabled="!selectedTreeId"
                  class="w-full px-4 py-2 bg-teal-500/20 text-teal-400 rounded-lg hover:bg-teal-500/30 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  View Statistics
                </button>
              </div>
            </div>
          </div>

          <!-- Recent Tab -->
          <div v-if="activeTab === 'recent'" class="space-y-2">
            <div
              v-for="item in recentAdditions"
              :key="`${item.type}-${item.id}`"
              class="flex items-center gap-4 p-3 bg-theme-tertiary rounded-lg"
            >
              <span :class="[
                'px-2 py-1 rounded text-xs font-medium',
                item.type === 'person' ? 'bg-blue-500/20 text-blue-400' : 'bg-purple-500/20 text-purple-400'
              ]">
                {{ item.type }}
              </span>
              <span class="text-theme-primary flex-1">{{ item.name }}</span>
              <span class="text-theme-secondary text-sm">{{ formatDate(item.created_at) }}</span>
            </div>
          </div>

          <!-- N98: Research History Tab -->
          <div v-if="activeTab === 'research-history'" class="space-y-4">
            <div class="flex items-center justify-between flex-wrap gap-2">
              <div>
                <h3 class="text-lg font-semibold text-theme-primary">Research Search History</h3>
                <p v-if="selectedPerson" class="text-xs text-theme-secondary mt-0.5">
                  Showing logs for {{ selectedPerson.given_name }} {{ selectedPerson.surname }}
                  <button @click="selectedPerson = null" class="ml-2 text-accent hover:underline">Show all</button>
                </p>
              </div>
              <div class="flex gap-2 flex-wrap">
                <select v-model="researchLogFilter" class="bg-theme-tertiary text-theme-primary rounded px-2 py-1 text-sm border border-theme-border">
                  <option value="">All repositories</option>
                  <option v-for="s in researchLogSummary" :key="s.repository_searched" :value="s.repository_searched">
                    {{ s.repository_searched }}
                  </option>
                </select>
                <select v-model="researchLogNegative" class="bg-theme-tertiary text-theme-primary rounded px-2 py-1 text-sm border border-theme-border">
                  <option value="">All results</option>
                  <option value="0">Positive only</option>
                  <option value="1">Negative only</option>
                </select>
                <button @click="exportResearchLogsCsv" class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm">CSV Export</button>
              </div>
            </div>

            <!-- Repository summary cards -->
            <div v-if="researchLogSummary.length" class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div v-for="s in researchLogSummary" :key="s.repository_searched"
                   class="bg-theme-tertiary rounded-lg p-3 cursor-pointer hover:bg-theme-hover border border-transparent"
                   :class="{ 'border-blue-500': researchLogFilter === s.repository_searched }"
                   @click="researchLogFilter = researchLogFilter === s.repository_searched ? '' : s.repository_searched">
                <div class="text-xs text-theme-secondary truncate font-medium">{{ s.repository_searched || 'Unknown' }}</div>
                <div class="mt-1 flex gap-2 text-xs">
                  <span class="bg-green-500/20 text-green-400 px-1.5 py-0.5 rounded">{{ s.positive_count }} found</span>
                  <span class="bg-red-500/20 text-red-400 px-1.5 py-0.5 rounded">{{ s.negative_count }} negative</span>
                </div>
                <div class="text-xs text-theme-tertiary mt-1">Last: {{ formatDate(s.last_searched) }}</div>
              </div>
            </div>

            <!-- Log entries -->
            <div class="space-y-2 max-h-[500px] overflow-y-auto">
              <div v-for="log in filteredResearchLogs" :key="log.id"
                   class="flex items-start gap-3 p-3 bg-theme-tertiary rounded-lg">
                <span :class="[
                  'mt-0.5 px-2 py-0.5 rounded text-xs font-medium flex-shrink-0',
                  log.negative_result ? 'bg-red-500/20 text-red-400' : 'bg-green-500/20 text-green-400'
                ]">{{ log.negative_result ? 'Negative' : 'Found' }}</span>
                <div class="flex-1 min-w-0">
                  <div class="text-sm text-theme-primary font-medium">{{ log.repository_searched }}</div>
                  <div class="text-xs text-theme-secondary truncate">{{ log.search_terms }}</div>
                  <div v-if="log.results_summary" class="text-xs text-theme-tertiary mt-1">{{ log.results_summary }}</div>
                </div>
                <div class="text-xs text-theme-tertiary flex-shrink-0">{{ formatDate(log.searched_at) }}</div>
              </div>
              <div v-if="!filteredResearchLogs.length" class="text-center py-8 text-theme-secondary text-sm">
                No research logs found{{ researchLogFilter ? ' for ' + researchLogFilter : '' }}.
                <div v-if="!researchLogs.length" class="mt-2 text-xs">
                  The genealogy agent logs all searches here automatically (GPS Element 1 compliance).
                </div>
              </div>
            </div>
          </div>

          <!-- FAN Cluster Tab -->
          <div v-if="activeTab === 'fan-cluster'" class="space-y-6">
            <!-- Header with description -->
            <div class="bg-theme-tertiary rounded-lg p-4">
              <div class="flex items-start gap-4">
                <div class="w-12 h-12 bg-amber-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                  <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                  </svg>
                </div>
                <div class="flex-1">
                  <h3 class="text-lg font-semibold text-theme-primary">FAN Cluster Research</h3>
                  <p class="text-sm text-theme-secondary mt-1">
                    The FAN principle (Friends, Associates, Neighbors) helps identify hidden family connections through social networks.
                    Ancestors didn't live in isolation - they interacted with people who may hold clues to family relationships.
                  </p>
                </div>
              </div>
            </div>

            <!-- Person Selection and Create Cluster -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <!-- Select Person -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3">Research Subject</h4>
                <select
                  v-model="fanClusterPersonId"
                  @change="loadFanClustersForPerson"
                  class="w-full px-3 py-2 bg-theme-secondary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent text-sm"
                >
                  <option :value="null">Select a person to research...</option>
                  <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                    {{ person.surname }}, {{ person.given_name }} {{ person.birth_year ? `(${person.birth_year})` : '' }}
                  </option>
                </select>
                <p class="text-xs text-theme-secondary mt-2">
                  Select a person to view or create FAN clusters for their research.
                </p>
              </div>

              <!-- Quick Extract -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3">Quick Extract</h4>
                <div class="flex gap-2 flex-wrap">
                  <button
                    @click="extractFanFromSource('census')"
                    :disabled="!fanClusterPersonId || extractingFanMembers"
                    class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    From Census
                  </button>
                  <button
                    @click="extractFanFromSource('witnesses')"
                    :disabled="!fanClusterPersonId || extractingFanMembers"
                    class="px-3 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Witnesses
                  </button>
                  <button
                    @click="extractFanFromSource('church')"
                    :disabled="!fanClusterPersonId || extractingFanMembers"
                    class="px-3 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Church Associates
                  </button>
                </div>
                <p class="text-xs text-theme-secondary mt-2">
                  Auto-extract potential FAN members from existing records.
                </p>
              </div>
            </div>

            <!-- Loading indicator for extraction -->
            <div v-if="extractingFanMembers" class="bg-theme-tertiary rounded-lg p-4 flex items-center gap-3">
              <div class="animate-spin rounded-full h-5 w-5 border-2 border-accent border-t-transparent"></div>
              <span class="text-theme-secondary">Extracting potential FAN members from {{ fanClusterExtractSource }} records...</span>
            </div>

            <!-- Extracted Members Preview -->
            <div v-if="extractedFanMembers.length > 0" class="bg-theme-tertiary rounded-lg p-4">
              <div class="flex justify-between items-center mb-3">
                <h4 class="font-medium text-theme-primary">Extracted Members ({{ extractedFanMembers.length }})</h4>
                <div class="flex gap-2">
                  <button
                    @click="addExtractedMembersToCluster"
                    :disabled="!selectedFanCluster"
                    class="px-3 py-1 bg-accent text-white rounded text-sm hover:bg-accent-blue disabled:opacity-50"
                  >
                    Add to Cluster
                  </button>
                  <button @click="extractedFanMembers = []" class="px-3 py-1 bg-theme-secondary text-theme-primary rounded text-sm hover:bg-theme-hover">
                    Clear
                  </button>
                </div>
              </div>
              <div class="space-y-2 max-h-60 overflow-y-auto">
                <div
                  v-for="(member, idx) in extractedFanMembers"
                  :key="idx"
                  class="flex items-center justify-between p-2 bg-theme-secondary rounded"
                >
                  <div>
                    <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                    <span class="text-xs text-theme-secondary ml-2">({{ fanRelationshipTypes[member.relationship_type]?.split(' - ')[0] || member.relationship_type }})</span>
                    <div class="text-xs text-theme-secondary">{{ member.interaction_description }}</div>
                  </div>
                  <span :class="[
                    'px-2 py-0.5 rounded text-xs',
                    member.confidence === 'high' ? 'bg-green-500/20 text-green-400' :
                    member.confidence === 'medium' ? 'bg-yellow-500/20 text-yellow-400' :
                    'bg-red-500/20 text-red-400'
                  ]">
                    {{ member.confidence }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Clusters List and Detail -->
            <div v-if="fanClusterPersonId" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <!-- Clusters List -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <div class="flex justify-between items-center mb-3">
                  <h4 class="font-medium text-theme-primary">Clusters</h4>
                  <button
                    @click="openCreateFanCluster"
                    class="px-2 py-1 bg-accent text-white rounded text-sm hover:bg-accent-blue"
                  >
                    + New
                  </button>
                </div>
                <div v-if="loadingFanClusters" class="text-center py-4">
                  <div class="animate-spin rounded-full h-6 w-6 border-2 border-accent border-t-transparent mx-auto"></div>
                </div>
                <div v-else-if="fanClusters.length === 0" class="text-center py-4 text-theme-secondary text-sm">
                  No clusters yet. Create one to start organizing FAN research.
                </div>
                <div v-else class="space-y-2 max-h-80 overflow-y-auto">
                  <div
                    v-for="cluster in fanClusters"
                    :key="cluster.id"
                    @click="selectFanCluster(cluster)"
                    :class="[
                      'p-3 rounded-lg cursor-pointer transition-colors',
                      selectedFanCluster?.id === cluster.id
                        ? 'bg-accent/20 ring-1 ring-accent'
                        : 'bg-theme-secondary hover:bg-theme-hover'
                    ]"
                  >
                    <div class="font-medium text-theme-primary">{{ cluster.cluster_name }}</div>
                    <div class="text-xs text-theme-secondary mt-1">
                      {{ cluster.member_count || 0 }} members
                      <span v-if="cluster.research_period"> | {{ cluster.research_period }}</span>
                    </div>
                    <div v-if="cluster.location" class="text-xs text-theme-secondary truncate">{{ cluster.location }}</div>
                  </div>
                </div>
              </div>

              <!-- Cluster Members -->
              <div class="lg:col-span-2 bg-theme-tertiary rounded-lg p-4">
                <div v-if="!selectedFanCluster" class="text-center py-8 text-theme-secondary">
                  Select a cluster to view members
                </div>
                <div v-else>
                  <div class="flex justify-between items-center mb-3">
                    <div>
                      <h4 class="font-medium text-theme-primary">{{ selectedFanCluster.cluster_name }}</h4>
                      <p v-if="selectedFanCluster.notes" class="text-xs text-theme-secondary mt-1">{{ selectedFanCluster.notes }}</p>
                    </div>
                    <div class="flex gap-2">
                      <button
                        @click="openAddFanMember"
                        class="px-2 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700"
                      >
                        + Add Member
                      </button>
                      <button
                        @click="loadFanClusterAnalysis"
                        :disabled="loadingFanClusterAnalysis"
                        class="px-2 py-1 bg-purple-600 text-white rounded text-sm hover:bg-purple-700 disabled:opacity-50"
                      >
                        Analyze
                      </button>
                      <button
                        @click="confirmDeleteFanCluster"
                        class="px-2 py-1 bg-red-600 text-white rounded text-sm hover:bg-red-700"
                      >
                        Delete
                      </button>
                    </div>
                  </div>

                  <!-- Members by Relationship Type -->
                  <div v-if="loadingFanClusterMembers" class="text-center py-4">
                    <div class="animate-spin rounded-full h-6 w-6 border-2 border-accent border-t-transparent mx-auto"></div>
                  </div>
                  <div v-else-if="fanClusterMembers.length === 0" class="text-center py-4 text-theme-secondary text-sm">
                    No members yet. Add members manually or use Quick Extract.
                  </div>
                  <div v-else class="space-y-4">
                    <!-- Friends Section -->
                    <div v-if="fanMembersByType.friend?.length" class="space-y-2">
                      <h5 class="text-sm font-medium text-blue-400 flex items-center gap-2">
                        <span class="w-2 h-2 bg-blue-400 rounded-full"></span>
                        Friends ({{ fanMembersByType.friend.length }})
                      </h5>
                      <div class="space-y-1">
                        <div
                          v-for="member in fanMembersByType.friend"
                          :key="member.id"
                          class="flex items-center justify-between p-2 bg-theme-secondary rounded text-sm"
                        >
                          <div class="flex-1">
                            <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                            <span v-if="member.linked_given_name" class="text-accent text-xs ml-1">(linked)</span>
                            <div class="text-xs text-theme-secondary">{{ member.source_citation || member.interaction_description }}</div>
                          </div>
                          <div class="flex items-center gap-2">
                            <span :class="getConfidenceBadgeClass(member.confidence)">{{ member.confidence }}</span>
                            <button @click="openEditFanMember(member)" class="text-theme-secondary hover:text-accent">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteFanMember(member)" class="text-theme-secondary hover:text-red-400">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Associates Section -->
                    <div v-if="fanMembersByType.associate?.length" class="space-y-2">
                      <h5 class="text-sm font-medium text-green-400 flex items-center gap-2">
                        <span class="w-2 h-2 bg-green-400 rounded-full"></span>
                        Associates ({{ fanMembersByType.associate.length }})
                      </h5>
                      <div class="space-y-1">
                        <div
                          v-for="member in fanMembersByType.associate"
                          :key="member.id"
                          class="flex items-center justify-between p-2 bg-theme-secondary rounded text-sm"
                        >
                          <div class="flex-1">
                            <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                            <span v-if="member.linked_given_name" class="text-accent text-xs ml-1">(linked)</span>
                            <div class="text-xs text-theme-secondary">{{ member.source_citation || member.interaction_description }}</div>
                          </div>
                          <div class="flex items-center gap-2">
                            <span :class="getConfidenceBadgeClass(member.confidence)">{{ member.confidence }}</span>
                            <button @click="openEditFanMember(member)" class="text-theme-secondary hover:text-accent">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteFanMember(member)" class="text-theme-secondary hover:text-red-400">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Neighbors Section -->
                    <div v-if="fanMembersByType.neighbor?.length" class="space-y-2">
                      <h5 class="text-sm font-medium text-amber-400 flex items-center gap-2">
                        <span class="w-2 h-2 bg-amber-400 rounded-full"></span>
                        Neighbors ({{ fanMembersByType.neighbor.length }})
                      </h5>
                      <div class="space-y-1">
                        <div
                          v-for="member in fanMembersByType.neighbor"
                          :key="member.id"
                          class="flex items-center justify-between p-2 bg-theme-secondary rounded text-sm"
                        >
                          <div class="flex-1">
                            <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                            <span v-if="member.linked_given_name" class="text-accent text-xs ml-1">(linked)</span>
                            <div class="text-xs text-theme-secondary">{{ member.source_citation || member.interaction_description }}</div>
                          </div>
                          <div class="flex items-center gap-2">
                            <span :class="getConfidenceBadgeClass(member.confidence)">{{ member.confidence }}</span>
                            <button @click="openEditFanMember(member)" class="text-theme-secondary hover:text-accent">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteFanMember(member)" class="text-theme-secondary hover:text-red-400">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Witnesses Section -->
                    <div v-if="fanMembersByType.witness?.length" class="space-y-2">
                      <h5 class="text-sm font-medium text-pink-400 flex items-center gap-2">
                        <span class="w-2 h-2 bg-pink-400 rounded-full"></span>
                        Witnesses ({{ fanMembersByType.witness.length }})
                      </h5>
                      <div class="space-y-1">
                        <div
                          v-for="member in fanMembersByType.witness"
                          :key="member.id"
                          class="flex items-center justify-between p-2 bg-theme-secondary rounded text-sm"
                        >
                          <div class="flex-1">
                            <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                            <span v-if="member.linked_given_name" class="text-accent text-xs ml-1">(linked)</span>
                            <div class="text-xs text-theme-secondary">{{ member.source_citation || member.interaction_description }}</div>
                          </div>
                          <div class="flex items-center gap-2">
                            <span :class="getConfidenceBadgeClass(member.confidence)">{{ member.confidence }}</span>
                            <button @click="openEditFanMember(member)" class="text-theme-secondary hover:text-accent">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteFanMember(member)" class="text-theme-secondary hover:text-red-400">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Church Associates Section -->
                    <div v-if="fanMembersByType.church?.length" class="space-y-2">
                      <h5 class="text-sm font-medium text-purple-400 flex items-center gap-2">
                        <span class="w-2 h-2 bg-purple-400 rounded-full"></span>
                        Church Associates ({{ fanMembersByType.church.length }})
                      </h5>
                      <div class="space-y-1">
                        <div
                          v-for="member in fanMembersByType.church"
                          :key="member.id"
                          class="flex items-center justify-between p-2 bg-theme-secondary rounded text-sm"
                        >
                          <div class="flex-1">
                            <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                            <span v-if="member.linked_given_name" class="text-accent text-xs ml-1">(linked)</span>
                            <div class="text-xs text-theme-secondary">{{ member.source_citation || member.interaction_description }}</div>
                          </div>
                          <div class="flex items-center gap-2">
                            <span :class="getConfidenceBadgeClass(member.confidence)">{{ member.confidence }}</span>
                            <button @click="openEditFanMember(member)" class="text-theme-secondary hover:text-accent">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteFanMember(member)" class="text-theme-secondary hover:text-red-400">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Business Associates Section -->
                    <div v-if="fanMembersByType.business?.length" class="space-y-2">
                      <h5 class="text-sm font-medium text-cyan-400 flex items-center gap-2">
                        <span class="w-2 h-2 bg-cyan-400 rounded-full"></span>
                        Business Associates ({{ fanMembersByType.business.length }})
                      </h5>
                      <div class="space-y-1">
                        <div
                          v-for="member in fanMembersByType.business"
                          :key="member.id"
                          class="flex items-center justify-between p-2 bg-theme-secondary rounded text-sm"
                        >
                          <div class="flex-1">
                            <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                            <span v-if="member.linked_given_name" class="text-accent text-xs ml-1">(linked)</span>
                            <div class="text-xs text-theme-secondary">{{ member.source_citation || member.interaction_description }}</div>
                          </div>
                          <div class="flex items-center gap-2">
                            <span :class="getConfidenceBadgeClass(member.confidence)">{{ member.confidence }}</span>
                            <button @click="openEditFanMember(member)" class="text-theme-secondary hover:text-accent">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteFanMember(member)" class="text-theme-secondary hover:text-red-400">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Other Section -->
                    <div v-if="fanMembersByType.other?.length" class="space-y-2">
                      <h5 class="text-sm font-medium text-gray-400 flex items-center gap-2">
                        <span class="w-2 h-2 bg-gray-400 rounded-full"></span>
                        Other ({{ fanMembersByType.other.length }})
                      </h5>
                      <div class="space-y-1">
                        <div
                          v-for="member in fanMembersByType.other"
                          :key="member.id"
                          class="flex items-center justify-between p-2 bg-theme-secondary rounded text-sm"
                        >
                          <div class="flex-1">
                            <span class="font-medium text-theme-primary">{{ member.member_name }}</span>
                            <span v-if="member.linked_given_name" class="text-accent text-xs ml-1">(linked)</span>
                            <div class="text-xs text-theme-secondary">{{ member.source_citation || member.interaction_description }}</div>
                          </div>
                          <div class="flex items-center gap-2">
                            <span :class="getConfidenceBadgeClass(member.confidence)">{{ member.confidence }}</span>
                            <button @click="openEditFanMember(member)" class="text-theme-secondary hover:text-accent">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                            </button>
                            <button @click="confirmDeleteFanMember(member)" class="text-theme-secondary hover:text-red-400">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                              </svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- N93: Co-occurrence Panel -->
            <div v-if="fanClusterPersonId" class="bg-theme-tertiary rounded-lg p-4">
              <div class="flex items-center justify-between mb-3">
                <div>
                  <h4 class="font-medium text-theme-primary">Accumulated Co-occurrences</h4>
                  <p class="text-xs text-theme-secondary mt-0.5">Names appearing alongside this person in research sources, ranked by frequency × confidence.</p>
                </div>
                <button @click="loadFanCooccurrences" :disabled="loadingFanCooccurrences" class="px-2 py-1 bg-theme-secondary text-theme-primary rounded text-sm hover:bg-theme-hover disabled:opacity-50">
                  Refresh
                </button>
              </div>
              <div v-if="loadingFanCooccurrences" class="flex items-center gap-2 py-4 text-theme-secondary text-sm">
                <div class="animate-spin rounded-full h-4 w-4 border-2 border-accent border-t-transparent"></div>
                Loading...
              </div>
              <div v-else-if="fanCooccurrences.length === 0" class="text-center py-4 text-theme-secondary text-sm">
                No co-occurrences recorded yet. They accumulate automatically as the genealogy agent searches.
              </div>
              <div v-else class="space-y-1 max-h-64 overflow-y-auto">
                <div v-for="co in fanCooccurrences" :key="co.cooccurring_name + co.source_type"
                     class="flex items-center justify-between px-3 py-2 bg-theme-secondary rounded hover:bg-theme-hover">
                  <div class="flex items-center gap-2 flex-1 min-w-0">
                    <span v-if="co.occurrence_count >= 2" class="flex-shrink-0 px-1.5 py-0.5 bg-amber-500/20 text-amber-400 rounded text-xs font-medium">×{{ co.occurrence_count }}</span>
                    <span v-else class="flex-shrink-0 px-1.5 py-0.5 bg-theme-tertiary text-theme-secondary rounded text-xs">×1</span>
                    <span class="font-medium text-theme-primary truncate">{{ co.cooccurring_name }}</span>
                    <span v-if="co.source_location" class="text-xs text-theme-secondary truncate hidden md:block">{{ co.source_location }}</span>
                  </div>
                  <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="text-xs text-theme-tertiary px-1.5 py-0.5 bg-theme-tertiary rounded">{{ co.source_type }}</span>
                    <span class="text-xs text-theme-tertiary w-8 text-right">{{ (co.confidence * 100).toFixed(0) }}%</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Analysis Panel -->
            <div v-if="fanClusterAnalysis" class="bg-theme-tertiary rounded-lg p-4">
              <div class="flex justify-between items-center mb-4">
                <h4 class="font-medium text-theme-primary">Cluster Analysis</h4>
                <button @click="fanClusterAnalysis = null" class="text-theme-secondary hover:text-theme-primary">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Statistics -->
                <div class="bg-theme-secondary rounded-lg p-3">
                  <h5 class="text-sm font-medium text-theme-primary mb-2">Statistics</h5>
                  <div class="space-y-1 text-sm">
                    <div class="flex justify-between">
                      <span class="text-theme-secondary">Total Members:</span>
                      <span class="text-theme-primary">{{ fanClusterAnalysis.total_members }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-theme-secondary">Linked to Tree:</span>
                      <span class="text-green-400">{{ fanClusterAnalysis.linked_members }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-theme-secondary">Unlinked:</span>
                      <span class="text-amber-400">{{ fanClusterAnalysis.unlinked_members }}</span>
                    </div>
                    <div v-if="fanClusterAnalysis.date_range?.earliest" class="flex justify-between">
                      <span class="text-theme-secondary">Date Range:</span>
                      <span class="text-theme-primary">{{ fanClusterAnalysis.date_range.earliest }} - {{ fanClusterAnalysis.date_range.latest }}</span>
                    </div>
                  </div>
                </div>

                <!-- Relationship Distribution -->
                <div class="bg-theme-secondary rounded-lg p-3">
                  <h5 class="text-sm font-medium text-theme-primary mb-2">Relationships</h5>
                  <div class="space-y-1 text-sm">
                    <div v-for="(count, type) in fanClusterAnalysis.relationship_distribution" :key="type" class="flex justify-between">
                      <span class="text-theme-secondary capitalize">{{ type }}:</span>
                      <span class="text-theme-primary">{{ count }}</span>
                    </div>
                  </div>
                </div>

                <!-- Potential Family -->
                <div class="bg-theme-secondary rounded-lg p-3">
                  <h5 class="text-sm font-medium text-theme-primary mb-2">Potential Family Connections</h5>
                  <div v-if="fanClusterAnalysis.potential_family_connections?.length" class="space-y-1 text-sm">
                    <div v-for="member in fanClusterAnalysis.potential_family_connections.slice(0, 5)" :key="member.name" class="flex justify-between items-center">
                      <span class="text-theme-primary truncate flex-1">{{ member.name }}</span>
                      <span class="text-xs text-amber-400 ml-2">{{ member.count }}x</span>
                    </div>
                  </div>
                  <div v-else class="text-sm text-theme-secondary">No recurring patterns found yet.</div>
                </div>
              </div>

              <!-- Analysis Notes -->
              <div v-if="fanClusterAnalysis.analysis_notes?.length" class="mt-4 p-3 bg-blue-500/10 rounded-lg border border-blue-500/30">
                <h5 class="text-sm font-medium text-blue-400 mb-2">Research Notes</h5>
                <ul class="text-sm text-theme-secondary space-y-1">
                  <li v-for="(note, idx) in fanClusterAnalysis.analysis_notes" :key="idx">{{ note }}</li>
                </ul>
              </div>
            </div>

            <!-- Research Suggestions -->
            <div v-if="fanClusterSuggestions.length > 0" class="bg-theme-tertiary rounded-lg p-4">
              <div class="flex justify-between items-center mb-4">
                <h4 class="font-medium text-theme-primary">Research Suggestions</h4>
                <button @click="fanClusterSuggestions = []" class="text-theme-secondary hover:text-theme-primary text-sm">
                  Clear
                </button>
              </div>
              <div class="space-y-2 max-h-60 overflow-y-auto">
                <div
                  v-for="(suggestion, idx) in fanClusterSuggestions"
                  :key="idx"
                  class="p-3 bg-theme-secondary rounded-lg"
                >
                  <div class="flex items-start gap-3">
                    <span :class="[
                      'px-2 py-0.5 rounded text-xs font-medium',
                      suggestion.priority === 'high' ? 'bg-red-500/20 text-red-400' :
                      suggestion.priority === 'medium' ? 'bg-amber-500/20 text-amber-400' :
                      'bg-gray-500/20 text-gray-400'
                    ]">
                      {{ suggestion.priority }}
                    </span>
                    <div class="flex-1">
                      <div class="font-medium text-theme-primary">{{ suggestion.target_name || suggestion.source_type || 'Research' }}</div>
                      <div class="text-sm text-theme-secondary">{{ suggestion.reason }}</div>
                      <div v-if="suggestion.suggested_records?.length" class="mt-2">
                        <div class="text-xs text-theme-secondary mb-1">Suggested records:</div>
                        <ul class="text-xs text-theme-secondary list-disc list-inside">
                          <li v-for="(record, ridx) in suggestion.suggested_records.slice(0, 3)" :key="ridx">{{ record }}</li>
                        </ul>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Person Detail Modal -->
      <!-- Person Detail Slide-In Panel (N135) -->
      <Transition name="slide-panel">
      <div v-if="selectedPerson" class="fixed inset-0 z-40 flex">
        <!-- Backdrop -->
        <div class="flex-1 bg-black/30" @click="selectedPerson = null"></div>
        <!-- Panel -->
        <div class="w-full max-w-3xl bg-theme-secondary shadow-2xl flex flex-col h-full overflow-hidden border-l border-theme">
          <!-- Sticky Header -->
          <div class="shrink-0 p-4 border-b border-theme bg-theme-secondary">
            <div class="flex justify-between items-start">
              <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                  <div v-if="selectedPerson.primary_photo_url" class="w-20 h-20 rounded-full overflow-hidden border-2 border-accent shadow-lg">
                    <img :src="selectedPerson.primary_photo_url" :alt="`${selectedPerson.given_name} ${selectedPerson.surname}`" class="w-full h-full object-cover" />
                  </div>
                  <div v-else class="w-20 h-20 rounded-full bg-theme-tertiary flex items-center justify-center border-2 border-theme-border">
                    <svg class="w-10 h-10 text-theme-secondary" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                  </div>
                </div>
                <div>
                  <h3 class="text-2xl font-bold text-theme-primary">
                    <span v-if="selectedPerson.title" class="text-theme-secondary font-normal">{{ selectedPerson.title }} </span>
                    {{ selectedPerson.given_name }}
                    <span v-if="selectedPerson.nickname" class="text-theme-secondary font-normal">"{{ selectedPerson.nickname }}"</span>
                    {{ selectedPerson.surname }}
                    <span v-if="selectedPerson.suffix" class="text-theme-secondary font-normal">{{ selectedPerson.suffix }}</span>
                  </h3>
                  <div class="flex items-center gap-3 text-sm text-theme-secondary mt-1">
                    <span>{{ selectedPerson.tree_name }}</span>
                    <span v-if="selectedPerson.sex" class="px-1.5 py-0.5 rounded text-xs" :class="selectedPerson.sex === 'M' ? 'bg-blue-500/20 text-blue-400' : selectedPerson.sex === 'F' ? 'bg-pink-500/20 text-pink-400' : 'bg-gray-500/20 text-gray-400'">
                      {{ selectedPerson.sex === 'M' ? 'Male' : selectedPerson.sex === 'F' ? 'Female' : 'Unknown' }}
                    </span>
                    <span v-if="selectedPerson.living" class="px-1.5 py-0.5 bg-green-500/20 text-green-400 rounded text-xs">Living</span>
                    <span v-if="selectedPerson.birth_date && selectedPerson.death_date" class="text-xs opacity-75">
                      {{ selectedPerson.birth_date?.substring(selectedPerson.birth_date.length - 4) }} &ndash; {{ selectedPerson.death_date?.substring(selectedPerson.death_date.length - 4) }}
                    </span>
                  </div>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <button @click="openEditPerson(selectedPerson)" class="px-3 py-1.5 bg-accent text-white rounded hover:bg-accent-blue text-sm">Edit</button>
                <button @click="confirmDeletePerson(selectedPerson)" class="px-3 py-1.5 bg-red-600 text-white rounded hover:bg-red-700 text-sm">Delete</button>
                <button @click="selectedPerson = null" class="text-theme-secondary hover:text-theme-primary ml-1">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
              </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-theme">
              <span class="text-xs text-theme-secondary mr-1">Add:</span>
              <button @click="openAddRelative(selectedPerson, 'father')" :disabled="selectedPerson.family_as_child?.husband_id" class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">+ Father</button>
              <button @click="openAddRelative(selectedPerson, 'mother')" :disabled="selectedPerson.family_as_child?.wife_id" class="px-2 py-1 bg-pink-600 text-white rounded text-xs hover:bg-pink-700 disabled:opacity-50 disabled:cursor-not-allowed">+ Mother</button>
              <button @click="openAddRelative(selectedPerson, 'spouse')" class="px-2 py-1 bg-purple-600 text-white rounded text-xs hover:bg-purple-700">+ Spouse</button>
              <button @click="openAddRelative(selectedPerson, 'child')" class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">+ Child</button>
              <span class="mx-1 text-theme-secondary">|</span>
              <button @click="loadPersonTimeline(selectedPerson.id)" class="px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-xs hover:bg-accent hover:text-white">Timeline</button>
              <button @click="loadPedigreeChart(selectedPerson.id)" class="px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-xs hover:bg-accent hover:text-white">Pedigree</button>
              <button @click="loadDescendantReport(selectedPerson.id)" class="px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-xs hover:bg-accent hover:text-white">Descendants</button>
              <button @click="loadIndividualSummary(selectedPerson.id)" class="px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-xs hover:bg-accent hover:text-white">Summary</button>
              <button @click="loadAhnentafelReport(selectedPerson.id)" class="px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-xs hover:bg-accent hover:text-white">Ahnentafel</button>
              <button @click="openAIResearch(selectedPerson.id)" class="px-2 py-1 bg-violet-600 text-white rounded text-xs hover:bg-violet-700">AI Research</button>
            </div>
          </div>

          <!-- Scrollable Content -->
          <div class="flex-1 overflow-y-auto p-4 space-y-1">

            <!-- VITALS SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('vitals')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">Vital Events</span>
                <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.vitals }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div v-show="panelSections.vitals" class="px-4 pb-4 space-y-3">
                <div class="grid grid-cols-3 gap-3">
                  <div class="p-3 bg-theme-tertiary rounded-lg">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide mb-1">Birth</div>
                    <div class="text-theme-primary text-sm">{{ selectedPerson.birth_date || 'Unknown' }}</div>
                    <div v-if="selectedPerson.birth_place" class="text-theme-secondary text-xs mt-0.5">{{ selectedPerson.birth_place }}</div>
                  </div>
                  <div class="p-3 bg-theme-tertiary rounded-lg">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide mb-1">Death</div>
                    <div class="text-theme-primary text-sm">{{ selectedPerson.death_date || 'Unknown' }}</div>
                    <div v-if="selectedPerson.death_place" class="text-theme-secondary text-xs mt-0.5">{{ selectedPerson.death_place }}</div>
                    <div v-if="selectedPerson.cause_of_death" class="text-theme-secondary text-xs mt-0.5 italic">Cause: {{ selectedPerson.cause_of_death }}</div>
                  </div>
                  <div class="p-3 bg-theme-tertiary rounded-lg">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide mb-1">Burial</div>
                    <div class="text-theme-primary text-sm">{{ selectedPerson.burial_date || 'Unknown' }}</div>
                    <div v-if="selectedPerson.burial_place" class="text-theme-secondary text-xs mt-0.5">{{ selectedPerson.burial_place }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- BIOGRAPHICAL SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('biographical')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">Biographical Details</span>
                <div class="flex items-center gap-2">
                  <button @click.stop="openEditPerson(selectedPerson); personEditTab = 'basic'" class="text-xs text-accent hover:text-accent-blue px-1">Edit</button>
                  <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.biographical }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
              </button>
              <div v-show="panelSections.biographical" class="px-4 pb-4">
                <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                  <div class="flex"><span class="text-theme-secondary w-28 flex-shrink-0">Occupation:</span><span class="text-theme-primary">{{ selectedPerson.occupation || '&#8212;' }}</span></div>
                  <div class="flex"><span class="text-theme-secondary w-28 flex-shrink-0">Education:</span><span class="text-theme-primary">{{ selectedPerson.education || '&#8212;' }}</span></div>
                  <div class="flex"><span class="text-theme-secondary w-28 flex-shrink-0">Religion:</span><span class="text-theme-primary">{{ selectedPerson.religion || '&#8212;' }}</span></div>
                  <div class="flex"><span class="text-theme-secondary w-28 flex-shrink-0">Nationality:</span><span class="text-theme-primary">{{ selectedPerson.nationality || '&#8212;' }}</span></div>
                  <div v-if="selectedPerson.ssn" class="flex"><span class="text-theme-secondary w-28 flex-shrink-0">SSN:</span><span class="text-theme-primary">{{ selectedPerson.ssn }}</span></div>
                  <div v-if="selectedPerson.id_number" class="flex"><span class="text-theme-secondary w-28 flex-shrink-0">ID Number:</span><span class="text-theme-primary">{{ selectedPerson.id_number }}</span></div>
                  <div v-if="selectedPerson.physical_description" class="flex col-span-2"><span class="text-theme-secondary w-28 flex-shrink-0">Physical:</span><span class="text-theme-primary">{{ selectedPerson.physical_description }}</span></div>
                  <div v-if="selectedPerson.property" class="flex col-span-2"><span class="text-theme-secondary w-28 flex-shrink-0">Property:</span><span class="text-theme-primary">{{ selectedPerson.property }}</span></div>
                </div>
              </div>
            </div>

            <!-- NAME VARIANTS SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('nameVariants'); panelSections.nameVariants && !showNameVariants && toggleNameVariants(selectedPerson.id)" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">
                  Name Variants
                  <span v-if="selectedPerson.name_variants?.length" class="text-xs text-theme-secondary font-normal ml-1">({{ selectedPerson.name_variants.length }})</span>
                </span>
                <div class="flex items-center gap-2">
                  <button @click.stop="showAddNameVariant = !showAddNameVariant; if (!panelSections.nameVariants) { panelSections.nameVariants = true; toggleNameVariants(selectedPerson.id); }" class="text-xs text-accent hover:text-accent-blue px-1">+ Add</button>
                  <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.nameVariants }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
              </button>
              <div v-show="panelSections.nameVariants" class="px-4 pb-4">
                <div v-if="loadingNameVariants" class="text-center py-2">
                  <div class="animate-spin w-5 h-5 border-2 border-accent border-t-transparent rounded-full mx-auto"></div>
                </div>
                <div v-else>
                  <div v-if="(nameVariants.length === 0) && (!selectedPerson.name_variants?.length)" class="text-sm text-theme-secondary text-center py-2">No name variants recorded</div>
                  <div v-for="variant in (nameVariants.length ? nameVariants : selectedPerson.name_variants || [])" :key="variant.id" class="flex items-center justify-between py-1.5 px-2 bg-theme-tertiary rounded mb-1">
                    <div>
                      <span class="text-sm text-theme-primary">{{ variant.given_name || variant.given_names }} {{ variant.surname }}</span>
                      <span v-if="variant.variant_type || variant.name_type" class="ml-2 text-xs px-1.5 py-0.5 bg-accent/20 text-accent rounded">{{ variant.variant_type || variant.name_type }}</span>
                      <span v-if="variant.notes" class="ml-2 text-xs text-theme-secondary italic">{{ variant.notes }}</span>
                    </div>
                    <button @click="deleteNameVariant(variant.id)" class="text-red-400 hover:text-red-300 text-xs">Delete</button>
                  </div>
                  <div v-if="showAddNameVariant" class="mt-2 flex gap-2 items-end">
                    <input v-model="newVariantGiven" placeholder="Given name" class="flex-1 px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-sm border border-theme">
                    <input v-model="newVariantSurname" placeholder="Surname" class="flex-1 px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-sm border border-theme">
                    <select v-model="newVariantType" class="px-2 py-1 bg-theme-tertiary text-theme-primary rounded text-sm border border-theme">
                      <option value="">Type</option>
                      <option value="maiden">Maiden</option>
                      <option value="married">Married</option>
                      <option value="alias">Alias</option>
                      <option value="nickname">Nickname</option>
                      <option value="spelling">Spelling</option>
                      <option value="religious">Religious</option>
                      <option value="phonetic">Phonetic</option>
                    </select>
                    <button @click="addNameVariant" class="px-3 py-1 bg-accent text-white rounded text-sm hover:bg-accent-blue">Add</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- FAMILY SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('family')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">Family</span>
                <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.family }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div v-show="panelSections.family" class="px-4 pb-4 space-y-3">
                <!-- Parents -->
                <div v-if="selectedPerson.family_as_child" class="p-3 bg-theme-tertiary rounded-lg">
                  <div class="text-xs text-theme-secondary uppercase tracking-wide mb-2">Parents</div>
                  <div class="flex gap-4">
                    <button v-if="selectedPerson.family_as_child.father_given" @click="selectPerson({ id: selectedPerson.family_as_child.father_id })" class="text-theme-primary hover:text-accent cursor-pointer underline underline-offset-2 decoration-dotted hover:decoration-solid transition-colors flex items-center gap-1">
                      <span class="text-blue-400">&#9794;</span> {{ selectedPerson.family_as_child.father_given }} {{ selectedPerson.family_as_child.father_surname }}
                    </button>
                    <button v-if="selectedPerson.family_as_child.mother_given" @click="selectPerson({ id: selectedPerson.family_as_child.mother_id })" class="text-theme-primary hover:text-accent cursor-pointer underline underline-offset-2 decoration-dotted hover:decoration-solid transition-colors flex items-center gap-1">
                      <span class="text-pink-400">&#9792;</span> {{ selectedPerson.family_as_child.mother_given }} {{ selectedPerson.family_as_child.mother_surname }}
                    </button>
                  </div>
                </div>

                <!-- Siblings -->
                <div v-if="selectedPerson.siblings?.length > 0">
                  <div class="text-xs text-theme-secondary uppercase tracking-wide mb-2">Siblings ({{ selectedPerson.siblings.length }})</div>
                  <div class="flex flex-wrap gap-2">
                    <button v-for="sibling in selectedPerson.siblings" :key="sibling.id" @click="selectPerson({ id: sibling.id })" class="px-3 py-1.5 bg-theme-tertiary rounded text-sm text-theme-primary hover:bg-accent hover:text-white cursor-pointer transition-colors flex items-center gap-1">
                      <span>{{ sibling.sex === 'M' ? '&#9794;' : sibling.sex === 'F' ? '&#9792;' : '' }}</span>
                      {{ sibling.given_name }} {{ sibling.surname }}
                      <span v-if="sibling.birth_date" class="text-xs opacity-75">({{ sibling.birth_date?.substring(0, 4) }})</span>
                    </button>
                  </div>
                </div>

                <!-- Spouse families -->
                <div v-if="selectedPerson.families_as_spouse?.length > 0">
                  <div v-for="family in selectedPerson.families_as_spouse" :key="family.id" class="p-3 bg-theme-tertiary rounded-lg mb-2">
                    <div class="flex justify-between items-start">
                      <div>
                        <button @click="selectPerson({ id: family.husband_db_id === selectedPerson.id ? family.wife_db_id : family.husband_db_id })" class="text-theme-primary font-medium hover:text-accent cursor-pointer underline underline-offset-2 decoration-dotted hover:decoration-solid transition-colors">
                          Spouse: {{ family.husband_db_id === selectedPerson.id ? `${family.wife_given} ${family.wife_surname}` : `${family.husband_given} ${family.husband_surname}` }}
                        </button>
                        <span v-if="family.marriage_date" class="text-theme-secondary text-sm ml-2">m. {{ family.marriage_date }}</span>
                      </div>
                      <button @click="openEditFamily(family)" class="px-2 py-1 bg-theme-secondary text-theme-primary rounded hover:bg-theme-primary hover:text-white text-xs">Edit Family</button>
                    </div>
                    <div v-if="family.children?.length > 0" class="mt-2">
                      <div class="text-xs text-theme-secondary mb-1">Children ({{ family.children.length }}):</div>
                      <div class="flex flex-wrap gap-2">
                        <button v-for="child in family.children" :key="child.id" @click="selectPerson({ id: child.id })" class="px-2 py-1 bg-theme-secondary rounded text-sm text-theme-primary hover:bg-accent hover:text-white cursor-pointer transition-colors flex items-center gap-1">
                          <span>{{ child.sex === 'M' ? '&#9794;' : child.sex === 'F' ? '&#9792;' : '' }}</span>
                          {{ child.given_name }}
                          <span v-if="child.birth_date" class="text-xs opacity-75">({{ child.birth_date?.substring(0, 4) }})</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- RESIDENCES SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('residences')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">
                  Residences
                  <span v-if="selectedPerson.residences?.length" class="text-xs text-theme-secondary font-normal ml-1">({{ selectedPerson.residences.length }})</span>
                </span>
                <div class="flex items-center gap-2">
                  <button @click.stop="openEditPerson(selectedPerson); personEditTab = 'residences'" class="text-xs text-accent hover:text-accent-blue px-1">Edit</button>
                  <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.residences }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
              </button>
              <div v-show="panelSections.residences" class="px-4 pb-4">
                <div v-if="selectedPerson.residences?.length > 0" class="space-y-2">
                  <div v-for="res in selectedPerson.residences" :key="res.id" class="flex items-start gap-3 p-2 bg-theme-tertiary rounded text-sm">
                    <div class="text-theme-secondary w-24 flex-shrink-0">{{ res.residence_date || 'Unknown' }}</div>
                    <div class="text-theme-primary flex-1">{{ res.place || 'Unknown place' }}</div>
                  </div>
                </div>
                <div v-else class="text-sm text-theme-secondary text-center py-2">No residences recorded</div>
              </div>
            </div>

            <!-- LIFE EVENTS SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('events')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">
                  Life Events
                  <span v-if="selectedPerson.events?.length" class="text-xs text-theme-secondary font-normal ml-1">({{ selectedPerson.events.length }})</span>
                </span>
                <div class="flex items-center gap-2">
                  <button @click.stop="openCreateEvent(selectedPerson)" class="text-xs text-accent hover:text-accent-blue px-1">+ Add</button>
                  <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.events }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
              </button>
              <div v-show="panelSections.events" class="px-4 pb-4">
                <div v-if="selectedPerson.events?.length > 0" class="space-y-2">
                  <div v-for="event in selectedPerson.events" :key="event.id" class="p-3 bg-theme-tertiary rounded-lg flex justify-between items-start">
                    <div>
                      <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 bg-accent/20 text-accent rounded text-xs font-medium">{{ getEventTypeLabel(event.event_type) }}</span>
                        <span v-if="event.event_date" class="text-theme-primary text-sm">{{ event.event_date }}</span>
                      </div>
                      <div v-if="event.event_place" class="text-theme-secondary text-sm mt-1">{{ event.event_place }}</div>
                      <div v-if="event.description" class="text-theme-secondary text-xs mt-1 italic">{{ event.description }}</div>
                      <div v-if="event.source_title" class="text-theme-secondary text-xs mt-1">Source: {{ event.source_title }}</div>
                    </div>
                    <div class="flex gap-1">
                      <button @click="openEditEvent(event)" class="p-1 text-theme-secondary hover:text-accent" title="Edit event">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                      </button>
                      <button @click="confirmDeleteEvent(event)" class="p-1 text-theme-secondary hover:text-red-500" title="Delete event">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      </button>
                    </div>
                  </div>
                </div>
                <div v-else class="text-sm text-theme-secondary text-center py-2">No life events recorded</div>
              </div>
            </div>

            <!-- MEDIA SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('media')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">
                  Photos & Documents
                  <span v-if="selectedPerson.media?.length" class="text-xs text-theme-secondary font-normal ml-1">({{ selectedPerson.media.length }})</span>
                </span>
                <div class="flex items-center gap-2">
                  <button @click.stop="openLinkMediaToPersonModal" class="text-xs text-accent hover:text-accent-blue px-1">+ Add</button>
                  <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.media }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
              </button>
              <div v-show="panelSections.media" class="px-4 pb-4">
                <div v-if="selectedPerson.media?.length > 0" class="grid grid-cols-5 gap-2">
                  <div v-for="item in paginatedPersonMedia" :key="item.id" class="relative aspect-square bg-theme-tertiary rounded overflow-hidden group">
                    <div v-if="selectedPerson.primary_photo_id === item.id" class="absolute top-1 left-1 z-10 bg-yellow-500 text-white rounded-full p-1" title="Primary Photo">
                      <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    </div>
                    <button v-if="isImage(item.file_format) && item.nextcloud_path && selectedPerson.primary_photo_id !== item.id" @click.stop="setPersonPrimaryPhoto(selectedPerson.id, item.id)" class="absolute top-1 right-1 z-10 bg-theme-secondary/80 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity hover:bg-accent" title="Set as Primary Photo">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                    </button>
                    <button v-if="selectedPerson.primary_photo_id === item.id" @click.stop="setPersonPrimaryPhoto(selectedPerson.id, null)" class="absolute top-1 right-1 z-10 bg-red-500/80 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-600" title="Remove as Primary Photo">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                    <img v-if="item.nextcloud_path && isImage(item.file_format)" :src="`/api/media/file?path=${encodeURIComponent(item.nextcloud_path)}`" class="w-full h-full object-cover cursor-pointer hover:opacity-90 transition-opacity" @click="openImageModal(item)" />
                  </div>
                </div>
                <p v-else class="text-theme-secondary text-sm text-center py-2">No media linked</p>
                <div v-if="personMediaTotalPages > 1" class="flex items-center justify-center gap-2 mt-3">
                  <button @click="personMediaPage = 1" :disabled="personMediaPage === 1" class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed">&#171;</button>
                  <button @click="personMediaPage--" :disabled="personMediaPage === 1" class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed">&#8249;</button>
                  <span class="text-xs text-theme-secondary px-2">{{ personMediaPage }} / {{ personMediaTotalPages }}</span>
                  <button @click="personMediaPage++" :disabled="personMediaPage >= personMediaTotalPages" class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed">&#8250;</button>
                  <button @click="personMediaPage = personMediaTotalPages" :disabled="personMediaPage >= personMediaTotalPages" class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed">&#187;</button>
                </div>
              </div>
            </div>

            <!-- SOURCES SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('sources')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">
                  Sources
                  <span v-if="selectedPerson.sources?.length" class="text-xs text-theme-secondary font-normal ml-1">({{ selectedPerson.sources.length }})</span>
                </span>
                <div class="flex items-center gap-2">
                  <button @click.stop="openEditPerson(selectedPerson); personEditTab = 'sources'" class="text-xs text-accent hover:text-accent-blue px-1">Manage</button>
                  <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.sources }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
              </button>
              <div v-show="panelSections.sources" class="px-4 pb-4">
                <div v-if="selectedPerson.sources?.length > 0" class="space-y-2">
                  <div v-for="source in selectedPerson.sources" :key="source.id" class="p-3 bg-theme-tertiary rounded-lg">
                    <div class="font-medium text-theme-primary text-sm">{{ source.title || 'Untitled Source' }}</div>
                    <div class="grid grid-cols-1 gap-0.5 mt-1 text-xs">
                      <div v-if="source.author" class="text-theme-secondary"><span class="opacity-70">Author:</span> {{ source.author }}</div>
                      <div v-if="source.publication" class="text-theme-secondary"><span class="opacity-70">Publication:</span> {{ truncateText(source.publication, 120) }}</div>
                      <div v-if="source.repository" class="text-theme-secondary"><span class="opacity-70">Repository:</span> {{ source.repository }}</div>
                      <div v-if="source.url" class="text-theme-secondary truncate"><span class="opacity-70">URL:</span> <a :href="source.url" target="_blank" @click.stop class="text-accent hover:underline ml-1">{{ truncateText(source.url, 60) }}</a></div>
                    </div>
                    <div v-if="source.citation_page || source.citation_quality" class="flex gap-3 mt-1.5 pt-1.5 border-t border-theme-secondary/30">
                      <div v-if="source.citation_page" class="text-xs text-accent"><span class="opacity-70">Page:</span> {{ source.citation_page }}</div>
                      <div v-if="source.citation_quality" class="text-xs text-theme-secondary"><span class="opacity-70">Quality:</span> {{ formatQuality(source.citation_quality) }}</div>
                    </div>
                    <div v-if="source.notes" class="mt-1.5 pt-1.5 border-t border-theme-secondary/30 text-xs text-theme-secondary italic">{{ truncateText(source.notes, 100) }}</div>
                  </div>
                </div>
                <div v-else class="text-sm text-theme-secondary text-center py-2">No sources linked</div>
              </div>
            </div>

            <!-- EXTERNAL LINKS SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('externalLinks')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">
                  External Links
                  <span v-if="selectedPerson.external_links?.length || selectedPerson.external_ids?.length" class="text-xs text-theme-secondary font-normal ml-1">({{ (selectedPerson.external_links?.length || 0) + (selectedPerson.external_ids?.length || 0) }})</span>
                </span>
                <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.externalLinks }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div v-show="panelSections.externalLinks" class="px-4 pb-4">
                <!-- Service links -->
                <div v-if="selectedPerson.external_links?.length > 0" class="space-y-2 mb-3">
                  <div v-for="link in selectedPerson.external_links" :key="link.service_type" class="flex items-center justify-between p-2 bg-theme-tertiary rounded">
                    <div class="flex items-center gap-3">
                      <span :class="[getServiceColor(link.service_type), 'px-2 py-0.5 rounded text-white text-xs font-medium']">{{ getServiceLabel(link.service_type) }}</span>
                      <span class="text-sm text-theme-primary">{{ link.external_person_id }}</span>
                      <a :href="getExternalServiceUrl(link.service_type, link.external_person_id)" target="_blank" @click.stop class="text-accent hover:text-accent-blue text-xs">Open</a>
                    </div>
                  </div>
                </div>
                <!-- External IDs (GEDCOM-imported) -->
                <div v-if="selectedPerson.external_ids?.length > 0" class="space-y-2">
                  <div class="text-xs text-theme-secondary uppercase tracking-wide" v-if="selectedPerson.external_links?.length">GEDCOM IDs</div>
                  <div v-for="eid in selectedPerson.external_ids" :key="eid.id" class="flex items-center gap-3 p-2 bg-theme-tertiary rounded text-sm">
                    <span class="text-theme-secondary text-xs w-24">{{ eid.id_type }}</span>
                    <span class="text-theme-primary">{{ eid.external_id }}</span>
                  </div>
                </div>
                <div v-if="!selectedPerson.external_links?.length && !selectedPerson.external_ids?.length" class="text-sm text-theme-secondary text-center py-2">No external links</div>
              </div>
            </div>

            <!-- NOTES SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('notes')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">Notes</span>
                <div class="flex items-center gap-2">
                  <button @click.stop="openEditPerson(selectedPerson); personEditTab = 'basic'" class="text-xs text-accent hover:text-accent-blue px-1">Edit</button>
                  <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.notes }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
              </button>
              <div v-show="panelSections.notes" class="px-4 pb-4">
                <div v-if="selectedPerson.notes" class="text-sm text-theme-primary whitespace-pre-wrap bg-theme-tertiary rounded-lg p-3">{{ selectedPerson.notes }}</div>
                <div v-else class="text-sm text-theme-secondary text-center py-2">No notes recorded</div>
              </div>
            </div>

            <!-- CHANGE HISTORY SECTION -->
            <div class="rounded-lg border border-theme">
              <button @click="togglePanelSection('history')" class="w-full flex justify-between items-center px-4 py-2.5 hover:bg-theme-tertiary/50 transition-colors">
                <span class="text-sm font-semibold text-theme-primary uppercase tracking-wide">Change History</span>
                <svg class="w-4 h-4 text-theme-secondary transition-transform" :class="{ 'rotate-180': panelSections.history }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div v-show="panelSections.history" class="px-4 pb-4">
                <div v-if="loadingPanelActivity" class="text-center py-4">
                  <div class="animate-spin w-5 h-5 border-2 border-accent border-t-transparent rounded-full mx-auto"></div>
                </div>
                <div v-else-if="panelActivityLog.length > 0" class="space-y-2">
                  <div v-for="activity in panelActivityLog" :key="activity.id" class="p-2 bg-theme-tertiary rounded text-sm">
                    <div class="flex justify-between items-start">
                      <div>
                        <span class="text-theme-primary font-medium">{{ activity.user_name || 'System' }}</span>
                        <span class="text-theme-secondary ml-1">{{ formatActivityAction(activity.action) }}</span>
                        <span v-if="activity.entity_type" class="text-accent ml-1">{{ activity.entity_type }}</span>
                      </div>
                      <span class="text-xs text-theme-secondary">{{ formatDate(activity.created_at) }}</span>
                    </div>
                    <div v-if="activity.new_values" class="text-xs text-theme-secondary mt-1">{{ JSON.stringify(activity.new_values).substring(0, 100) }}</div>
                  </div>
                </div>
                <div v-else class="text-sm text-theme-secondary text-center py-2">No activity recorded</div>
              </div>
            </div>

          </div>
        </div>
      </div>
      </Transition>

      <!-- Image Lightbox Modal -->
      <div
        v-if="showImageModal && enlargedImage"
        class="fixed inset-0 bg-black/80 flex items-center justify-center z-[60]"
        @click.self="closeImageModal"
      >
        <div
          class="relative bg-theme-secondary rounded-lg shadow-2xl overflow-hidden"
          :style="{
            width: imageModalSize.width + 'px',
            height: imageModalSize.height + 'px',
            minWidth: '300px',
            minHeight: '200px',
            maxWidth: '95vw',
            maxHeight: '95vh'
          }"
        >
          <!-- Header with title and close button -->
          <div class="absolute top-0 left-0 right-0 bg-gradient-to-b from-black/70 to-transparent p-3 flex justify-between items-start z-10">
            <span class="text-white text-sm font-medium truncate max-w-[80%]">{{ enlargedImage.title }}</span>
            <button
              @click="closeImageModal"
              class="text-white hover:text-red-400 transition-colors flex-shrink-0 ml-2"
              title="Close (Esc)"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <!-- Image or Document -->
          <img
            v-if="!enlargedImage.type || enlargedImage.type === 'image'"
            :src="enlargedImage.src"
            :alt="enlargedImage.title"
            class="w-full h-full object-contain"
          />
          <iframe
            v-else
            :src="enlargedImage.src"
            :title="enlargedImage.title"
            class="w-full h-full border-0"
          />

          <!-- Resize handle (bottom-right corner) -->
          <div
            class="absolute bottom-0 right-0 w-6 h-6 cursor-se-resize flex items-center justify-center text-white/50 hover:text-white bg-black/30 rounded-tl"
            @mousedown="startResize"
          >
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
              <path d="M22 22H20V20H22V22ZM22 18H20V16H22V18ZM18 22H16V20H18V22ZM22 14H20V12H22V14ZM18 18H16V16H18V18ZM14 22H12V20H14V22ZM14 18H12V16H14V18ZM18 14H16V12H18V14ZM10 22H8V20H10V22Z"/>
            </svg>
          </div>
        </div>
      </div>

      <!-- Create/Edit Event Modal (Phase 2.1) -->
      <div v-if="showEventModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeEventModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">
                {{ editingEvent?.id ? 'Edit Event' : 'Add Life Event' }}
              </h3>
              <button @click="closeEventModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveEvent">
              <div class="space-y-4">
                <!-- Event Type -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Event Type *</label>
                  <select
                    v-model="editingEvent.event_type"
                    required
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option value="">Select event type...</option>
                    <optgroup label="Religious">
                      <option value="CHR">Christening</option>
                      <option value="BAPM">Baptism</option>
                      <option value="CONF">Confirmation</option>
                      <option value="BARM">Bar Mitzvah</option>
                      <option value="BASM">Bas Mitzvah</option>
                      <option value="FCOM">First Communion</option>
                      <option value="ORDN">Ordination</option>
                      <option value="BLES">Blessing</option>
                    </optgroup>
                    <optgroup label="Life Milestones">
                      <option value="GRAD">Graduation</option>
                      <option value="RETI">Retirement</option>
                      <option value="ADOP">Adoption</option>
                    </optgroup>
                    <optgroup label="Migration">
                      <option value="EMIG">Emigration</option>
                      <option value="IMMI">Immigration</option>
                      <option value="NATU">Naturalization</option>
                    </optgroup>
                    <optgroup label="Legal/Records">
                      <option value="CENS">Census</option>
                      <option value="PROB">Probate</option>
                      <option value="WILL">Will</option>
                    </optgroup>
                    <optgroup label="Other">
                      <option value="MIL">Military Service</option>
                      <option value="EDUC">Education</option>
                      <option value="OCCU">Occupation Change</option>
                      <option value="CREM">Cremation</option>
                      <option value="EVEN">Custom Event</option>
                    </optgroup>
                  </select>
                </div>

                <!-- Date -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Date</label>
                  <input
                    v-model="editingEvent.event_date"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., 15 Jun 1920, ABT 1915, BEF 1900"
                  />
                  <p class="text-xs text-theme-secondary mt-1">GEDCOM format: exact date, ABT (about), BEF (before), AFT (after)</p>
                </div>

                <!-- Place with Autocomplete -->
                <div class="relative">
                  <label class="block text-sm text-theme-secondary mb-1">Place</label>
                  <input
                    v-model="editingEvent.event_place"
                    @input="searchPlacesForEvent"
                    @focus="showEventPlaceResults = true"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="City, County, State, Country"
                    autocomplete="off"
                  />
                  <!-- Place autocomplete dropdown -->
                  <div
                    v-if="showEventPlaceResults && eventPlaceSearchResults.length > 0"
                    class="absolute z-20 w-full mt-1 bg-theme-secondary border border-theme rounded-lg shadow-xl max-h-48 overflow-y-auto"
                  >
                    <div
                      v-for="place in eventPlaceSearchResults"
                      :key="place.id"
                      @click="selectPlaceForEvent(place)"
                      class="px-3 py-2 hover:bg-theme-tertiary cursor-pointer border-b border-theme last:border-b-0"
                    >
                      <div class="text-theme-primary text-sm">{{ place.name }}</div>
                      <div v-if="place.hierarchy_path" class="text-xs text-theme-secondary">{{ place.hierarchy_path }}</div>
                      <div v-if="place.usage_count" class="text-xs text-accent">{{ place.usage_count }} events</div>
                    </div>
                  </div>
                  <!-- Place hierarchy display -->
                  <div v-if="editingEvent.place_id && selectedEventPlaceHierarchy" class="mt-1 text-xs text-theme-secondary">
                    <span class="text-accent">Linked to:</span> {{ selectedEventPlaceHierarchy }}
                  </div>
                </div>

                <!-- Description -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Description / Notes</label>
                  <textarea
                    v-model="editingEvent.description"
                    rows="2"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Additional details about this event..."
                  ></textarea>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    @click="closeEventModal"
                    class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    :disabled="savingEvent"
                    class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue transition-colors disabled:opacity-50"
                  >
                    {{ savingEvent ? 'Saving...' : (editingEvent?.id ? 'Update Event' : 'Add Event') }}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Event Confirmation Modal -->
      <div v-if="showDeleteEventModal && deletingEvent" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showDeleteEventModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Delete Event?</h3>
          <p class="text-theme-secondary mb-4">
            Are you sure you want to delete this <strong>{{ getEventTypeLabel(deletingEvent.event_type) }}</strong> event?
            <span v-if="deletingEvent.event_date"> ({{ deletingEvent.event_date }})</span>
          </p>
          <div class="flex justify-end gap-3">
            <button
              @click="showDeleteEventModal = false"
              class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white"
            >
              Cancel
            </button>
            <button
              @click="deleteEvent"
              :disabled="deletingEventLoading"
              class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
            >
              {{ deletingEventLoading ? 'Deleting...' : 'Delete Event' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Create/Edit Family Event Modal (Phase 2.3) -->
      <div v-if="showFamilyEventModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeFamilyEventModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">
                {{ editingFamilyEvent?.id ? 'Edit Family Event' : 'Add Family Event' }}
              </h3>
              <button @click="closeFamilyEventModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveFamilyEvent">
              <div class="space-y-4">
                <!-- Event Type -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Event Type *</label>
                  <select
                    v-model="editingFamilyEvent.event_type"
                    required
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option value="">Select event type...</option>
                    <optgroup label="Pre-Marriage">
                      <option value="ENGA">Engagement</option>
                      <option value="MARB">Marriage Bann</option>
                      <option value="MARC">Marriage Contract</option>
                      <option value="MARL">Marriage License</option>
                    </optgroup>
                    <optgroup label="Post-Marriage">
                      <option value="MARS">Marriage Settlement</option>
                      <option value="ANUL">Annulment</option>
                    </optgroup>
                    <optgroup label="Records">
                      <option value="CENS">Family Census</option>
                      <option value="EVEN">Custom Event</option>
                    </optgroup>
                  </select>
                </div>

                <!-- Date -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Date</label>
                  <input
                    v-model="editingFamilyEvent.event_date"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., 15 Jun 1920, ABT 1915, BEF 1900"
                  />
                  <p class="text-xs text-theme-secondary mt-1">GEDCOM format: exact date, ABT (about), BEF (before), AFT (after)</p>
                </div>

                <!-- Place with Autocomplete -->
                <div class="relative">
                  <label class="block text-sm text-theme-secondary mb-1">Place</label>
                  <input
                    v-model="editingFamilyEvent.event_place"
                    @input="searchPlacesForFamilyEvent"
                    @focus="showFamilyEventPlaceResults = true"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="City, County, State, Country"
                    autocomplete="off"
                  />
                  <!-- Place autocomplete dropdown -->
                  <div
                    v-if="showFamilyEventPlaceResults && familyEventPlaceSearchResults.length > 0"
                    class="absolute z-20 w-full mt-1 bg-theme-secondary border border-theme rounded-lg shadow-xl max-h-48 overflow-y-auto"
                  >
                    <div
                      v-for="place in familyEventPlaceSearchResults"
                      :key="place.id"
                      @click="selectPlaceForFamilyEvent(place)"
                      class="px-3 py-2 hover:bg-theme-tertiary cursor-pointer border-b border-theme last:border-b-0"
                    >
                      <div class="text-theme-primary text-sm">{{ place.name }}</div>
                      <div v-if="place.hierarchy_path" class="text-xs text-theme-secondary">{{ place.hierarchy_path }}</div>
                      <div v-if="place.usage_count" class="text-xs text-accent">{{ place.usage_count }} events</div>
                    </div>
                  </div>
                  <!-- Place hierarchy display -->
                  <div v-if="editingFamilyEvent.place_id && selectedFamilyEventPlaceHierarchy" class="mt-1 text-xs text-theme-secondary">
                    <span class="text-accent">Linked to:</span> {{ selectedFamilyEventPlaceHierarchy }}
                  </div>
                </div>

                <!-- Description -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Description / Notes</label>
                  <textarea
                    v-model="editingFamilyEvent.description"
                    rows="2"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Additional details about this event..."
                  ></textarea>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    @click="closeFamilyEventModal"
                    class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    :disabled="savingFamilyEvent"
                    class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue transition-colors disabled:opacity-50"
                  >
                    {{ savingFamilyEvent ? 'Saving...' : (editingFamilyEvent?.id ? 'Update Event' : 'Add Event') }}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Family Event Confirmation Modal -->
      <div v-if="showDeleteFamilyEventModal && deletingFamilyEvent" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showDeleteFamilyEventModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Delete Family Event?</h3>
          <p class="text-theme-secondary mb-4">
            Are you sure you want to delete this <strong>{{ getFamilyEventTypeLabel(deletingFamilyEvent.event_type) }}</strong> event?
            <span v-if="deletingFamilyEvent.event_date"> ({{ deletingFamilyEvent.event_date }})</span>
          </p>
          <div class="flex justify-end gap-3">
            <button
              @click="showDeleteFamilyEventModal = false"
              class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white"
            >
              Cancel
            </button>
            <button
              @click="deleteFamilyEvent"
              :disabled="deletingFamilyEventLoading"
              class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
            >
              {{ deletingFamilyEventLoading ? 'Deleting...' : 'Delete Event' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Create/Edit Source Modal (Phase 2.4) -->
      <div v-if="showSourceModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeSourceModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">
                {{ editingSource?.id ? 'Edit Source' : 'Add Source' }}
              </h3>
              <button @click="closeSourceModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveSource">
              <div class="space-y-4">
                <!-- Title -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Source Title *</label>
                  <input
                    v-model="editingSource.title"
                    type="text"
                    required
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., 1920 US Federal Census"
                  />
                </div>

                <!-- Author -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Author / Originator</label>
                  <input
                    v-model="editingSource.author"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., Bureau of the Census"
                  />
                </div>

                <!-- Publication Info -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Publication Info</label>
                  <input
                    v-model="editingSource.publication_info"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Publisher, location, date"
                  />
                </div>

                <!-- Repository Reference -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Repository / Archive</label>
                  <input
                    v-model="editingSource.repository_ref"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., National Archives, FamilySearch"
                  />
                </div>

                <!-- Call Number -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Call Number / Film Number</label>
                  <input
                    v-model="editingSource.call_number"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., T625, Roll 123"
                  />
                </div>

                <!-- Source Text / Notes -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Source Text / Notes</label>
                  <textarea
                    v-model="editingSource.source_text"
                    rows="3"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Transcription or notes about this source..."
                  ></textarea>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    @click="closeSourceModal"
                    class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    :disabled="savingSource"
                    class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue transition-colors disabled:opacity-50"
                  >
                    {{ savingSource ? 'Saving...' : (editingSource?.id ? 'Update Source' : 'Add Source') }}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Source Confirmation Modal -->
      <div v-if="showDeleteSourceModal && deletingSource" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showDeleteSourceModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Delete Source?</h3>
          <p class="text-theme-secondary mb-4">
            Are you sure you want to delete the source <strong>"{{ deletingSource.title }}"</strong>?
          </p>
          <p class="text-yellow-500 text-sm mb-4">
            Warning: This will remove all citations linked to this source.
          </p>
          <div class="flex justify-end gap-3">
            <button
              @click="showDeleteSourceModal = false"
              class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white"
            >
              Cancel
            </button>
            <button
              @click="deleteSource"
              :disabled="deletingSourceLoading"
              class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
            >
              {{ deletingSourceLoading ? 'Deleting...' : 'Delete Source' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Create/Edit Citation Modal (Phase 2.5) -->
      <div v-if="showCitationModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeCitationModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">
                {{ editingCitation?.id ? 'Edit Citation' : 'Add Citation' }}
              </h3>
              <button @click="closeCitationModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveCitation">
              <div class="space-y-4">
                <!-- Source Selection -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Source *</label>
                  <select
                    v-model="editingCitation.source_id"
                    required
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option :value="null">Select a source...</option>
                    <option v-for="source in sources" :key="source.id" :value="source.id">
                      {{ source.title }}
                    </option>
                  </select>
                </div>

                <!-- Fact Type -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Fact Being Cited</label>
                  <select
                    v-model="editingCitation.fact_type"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option value="">Select fact type...</option>
                    <optgroup label="Vital Events">
                      <option value="BIRT">Birth</option>
                      <option value="DEAT">Death</option>
                      <option value="BURI">Burial</option>
                      <option value="CREM">Cremation</option>
                    </optgroup>
                    <optgroup label="Religious Events">
                      <option value="CHR">Christening</option>
                      <option value="BAPM">Baptism</option>
                      <option value="CONF">Confirmation</option>
                      <option value="FCOM">First Communion</option>
                      <option value="ORDN">Ordination</option>
                    </optgroup>
                    <optgroup label="Life Events">
                      <option value="GRAD">Graduation</option>
                      <option value="RETI">Retirement</option>
                      <option value="ADOP">Adoption</option>
                      <option value="NATU">Naturalization</option>
                      <option value="EMIG">Emigration</option>
                      <option value="IMMI">Immigration</option>
                    </optgroup>
                    <optgroup label="Records">
                      <option value="CENS">Census</option>
                      <option value="PROB">Probate</option>
                      <option value="WILL">Will</option>
                      <option value="MIL">Military Service</option>
                    </optgroup>
                    <optgroup label="Attributes">
                      <option value="OCCU">Occupation</option>
                      <option value="EDUC">Education</option>
                      <option value="RESI">Residence</option>
                      <option value="RELI">Religion</option>
                      <option value="TITL">Title</option>
                    </optgroup>
                    <optgroup label="Family Events">
                      <option value="MARR">Marriage</option>
                      <option value="DIV">Divorce</option>
                      <option value="ENGA">Engagement</option>
                      <option value="MARB">Marriage Bann</option>
                    </optgroup>
                    <optgroup label="Other">
                      <option value="EVEN">Custom Event</option>
                      <option value="NOTE">Note</option>
                      <option value="PHOT">Photograph</option>
                    </optgroup>
                  </select>
                </div>

                <!-- Page / Where Within Source -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Page / Location in Source</label>
                  <input
                    v-model="editingCitation.page"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., Page 42, Line 15"
                  />
                </div>

                <!-- Quality -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Data Quality</label>
                  <select
                    v-model="editingCitation.quality"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option :value="null">Not specified</option>
                    <option :value="3">3 - Direct & primary evidence</option>
                    <option :value="2">2 - Secondary evidence</option>
                    <option :value="1">1 - Questionable reliability</option>
                    <option :value="0">0 - Unreliable/estimated</option>
                  </select>
                </div>

                <!-- Citation Text / Transcription -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Citation Text / Transcription</label>
                  <textarea
                    v-model="editingCitation.text"
                    rows="3"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Exact transcription or notes from the source..."
                  ></textarea>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    @click="closeCitationModal"
                    class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    :disabled="savingCitation"
                    class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue transition-colors disabled:opacity-50"
                  >
                    {{ savingCitation ? 'Saving...' : (editingCitation?.id ? 'Update Citation' : 'Add Citation') }}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Citation Confirmation Modal -->
      <div v-if="showDeleteCitationModal && deletingCitation" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showDeleteCitationModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Delete Citation?</h3>
          <p class="text-theme-secondary mb-4">
            Are you sure you want to delete this citation for <strong>{{ getCitationFactTypeLabel(deletingCitation.fact_type) }}</strong>?
          </p>
          <div class="flex justify-end gap-3">
            <button
              @click="showDeleteCitationModal = false"
              class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white"
            >
              Cancel
            </button>
            <button
              @click="deleteCitation"
              :disabled="deletingCitationLoading"
              class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
            >
              {{ deletingCitationLoading ? 'Deleting...' : 'Delete Citation' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Create/Edit Repository Modal (Phase 2.6) -->
      <div v-if="showRepositoryModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeRepositoryModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">
                {{ editingRepository?.id ? 'Edit Repository' : 'Add Repository' }}
              </h3>
              <button @click="closeRepositoryModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveRepository">
              <div class="space-y-4">
                <!-- Name -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Repository Name *</label>
                  <input
                    v-model="editingRepository.name"
                    type="text"
                    required
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., National Archives, FamilySearch Library"
                  />
                </div>

                <!-- Address -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Address</label>
                  <textarea
                    v-model="editingRepository.address"
                    rows="2"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Street, City, State, Country"
                  ></textarea>
                </div>

                <!-- Phone -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Phone</label>
                  <input
                    v-model="editingRepository.phone"
                    type="tel"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="+1 (555) 123-4567"
                  />
                </div>

                <!-- Email -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Email</label>
                  <input
                    v-model="editingRepository.email"
                    type="email"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="contact@archive.org"
                  />
                </div>

                <!-- URL -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Website URL</label>
                  <input
                    v-model="editingRepository.url"
                    type="url"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="https://www.archive.org"
                  />
                </div>

                <!-- Notes -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Notes</label>
                  <textarea
                    v-model="editingRepository.notes"
                    rows="2"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Additional notes about this repository..."
                  ></textarea>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    @click="closeRepositoryModal"
                    class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    :disabled="savingRepository"
                    class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue transition-colors disabled:opacity-50"
                  >
                    {{ savingRepository ? 'Saving...' : (editingRepository?.id ? 'Update Repository' : 'Add Repository') }}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Repository Confirmation Modal -->
      <div v-if="showDeleteRepositoryModal && deletingRepository" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showDeleteRepositoryModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Delete Repository?</h3>
          <p class="text-theme-secondary mb-4">
            Are you sure you want to delete the repository <strong>"{{ deletingRepository.name }}"</strong>?
          </p>
          <p v-if="deletingRepository.source_count > 0" class="text-yellow-500 text-sm mb-4">
            Note: {{ deletingRepository.source_count }} source(s) reference this repository.
          </p>
          <div class="flex justify-end gap-3">
            <button
              @click="showDeleteRepositoryModal = false"
              class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white"
            >
              Cancel
            </button>
            <button
              @click="deleteRepository"
              :disabled="deletingRepositoryLoading"
              class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
            >
              {{ deletingRepositoryLoading ? 'Deleting...' : 'Delete Repository' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Media Upload Modal (Phase 3.1) -->
      <div v-if="showMediaUploadModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeMediaUploadModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">Upload Media</h3>
              <button @click="closeMediaUploadModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="uploadMediaFile">
              <div class="space-y-4">
                <!-- Drag-Drop Zone -->
                <div
                  class="border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                  :class="isDraggingMedia ? 'border-accent bg-accent/10' : 'border-theme hover:border-accent'"
                  @dragover.prevent="isDraggingMedia = true"
                  @dragleave.prevent="isDraggingMedia = false"
                  @drop.prevent="handleMediaDrop"
                >
                  <input
                    ref="mediaFileInput"
                    type="file"
                    accept="image/*,application/pdf,.doc,.docx,.txt"
                    class="hidden"
                    @change="handleMediaFileSelect"
                  />
                  <div v-if="!mediaUploadFile">
                    <svg class="w-12 h-12 mx-auto text-theme-secondary mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-theme-secondary mb-2">Drag & drop a file here, or</p>
                    <button type="button" @click="$refs.mediaFileInput.click()" class="btn-secondary">
                      Browse Files
                    </button>
                  </div>
                  <div v-else class="flex items-center justify-center space-x-3">
                    <svg class="w-8 h-8 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-theme-primary">{{ mediaUploadFile.name }}</span>
                    <button type="button" @click="clearMediaUpload" class="text-red-400 hover:text-red-300">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </div>
                </div>

                <!-- Title -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Title</label>
                  <input
                    v-model="mediaUploadData.title"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Photo title or description..."
                  />
                </div>

                <!-- Date -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Date</label>
                  <input
                    v-model="mediaUploadData.date"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="e.g., 25 Dec 1950 or ABT 1920"
                  />
                </div>

                <!-- Description -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Description</label>
                  <textarea
                    v-model="mediaUploadData.description"
                    rows="2"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Additional details about this media..."
                  ></textarea>
                </div>

                <!-- Link to Person (Phase 3.2) -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Link to Person (optional)</label>
                  <select
                    v-model="mediaUploadData.person_id"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option :value="null">-- Select Person --</option>
                    <option v-for="p in sortedPersons" :key="p.id" :value="p.id">
                      {{ p.surname }}, {{ p.given_name }} {{ p.birth_date ? `(${p.birth_date})` : '' }}
                    </option>
                  </select>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    @click="closeMediaUploadModal"
                    class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    :disabled="!mediaUploadFile || uploadingMediaFile"
                    class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue transition-colors disabled:opacity-50"
                  >
                    {{ uploadingMediaFile ? 'Uploading...' : 'Upload Media' }}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Link Media to Person Modal -->
      <div v-if="showLinkMediaToPersonModal && selectedPerson" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showLinkMediaToPersonModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[80vh] overflow-hidden flex flex-col">
          <div class="p-4 border-b border-theme flex justify-between items-center">
            <h3 class="text-lg font-bold text-theme-primary">Add Media to {{ selectedPerson.given_name }} {{ selectedPerson.surname }}</h3>
            <button @click="showLinkMediaToPersonModal = false" class="text-theme-secondary hover:text-theme-primary">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <div class="p-4 border-b border-theme">
            <input
              v-model="linkMediaToPersonSearch"
              type="text"
              placeholder="Search media by title or path..."
              class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded text-theme-primary"
            />
          </div>
          <div class="p-4 overflow-y-auto flex-1">
            <div v-if="availableMediaForPerson.length > 0" class="grid grid-cols-4 gap-3">
              <div
                v-for="item in availableMediaForPerson"
                :key="item.id"
                @click="linkMediaToPersonFromPanel(item.id)"
                class="relative aspect-square bg-theme-tertiary rounded overflow-hidden cursor-pointer hover:ring-2 hover:ring-accent transition-all group"
              >
                <img
                  v-if="item.nextcloud_path && isImage(item.file_format)"
                  :src="`/api/media/file?path=${encodeURIComponent(item.nextcloud_path)}`"
                  class="w-full h-full object-cover"
                />
                <div v-else class="w-full h-full flex items-center justify-center text-theme-secondary">
                  <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div class="absolute bottom-0 left-0 right-0 bg-black/70 text-white text-xs p-1 truncate opacity-0 group-hover:opacity-100 transition-opacity">
                  {{ item.title || item.original_path?.split('/').pop() || 'Untitled' }}
                </div>
              </div>
            </div>
            <p v-else class="text-theme-secondary text-center py-8">
              {{ linkMediaToPersonSearch ? 'No matching media found.' : 'No available media to link. All media may already be linked to this person.' }}
            </p>
          </div>
          <div class="p-4 border-t border-theme text-sm text-theme-secondary">
            Click on a photo to link it to this person. Showing up to 50 results.
          </div>
        </div>
      </div>

      <!-- Media Detail Modal (Phase 3.2) -->
      <div v-if="showMediaDetailModal && selectedMedia" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeMediaDetailModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">{{ selectedMedia.title || 'Media Details' }}</h3>
              <button @click="closeMediaDetailModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div class="grid grid-cols-2 gap-6">
              <!-- Media Preview with Face Regions -->
              <div class="bg-theme-tertiary rounded-lg p-4 flex flex-col items-center justify-center min-h-[300px]">
                <div v-if="getMediaUrl(selectedMedia) && isImage(selectedMedia.file_format)" class="relative inline-block">
                  <img
                    ref="mediaPreviewImg"
                    :src="getMediaUrl(selectedMedia)"
                    :alt="selectedMedia.title"
                    class="max-w-full max-h-[400px] object-contain rounded"
                    @load="onMediaImageLoad"
                  />
                  <!-- Face Region Overlays -->
                  <div
                    v-for="person in personFaceRegions"
                    :key="person.id"
                    class="absolute border-2 border-accent rounded cursor-pointer group"
                    :style="getFaceRegionStyle(person)"
                    :title="`${person.given_name} ${person.surname}`"
                  >
                    <span class="absolute -bottom-5 left-1/2 -translate-x-1/2 bg-accent text-white text-xs px-1 rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity">
                      {{ person.given_name }} {{ person.surname }}
                    </span>
                  </div>
                </div>
                <div v-else class="text-center">
                  <svg class="w-16 h-16 mx-auto text-theme-secondary mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                  </svg>
                  <p class="text-theme-secondary">{{ selectedMedia.file_format?.toUpperCase() || 'Document' }}</p>
                  <a v-if="getMediaUrl(selectedMedia)" :href="getMediaUrl(selectedMedia)" target="_blank" class="btn-secondary mt-2">
                    Download File
                  </a>
                </div>
                <!-- Tag Faces Button -->
                <button
                  v-if="getMediaUrl(selectedMedia) && isImage(selectedMedia.file_format)"
                  @click="openFaceTaggingModal"
                  class="mt-3 px-3 py-1 bg-purple-600 text-white rounded text-sm hover:bg-purple-700"
                >
                  <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                  Tag Faces
                </button>
              </div>

              <!-- Media Info -->
              <div class="space-y-4">
                <div>
                  <label class="text-sm text-theme-secondary">Title</label>
                  <p class="text-theme-primary">{{ selectedMedia.title || 'Untitled' }}</p>
                </div>
                <div v-if="selectedMedia.media_date">
                  <label class="text-sm text-theme-secondary">Date</label>
                  <p class="text-theme-primary">{{ selectedMedia.media_date }}</p>
                </div>
                <div v-if="selectedMedia.description">
                  <label class="text-sm text-theme-secondary">Description</label>
                  <p class="text-theme-primary">{{ selectedMedia.description }}</p>
                </div>
                <div>
                  <label class="text-sm text-theme-secondary">File Info</label>
                  <p class="text-theme-primary">
                    {{ selectedMedia.file_format?.toUpperCase() }}
                    <span v-if="selectedMedia.file_size"> · {{ formatFileSize(selectedMedia.file_size) }}</span>
                    <span v-if="selectedMedia.width && selectedMedia.height"> · {{ selectedMedia.width }}×{{ selectedMedia.height }}</span>
                  </p>
                </div>
                <!-- Media Type/Category (Phase 3.6) -->
                <div>
                  <label class="text-sm text-theme-secondary">Category</label>
                  <select
                    :value="selectedMedia.media_type || 'photo'"
                    @change="updateMediaType(selectedMedia.id, $event.target.value)"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option v-for="cat in mediaCategories.filter(c => c.id !== 'all')" :key="cat.id" :value="cat.id">
                      {{ cat.icon }} {{ cat.label }}
                    </option>
                  </select>
                </div>
                <div v-if="selectedMedia.original_path">
                  <label class="text-sm text-theme-secondary">Original Path</label>
                  <p class="text-theme-primary text-xs break-all">{{ selectedMedia.original_path }}</p>
                </div>

                <!-- Document Transcription (Phase 3.7) -->
                <div v-if="['document', 'certificate', 'census', 'military', 'obituary', 'headstone'].includes(selectedMedia.media_type)">
                  <div class="flex justify-between items-center mb-1">
                    <label class="text-sm text-theme-secondary">Transcription</label>
                    <div class="flex gap-2">
                      <button
                        v-if="!editingTranscription"
                        @click="startEditingTranscription"
                        class="text-accent text-xs hover:underline"
                      >
                        {{ selectedMedia.transcription ? 'Edit' : 'Add' }}
                      </button>
                      <span
                        v-if="selectedMedia.transcription_source"
                        class="text-xs text-theme-secondary"
                        :title="`Transcribed via ${selectedMedia.transcription_source}`"
                      >
                        ({{ selectedMedia.transcription_source }})
                      </span>
                    </div>
                  </div>
                  <div v-if="editingTranscription">
                    <textarea
                      v-model="transcriptionText"
                      rows="6"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="Enter transcription of the document..."
                    ></textarea>
                    <div class="flex justify-end gap-2 mt-2">
                      <button
                        @click="cancelEditingTranscription"
                        class="px-3 py-1 text-sm text-theme-secondary hover:text-theme-primary"
                      >
                        Cancel
                      </button>
                      <button
                        @click="saveTranscription"
                        :disabled="savingTranscription"
                        class="px-3 py-1 bg-accent text-white rounded text-sm hover:bg-accent/80 disabled:opacity-50"
                      >
                        {{ savingTranscription ? 'Saving...' : 'Save' }}
                      </button>
                    </div>
                  </div>
                  <div v-else-if="selectedMedia.transcription" class="bg-theme-tertiary p-3 rounded-lg max-h-40 overflow-y-auto">
                    <pre class="text-theme-primary text-sm whitespace-pre-wrap font-sans">{{ selectedMedia.transcription }}</pre>
                  </div>
                  <p v-else class="text-theme-secondary text-sm italic">No transcription available</p>
                </div>

                <div v-if="['document', 'certificate', 'census', 'military', 'obituary', 'headstone'].includes(selectedMedia.media_type)">
                  <div class="flex justify-between items-center mb-1">
                    <label class="text-sm text-theme-secondary">Intake Preview</label>
                    <button
                      @click="loadMediaIntakePreview(selectedMedia.id)"
                      :disabled="loadingMediaIntakePreview"
                      class="text-accent text-xs hover:underline disabled:opacity-50"
                    >
                      {{ loadingMediaIntakePreview ? 'Loading...' : 'Refresh' }}
                    </button>
                  </div>
                  <div v-if="mediaIntakePreview" class="bg-theme-tertiary p-3 rounded-lg space-y-3">
                    <div>
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Status</div>
                      <div class="text-theme-primary text-sm">
                        {{ mediaIntakePreview.packet?.status || 'unknown' }}
                        <span class="text-theme-secondary">
                          · Proposal ready: {{ mediaIntakePreview.packet?.proposal_ready ? 'yes' : 'no' }}
                        </span>
                      </div>
                    </div>
                    <div v-if="mediaIntakePreview.packet?.packet_summary">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Summary</div>
                      <p class="text-theme-primary text-sm whitespace-pre-wrap">{{ mediaIntakePreview.packet.packet_summary }}</p>
                    </div>
                    <div v-if="mediaIntakePreview.packet?.person_candidates?.length">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Candidates</div>
                      <div class="space-y-1">
                        <div v-for="candidate in mediaIntakePreview.packet.person_candidates" :key="`${candidate.name}-${candidate.matched_person_id || 'new'}`" class="text-sm text-theme-primary">
                          {{ candidate.name }} · {{ candidate.match_type }} · {{ candidate.confidence }}
                          <span v-if="candidate.matched_person_name" class="text-theme-secondary">· {{ candidate.matched_person_name }}</span>
                        </div>
                      </div>
                    </div>
                    <div v-if="mediaIntakePreview.packet?.questions?.length">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Questions</div>
                      <div class="space-y-1">
                        <p v-for="question in mediaIntakePreview.packet.questions" :key="question" class="text-theme-primary text-sm whitespace-pre-wrap">
                          {{ question }}
                        </p>
                      </div>
                    </div>
                    <div v-if="mediaIntakePreview.packet?.page_anchors?.length">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Evidence Anchors</div>
                      <div class="space-y-1">
                        <p v-for="anchor in mediaIntakePreview.packet.page_anchors" :key="anchor" class="text-theme-secondary text-xs whitespace-pre-wrap">
                          {{ anchor }}
                        </p>
                      </div>
                    </div>
                  </div>
                  <p v-else-if="mediaIntakePreviewError" class="text-red-400 text-sm">{{ mediaIntakePreviewError }}</p>
                  <p v-else class="text-theme-secondary text-sm italic">
                    Preview the transcript-backed intake summary, candidate matches, and review questions for this document.
                  </p>
                </div>

                <!-- Linked Persons -->
                <div>
                  <label class="text-sm text-theme-secondary mb-2 block">Linked Persons</label>
                  <div v-if="selectedMedia.persons && selectedMedia.persons.length > 0" class="space-y-1">
                    <div v-for="person in selectedMedia.persons" :key="person.id" class="flex items-center justify-between bg-theme-tertiary p-2 rounded">
                      <span class="text-theme-primary">{{ person.given_name }} {{ person.surname }}</span>
                      <button @click="unlinkMediaFromPerson(selectedMedia.id, person.id)" class="text-red-400 hover:text-red-300 text-sm">
                        Unlink
                      </button>
                    </div>
                  </div>
                  <p v-else class="text-theme-secondary text-sm">No persons linked</p>

                  <!-- Add Person Link -->
                  <div class="mt-2 flex gap-2">
                    <select v-model="linkPersonToMediaId" class="flex-1 px-2 py-1 bg-theme-tertiary border border-theme rounded text-theme-primary text-sm">
                      <option :value="null">-- Link Person --</option>
                      <option v-for="p in sortedPersons" :key="p.id" :value="p.id">
                        {{ p.surname }}, {{ p.given_name }}
                      </option>
                    </select>
                    <button
                      @click="linkMediaToPerson(selectedMedia.id, linkPersonToMediaId)"
                      :disabled="!linkPersonToMediaId"
                      class="px-3 py-1 bg-accent text-white rounded text-sm disabled:opacity-50"
                    >
                      Link
                    </button>
                  </div>
                </div>

                <!-- Linked Families (Phase 3.3) -->
                <div>
                  <label class="text-sm text-theme-secondary mb-2 block">Linked Families</label>
                  <div v-if="selectedMedia.families && selectedMedia.families.length > 0" class="space-y-1">
                    <div v-for="family in selectedMedia.families" :key="family.id" class="flex items-center justify-between bg-theme-tertiary p-2 rounded">
                      <span class="text-theme-primary">
                        {{ family.husband_given_name || '?' }} {{ family.husband_surname || '' }} &
                        {{ family.wife_given_name || '?' }} {{ family.wife_surname || '' }}
                        <span v-if="family.marriage_date" class="text-theme-secondary text-xs ml-1">({{ family.marriage_date }})</span>
                      </span>
                      <button @click="unlinkMediaFromFamily(selectedMedia.id, family.id)" class="text-red-400 hover:text-red-300 text-sm">
                        Unlink
                      </button>
                    </div>
                  </div>
                  <p v-else class="text-theme-secondary text-sm">No families linked</p>

                  <!-- Add Family Link -->
                  <div class="mt-2 flex gap-2">
                    <select v-model="linkFamilyToMediaId" class="flex-1 px-2 py-1 bg-theme-tertiary border border-theme rounded text-theme-primary text-sm">
                      <option :value="null">-- Link Family --</option>
                      <option v-for="f in allFamilies" :key="f.id" :value="f.id">
                        {{ f.husband_given || f.husband_surname ? `${f.husband_given || ''} ${f.husband_surname || ''}`.trim() : '?' }} & {{ f.wife_given || f.wife_surname ? `${f.wife_given || ''} ${f.wife_surname || ''}`.trim() : '?' }}
                        <template v-if="f.marriage_date"> ({{ f.marriage_date }})</template>
                      </option>
                    </select>
                    <button
                      @click="linkMediaToFamily(selectedMedia.id, linkFamilyToMediaId)"
                      :disabled="!linkFamilyToMediaId"
                      class="px-3 py-1 bg-accent text-white rounded text-sm disabled:opacity-50"
                    >
                      Link
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between mt-6 pt-4 border-t border-theme">
              <button @click="confirmDeleteMedia(selectedMedia)" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                Delete Media
              </button>
              <button @click="closeMediaDetailModal" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Delete Media Confirmation Modal -->
      <div v-if="showDeleteMediaModal && deletingMedia" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showDeleteMediaModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Delete Media?</h3>
          <p class="text-theme-secondary mb-4">
            Are you sure you want to delete <strong>"{{ deletingMedia.title || 'this media' }}"</strong>?
            This will also remove the file from Nextcloud.
          </p>
          <div class="flex justify-end gap-3">
            <button
              @click="showDeleteMediaModal = false"
              class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white"
            >
              Cancel
            </button>
            <button
              @click="deleteMedia"
              :disabled="deletingMediaLoading"
              class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
            >
              {{ deletingMediaLoading ? 'Deleting...' : 'Delete Media' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Face Tagging Modal (Phase 3.4) -->
      <div v-if="showFaceTaggingModal && selectedMedia" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeFaceTaggingModal">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-5xl w-full mx-4 max-h-[95vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <div>
                <h3 class="text-xl font-bold text-theme-primary">Tag Faces</h3>
                <p class="text-theme-secondary text-sm">Click and drag to draw a rectangle around a face</p>
              </div>
              <button @click="closeFaceTaggingModal" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div class="grid grid-cols-3 gap-4">
              <!-- Image with face regions -->
              <div class="col-span-2 bg-theme-tertiary rounded-lg p-4">
                <div
                  ref="faceTaggingContainer"
                  class="relative inline-block cursor-crosshair select-none"
                  @mousedown="startDrawingFaceRegion"
                  @mousemove="drawFaceRegion"
                  @mouseup="endDrawingFaceRegion"
                  @mouseleave="cancelDrawingFaceRegion"
                >
                  <img
                    ref="faceTaggingImg"
                    :src="getMediaUrl(selectedMedia)"
                    :alt="selectedMedia.title"
                    class="max-w-full rounded"
                    @load="onFaceTaggingImageLoad"
                    draggable="false"
                  />
                  <!-- Existing face regions -->
                  <div
                    v-for="person in personFaceRegions"
                    :key="person.id"
                    class="absolute border-2 rounded group"
                    :class="person.face_confirmed ? 'border-green-400' : 'border-yellow-400'"
                    :style="getFaceTaggingRegionStyle(person)"
                  >
                    <div class="absolute -top-6 left-0 right-0 flex items-center justify-between">
                      <span class="bg-black/75 text-white text-xs px-1 rounded truncate max-w-[100px]">
                        {{ person.given_name }} {{ person.surname }}
                      </span>
                      <button
                        @click.stop="removeFaceRegion(person.id)"
                        class="bg-red-500 hover:bg-red-600 text-white text-xs px-1 rounded ml-1"
                        title="Remove face tag"
                      >
                        ×
                      </button>
                    </div>
                  </div>
                  <!-- Drawing region preview -->
                  <div
                    v-if="isDrawingFaceRegion && drawingRegion"
                    class="absolute border-2 border-dashed border-accent bg-accent/20 pointer-events-none"
                    :style="{
                      left: `${Math.min(drawingRegion.startX, drawingRegion.currentX)}px`,
                      top: `${Math.min(drawingRegion.startY, drawingRegion.currentY)}px`,
                      width: `${Math.abs(drawingRegion.currentX - drawingRegion.startX)}px`,
                      height: `${Math.abs(drawingRegion.currentY - drawingRegion.startY)}px`
                    }"
                  ></div>
                </div>
              </div>

              <!-- Right panel: Person selector and tagged list -->
              <div class="space-y-4">
                <!-- Select person for new tag -->
                <div v-if="newFaceRegion" class="bg-theme-tertiary p-4 rounded-lg">
                  <h4 class="font-medium text-theme-primary mb-2">New Face Region</h4>
                  <p class="text-theme-secondary text-sm mb-2">Select a person to tag:</p>
                  <select
                    v-model="newFaceRegionPersonId"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary text-sm mb-2"
                  >
                    <option :value="null">-- Select Person --</option>
                    <option v-for="p in sortedPersons" :key="p.id" :value="p.id">
                      {{ p.surname }}, {{ p.given_name }}
                    </option>
                  </select>
                  <div class="flex gap-2">
                    <button
                      @click="saveNewFaceRegion"
                      :disabled="!newFaceRegionPersonId || savingFaceRegion"
                      class="flex-1 px-3 py-2 bg-accent text-white rounded text-sm disabled:opacity-50"
                    >
                      {{ savingFaceRegion ? 'Saving...' : 'Save Tag' }}
                    </button>
                    <button
                      @click="cancelNewFaceRegion"
                      class="px-3 py-2 bg-theme-secondary border border-theme text-theme-primary rounded text-sm"
                    >
                      Cancel
                    </button>
                  </div>
                </div>

                <!-- Tagged persons list -->
                <div class="bg-theme-tertiary p-4 rounded-lg">
                  <h4 class="font-medium text-theme-primary mb-2">Tagged in this photo</h4>
                  <div v-if="personFaceRegions.length > 0" class="space-y-2">
                    <div
                      v-for="person in personFaceRegions"
                      :key="person.id"
                      class="flex items-center justify-between p-2 bg-theme-secondary rounded"
                    >
                      <div class="flex items-center gap-2">
                        <span
                          class="w-3 h-3 rounded-full"
                          :class="person.face_confirmed ? 'bg-green-400' : 'bg-yellow-400'"
                          :title="person.face_confirmed ? 'Confirmed' : 'Unconfirmed'"
                        ></span>
                        <span class="text-theme-primary text-sm">{{ person.given_name }} {{ person.surname }}</span>
                      </div>
                      <div class="flex items-center gap-2">
                        <button
                          v-if="!person.face_confirmed"
                          @click="confirmFaceTag(person.id, true)"
                          :disabled="confirmingFaceTag"
                          class="text-green-400 hover:text-green-300 text-sm disabled:opacity-50"
                          title="Confirm this is the correct person"
                        >
                          Confirm
                        </button>
                        <button
                          v-else
                          @click="confirmFaceTag(person.id, false)"
                          :disabled="confirmingFaceTag"
                          class="text-yellow-400 hover:text-yellow-300 text-sm disabled:opacity-50"
                          title="Mark as unconfirmed"
                        >
                          Unconfirm
                        </button>
                        <button
                          @click="removeFaceRegion(person.id)"
                          class="text-red-400 hover:text-red-300 text-sm"
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                  </div>
                  <p v-else class="text-theme-secondary text-sm">No faces tagged yet</p>
                </div>

                <!-- Instructions -->
                <div class="bg-theme-tertiary p-4 rounded-lg">
                  <h4 class="font-medium text-theme-primary mb-2">Instructions</h4>
                  <ul class="text-theme-secondary text-sm space-y-1">
                    <li>• Click and drag to draw a rectangle</li>
                    <li>• Select a person to tag</li>
                    <li>• Green = Confirmed, Yellow = Unconfirmed</li>
                    <li>• Click × to remove a tag</li>
                  </ul>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="closeFaceTaggingModal" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Done
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Windows Import Modal removed 2026-01-10 - SSH access deprecated, use Nextcloud -->

      <!-- Phase 3.7: Transcription Queue Modal -->
      <div v-if="showTranscriptionQueueModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showTranscriptionQueueModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary flex items-center gap-2">
                <svg class="w-6 h-6 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Transcription Queue
              </h3>
              <button @click="showTranscriptionQueueModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <p class="text-theme-secondary text-sm mb-4">
              Documents awaiting transcription. Click on an item to view and add transcription.
            </p>

            <!-- Queue Statistics -->
            <div class="bg-theme-tertiary rounded-lg p-4 mb-4">
              <div class="flex items-center justify-between">
                <span class="text-theme-secondary">Documents needing transcription:</span>
                <span class="text-xl font-bold text-accent">{{ transcriptionQueue.length }}</span>
              </div>
            </div>

            <!-- Queue List -->
            <div v-if="transcriptionQueue.length > 0" class="space-y-2 max-h-96 overflow-y-auto">
              <div
                v-for="item in transcriptionQueue"
                :key="item.id"
                class="bg-theme-tertiary rounded-lg p-3 cursor-pointer hover:bg-theme-primary/10 transition-colors"
                @click="selectMedia(item); showTranscriptionQueueModal = false"
              >
                <div class="flex items-center gap-3">
                  <!-- Thumbnail -->
                  <div class="w-12 h-12 bg-theme-secondary rounded flex-shrink-0 flex items-center justify-center overflow-hidden">
                    <img
                      v-if="isImage(item.format)"
                      :src="`/api/genealogy/media/${item.id}/thumbnail`"
                      :alt="item.title || item.filename"
                      class="w-full h-full object-cover"
                      @error="$event.target.style.display='none'"
                    />
                    <svg v-else class="w-6 h-6 text-theme-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                  </div>

                  <!-- Info -->
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-theme-primary truncate">
                      {{ item.title || item.filename }}
                    </div>
                    <div class="text-sm text-theme-secondary flex items-center gap-2">
                      <span class="uppercase text-xs bg-theme-secondary px-1.5 py-0.5 rounded">{{ item.format }}</span>
                      <span v-if="item.file_size">{{ formatFileSize(item.file_size) }}</span>
                    </div>
                  </div>

                  <!-- Action Arrow -->
                  <svg class="w-5 h-5 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                  </svg>
                </div>
              </div>
            </div>

            <div v-else class="text-center py-8 text-theme-secondary">
              <svg class="w-16 h-16 mx-auto mb-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <p>All documents have been transcribed!</p>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t border-theme">
              <button
                @click="loadTranscriptionQueue"
                class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent/80"
              >
                Refresh Queue
              </button>
              <button @click="showTranscriptionQueueModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="showIntakeRunsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showIntakeRunsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-5xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary flex items-center gap-2">
                <svg class="w-6 h-6 text-fuchsia-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V9m-7-4h6m0 0v6m0-6L10 14"/>
                </svg>
                Saved Intake Runs
              </h3>
              <button @click="showIntakeRunsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div class="rounded-lg border border-fuchsia-500/20 bg-fuchsia-500/5 p-4 mb-4 space-y-3">
              <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                  <div class="text-sm font-medium text-theme-primary">Step 1: Stage Folder Into A Saved Run</div>
                  <div class="text-xs text-theme-secondary mt-1">
                    Scan a backend Nextcloud path recursively for PDFs, images, and document files, then save a resumable run that you can review from this modal. This stage is read-only for genealogy data and does not execute FT copies.
                  </div>
                </div>
                <span class="px-2 py-0.5 rounded text-xs bg-fuchsia-500/10 text-fuchsia-400">staging only</span>
              </div>

              <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
                <label class="block lg:col-span-2">
                  <span class="text-xs text-theme-secondary uppercase tracking-wide">Root Path</span>
                  <input
                    v-model="intakeRunStageForm.root_path"
                    type="text"
                    class="mt-1 w-full rounded-lg border border-theme bg-theme-secondary text-theme-primary px-3 py-2"
                    placeholder="/Library/FamilyTree"
                  />
                </label>

                <label class="block">
                  <span class="text-xs text-theme-secondary uppercase tracking-wide">Packet Label Override</span>
                  <input
                    v-model="intakeRunStageForm.packet_label"
                    type="text"
                    class="mt-1 w-full rounded-lg border border-theme bg-theme-secondary text-theme-primary px-3 py-2"
                    placeholder="Optional"
                  />
                </label>

                <label class="block">
                  <span class="text-xs text-theme-secondary uppercase tracking-wide">File Limit</span>
                  <input
                    v-model="intakeRunStageForm.limit"
                    type="number"
                    min="1"
                    max="500"
                    class="mt-1 w-full rounded-lg border border-theme bg-theme-secondary text-theme-primary px-3 py-2"
                  />
                </label>
              </div>

              <div class="flex items-center justify-between gap-3 flex-wrap">
                <label class="inline-flex items-center gap-2 text-sm text-theme-primary">
                  <input v-model="intakeRunStageForm.unprocessed_only" type="checkbox" class="rounded border-theme bg-theme-secondary text-fuchsia-500 focus:ring-fuchsia-500">
                  <span>Only stage files already marked as not ingested</span>
                </label>

                <button
                  type="button"
                  class="px-4 py-2 rounded-lg bg-fuchsia-500/10 text-fuchsia-400 hover:bg-fuchsia-500/20 disabled:opacity-60"
                  :disabled="stagingIntakeRun || !selectedTreeId || !`${intakeRunStageForm.root_path || ''}`.trim()"
                  @click="stageIntakeRun"
                >
                  {{ stagingIntakeRun ? 'Scanning Folder...' : 'Scan Folder And Save Run' }}
                </button>
              </div>

              <div class="text-xs text-theme-secondary">
                Uses the selected tree and saves the run so it can be reopened here for packet review and decision capture.
              </div>

              <div v-if="intakeRunStageError" class="rounded-lg border border-rose-500/20 bg-rose-500/5 px-3 py-2 text-sm text-rose-400 whitespace-pre-wrap">
                {{ intakeRunStageError }}
              </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div class="space-y-2 max-h-[420px] overflow-y-auto">
                <div
                  v-for="run in intakeRuns"
                  :key="run.run_key"
                  class="bg-theme-tertiary rounded-lg p-3 cursor-pointer hover:bg-theme-primary/10 transition-colors border"
                  :class="selectedIntakeRun?.run_key === run.run_key ? 'border-fuchsia-400' : 'border-transparent'"
                  @click="selectIntakeRun(run.run_key)"
                >
                  <div class="font-medium text-theme-primary">{{ run.packet_label || 'Unnamed intake run' }}</div>
                  <div class="text-xs text-theme-secondary mt-1 break-all">{{ run.root_path }}</div>
                  <div v-if="run.summary" class="mt-2">
                    <div class="text-xs text-theme-primary">
                      {{ run.summary.next_action || 'No next action available.' }}
                    </div>
                    <div class="flex flex-wrap gap-2 mt-2 text-xs">
                      <span
                        class="px-2 py-0.5 rounded"
                        :class="{
                          'bg-emerald-500/10 text-emerald-400': run.summary.run_health === 'ready',
                          'bg-amber-500/10 text-amber-400': run.summary.run_health === 'partial',
                          'bg-rose-500/10 text-rose-400': run.summary.run_health === 'blocked',
                          'bg-theme-secondary text-theme-secondary': run.summary.run_health === 'untouched',
                        }"
                      >
                        {{ run.summary.run_health || 'unknown' }}
                      </span>
                      <span
                        v-if="run.summary.packet_totals?.packets_with_copy_execution"
                        class="px-2 py-0.5 rounded bg-fuchsia-500/10 text-fuchsia-400"
                      >
                        executed {{ run.summary.packet_totals.packets_with_copy_execution }}
                      </span>
                    </div>
                    <div
                      v-if="run.summary.blocked_packets?.length"
                      class="text-xs text-theme-secondary mt-2 whitespace-pre-wrap"
                    >
                      Blocked: {{ run.summary.blocked_packets.join(', ') }}
                    </div>
                  </div>
                  <div
                    v-if="run.copy_progress"
                    class="text-xs mt-2 flex flex-wrap gap-2"
                  >
                    <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400">
                      copied {{ run.copy_progress.copied || 0 }}
                    </span>
                    <span class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400">
                      present {{ run.copy_progress.already_in_place || 0 }}
                    </span>
                    <span
                      v-if="run.copy_progress.blocked_conflicts"
                      class="px-2 py-0.5 rounded bg-amber-500/10 text-amber-400"
                    >
                      conflicts {{ run.copy_progress.blocked_conflicts }}
                    </span>
                  </div>
                  <div class="text-xs text-theme-secondary mt-1">
                    {{ run.status }} · {{ formatDate(run.updated_at) }}
                  </div>
                </div>

                <div v-if="!intakeRuns.length" class="text-center py-8 text-theme-secondary text-sm">
                  No saved intake runs for this tree.
                </div>
              </div>

              <div class="bg-theme-tertiary rounded-lg p-4 min-h-[240px]">
                <div v-if="loadingIntakeRunPreview" class="text-theme-secondary text-sm whitespace-pre-wrap">Loading intake run preview...
Large packets can take time on the first pass while the packet preview is prepared.</div>
                <div v-else-if="selectedIntakeRun" class="space-y-3">
                  <div>
                    <div class="text-xs text-theme-secondary uppercase tracking-wide">Run</div>
                    <div class="text-theme-primary text-sm break-all">{{ selectedIntakeRun.run_key }}</div>
                  </div>
                  <div v-if="selectedIntakeRun.root_path">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide">Scope</div>
                    <div class="text-theme-primary text-sm break-all">{{ selectedIntakeRun.root_path }}</div>
                  </div>
                  <div class="rounded border border-theme p-3">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Current Workflow Phase</div>
                      <span
                        class="px-2 py-0.5 rounded text-xs"
                        :class="{
                          'bg-emerald-500/10 text-emerald-400': selectedIntakeRunPhaseSummary.tone === 'ready',
                          'bg-amber-500/10 text-amber-400': selectedIntakeRunPhaseSummary.tone === 'pending',
                          'bg-rose-500/10 text-rose-400': selectedIntakeRunPhaseSummary.tone === 'blocked',
                          'bg-sky-500/10 text-sky-400': selectedIntakeRunPhaseSummary.tone === 'info',
                        }"
                      >
                        {{ selectedIntakeRunPhaseSummary.current }}
                      </span>
                    </div>
                    <div class="text-theme-primary text-sm mt-2">
                      {{ selectedIntakeRunPhaseSummary.summary }}
                    </div>
                    <div class="text-theme-secondary text-sm mt-1 whitespace-pre-wrap">
                      {{ selectedIntakeRunPhaseSummary.detail }}
                    </div>
                    <div class="mt-2 text-xs text-theme-secondary whitespace-pre-wrap">
                      Next phase: {{ selectedIntakeRunPhaseSummary.next }}
                    </div>
                  </div>
                  <div v-if="selectedIntakeRun.summary" class="rounded border border-theme p-3">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide">Run Summary</div>
                    <div class="text-theme-primary text-sm mt-1">
                      {{ selectedIntakeRun.summary.next_action || 'No next action available.' }}
                    </div>
                    <div class="flex flex-wrap gap-2 mt-2 text-xs">
                      <span
                        class="px-2 py-0.5 rounded"
                        :class="{
                          'bg-emerald-500/10 text-emerald-400': selectedIntakeRun.summary.run_health === 'ready',
                          'bg-amber-500/10 text-amber-400': selectedIntakeRun.summary.run_health === 'partial',
                          'bg-rose-500/10 text-rose-400': selectedIntakeRun.summary.run_health === 'blocked',
                          'bg-theme-secondary text-theme-secondary': selectedIntakeRun.summary.run_health === 'untouched',
                        }"
                      >
                        {{ selectedIntakeRun.summary.run_health || 'unknown' }}
                      </span>
                      <span
                        v-if="selectedIntakeRun.summary.packet_totals?.total_packets !== null && selectedIntakeRun.summary.packet_totals?.total_packets !== undefined"
                        class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400"
                      >
                        packets {{ selectedIntakeRun.summary.packet_totals.total_packets }}
                      </span>
                      <span
                        v-if="selectedIntakeRun.summary.review_signals?.packets_with_questions"
                        class="px-2 py-0.5 rounded bg-fuchsia-500/10 text-fuchsia-400"
                      >
                        questions {{ selectedIntakeRun.summary.review_signals.packets_with_questions }}
                      </span>
                      <span
                        v-if="selectedIntakeRun.summary.review_signals?.packets_proposal_ready"
                        class="px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-400"
                      >
                        proposals {{ selectedIntakeRun.summary.review_signals.packets_proposal_ready }}
                      </span>
                    </div>
                    <div
                      v-if="selectedIntakeRun.summary.blocked_packets?.length"
                      class="mt-2 text-xs text-theme-secondary whitespace-pre-wrap"
                    >
                      Blocked packets: {{ selectedIntakeRun.summary.blocked_packets.join(', ') }}
                    </div>
                  </div>
                  <div v-if="selectedIntakeRunRecommendationPrimary" class="rounded border border-theme p-3">
                    <div class="flex items-center justify-between gap-2">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Recommended Next</div>
                      <span
                        class="px-2 py-0.5 rounded text-xs"
                        :class="{
                          'bg-rose-500/10 text-rose-400': selectedIntakeRunRecommendationPrimary.priority === 'high',
                          'bg-amber-500/10 text-amber-400': selectedIntakeRunRecommendationPrimary.priority === 'medium',
                          'bg-sky-500/10 text-sky-400': selectedIntakeRunRecommendationPrimary.priority === 'low',
                        }"
                      >
                        {{ selectedIntakeRunRecommendationPrimary.priority || 'unknown' }}
                      </span>
                    </div>
                    <div class="text-theme-primary text-sm mt-2">
                      {{ selectedIntakeRunRecommendationPrimary.packet_label || 'No packet recommended.' }}
                    </div>
                    <div class="text-theme-secondary text-sm mt-1 whitespace-pre-wrap">
                      {{ selectedIntakeRunRecommendationPrimary.reason || '' }}
                    </div>
                    <div class="flex flex-wrap gap-2 mt-2 text-xs">
                      <span class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary">
                        {{ selectedIntakeRunRecommendationPrimary.category || 'unknown' }}
                      </span>
                      <span
                        v-if="selectedIntakeRunRecommendationPrimary.packet_key"
                        class="px-2 py-0.5 rounded bg-theme-primary/10 text-theme-secondary"
                      >
                        {{ selectedIntakeRunRecommendationPrimary.packet_key }}
                      </span>
                    </div>
                    <button
                      type="button"
                      class="mt-3 px-3 py-1.5 rounded bg-fuchsia-500/10 text-fuchsia-400 hover:bg-fuchsia-500/20"
                      @click="jumpToIntakeRunPacket(selectedIntakeRunRecommendationPrimary.packet_label)"
                    >
                      Open recommended packet
                    </button>
                    <div class="flex flex-wrap gap-2 mt-3">
                      <button
                        v-if="selectedIntakeRunRecommendationShortcuts.blocked"
                        type="button"
                        class="px-3 py-1.5 rounded bg-rose-500/10 text-rose-400 hover:bg-rose-500/20"
                        @click="jumpToIntakeRunPacket(selectedIntakeRunRecommendationShortcuts.blocked.packet_label)"
                      >
                        Blocked
                      </button>
                      <button
                        v-if="selectedIntakeRunRecommendationShortcuts.questions"
                        type="button"
                        class="px-3 py-1.5 rounded bg-fuchsia-500/10 text-fuchsia-400 hover:bg-fuchsia-500/20"
                        @click="jumpToIntakeRunPacket(selectedIntakeRunRecommendationShortcuts.questions.packet_label)"
                      >
                        Questions
                      </button>
                      <button
                        v-if="selectedIntakeRunRecommendationShortcuts.proposal_ready"
                        type="button"
                        class="px-3 py-1.5 rounded bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20"
                        @click="jumpToIntakeRunPacket(selectedIntakeRunRecommendationShortcuts.proposal_ready.packet_label)"
                      >
                        Proposal Ready
                      </button>
                      <button
                        v-if="selectedIntakeRunRecommendationShortcuts.unreviewed"
                        type="button"
                        class="px-3 py-1.5 rounded bg-sky-500/10 text-sky-400 hover:bg-sky-500/20"
                        @click="jumpToIntakeRunPacket(selectedIntakeRunRecommendationShortcuts.unreviewed.packet_label)"
                      >
                        Unreviewed
                      </button>
                    </div>
                  </div>
                  <div v-if="selectedIntakeRunWorkspaceOverview" class="rounded border border-theme p-3">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide">Workspace Overview</div>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Ready</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunWorkspaceOverview.ready_packets || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Blocked</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunWorkspaceOverview.blocked_packets || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Pending</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunWorkspaceOverview.pending_packets || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Questions</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunWorkspaceOverview.question_packets || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">High Priority</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunWorkspaceOverview.high_priority_count || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Proposal Ready</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunWorkspaceOverview.ready_for_proposals_count || 0 }}</div>
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunPriorityPreview.high.length" class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">High Priority Packets</div>
                      <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                        {{ selectedIntakeRunPriorityPreview.high.join(', ') }}
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunPriorityPreview.medium.length" class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Medium Priority Packets</div>
                      <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                        {{ selectedIntakeRunPriorityPreview.medium.join(', ') }}
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunPriorityPreview.ready.length" class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Ready For Proposals</div>
                      <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                        {{ selectedIntakeRunPriorityPreview.ready.join(', ') }}
                      </div>
                    </div>
                  </div>
                  <div v-if="selectedIntakeRunDraftPlan" class="rounded border border-theme p-3">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide">Proposal Draft Plan</div>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Ready Packets</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftPlan.summary?.ready_packets || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Pending Packets</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftPlan.summary?.pending_packets || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Blocked Packets</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftPlan.summary?.blocked_packets || 0 }}</div>
                      </div>
                      <div class="rounded border border-theme p-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Can Generate Any</div>
                        <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftPlan.summary?.can_generate_any ? 'yes' : 'no' }}</div>
                      </div>
                    </div>
                    <div class="flex flex-wrap gap-2 mt-3 text-xs">
                      <span
                        class="px-2 py-0.5 rounded"
                        :class="selectedIntakeRunDraftPlan.summary?.can_generate_all ? 'bg-emerald-500/10 text-emerald-400' : 'bg-theme-secondary text-theme-primary'"
                      >
                        all packets {{ selectedIntakeRunDraftPlan.summary?.can_generate_all ? 'ready' : 'not ready' }}
                      </span>
                    </div>
                    <div v-if="selectedIntakeRunDraftPlan.ready_packets?.length" class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Ready Packet Labels</div>
                      <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                        {{ selectedIntakeRunDraftPlan.ready_packets.map((packet) => packet.packet_label).join(', ') }}
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunDraftPlan.pending_packets?.length" class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Pending Draft Work</div>
                      <div class="space-y-1 mt-1">
                        <div
                          v-for="packet in selectedIntakeRunDraftPlan.pending_packets.slice(0, 5)"
                          :key="`draft-pending-${packet.packet_key || packet.packet_label}`"
                          class="text-theme-primary text-sm whitespace-pre-wrap"
                        >
                          {{ packet.packet_label }}<span v-if="packet.reasons?.length"> · {{ packet.reasons.join(', ') }}</span>
                        </div>
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunDraftPlan.blocked_packets?.length" class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Blocked Draft Work</div>
                      <div class="space-y-1 mt-1">
                        <div
                          v-for="packet in selectedIntakeRunDraftPlan.blocked_packets.slice(0, 5)"
                          :key="`draft-blocked-${packet.packet_key || packet.packet_label}`"
                          class="text-theme-primary text-sm whitespace-pre-wrap"
                        >
                          {{ packet.packet_label }}<span v-if="packet.reasons?.length"> · {{ packet.reasons.join(', ') }}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div v-if="selectedIntakeRun.copy_progress" class="grid grid-cols-2 gap-2">
                    <div class="rounded border border-theme p-2">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Copied</div>
                      <div class="text-theme-primary text-sm">{{ selectedIntakeRun.copy_progress.copied || 0 }}</div>
                    </div>
                    <div class="rounded border border-theme p-2">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Already In Place</div>
                      <div class="text-theme-primary text-sm">{{ selectedIntakeRun.copy_progress.already_in_place || 0 }}</div>
                    </div>
                    <div class="rounded border border-theme p-2">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Conflict Packets</div>
                      <div class="text-theme-primary text-sm">{{ selectedIntakeRun.copy_progress.blocked_conflicts || 0 }}</div>
                    </div>
                    <div class="rounded border border-theme p-2">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Packets With Execution</div>
                      <div class="text-theme-primary text-sm">{{ selectedIntakeRun.copy_progress.packets_with_execution || 0 }}</div>
                    </div>
                  </div>
                  <div v-if="intakeRunPacketOptions.length">
                    <label class="block text-xs text-theme-secondary uppercase tracking-wide mb-1">Packet</label>
                    <select
                      v-model="selectedIntakeRunPacketLabel"
                      class="w-full px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                      @change="reloadSelectedIntakeRunPacket"
                    >
                      <option
                        v-for="packet in intakeRunPacketOptions"
                        :key="packet.value"
                        :value="packet.value"
                      >
                        {{ packet.label }}
                      </option>
                    </select>
                    <div class="grid grid-cols-2 gap-2 mt-3">
                      <input
                        v-model="intakeRunPacketSearch"
                        type="text"
                        class="col-span-2 px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                        placeholder="Search packet label, reason, or action"
                      >
                      <select
                        v-model="intakeRunPacketStageFilter"
                        class="px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                      >
                        <option value="all">All stages</option>
                        <option value="blocked">Blocked</option>
                        <option value="pending">Pending</option>
                        <option value="ready">Ready</option>
                        <option value="unknown">Unknown</option>
                      </select>
                      <select
                        v-model="intakeRunPacketQuestionFilter"
                        class="px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                      >
                        <option value="all">All questions</option>
                        <option value="yes">Has questions</option>
                        <option value="no">No questions</option>
                      </select>
                      <select
                        v-model="intakeRunPacketProposalFilter"
                        class="px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                      >
                        <option value="all">All proposal states</option>
                        <option value="ready">Proposal ready</option>
                        <option value="not_ready">Proposal not ready</option>
                      </select>
                      <select
                        v-model="intakeRunPacketSort"
                        class="px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                      >
                        <option value="priority">Sort by priority</option>
                        <option value="stage">Sort by stage</option>
                        <option value="questions">Sort by questions</option>
                        <option value="proposal">Sort by proposal ready</option>
                        <option value="label">Sort by label</option>
                      </select>
                    </div>
                    <div class="text-theme-secondary text-xs mt-2">
                      Showing {{ filteredIntakeRunPacketList.length }} of {{ intakeRunPacketList.length }} packets
                    </div>
                    <div class="flex flex-wrap gap-2 mt-3">
                      <button
                        type="button"
                        class="px-3 py-1.5 rounded bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 disabled:opacity-50"
                        :disabled="!nextBlockedIntakePacket"
                        @click="jumpToIntakeRunPacket(nextBlockedIntakePacket?.value)"
                      >
                        Next blocked
                      </button>
                      <button
                        type="button"
                        class="px-3 py-1.5 rounded bg-fuchsia-500/10 text-fuchsia-400 hover:bg-fuchsia-500/20 disabled:opacity-50"
                        :disabled="!nextQuestionIntakePacket"
                        @click="jumpToIntakeRunPacket(nextQuestionIntakePacket?.value)"
                      >
                        Next questions
                      </button>
                      <button
                        type="button"
                        class="px-3 py-1.5 rounded bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 disabled:opacity-50"
                        :disabled="!nextProposalReadyIntakePacket"
                        @click="jumpToIntakeRunPacket(nextProposalReadyIntakePacket?.value)"
                      >
                        Next proposal ready
                      </button>
                      <button
                        type="button"
                        class="px-3 py-1.5 rounded bg-sky-500/10 text-sky-400 hover:bg-sky-500/20 disabled:opacity-50"
                        :disabled="!nextUnreviewedIntakePacket"
                        @click="jumpToIntakeRunPacket(nextUnreviewedIntakePacket?.value)"
                      >
                        Next unreviewed
                      </button>
                    </div>
                    <div class="mt-3 space-y-2 max-h-48 overflow-y-auto pr-1">
                      <button
                        v-for="packet in filteredIntakeRunPacketList"
                        :key="`packet-row-${packet.value}`"
                        type="button"
                        class="w-full text-left rounded border p-2 transition-colors"
                        :class="packet.selected ? 'border-fuchsia-400 bg-fuchsia-500/10' : 'border-theme bg-theme-secondary hover:bg-theme-primary/5'"
                        @click="selectedIntakeRunPacketLabel = packet.value; reloadSelectedIntakeRunPacket()"
                      >
                        <div class="flex items-start justify-between gap-2">
                          <div class="min-w-0">
                            <div class="text-theme-primary text-sm break-words">{{ packet.label }}</div>
                            <div
                              v-if="packet.reason || packet.actionLabel"
                              class="text-theme-secondary text-xs mt-1 whitespace-pre-wrap"
                            >
                              {{ packet.reason || packet.actionLabel }}
                            </div>
                          </div>
                          <div class="flex flex-wrap gap-1 justify-end">
                            <span
                              v-if="packet.status"
                              class="px-2 py-0.5 rounded text-xs"
                              :class="{
                                'bg-emerald-500/10 text-emerald-400': packet.status === 'ready',
                                'bg-amber-500/10 text-amber-400': packet.status === 'pending',
                                'bg-rose-500/10 text-rose-400': packet.status === 'blocked',
                              }"
                            >
                              {{ packet.status }}
                            </span>
                            <span
                              v-if="packet.questionCount"
                              class="px-2 py-0.5 rounded text-xs bg-fuchsia-500/10 text-fuchsia-400"
                            >
                              q {{ packet.questionCount }}
                            </span>
                            <span
                              class="px-2 py-0.5 rounded text-xs"
                              :class="packet.proposalReady ? 'bg-emerald-500/10 text-emerald-400' : 'bg-theme-primary/10 text-theme-secondary'"
                            >
                              {{ packet.proposalReady ? 'proposal ready' : 'proposal pending' }}
                            </span>
                            <span
                              v-if="packet.applyStatus"
                              class="px-2 py-0.5 rounded text-xs"
                              :class="{
                                'bg-emerald-500/10 text-emerald-400': packet.applyStatus === 'success',
                                'bg-amber-500/10 text-amber-400': packet.applyStatus === 'partial',
                                'bg-rose-500/10 text-rose-400': packet.applyStatus === 'failed',
                                'bg-theme-primary/10 text-theme-secondary': packet.applyStatus === 'empty',
                              }"
                            >
                              apply {{ packet.applyStatus }}
                            </span>
                          </div>
                        </div>
                        <div
                          v-if="packet.applyUpdatedAt"
                          class="text-theme-secondary text-xs mt-1"
                        >
                          Applied {{ formatDateTime(packet.applyUpdatedAt) }}
                        </div>
                      </button>
                    </div>
                  </div>
                  <div v-if="selectedIntakeRunPreview">
                    <div class="text-xs text-theme-secondary uppercase tracking-wide">Packet</div>
                    <div class="text-theme-primary text-sm">{{ selectedIntakeRunPreview.packet_label }}</div>
                    <div class="text-theme-secondary text-xs mt-1">
                      {{ selectedIntakeRunPreview.document_count }} document(s)
                    </div>
                    <div class="text-theme-secondary text-xs mt-1">
                      {{ selectedIntakeRunPreview.page_count || selectedIntakeRunPreview.media_summary?.page_count || 0 }} page(s)
                      <span v-if="selectedIntakeRunPreview.media_summary?.is_mixed_media">
                        · mixed packet
                      </span>
                      <span
                        v-else-if="selectedIntakeRunPreview.media_summary?.document_types?.length"
                      >
                        · {{ selectedIntakeRunPreview.media_summary.document_types.join(', ') }}
                      </span>
                    </div>
                    <div class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap">
                      Step 2: inspect the packet contents below. Step 3: read the evidence summary and questions. Step 4: record the human decision.
                    </div>
                    <div class="flex flex-wrap gap-2 mt-2 text-xs">
                      <span
                        v-if="selectedIntakeRunPacketStage?.status"
                        class="px-2 py-0.5 rounded"
                        :class="{
                          'bg-emerald-500/10 text-emerald-400': selectedIntakeRunPacketStage.status === 'ready',
                          'bg-amber-500/10 text-amber-400': selectedIntakeRunPacketStage.status === 'pending',
                          'bg-rose-500/10 text-rose-400': selectedIntakeRunPacketStage.status === 'blocked',
                        }"
                      >
                        stage {{ selectedIntakeRunPacketStage.status }}
                      </span>
                      <span
                        v-if="selectedIntakeRunPacketReviewDecision?.decision"
                        class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400"
                      >
                        decision {{ selectedIntakeRunPacketReviewDecision.decision }}
                      </span>
                      <span
                        v-if="selectedIntakeRunPacketPreviewState"
                        class="px-2 py-0.5 rounded bg-fuchsia-500/10 text-fuchsia-400"
                      >
                        questions {{ selectedIntakeRunPacketPreviewState.questions?.length || 0 }}
                      </span>
                      <span
                        v-if="selectedIntakeRunPacketPreviewState"
                        class="px-2 py-0.5 rounded"
                        :class="selectedIntakeRunPacketPreviewState.proposal_ready ? 'bg-emerald-500/10 text-emerald-400' : 'bg-theme-secondary text-theme-primary'"
                      >
                        proposal {{ selectedIntakeRunPacketPreviewState.proposal_ready ? 'ready' : 'not ready' }}
                      </span>
                    </div>
                    <div
                      v-if="selectedIntakeRunPacketStage?.reason"
                      class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap"
                    >
                      Next packet step: {{ selectedIntakeRunPacketStage.reason }}
                    </div>
                    <div class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Summary</div>
                      <p class="text-theme-primary text-sm whitespace-pre-wrap">
                        {{ selectedIntakeRunPreview.preview?.packet_summary || 'No summary available.' }}
                      </p>
                    </div>
                    <div v-if="selectedIntakeRunPreview.preview?.questions?.length" class="mt-3 rounded border border-amber-500/20 bg-amber-500/5 p-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Open Questions</div>
                      <div class="space-y-1 mt-2">
                        <p v-for="question in selectedIntakeRunPreview.preview.questions" :key="question" class="text-theme-primary text-sm whitespace-pre-wrap">
                          {{ question }}
                        </p>
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunLikelyMatches.length" class="mt-3 rounded border border-sky-500/20 bg-sky-500/5 p-3">
                      <div class="flex items-center justify-between gap-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Likely Tree Matches</div>
                        <span class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400 text-xs">
                          top {{ selectedIntakeRunLikelyMatches.length }}
                        </span>
                      </div>
                      <div class="space-y-1 mt-2">
                        <div
                          v-for="candidate in selectedIntakeRunLikelyMatches"
                          :key="`${candidate.name}-${candidate.matched_person_id || 'new'}`"
                          class="text-sm text-theme-primary"
                        >
                          {{ candidate.name }} · {{ candidate.match_type }} · {{ candidate.confidence }}
                          <span v-if="candidate.matched_person_name" class="text-theme-secondary">
                            · {{ candidate.matched_person_name }}
                          </span>
                        </div>
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunDocumentPreviewItems.length" class="mt-3 rounded border border-theme p-3">
                      <div class="flex items-center justify-between gap-2 flex-wrap">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Staged Packet Items</div>
                        <div class="flex flex-wrap gap-2 text-xs">
                          <span class="px-2 py-0.5 rounded bg-theme-primary/10 text-theme-primary">
                            {{ selectedIntakeRunDocumentPreviewItems.length }} staged item{{ selectedIntakeRunDocumentPreviewItems.length === 1 ? '' : 's' }}
                          </span>
                          <span
                            v-if="selectedIntakeRunDocumentTypeSummary"
                            class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400"
                          >
                            {{ selectedIntakeRunDocumentTypeSummary }}
                          </span>
                        </div>
                      </div>
                      <div class="text-theme-secondary text-sm mt-2">
                        These are the bulk-load items currently inside this packet. Review the source names, source paths, and planned FT targets before approving the packet.
                      </div>
                      <div v-if="selectedIntakeRunDocumentIssueSummary.length" class="flex flex-wrap gap-2 mt-2 text-xs">
                        <span
                          v-for="issue in selectedIntakeRunDocumentIssueSummary"
                          :key="issue"
                          class="px-2 py-0.5 rounded bg-amber-500/10 text-amber-400"
                        >
                          {{ issue }}
                        </span>
                      </div>
                      <div class="space-y-2 mt-3 max-h-80 overflow-y-auto pr-1">
                        <details
                          v-for="document in selectedIntakeRunDocumentPreviewItems"
                          :key="document.documentId"
                          class="rounded border p-2"
                          :class="{
                            'border-rose-500/40 bg-rose-500/5': document.copyStatus === 'conflict',
                            'border-amber-500/40 bg-amber-500/5': document.copyStatus === 'missing_source_path' || !document.previewable,
                            'border-fuchsia-500/30 bg-fuchsia-500/5': document.alreadyIngested,
                            'border-theme': document.copyStatus !== 'conflict' && document.copyStatus !== 'missing_source_path' && document.previewable && !document.alreadyIngested,
                          }"
                        >
                          <summary class="cursor-pointer list-none">
                            <div class="flex items-start justify-between gap-2">
                              <div class="min-w-0">
                                <div class="text-theme-primary text-sm break-words">{{ document.sourceName }}</div>
                                <div class="flex flex-wrap gap-1.5 mt-1 text-xs">
                                  <span class="px-2 py-0.5 rounded bg-theme-primary/10 text-theme-primary">
                                    {{ document.documentType }}
                                  </span>
                                  <span class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary">
                                    {{ document.pageCount }} page{{ document.pageCount === 1 ? '' : 's' }}
                                  </span>
                                  <span
                                    class="px-2 py-0.5 rounded"
                                    :class="{
                                      'bg-emerald-500/10 text-emerald-400': document.copyStatus === 'ready' || document.copyStatus === 'already_in_place',
                                      'bg-amber-500/10 text-amber-400': document.copyStatus === 'missing_source_path',
                                      'bg-rose-500/10 text-rose-400': document.copyStatus === 'conflict',
                                    }"
                                  >
                                    {{ document.copyStatusLabel }}
                                  </span>
                                  <span
                                    class="px-2 py-0.5 rounded"
                                    :class="document.previewable ? 'bg-sky-500/10 text-sky-400' : 'bg-theme-primary/10 text-theme-secondary'"
                                  >
                                    {{ document.previewable ? 'preview available' : 'preview unavailable' }}
                                  </span>
                                  <span
                                    v-if="document.alreadyIngested"
                                    class="px-2 py-0.5 rounded bg-fuchsia-500/10 text-fuchsia-400"
                                  >
                                    already ingested
                                  </span>
                                </div>
                                <div class="text-theme-secondary text-xs mt-1 whitespace-pre-wrap">
                                  {{ document.sourcePath }}
                                </div>
                              </div>
                              <div class="flex flex-wrap gap-1 justify-end text-xs shrink-0">
                                <button
                                  v-if="document.previewable"
                                  @click.stop="openIntakeRunDocumentPreview(document)"
                                  class="px-3 py-1.5 rounded bg-sky-500/10 text-sky-400 hover:bg-sky-500/20 text-xs"
                                >
                                  Preview File
                                </button>
                              </div>
                            </div>
                          </summary>
                          <div class="grid grid-cols-1 lg:grid-cols-2 gap-2 mt-3">
                            <div class="rounded border border-theme p-2">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide">Source Path</div>
                              <div class="text-theme-primary text-sm break-all whitespace-pre-wrap mt-1">{{ document.sourcePath }}</div>
                            </div>
                            <div class="rounded border border-theme p-2">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide">Planned FT Target</div>
                              <div class="text-theme-primary text-sm break-all whitespace-pre-wrap mt-1">{{ document.referenceCopyPath }}</div>
                            </div>
                          </div>
                          <div class="flex flex-wrap gap-2 mt-2 text-xs">
                            <span class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary">
                              pages {{ document.pageCount }}
                            </span>
                            <span class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary">
                              {{ document.classificationLabel }}
                            </span>
                            <span class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary">
                              {{ document.duplicateScopeLabel }}
                            </span>
                            <span
                              v-if="document.alreadyIngested"
                              class="px-2 py-0.5 rounded bg-fuchsia-500/10 text-fuchsia-400"
                            >
                              already ingested
                            </span>
                          </div>
                          <div v-if="document.copyReason" class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap">
                            {{ document.copyReason }}
                          </div>
                          <div v-if="document.anchorLabels.length" class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap">
                            Page anchors: {{ document.anchorLabels.join(', ') }}
                          </div>
                        </details>
                      </div>
                    </div>
                    <details v-if="selectedIntakeRunProposalPreview" class="mt-3 rounded border border-theme p-3">
                      <summary class="cursor-pointer list-none flex items-center justify-between gap-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Proposal Evidence Preview</div>
                        <span
                          class="px-2 py-0.5 rounded text-xs"
                          :class="selectedIntakeRunProposalPreview.proposal_outline?.can_generate ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400'"
                        >
                          {{ selectedIntakeRunProposalPreview.proposal_outline?.can_generate ? 'ready for review queue' : 'blocked or incomplete' }}
                        </span>
                      </summary>
                      <div class="text-theme-secondary text-sm mt-3 whitespace-pre-wrap">
                        {{ selectedIntakeRunProposalReviewHeadline }}
                      </div>
                      <div class="grid grid-cols-2 gap-2 mt-2">
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Decision</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalDecisionLabel }}</div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Questions</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalQuestionSummary }}</div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Anchors</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalAnchorSummary }}</div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Reviewed By</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalReviewedByLabel }}</div>
                        </div>
                      </div>
                      <div v-if="selectedIntakeRunProposalReviewNotes" class="mt-3 rounded border border-sky-500/20 bg-sky-500/5 p-3">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Review Notes</div>
                        <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                          {{ selectedIntakeRunProposalReviewNotes }}
                        </div>
                      </div>
                      <div v-if="selectedIntakeRunProposalPreview.evidence?.summary_text" class="mt-3">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Evidence Summary</div>
                        <div class="space-y-2 mt-2">
                          <div
                            v-for="(paragraph, index) in selectedIntakeRunProposalEvidenceParagraphs"
                            :key="`proposal-evidence-paragraph-${index}`"
                            class="text-theme-primary text-sm whitespace-pre-wrap"
                          >
                            {{ paragraph }}
                          </div>
                        </div>
                      </div>
                      <div v-if="selectedIntakeRunProposalEvidenceAnchors.length" class="mt-3">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Evidence Anchors</div>
                        <div class="flex flex-wrap gap-2 mt-2">
                          <span
                            v-for="anchor in selectedIntakeRunProposalEvidenceAnchors"
                            :key="`proposal-anchor-${anchor}`"
                            class="px-2 py-0.5 rounded bg-theme-primary/10 text-theme-primary text-xs"
                          >
                            {{ anchor }}
                          </span>
                        </div>
                      </div>
                      <div class="mt-3">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Suggested Sections</div>
                        <div class="flex flex-wrap gap-2 mt-2">
                          <span
                            v-for="section in selectedIntakeRunProposalSuggestedSections"
                            :key="`proposal-section-${section}`"
                            class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400 text-xs"
                          >
                            {{ formatGenealogyProposalSectionLabel(section) }}
                          </span>
                        </div>
                      </div>
                      <div
                        v-if="selectedIntakeRunProposalReadinessChecklist.length"
                        class="mt-3 rounded border border-theme p-3"
                      >
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Review Checklist</div>
                        <div class="space-y-2 mt-2">
                          <div
                            v-for="item in selectedIntakeRunProposalReadinessChecklist"
                            :key="`proposal-check-${item.label}`"
                            class="flex items-start gap-2"
                          >
                            <span
                              class="mt-0.5 h-2 w-2 rounded-full shrink-0"
                              :class="item.ok ? 'bg-emerald-400' : 'bg-amber-400'"
                            />
                            <div>
                              <div class="text-theme-primary text-sm">{{ item.label }}</div>
                              <div class="text-theme-secondary text-xs whitespace-pre-wrap">{{ item.detail }}</div>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div
                        v-if="selectedIntakeRunProposalBlockingReasons.length"
                        class="mt-3"
                      >
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Blocking Reasons</div>
                        <div class="space-y-2 mt-2">
                          <div
                            v-for="(reason, index) in selectedIntakeRunProposalBlockingReasons"
                            :key="`proposal-blocking-reason-${index}`"
                            class="rounded border border-amber-500/20 bg-amber-500/10 p-2 text-sm text-amber-200 whitespace-pre-wrap"
                          >
                            {{ reason }}
                          </div>
                        </div>
                      </div>
                      <div class="mt-3 rounded border border-theme p-3">
                        <div class="flex items-center justify-between gap-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Planned Apply Or Queue Result</div>
                          <span class="px-2 py-0.5 rounded text-xs bg-sky-500/10 text-sky-400">read-only</span>
                        </div>
                        <div class="text-theme-secondary text-sm mt-1">
                          Preview what this packet would produce before committing. Configure the existing target person and approved sections below, then either queue review proposals or apply only the supported direct changes.
                        </div>
                        <div class="mt-3">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Sections Approved For This Packet</div>
                          <div class="flex flex-wrap gap-2 mt-2">
                            <label
                              v-for="section in selectedIntakeRunProposalPreview.proposal_outline?.suggested_sections || []"
                              :key="`approval-section-${section}`"
                              class="inline-flex items-center gap-2 px-2 py-1 rounded border border-theme text-theme-primary text-sm"
                            >
                              <input
                                v-model="intakeProposalApprovedSections"
                                type="checkbox"
                                :value="section"
                                class="rounded border-theme"
                              >
                              <span>{{ section }}</span>
                            </label>
                          </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 mt-3">
                          <div>
                            <label class="block text-xs text-theme-secondary uppercase tracking-wide mb-1">Existing Person To Update</label>
                            <input
                              v-model="intakeProposalTargetPersonId"
                              type="text"
                              class="w-full px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                              placeholder="Existing person_id"
                            >
                          </div>
                          <div>
                            <label class="block text-xs text-theme-secondary uppercase tracking-wide mb-1">Relationship Type</label>
                            <select
                              v-model="intakeProposalRelationshipType"
                              class="w-full px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                            >
                              <option value="">None</option>
                              <option value="parent">Parent</option>
                              <option value="child">Child</option>
                              <option value="spouse">Spouse</option>
                              <option value="sibling">Sibling</option>
                            </select>
                          </div>
                          <div class="col-span-2">
                            <label class="block text-xs text-theme-secondary uppercase tracking-wide mb-1">Existing Related Person</label>
                            <input
                              v-model="intakeProposalRelatedPersonId"
                              type="text"
                              class="w-full px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                              placeholder="Existing related_person_id when relationship review is explicit"
                            >
                          </div>
                        </div>
                        <div v-if="loadingIntakeApprovalDraftPreview" class="mt-3 text-sm text-theme-secondary">
                          Building approval draft preview...
                        </div>
                        <div
                          v-else-if="intakeApprovalDraftPreviewError"
                          class="mt-3 rounded border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-300"
                        >
                          {{ intakeApprovalDraftPreviewError }}
                        </div>
                        <div
                          v-else-if="selectedIntakeRunApprovalDraftFormatted"
                          class="mt-3 rounded border border-theme p-3"
                        >
                          <div class="flex items-center justify-between gap-2">
                            <div class="text-xs text-theme-secondary uppercase tracking-wide">Planned Result</div>
                            <span
                              class="px-2 py-0.5 rounded text-xs"
                              :class="selectedIntakeRunApprovalDraftFormatted.status === 'blocked'
                                ? 'bg-rose-500/10 text-rose-300'
                                : selectedIntakeRunApprovalDraftFormatted.status === 'empty'
                                  ? 'bg-theme-secondary text-theme-primary'
                                  : 'bg-emerald-500/10 text-emerald-400'"
                            >
                              {{ selectedIntakeRunApprovalDraftFormatted.status }}
                            </span>
                          </div>
                          <div class="text-theme-primary text-sm mt-2 whitespace-pre-wrap">
                            {{ selectedIntakeRunApprovalDraftFormatted.summary }}
                          </div>
                          <div class="grid grid-cols-2 gap-2 mt-3">
                            <div class="rounded border border-theme p-2">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide">Person Changes</div>
                              <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalDraftFormatted.counts?.existing_person_changes || 0 }}</div>
                            </div>
                            <div class="rounded border border-theme p-2">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide">Relationship Review Items</div>
                              <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalDraftFormatted.counts?.relationship_proposals || 0 }}</div>
                            </div>
                            <div class="rounded border border-theme p-2">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide">Skipped</div>
                              <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalDraftFormatted.counts?.skipped || 0 }}</div>
                            </div>
                            <div class="rounded border border-theme p-2">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide">Blocked</div>
                              <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalDraftFormatted.counts?.blocked || 0 }}</div>
                            </div>
                          </div>
                          <div
                            v-if="selectedIntakeRunApprovalDraftFormatted.highlights?.length"
                            class="mt-3"
                          >
                            <div class="text-xs text-theme-secondary uppercase tracking-wide">Highlights</div>
                            <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                              {{ selectedIntakeRunApprovalDraftFormatted.highlights.join('\n') }}
                            </div>
                          </div>
                          <div class="mt-3">
                            <div class="text-xs text-theme-secondary uppercase tracking-wide">Next Action</div>
                            <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                              {{ selectedIntakeRunApprovalDraftFormatted.next_action }}
                            </div>
                          </div>
                          <div class="mt-4 space-y-3">
                            <!-- Action A: Generate Review Proposals -->
                            <div class="rounded border border-theme p-3">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide mb-2">Step 1 — Queue for Human Review</div>
                              <button
                                class="btn-primary"
                                :disabled="generatingIntakeRunProposals || selectedIntakeRunApprovalDraftFormatted.status !== 'ready'"
                                @click="generateIntakeRunProposals"
                              >
                                {{ generatingIntakeRunProposals ? 'Queueing...' : 'Queue Review Proposals In Research Hub' }}
                              </button>
                              <div class="text-theme-secondary text-xs mt-2">
                                Creates pending review rows in the Research Hub. No tree data is written — each proposal must be approved or rejected individually by the operator.
                              </div>
                            </div>
                            <!-- Action B: Apply Supported Changes -->
                            <div class="rounded border border-theme p-3">
                              <div class="text-xs text-theme-secondary uppercase tracking-wide mb-2">Step 2 — Apply Direct Changes</div>
                              <button
                                class="btn-primary"
                                :disabled="applyingIntakeApprovalDraft || selectedIntakeRunApprovalDraftFormatted.status !== 'ready' || (selectedIntakeRunPacketApplyState?.status === 'success' && selectedIntakeRunPacketApplyStateIsCurrent)"
                                @click="applyApprovalDraft"
                              >
                                {{ applyingIntakeApprovalDraft ? 'Applying...' : (selectedIntakeRunPacketApplyState?.status === 'success' && selectedIntakeRunPacketApplyStateIsCurrent ? 'Already Applied' : 'Apply Safe Existing-Person Changes') }}
                              </button>
                              <div class="text-theme-secondary text-xs mt-2">
                                Applies only supported existing-person field edits from the current plan. Relationship proposals still require Research Hub review before any final operator decision.
                                <span v-if="selectedIntakeRunPacketApplyState?.status === 'success' && selectedIntakeRunPacketApplyStateIsCurrent" class="block mt-1 text-emerald-400">
                                  This packet has already been applied against the current plan.
                                </span>
                                <span v-else-if="selectedIntakeRunPacketApplyState?.status === 'success' && !selectedIntakeRunPacketApplyStateIsCurrent" class="block mt-1 text-amber-400">
                                  A prior apply exists, but the plan has changed. Review the updated plan before applying again.
                                </span>
                              </div>
                            </div>
                          </div>
                          <div
                            v-if="selectedIntakeRunProposalGenerationResult"
                            class="mt-3 rounded border border-theme p-3"
                          >
                            <div class="flex items-center justify-between gap-2">
                              <div>
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Review Proposals Created</div>
                                <div class="text-theme-secondary text-xs mt-0.5">Rows queued in the Research Hub for operator review. No tree data has been written yet.</div>
                              </div>
                              <span
                                class="px-2 py-0.5 rounded text-xs shrink-0"
                                :class="selectedIntakeRunProposalGenerationResult.success ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-300'"
                              >
                                {{ selectedIntakeRunProposalGenerationResult.success ? 'queued' : 'attention needed' }}
                              </span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3">
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Person Proposal Rows</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalGenerationResult.persisted_person_changes?.length || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Relationship Proposal Rows</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalGenerationResult.persisted_relationships?.length || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Skipped</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalGenerationResult.skipped?.length || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Failed</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunProposalGenerationResult.failed?.length || 0 }}</div>
                              </div>
                            </div>
                            <div v-if="selectedIntakeRunProposalGenerationResult.success" class="mt-3">
                              <a
                                :href="buildResearchHubLink()"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded bg-sky-500/10 text-sky-400 hover:bg-sky-500/20 text-sm font-medium"
                              >
                                Open in Research Hub &rarr;
                              </a>
                              <div class="text-theme-secondary text-xs mt-1">Review and approve or reject each pending proposal in the Research Hub.</div>
                            </div>
                            <div
                              v-if="selectedIntakeRunProposalGenerationResult.errors?.length"
                              class="mt-3 text-sm text-rose-300 whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunProposalGenerationResult.errors.join('\n') }}
                            </div>
                            <div
                              v-if="selectedIntakeRunProposalGenerationResult.summary?.summary"
                              class="mt-3 text-theme-primary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunProposalGenerationResult.summary.summary }}
                            </div>
                            <div
                              v-if="selectedIntakeRunProposalGenerationResult.summary?.highlights?.length"
                              class="mt-2 text-theme-secondary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunProposalGenerationResult.summary.highlights.join('\n') }}
                            </div>
                            <div
                              v-if="selectedIntakeRunProposalGenerationResult.summary?.next_action"
                              class="mt-2 text-theme-secondary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunProposalGenerationResult.summary.next_action }}
                            </div>
                          </div>
                          <div
                            v-if="selectedIntakeRunPacketProposalGenerationState"
                            class="mt-3 rounded border border-theme p-3"
                          >
                            <div class="flex items-center justify-between gap-2">
                              <div>
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Saved Proposal Review Record</div>
                                <div class="text-theme-secondary text-xs mt-0.5">Persisted proposal-generation state and the queued review rows for this packet.</div>
                              </div>
                              <div class="flex items-center gap-2">
                                <button
                                  class="px-3 py-1.5 rounded bg-theme-secondary border border-theme text-theme-primary text-sm hover:bg-theme-primary/5 disabled:opacity-50"
                                  :disabled="loadingIntakeGeneratedProposals"
                                  @click="loadGeneratedProposals"
                                >
                                  {{ loadingIntakeGeneratedProposals ? 'Loading...' : 'Reload' }}
                                </button>
                                <a
                                  :href="buildResearchHubLink()"
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  class="px-3 py-1.5 rounded bg-sky-500/10 text-sky-400 hover:bg-sky-500/20 text-sm"
                                >
                                  Open in Hub
                                </a>
                              </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3">
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Status</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunPacketProposalGenerationState.status || 'unknown' }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Updated</div>
                                <div class="text-theme-primary text-sm">{{ formatDateTime(selectedIntakeRunPacketProposalGenerationState.updated_at) }}</div>
                              </div>
                            </div>
                            <div
                              v-if="selectedIntakeRunPacketProposalGenerationState.summary"
                              class="mt-3 text-theme-primary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunPacketProposalGenerationState.summary }}
                            </div>
                            <div
                              v-if="intakeGeneratedProposalsError"
                              class="mt-3 rounded border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-300"
                            >
                              {{ intakeGeneratedProposalsError }}
                            </div>
                            <div
                              v-else-if="selectedIntakeRunGeneratedProposalReview"
                              class="mt-3 space-y-3"
                            >
                              <div class="grid grid-cols-3 gap-2">
                                <div class="rounded border border-theme p-2">
                                  <div class="text-xs text-theme-secondary uppercase tracking-wide">Person Changes</div>
                                  <div class="text-theme-primary text-sm">{{ selectedIntakeRunGeneratedProposalReview.counts?.person_changes || 0 }}</div>
                                </div>
                                <div class="rounded border border-theme p-2">
                                  <div class="text-xs text-theme-secondary uppercase tracking-wide">Relationships</div>
                                  <div class="text-theme-primary text-sm">{{ selectedIntakeRunGeneratedProposalReview.counts?.relationships || 0 }}</div>
                                </div>
                                <div class="rounded border border-theme p-2">
                                  <div class="text-xs text-theme-secondary uppercase tracking-wide">Total</div>
                                  <div class="text-theme-primary text-sm">{{ selectedIntakeRunGeneratedProposalReview.counts?.total || 0 }}</div>
                                </div>
                              </div>
                              <div
                                v-if="generatedProposalPendingUnifiedIds.length"
                                class="flex items-center justify-between gap-2"
                              >
                                <div class="text-xs text-theme-secondary">{{ generatedProposalPendingUnifiedIds.length }} pending person-change rows</div>
                                <button
                                  class="px-3 py-1.5 rounded bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 text-sm disabled:opacity-50"
                                  :disabled="bulkApprovingPendingProposals"
                                  @click="approveAllPendingGeneratedProposals"
                                >
                                  {{ bulkApprovingPendingProposals ? 'Approving...' : 'Approve All Pending Person Changes' }}
                                </button>
                              </div>
                              <div
                                v-if="selectedIntakeRunGeneratedProposalReview.relationships?.some((relationship) => relationship.status === 'pending')"
                                class="rounded border border-amber-500/20 bg-amber-500/5 px-3 py-2 text-xs text-amber-200"
                              >
                                Relationship proposals stay review-only in this packet panel. Open them in Research Hub for final operator review.
                              </div>
                              <div
                                v-else-if="selectedIntakeRunGeneratedProposalReview?.counts?.total > 0"
                                class="rounded border border-emerald-500/20 bg-emerald-500/5 px-3 py-2 flex items-center justify-between gap-2"
                              >
                                <div>
                                  <div class="text-emerald-400 text-xs font-medium">All proposals resolved</div>
                                  <div class="text-theme-secondary text-xs mt-0.5">No pending actions remain for this packet. Changes marked approved will be applied on the next agent cycle.</div>
                                </div>
                                <a
                                  :href="buildResearchHubLink()"
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  class="shrink-0 px-3 py-1.5 rounded bg-sky-500/10 text-sky-400 hover:bg-sky-500/20 text-sm"
                                >
                                  Open Hub
                                </a>
                              </div>
                              <div v-if="selectedIntakeRunGeneratedProposalReview.person_changes?.length">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Person Change Review Rows</div>
                                <div class="space-y-2 mt-2">
                                  <div
                                    v-for="change in selectedIntakeRunGeneratedProposalReview.person_changes"
                                    :key="`generated-person-change-${change.proposal_id}`"
                                    class="rounded border border-theme p-3"
                                    :class="{ 'opacity-60': change.status === 'applied' || change.status === 'rejected' }"
                                  >
                                    <div class="flex items-center justify-between gap-2">
                                      <div class="text-theme-primary text-sm font-medium">{{ change.person_name || `Person #${change.person_id}` }}</div>
                                      <div class="flex items-center gap-1.5 shrink-0">
                                        <span
                                          v-if="change.confidence !== null && change.confidence !== undefined"
                                          class="px-1.5 py-0.5 rounded text-xs bg-theme-primary/10 text-theme-secondary"
                                          :title="`AI confidence: ${Math.round(change.confidence * 100)}%`"
                                        >{{ Math.round(change.confidence * 100) }}%</span>
                                        <span
                                          class="px-2 py-0.5 rounded text-xs"
                                          :class="{
                                            'bg-amber-500/10 text-amber-400': change.status === 'pending',
                                            'bg-blue-500/10 text-blue-400': change.status === 'approved',
                                            'bg-emerald-500/10 text-emerald-400': change.status === 'applied',
                                            'bg-rose-500/10 text-rose-300': change.status === 'rejected',
                                            'bg-theme-primary/10 text-theme-secondary': !['pending','approved','applied','rejected'].includes(change.status),
                                          }"
                                        >{{ change.status || 'pending' }}</span>
                                        <span class="text-theme-secondary/60 text-xs">#{{ change.proposal_id }}</span>
                                      </div>
                                    </div>
                                    <div class="text-theme-secondary text-sm mt-1">
                                      {{ change.change_type }}<span v-if="change.field_name"> · {{ change.field_name }}</span>
                                    </div>
                                    <div v-if="change.current_value || change.proposed_value" class="mt-2 space-y-0.5">
                                      <div v-if="change.current_value" class="text-theme-secondary text-xs line-through">{{ change.current_value }}</div>
                                      <div v-if="change.proposed_value" class="text-theme-primary text-sm whitespace-pre-wrap">{{ change.proposed_value }}</div>
                                    </div>
                                    <div v-if="change.evidence_summary" class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap">{{ change.evidence_summary }}</div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                      <template v-if="!['applied', 'rejected'].includes(change.status)">
                                        <button
                                          class="px-3 py-1.5 rounded bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 text-sm disabled:opacity-50"
                                          :disabled="resolveGeneratedProposalActionState(change.unified_id) || bulkApprovingPendingProposals"
                                          @click="reviewGeneratedProposal(change.unified_id, 'approve')"
                                        >
                                          {{ resolveGeneratedProposalActionState(change.unified_id) ? 'Working...' : 'Approve' }}
                                        </button>
                                        <button
                                          class="px-3 py-1.5 rounded bg-rose-500/10 text-rose-300 hover:bg-rose-500/20 text-sm disabled:opacity-50"
                                          :disabled="resolveGeneratedProposalActionState(change.unified_id) || bulkApprovingPendingProposals"
                                          @click="reviewGeneratedProposal(change.unified_id, 'reject')"
                                        >
                                          Reject
                                        </button>
                                      </template>
                                      <a
                                        :href="buildResearchHubLink(change.unified_id)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="px-3 py-1.5 rounded bg-theme-secondary border border-theme text-theme-primary text-sm hover:bg-theme-primary/5"
                                      >
                                        View in Hub
                                      </a>
                                    </div>
                                    <div v-if="proposalRowErrors[change.unified_id]" class="mt-2 text-xs text-rose-300">{{ proposalRowErrors[change.unified_id] }}</div>
                                  </div>
                                </div>
                              </div>
                              <div v-if="selectedIntakeRunGeneratedProposalReview.relationships?.length">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Relationship Review Rows</div>
                                <div class="space-y-2 mt-2">
                                  <div
                                    v-for="relationship in selectedIntakeRunGeneratedProposalReview.relationships"
                                    :key="`generated-relationship-${relationship.proposal_id}`"
                                    class="rounded border border-theme p-3"
                                    :class="{ 'opacity-60': relationship.status === 'applied' || relationship.status === 'rejected' }"
                                  >
                                    <div class="flex items-center justify-between gap-2">
                                      <div class="text-theme-primary text-sm font-medium">{{ relationship.person_name || `Person #${relationship.person_id}` }}</div>
                                      <div class="flex items-center gap-1.5 shrink-0">
                                        <span
                                          v-if="relationship.confidence !== null && relationship.confidence !== undefined"
                                          class="px-1.5 py-0.5 rounded text-xs bg-theme-primary/10 text-theme-secondary"
                                          :title="`AI confidence: ${Math.round(relationship.confidence * 100)}%`"
                                        >{{ Math.round(relationship.confidence * 100) }}%</span>
                                        <span
                                          class="px-2 py-0.5 rounded text-xs"
                                          :class="{
                                            'bg-amber-500/10 text-amber-400': relationship.status === 'pending',
                                            'bg-blue-500/10 text-blue-400': relationship.status === 'approved',
                                            'bg-emerald-500/10 text-emerald-400': relationship.status === 'applied',
                                            'bg-rose-500/10 text-rose-300': relationship.status === 'rejected',
                                            'bg-theme-primary/10 text-theme-secondary': !['pending','approved','applied','rejected'].includes(relationship.status),
                                          }"
                                        >{{ relationship.status || 'pending' }}</span>
                                        <span class="text-theme-secondary/60 text-xs">#{{ relationship.proposal_id }}</span>
                                      </div>
                                    </div>
                                    <div class="text-theme-secondary text-sm mt-1">
                                      {{ relationship.relationship_type }}<span v-if="relationship.proposed_name"> · {{ relationship.proposed_name }}</span>
                                    </div>
                                    <div
                                      v-if="relationship.proposed_birth_date || relationship.proposed_birth_place || relationship.proposed_death_date || relationship.proposed_death_place"
                                      class="mt-2 text-xs text-theme-secondary space-y-0.5"
                                    >
                                      <div v-if="relationship.proposed_birth_date || relationship.proposed_birth_place">
                                        <span class="text-theme-secondary/60">b.</span>
                                        <span v-if="relationship.proposed_birth_date"> {{ relationship.proposed_birth_date }}</span>
                                        <span v-if="relationship.proposed_birth_place"> {{ relationship.proposed_birth_place }}</span>
                                      </div>
                                      <div v-if="relationship.proposed_death_date || relationship.proposed_death_place">
                                        <span class="text-theme-secondary/60">d.</span>
                                        <span v-if="relationship.proposed_death_date"> {{ relationship.proposed_death_date }}</span>
                                        <span v-if="relationship.proposed_death_place"> {{ relationship.proposed_death_place }}</span>
                                      </div>
                                    </div>
                                    <div v-if="relationship.evidence_summary" class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap">{{ relationship.evidence_summary }}</div>
                                    <div
                                      v-if="relationship.status === 'pending'"
                                      class="mt-2 rounded border border-amber-500/20 bg-amber-500/5 px-2.5 py-2 text-xs text-amber-200"
                                    >
                                      Review safeguard: relationship proposals are opened in Research Hub instead of being directly resolved from this packet panel.
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                      <a
                                        :href="buildResearchHubLink(relationship.unified_id)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="px-3 py-1.5 rounded bg-theme-secondary border border-theme text-theme-primary text-sm hover:bg-theme-primary/5"
                                      >
                                        Open in Research Hub
                                      </a>
                                    </div>
                                    <div v-if="proposalRowErrors[relationship.unified_id]" class="mt-2 text-xs text-rose-300">{{ proposalRowErrors[relationship.unified_id] }}</div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                          <div
                            v-if="selectedIntakeRunPacketApplyState"
                            class="mt-3 rounded border border-theme p-3"
                          >
                            <div class="flex items-center justify-between gap-2">
                              <div>
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Prior Apply Record</div>
                                <div class="text-theme-secondary text-xs mt-0.5">Persisted outcome from the last Apply Supported Changes run on this packet.</div>
                              </div>
                              <div class="flex flex-wrap gap-2">
                                <span
                                  class="px-2 py-0.5 rounded text-xs"
                                  :class="{
                                    'bg-emerald-500/10 text-emerald-400': selectedIntakeRunPacketApplyState.status === 'success',
                                    'bg-amber-500/10 text-amber-400': selectedIntakeRunPacketApplyState.status === 'partial',
                                    'bg-rose-500/10 text-rose-300': selectedIntakeRunPacketApplyState.status === 'failed',
                                    'bg-theme-primary/10 text-theme-secondary': selectedIntakeRunPacketApplyState.status === 'empty',
                                  }"
                                >
                                  {{ selectedIntakeRunPacketApplyState.status || 'pending' }}
                                </span>
                                <span
                                  class="px-2 py-0.5 rounded text-xs"
                                  :class="selectedIntakeRunPacketApplyStateIsCurrent ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400'"
                                  :title="selectedIntakeRunPacketApplyStateIsCurrent ? 'This record matches the current approval plan.' : 'The approval plan has changed since this was applied — counts may not reflect the current plan.'"
                                >
                                  {{ selectedIntakeRunPacketApplyStateIsCurrent ? 'current plan' : 'stale — plan changed' }}
                                </span>
                              </div>
                            </div>
                            <div
                              v-if="selectedIntakeRunPacketApplyState.updated_at"
                              class="text-theme-secondary text-xs mt-2"
                            >
                              Applied {{ formatDateTime(selectedIntakeRunPacketApplyState.updated_at) }}
                            </div>
                            <div
                              v-if="selectedIntakeRunPacketApplyState.summary"
                              class="mt-2 text-theme-primary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunPacketApplyState.summary }}
                            </div>
                            <div
                              v-if="selectedIntakeRunPacketApplyState.next_action"
                              class="mt-2 text-theme-secondary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunPacketApplyState.next_action }}
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3">
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Applied Person Changes</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunPacketApplyState.counts?.applied_person_changes || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Applied Relationships</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunPacketApplyState.counts?.applied_relationships || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Skipped</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunPacketApplyState.counts?.skipped || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Failed</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunPacketApplyState.counts?.failed || 0 }}</div>
                              </div>
                            </div>
                          </div>
                          <div
                            v-if="selectedIntakeRunApprovalApplyResult"
                            class="mt-3 rounded border border-theme p-3"
                          >
                            <div class="flex items-center justify-between gap-2">
                              <div>
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Direct Changes Applied</div>
                                <div class="text-theme-secondary text-xs mt-0.5">Changes written to the tree in this session. The Prior Apply Record above is the persisted version of this.</div>
                              </div>
                              <span
                                class="px-2 py-0.5 rounded text-xs shrink-0"
                                :class="selectedIntakeRunApprovalApplyResult.success ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-300'"
                              >
                                {{ selectedIntakeRunApprovalApplyResult.success ? 'written' : 'attention needed' }}
                              </span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3">
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Applied Person Changes</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalApplyResult.applied_person_changes?.length || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Applied Relationships</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalApplyResult.applied_relationships?.length || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Skipped</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalApplyResult.skipped?.length || 0 }}</div>
                              </div>
                              <div class="rounded border border-theme p-2">
                                <div class="text-xs text-theme-secondary uppercase tracking-wide">Failed</div>
                                <div class="text-theme-primary text-sm">{{ selectedIntakeRunApprovalApplyResult.failed?.length || 0 }}</div>
                              </div>
                            </div>
                            <div
                              v-if="selectedIntakeRunApprovalApplyResult.errors?.length"
                              class="mt-3 text-sm text-rose-300 whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunApprovalApplyResult.errors.join('\n') }}
                            </div>
                            <div
                              v-if="selectedIntakeRunApprovalApplyResult.summary"
                              class="mt-3 text-theme-primary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunApprovalApplyResult.summary.summary }}
                            </div>
                            <div
                              v-if="selectedIntakeRunApprovalApplyResult.summary?.next_action"
                              class="mt-2 text-theme-secondary text-sm whitespace-pre-wrap"
                            >
                              {{ selectedIntakeRunApprovalApplyResult.summary.next_action }}
                            </div>
                          </div>
                        </div>
                      </div>
                    </details>
                    <div v-if="selectedIntakeRunDraftInput" class="mt-3 rounded border border-theme p-3">
                      <div class="flex items-center justify-between gap-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Draft Input Detail</div>
                        <span class="px-2 py-0.5 rounded text-xs bg-emerald-500/10 text-emerald-400">
                          ready packet
                        </span>
                      </div>
                      <div class="grid grid-cols-2 gap-2 mt-2">
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Copied</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftInput.copy_summary?.copied || 0 }}</div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Already In Place</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftInput.copy_summary?.already_in_place || 0 }}</div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Anchors</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftInput.page_anchors?.length || 0 }}</div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Decision</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunDraftInput.review_decision?.decision || 'unknown' }}</div>
                        </div>
                      </div>
                      <div v-if="selectedIntakeRunDraftInput.packet_summary" class="mt-3">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Packet Summary</div>
                        <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                          {{ selectedIntakeRunDraftInput.packet_summary }}
                        </div>
                      </div>
                      <div v-if="selectedIntakeRunDraftInput.page_anchors?.length" class="mt-3">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Page Anchors</div>
                        <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                          {{ selectedIntakeRunDraftInput.page_anchors.join(', ') }}
                        </div>
                      </div>
                      <div v-if="selectedIntakeRunDraftInput.review_decision?.notes" class="mt-3">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Review Notes</div>
                        <div class="text-theme-primary text-sm mt-1 whitespace-pre-wrap">
                          {{ selectedIntakeRunDraftInput.review_decision.notes }}
                        </div>
                      </div>
                      <details class="mt-3">
                        <summary class="text-theme-secondary text-xs uppercase tracking-wide cursor-pointer">
                          Raw Draft Input JSON
                        </summary>
                        <pre class="mt-2 p-3 rounded bg-theme-primary/5 text-theme-primary text-xs whitespace-pre-wrap break-all overflow-x-auto">{{ selectedIntakeRunDraftInputJson }}</pre>
                      </details>
                    </div>
                    <div v-if="selectedIntakeRunPacketPresentation" class="mt-3 rounded border border-theme p-3">
                      <div class="flex items-center justify-between gap-2">
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Operator Status</div>
                        <span
                          class="px-2 py-0.5 rounded-full text-xs font-semibold uppercase tracking-wide"
                          :class="{
                            'bg-emerald-500/10 text-emerald-400': selectedIntakeRunPacketPresentation.severity === 'ready',
                            'bg-amber-500/10 text-amber-400': selectedIntakeRunPacketPresentation.severity === 'pending',
                            'bg-rose-500/10 text-rose-400': selectedIntakeRunPacketPresentation.severity === 'blocked',
                          }"
                        >
                          {{ selectedIntakeRunPacketPresentation.severity || 'unknown' }}
                        </span>
                      </div>
                      <div class="text-theme-primary text-sm mt-2">
                        {{ selectedIntakeRunPacketPresentation.headline || 'No packet status available.' }}
                      </div>
                      <p class="text-theme-secondary text-sm mt-1 whitespace-pre-wrap">
                        {{ selectedIntakeRunPacketPresentation.summary || '' }}
                      </p>
                      <div
                        v-if="selectedIntakeRunPacketStage?.reason || selectedIntakeRunPacketStage?.status"
                        class="text-theme-secondary text-xs mt-2"
                      >
                        Stage:
                        {{ selectedIntakeRunPacketStage?.status || 'unknown' }}
                        <span v-if="selectedIntakeRunPacketStage?.reason">
                          · {{ selectedIntakeRunPacketStage.reason }}
                        </span>
                      </div>
                      <ul
                        v-if="selectedIntakeRunPacketPresentation.details?.length"
                        class="mt-2 space-y-1 text-theme-primary text-sm"
                      >
                        <li
                          v-for="detail in selectedIntakeRunPacketPresentation.details"
                          :key="detail"
                          class="whitespace-pre-wrap"
                        >
                          {{ detail }}
                        </li>
                      </ul>
                      <div v-if="selectedIntakeRunPacketAction" class="mt-3 rounded border border-theme p-3">
                        <div class="flex items-center justify-between gap-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Recommended Action</div>
                          <span
                            class="px-2 py-0.5 rounded-full text-xs font-semibold uppercase tracking-wide"
                            :class="{
                              'bg-rose-500/10 text-rose-400': selectedIntakeRunPacketAction.priority === 'high',
                              'bg-amber-500/10 text-amber-400': selectedIntakeRunPacketAction.priority === 'medium',
                              'bg-sky-500/10 text-sky-400': selectedIntakeRunPacketAction.priority === 'low',
                            }"
                          >
                            {{ selectedIntakeRunPacketAction.priority || 'unknown' }}
                          </span>
                        </div>
                        <div class="text-theme-primary text-sm mt-2">
                          {{ selectedIntakeRunPacketAction.label || 'No recommendation available.' }}
                        </div>
                        <p class="text-theme-secondary text-sm mt-1 whitespace-pre-wrap">
                          {{ selectedIntakeRunPacketAction.description || '' }}
                        </p>
                        <div v-if="selectedIntakeRunPacketAction.action_code" class="text-theme-secondary text-xs mt-2">
                          Action code: {{ selectedIntakeRunPacketAction.action_code }}
                        </div>
                      </div>
                    </div>
                    <div v-if="selectedIntakeRunPreview.registration?.copy_status" class="text-theme-secondary text-xs mt-1">
                      Copy status: {{ selectedIntakeRunPreview.registration.copy_status }}
                    </div>
                    <div v-if="selectedIntakeRunPreview.registration?.reference_copy_root" class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">FT Reference Copy Root</div>
                      <p class="text-theme-primary text-sm break-all whitespace-pre-wrap">
                        {{ selectedIntakeRunPreview.registration.reference_copy_root }}
                      </p>
                    </div>
                    <details v-if="selectedIntakeRunPacketPreviewState" class="mt-3 rounded border border-theme p-3">
                      <summary class="cursor-pointer list-none flex items-center justify-between gap-2">
                        <span class="text-xs text-theme-secondary uppercase tracking-wide">Saved Review State</span>
                        <span class="px-2 py-0.5 rounded bg-theme-primary/10 text-theme-primary text-xs">{{ selectedIntakeRunPreviewStateSummary }}</span>
                      </summary>
                      <div class="grid grid-cols-2 gap-2 mt-2">
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Updated</div>
                          <div class="text-theme-primary text-sm">
                            {{ formatDateTime(selectedIntakeRunPacketPreviewState.updated_at) }}
                          </div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Status</div>
                          <div class="text-theme-primary text-sm">
                            {{ selectedIntakeRunPacketPreviewState.status || 'unknown' }}
                          </div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Questions</div>
                          <div class="text-theme-primary text-sm">
                            {{ selectedIntakeRunPacketPreviewState.questions?.length || 0 }}
                          </div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Proposal Ready</div>
                          <div class="text-theme-primary text-sm">
                            {{ selectedIntakeRunPacketPreviewState.proposal_ready ? 'yes' : 'no' }}
                          </div>
                        </div>
                      </div>
                      <div
                        v-if="selectedIntakeRunPacketPreviewState.page_anchors?.length"
                        class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap"
                      >
                        Anchors: {{ selectedIntakeRunPacketPreviewState.page_anchors.join(', ') }}
                      </div>
                    </details>
                    <details v-if="selectedIntakeRunPacketBindingSignals" class="mt-3 rounded border border-theme p-3">
                      <summary class="cursor-pointer list-none flex items-center justify-between gap-2">
                        <span class="text-xs text-theme-secondary uppercase tracking-wide">Why This Matches</span>
                        <span class="px-2 py-0.5 rounded bg-theme-primary/10 text-theme-primary text-xs">{{ selectedIntakeRunBindingSignalsSummary }}</span>
                      </summary>
                      <div class="text-theme-primary text-sm mt-2">
                        {{ selectedIntakeRunPacketBindingSignals.primary_binding?.replace(/_/g, ' ') || 'none' }}
                      </div>
                      <div class="grid grid-cols-2 gap-2 mt-2">
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Existing Matches</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunPacketBindingSignals.existing_person_match_count ?? 0 }}</div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">New Candidates</div>
                          <div class="text-theme-primary text-sm">{{ selectedIntakeRunPacketBindingSignals.new_person_candidate_count ?? 0 }}</div>
                        </div>
                      </div>
                      <div
                        v-if="Object.entries(selectedIntakeRunPacketBindingSignals.document_classifications || {}).some(([, count]) => Number(count || 0) > 0)"
                        class="mt-2"
                      >
                        <div class="text-xs text-theme-secondary uppercase tracking-wide">Document Reuse State</div>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                          <span
                            v-for="[classification, count] in Object.entries(selectedIntakeRunPacketBindingSignals.document_classifications || {}).filter(([, count]) => Number(count || 0) > 0)"
                            :key="'doc-class-' + classification"
                            class="px-2 py-0.5 rounded text-xs bg-theme-primary/10 text-theme-primary"
                          >
                            {{ classification.replace(/_/g, ' ') }} · {{ count }}
                          </span>
                        </div>
                      </div>
                      <div
                        v-if="selectedIntakeRunPacketBindingSignals.event_signals?.length || selectedIntakeRunPacketBindingSignals.relationship_signals?.length || selectedIntakeRunPacketBindingSignals.source_signals?.length"
                        class="flex flex-wrap gap-1.5 mt-2"
                      >
                        <span
                          v-for="signal in selectedIntakeRunPacketBindingSignals.event_signals || []"
                          :key="'evt-' + signal"
                          class="px-2 py-0.5 rounded text-xs bg-sky-500/10 text-sky-400"
                        >
                          {{ signal }}
                        </span>
                        <span
                          v-for="signal in selectedIntakeRunPacketBindingSignals.relationship_signals || []"
                          :key="'rel-' + signal"
                          class="px-2 py-0.5 rounded text-xs bg-fuchsia-500/10 text-fuchsia-400"
                        >
                          {{ signal.replace(/_/g, ' ') }}
                        </span>
                        <span
                          v-for="signal in selectedIntakeRunPacketBindingSignals.source_signals || []"
                          :key="'src-' + signal"
                          class="px-2 py-0.5 rounded text-xs bg-violet-500/10 text-violet-400"
                        >
                          {{ signal.replace(/_/g, ' ') }}
                        </span>
                      </div>
                      <div v-if="selectedIntakeRunPacketBindingSignals.matched_people?.length" class="space-y-1 mt-2">
                        <div
                          v-for="match in selectedIntakeRunPacketBindingSignals.matched_people"
                          :key="match.matched_person_id || match.name"
                          class="text-sm text-theme-primary"
                        >
                          {{ match.name }}
                          <span v-if="match.matched_person_name" class="text-theme-secondary">
                            · {{ match.matched_person_name }}
                          </span>
                          <span
                            class="px-1.5 py-0.5 rounded text-xs ml-1"
                            :class="{
                              'bg-emerald-500/10 text-emerald-400': match.confidence === 'high',
                              'bg-amber-500/10 text-amber-400': match.confidence === 'medium',
                              'bg-rose-500/10 text-rose-400': match.confidence === 'low',
                            }"
                          >
                            {{ match.confidence }}
                          </span>
                        </div>
                      </div>
                    </details>
                    <div class="mt-3">
                      <div class="text-xs text-theme-secondary uppercase tracking-wide">Save Review Decision</div>
                      <div class="text-theme-secondary text-sm mt-2 whitespace-pre-wrap">
                        {{ selectedIntakeRunDecisionPrompt }}
                      </div>
                      <textarea
                        v-model="intakeRunDecisionNotes"
                        rows="3"
                        class="w-full mt-3 px-3 py-2 rounded-lg bg-theme-secondary border border-theme text-theme-primary"
                        placeholder="Optional review notes saved with this decision"
                      />
                      <div class="text-theme-secondary text-xs mt-2">
                        Notes are saved with the packet decision and help explain why it was approved, deferred, followed up, or rejected.
                      </div>
                      <div class="flex flex-wrap gap-2 mt-3">
                        <button
                          @click="submitIntakeRunDecision('approved')"
                          :disabled="savingIntakeRunDecision || !selectedIntakeRunPacket"
                          class="px-4 py-2 rounded bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 disabled:opacity-50 font-medium"
                        >
                          Approve
                        </button>
                        <button
                          @click="submitIntakeRunDecision('deferred')"
                          :disabled="savingIntakeRunDecision || !selectedIntakeRunPacket"
                          class="px-3 py-1.5 rounded bg-amber-500/10 text-amber-400 hover:bg-amber-500/20 disabled:opacity-50"
                        >
                          Defer
                        </button>
                        <button
                          @click="submitIntakeRunDecision('needs_followup')"
                          :disabled="savingIntakeRunDecision || !selectedIntakeRunPacket"
                          class="px-3 py-1.5 rounded bg-sky-500/10 text-sky-400 hover:bg-sky-500/20 disabled:opacity-50"
                        >
                          Follow Up
                        </button>
                        <button
                          @click="submitIntakeRunDecision('rejected')"
                          :disabled="savingIntakeRunDecision || !selectedIntakeRunPacket"
                          class="px-4 py-2 rounded bg-rose-500/20 text-rose-300 hover:bg-rose-500/30 disabled:opacity-50 font-medium"
                        >
                          Reject
                        </button>
                      </div>
                      <div v-if="selectedIntakeRunPacketReviewDecision" class="text-theme-secondary text-xs mt-2 whitespace-pre-wrap">
                        Current: {{ selectedIntakeRunPacketReviewDecision.decision || 'unknown' }}
                        <span v-if="selectedIntakeRunPacketReviewDecision.reviewed_by">
                          · {{ selectedIntakeRunPacketReviewDecision.reviewed_by }}
                        </span>
                        <span v-if="selectedIntakeRunPacketReviewDecision.updated_at">
                          · {{ formatDateTime(selectedIntakeRunPacketReviewDecision.updated_at) }}
                        </span>
                      </div>
                    </div>
                    <details v-if="selectedIntakeRunPacketExecution" class="mt-3 rounded border border-theme p-3">
                      <summary class="cursor-pointer list-none flex items-center justify-between gap-2">
                        <span class="text-xs text-theme-secondary uppercase tracking-wide">Copy Result</span>
                        <span class="px-2 py-0.5 rounded bg-theme-primary/10 text-theme-primary text-xs">{{ selectedIntakeRunCopyExecutionSummary }}</span>
                      </summary>
                      <div class="grid grid-cols-2 gap-2 mt-2">
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Updated</div>
                          <div class="text-theme-primary text-sm">
                            {{ formatDateTime(selectedIntakeRunPacketExecution.updated_at) }}
                          </div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Apply Mode</div>
                          <div class="text-theme-primary text-sm">
                            {{ selectedIntakeRunPacketExecution.execution?.apply ? 'apply' : 'preview' }}
                          </div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Copied</div>
                          <div class="text-theme-primary text-sm">
                            {{ selectedIntakeRunPacketExecution.execution?.summary?.copied || 0 }}
                          </div>
                        </div>
                        <div class="rounded border border-theme p-2">
                          <div class="text-xs text-theme-secondary uppercase tracking-wide">Blocked Conflicts</div>
                          <div class="text-theme-primary text-sm">
                            {{ selectedIntakeRunPacketExecution.execution?.summary?.blocked_conflicts || 0 }}
                          </div>
                        </div>
                      </div>
                      <div
                        v-if="selectedIntakeRunPacketExecution.execution?.results?.length"
                        class="space-y-2 mt-3"
                      >
                        <div
                          v-for="result in selectedIntakeRunPacketExecution.execution.results.slice(0, 4)"
                          :key="`${result.document_id || result.reference_copy_path}-${result.action}`"
                          class="rounded border border-theme p-2"
                        >
                          <div class="text-theme-primary text-sm">
                            {{ result.source_name || result.reference_copy_path || 'document' }}
                          </div>
                          <div class="text-theme-secondary text-xs mt-1">
                            {{ result.action || 'unknown' }}
                          </div>
                          <div
                            v-if="result.message"
                            class="text-theme-secondary text-xs mt-1 whitespace-pre-wrap"
                          >
                            {{ result.message }}
                          </div>
                        </div>
                      </div>
                    </details>
                  </div>
                </div>
                <div v-else class="text-theme-secondary text-sm">
                  Select a saved intake run to inspect its staged packet preview.
                </div>
              </div>
            </div>

            <div class="flex justify-between items-center mt-6 pt-4 border-t border-theme">
              <button
                @click="loadIntakeRuns"
                class="px-4 py-2 bg-fuchsia-500/20 text-fuchsia-400 rounded-lg hover:bg-fuchsia-500/30"
              >
                Refresh Runs
              </button>
              <button @click="showIntakeRunsModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 4: Export GEDCOM Modal -->
      <div v-if="showExportModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showExportModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">Export GEDCOM</h3>
              <button @click="showExportModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <!-- Loading State -->
            <div v-if="exportingGedcom" class="text-center py-8">
              <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-accent mx-auto mb-4"></div>
              <p class="text-theme-secondary">Generating GEDCOM file...</p>
            </div>

            <!-- Export Ready -->
            <div v-else-if="gedcomExportData">
              <div class="bg-green-500/20 border border-green-500 rounded-lg p-4 mb-4">
                <p class="text-green-400 font-medium">GEDCOM file ready for download</p>
                <p class="text-sm text-theme-secondary mt-1">
                  File: {{ gedcomExportData.filename }} ({{ formatBytes(gedcomExportData.size) }})
                </p>
              </div>

              <button
                @click="downloadGedcom"
                class="w-full px-4 py-3 bg-accent text-white rounded-lg hover:bg-accent/80 font-medium flex items-center justify-center gap-2"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download GEDCOM File
              </button>

              <div class="mt-4 text-sm text-theme-secondary">
                <p class="font-medium mb-2">GEDCOM files can be imported into:</p>
                <ul class="list-disc pl-5 space-y-1">
                  <li>Ancestry.com</li>
                  <li>FamilySearch</li>
                  <li>MyHeritage</li>
                  <li>Legacy Family Tree</li>
                  <li>RootsMagic</li>
                  <li>Gramps</li>
                </ul>
              </div>
            </div>

            <!-- Initial State -->
            <div v-else>
              <p class="text-theme-secondary mb-4">
                Export your family tree in GEDCOM 5.5.1 format. This standardized format can be imported
                into most genealogy software and online services.
              </p>

              <button
                @click="exportGedcom"
                class="w-full px-4 py-3 bg-accent text-white rounded-lg hover:bg-accent/80 font-medium"
              >
                Generate GEDCOM Export
              </button>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showExportModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 4: Validation Modal -->
      <div v-if="showValidationModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showValidationModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">Data Integrity Validation</h3>
              <button @click="showValidationModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <!-- Loading State -->
            <div v-if="validatingTree" class="text-center py-8">
              <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-accent mx-auto mb-4"></div>
              <p class="text-theme-secondary">Validating tree data...</p>
            </div>

            <!-- Results -->
            <div v-else-if="validationResults">
              <!-- Summary -->
              <div :class="validationResults.is_valid ? 'bg-green-500/20 border-green-500' : 'bg-red-500/20 border-red-500'" class="border rounded-lg p-4 mb-4">
                <div class="flex items-center gap-3">
                  <div v-if="validationResults.is_valid" class="text-green-400 text-2xl">✓</div>
                  <div v-else class="text-red-400 text-2xl">⚠</div>
                  <div>
                    <p :class="validationResults.is_valid ? 'text-green-400' : 'text-red-400'" class="font-medium">
                      {{ validationResults.is_valid ? 'No critical issues found' : 'Issues detected' }}
                    </p>
                    <p class="text-sm text-theme-secondary">
                      {{ validationResults.error_count }} errors, {{ validationResults.warning_count }} warnings
                    </p>
                  </div>
                </div>
              </div>

              <!-- Errors -->
              <div v-if="validationResults.issues && validationResults.issues.length > 0" class="mb-4">
                <h4 class="text-lg font-medium text-red-400 mb-2">Errors</h4>
                <div class="space-y-2">
                  <div v-for="issue in validationResults.issues" :key="issue.type" class="bg-red-500/10 border border-red-500/50 rounded-lg p-3">
                    <p class="text-red-400 font-medium">{{ issue.message }}</p>
                    <p class="text-sm text-theme-secondary mt-1">Type: {{ issue.type }}</p>
                  </div>
                </div>
              </div>

              <!-- Warnings -->
              <div v-if="validationResults.warnings && validationResults.warnings.length > 0">
                <h4 class="text-lg font-medium text-yellow-400 mb-2">Warnings</h4>
                <div class="space-y-2">
                  <div v-for="warning in validationResults.warnings" :key="warning.type" class="bg-yellow-500/10 border border-yellow-500/50 rounded-lg p-3">
                    <p class="text-yellow-400 font-medium">{{ warning.message }}</p>
                    <p class="text-sm text-theme-secondary mt-1">Type: {{ warning.type }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Initial State -->
            <div v-else>
              <p class="text-theme-secondary mb-4">
                Run validation checks on your family tree data to identify potential issues:
              </p>
              <ul class="list-disc pl-5 text-theme-secondary mb-4 space-y-1">
                <li>Orphaned references</li>
                <li>Invalid family links</li>
                <li>Duplicate GEDCOM IDs</li>
                <li>Circular relationships</li>
                <li>Missing media references</li>
              </ul>

              <button
                @click="validateTree"
                class="w-full px-4 py-3 bg-accent text-white rounded-lg hover:bg-accent/80 font-medium"
              >
                Run Validation
              </button>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showValidationModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 4: Statistics Modal -->
      <div v-if="showStatisticsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showStatisticsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">Tree Statistics</h3>
              <button @click="showStatisticsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <!-- Loading State -->
            <div v-if="loadingStatistics" class="text-center py-8">
              <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-accent mx-auto mb-4"></div>
              <p class="text-theme-secondary">Loading statistics...</p>
            </div>

            <!-- Statistics Display -->
            <div v-else-if="treeStatistics">
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                <!-- Person Stats -->
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-3">Persons</h4>
                  <div class="text-3xl font-bold text-accent mb-2">{{ treeStatistics.persons.total }}</div>
                  <div class="text-sm text-theme-secondary space-y-1">
                    <p>Male: {{ treeStatistics.persons.by_gender?.M || 0 }}</p>
                    <p>Female: {{ treeStatistics.persons.by_gender?.F || 0 }}</p>
                    <p>Unknown: {{ treeStatistics.persons.by_gender?.U || 0 }}</p>
                    <p class="mt-2">Living: {{ treeStatistics.persons.living }}</p>
                    <p>Deceased: {{ treeStatistics.persons.deceased }}</p>
                  </div>
                </div>

                <!-- Family Stats -->
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-3">Families</h4>
                  <div class="text-3xl font-bold text-accent mb-2">{{ treeStatistics.families.total }}</div>
                  <div class="text-sm text-theme-secondary">
                    <p>Avg. children per family: {{ treeStatistics.families.avg_children }}</p>
                  </div>
                </div>

                <!-- Date Range -->
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-3">Date Range</h4>
                  <div class="text-sm text-theme-secondary space-y-1">
                    <p>Earliest birth: {{ treeStatistics.dates.earliest_birth || 'Unknown' }}</p>
                    <p>Latest birth: {{ treeStatistics.dates.latest_birth || 'Unknown' }}</p>
                    <p class="mt-2">Earliest death: {{ treeStatistics.dates.earliest_death || 'Unknown' }}</p>
                    <p>Latest death: {{ treeStatistics.dates.latest_death || 'Unknown' }}</p>
                  </div>
                </div>

                <!-- Media Stats -->
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-3">Media</h4>
                  <div class="text-3xl font-bold text-accent mb-2">{{ treeStatistics.media.total }}</div>
                  <div class="text-sm text-theme-secondary space-y-1">
                    <p>Photos: {{ treeStatistics.media.photos }}</p>
                    <p>Documents: {{ treeStatistics.media.documents }}</p>
                    <p>Headstones: {{ treeStatistics.media.headstones }}</p>
                    <p>Audio: {{ treeStatistics.media.audio }}</p>
                    <p>Video: {{ treeStatistics.media.video }}</p>
                  </div>
                </div>

                <!-- Generations -->
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-3">Generations</h4>
                  <div class="text-3xl font-bold text-accent mb-2">{{ treeStatistics.generations || 0 }}</div>
                  <p class="text-sm text-theme-secondary">Estimated generations in tree</p>
                </div>

                <!-- Backup Status -->
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-3">Backup Info</h4>
                  <div v-if="loadingBackupStatus" class="text-sm text-theme-secondary">Loading...</div>
                  <div v-else-if="backupStatus" class="text-sm text-theme-secondary space-y-1">
                    <p>Est. GEDCOM size: {{ backupStatus.estimated_gedcom_size }}</p>
                    <p>Sources: {{ backupStatus.record_counts.sources }}</p>
                  </div>
                </div>
              </div>

              <!-- Top Surnames -->
              <div v-if="treeStatistics.surnames && treeStatistics.surnames.length > 0" class="mb-4">
                <h4 class="font-medium text-theme-primary mb-2">Top Surnames</h4>
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="surname in treeStatistics.surnames.slice(0, 10)"
                    :key="surname.surname"
                    class="px-3 py-1 bg-theme-tertiary rounded-full text-sm"
                  >
                    {{ surname.surname }} ({{ surname.count }})
                  </span>
                </div>
              </div>

              <!-- Top Places -->
              <div v-if="treeStatistics.places && treeStatistics.places.length > 0">
                <h4 class="font-medium text-theme-primary mb-2">Top Birth Places</h4>
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="place in treeStatistics.places.slice(0, 10)"
                    :key="place.place"
                    class="px-3 py-1 bg-theme-tertiary rounded-full text-sm"
                  >
                    {{ place.place }} ({{ place.count }})
                  </span>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showStatisticsModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 5: Relationship Calculator Modal -->
      <div v-if="showRelationshipModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showRelationshipModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Relationship Calculator</h3>
              <button @click="showRelationshipModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <p class="text-theme-secondary mb-4">
              Select two people to find out how they are related.
            </p>

            <!-- Person 1 Selection -->
            <div class="mb-4">
              <label class="block text-sm font-medium text-theme-secondary mb-1">First Person</label>
              <select
                v-model="relationshipPerson1"
                class="w-full px-3 py-2 bg-theme-tertiary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent"
              >
                <option :value="null">Select a person...</option>
                <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                  {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }})
                </option>
              </select>
            </div>

            <!-- Person 2 Selection -->
            <div class="mb-4">
              <label class="block text-sm font-medium text-theme-secondary mb-1">Second Person</label>
              <select
                v-model="relationshipPerson2"
                class="w-full px-3 py-2 bg-theme-tertiary text-theme-primary rounded-lg border border-theme focus:ring-2 focus:ring-accent"
              >
                <option :value="null">Select a person...</option>
                <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                  {{ person.surname }}, {{ person.given_name }} ({{ person.birth_year || '?' }})
                </option>
              </select>
            </div>

            <button
              @click="calculateRelationship"
              :disabled="!relationshipPerson1 || !relationshipPerson2 || calculatingRelationship"
              class="w-full px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed mb-4"
            >
              <span v-if="calculatingRelationship">Calculating...</span>
              <span v-else>Calculate Relationship</span>
            </button>

            <!-- Results -->
            <div v-if="relationshipResult" class="bg-theme-tertiary rounded-lg p-4">
              <div v-if="relationshipResult.relationship" class="text-center">
                <div class="text-3xl font-bold text-accent mb-2">{{ relationshipResult.relationship }}</div>
                <p class="text-theme-secondary mb-3">{{ relationshipResult.description }}</p>

                <!-- DNA Sharing Prediction -->
                <div v-if="relationshipResult.dna_sharing" class="mt-3 p-3 bg-theme-secondary rounded-lg">
                  <p class="text-xs font-medium text-theme-secondary mb-1">Predicted DNA Sharing</p>
                  <div class="text-lg font-bold text-accent">{{ relationshipResult.dna_sharing.avg_cm }} cM</div>
                  <div class="text-xs text-theme-secondary">Range: {{ relationshipResult.dna_sharing.min_cm }} – {{ relationshipResult.dna_sharing.max_cm }} cM ({{ relationshipResult.dna_sharing.percent }}%)</div>
                </div>

                <div v-if="relationshipResult.common_ancestor" class="text-sm text-theme-secondary">
                  <p class="font-medium mb-1">Common Ancestor:</p>
                  <p class="text-accent">{{ relationshipResult.common_ancestor.name }}</p>
                </div>

                <div v-if="relationshipResult.path && relationshipResult.path.length > 0" class="mt-4">
                  <p class="font-medium text-theme-secondary mb-2">Connection Path:</p>
                  <div class="flex flex-wrap justify-center gap-1 text-sm">
                    <span v-for="(step, idx) in relationshipResult.path" :key="idx" class="flex items-center">
                      <span class="px-2 py-1 bg-theme-secondary rounded">{{ step }}</span>
                      <span v-if="idx < relationshipResult.path.length - 1" class="mx-1 text-theme-secondary">→</span>
                    </span>
                  </div>
                </div>
              </div>
              <div v-else class="text-center text-theme-secondary">
                <p>{{ relationshipResult.message || 'No relationship found between these persons.' }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Activity Log Modal -->
      <div v-if="showPersonActivityLog" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showPersonActivityLog = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Change History</h3>
              <button @click="showPersonActivityLog = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div v-if="loadingPersonActivityLog" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Loading history...</p>
            </div>

            <div v-else-if="personActivityLog.length === 0" class="text-center py-8 text-theme-secondary">
              No changes recorded for this person.
            </div>

            <div v-else class="space-y-3">
              <div v-for="entry in personActivityLog" :key="entry.id" class="border-l-2 pl-4 py-2"
                   :class="entry.action === 'created' ? 'border-green-500' : entry.action === 'deleted' ? 'border-red-500' : 'border-accent'">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-medium text-theme-primary capitalize">{{ entry.action }}</span>
                  <span class="text-xs text-theme-secondary">{{ formatActivityDate(entry.created_at) }}</span>
                </div>
                <p v-if="entry.field" class="text-sm text-theme-secondary">
                  <span class="font-medium">{{ entry.field }}</span>:
                  <span v-if="entry.old_value" class="line-through text-red-400">{{ entry.old_value }}</span>
                  <span v-if="entry.old_value && entry.new_value"> → </span>
                  <span v-if="entry.new_value" class="text-green-400">{{ entry.new_value }}</span>
                </p>
                <p v-if="entry.description" class="text-xs text-theme-secondary mt-1">{{ entry.description }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 5: Places Analysis Modal -->
      <div v-if="showPlacesModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showPlacesModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Geographic Distribution</h3>
              <button @click="showPlacesModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div v-if="loadingPlaces" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Loading places data...</p>
            </div>

            <div v-else-if="placesData">
              <!-- Summary -->
              <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold text-accent">{{ placesData.total_places }}</div>
                  <div class="text-sm text-theme-secondary">Unique Places</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold text-blue-400">{{ placesData.birth_places?.length || 0 }}</div>
                  <div class="text-sm text-theme-secondary">Birth Places</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold text-red-400">{{ placesData.death_places?.length || 0 }}</div>
                  <div class="text-sm text-theme-secondary">Death Places</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold text-pink-400">{{ placesData.marriage_places?.length || 0 }}</div>
                  <div class="text-sm text-theme-secondary">Marriage Places</div>
                </div>
              </div>

              <!-- Regions -->
              <div v-if="placesData.regions && placesData.regions.length > 0" class="mb-6">
                <h4 class="font-medium text-theme-primary mb-3">By Region</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div
                    v-for="region in placesData.regions.slice(0, 10)"
                    :key="region.region"
                    class="bg-theme-tertiary rounded-lg p-3 flex justify-between items-center"
                  >
                    <span class="text-theme-primary">{{ region.region || 'Unknown' }}</span>
                    <span class="text-accent font-semibold">{{ region.count }}</span>
                  </div>
                </div>
              </div>

              <!-- Top Places Lists -->
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Birth Places -->
                <div v-if="placesData.birth_places && placesData.birth_places.length > 0">
                  <h4 class="font-medium text-theme-primary mb-2 flex items-center gap-2">
                    <span class="w-3 h-3 bg-blue-400 rounded-full"></span>
                    Top Birth Places
                  </h4>
                  <div class="space-y-1">
                    <div
                      v-for="place in placesData.birth_places.slice(0, 8)"
                      :key="place.place"
                      class="text-sm flex justify-between text-theme-secondary"
                    >
                      <span>{{ place.place }}</span>
                      <span class="text-blue-400">{{ place.count }}</span>
                    </div>
                  </div>
                </div>

                <!-- Death Places -->
                <div v-if="placesData.death_places && placesData.death_places.length > 0">
                  <h4 class="font-medium text-theme-primary mb-2 flex items-center gap-2">
                    <span class="w-3 h-3 bg-red-400 rounded-full"></span>
                    Top Death Places
                  </h4>
                  <div class="space-y-1">
                    <div
                      v-for="place in placesData.death_places.slice(0, 8)"
                      :key="place.place"
                      class="text-sm flex justify-between text-theme-secondary"
                    >
                      <span>{{ place.place }}</span>
                      <span class="text-red-400">{{ place.count }}</span>
                    </div>
                  </div>
                </div>

                <!-- Marriage Places -->
                <div v-if="placesData.marriage_places && placesData.marriage_places.length > 0">
                  <h4 class="font-medium text-theme-primary mb-2 flex items-center gap-2">
                    <span class="w-3 h-3 bg-pink-400 rounded-full"></span>
                    Top Marriage Places
                  </h4>
                  <div class="space-y-1">
                    <div
                      v-for="place in placesData.marriage_places.slice(0, 8)"
                      :key="place.place"
                      class="text-sm flex justify-between text-theme-secondary"
                    >
                      <span>{{ place.place }}</span>
                      <span class="text-pink-400">{{ place.count }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showPlacesModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 5: Person Timeline Modal -->
      <div v-if="showTimelineModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showTimelineModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">
                Timeline: {{ timelineData?.person?.name || 'Loading...' }}
              </h3>
              <button @click="showTimelineModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div v-if="loadingTimeline" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Loading timeline...</p>
            </div>

            <div v-else-if="timelineData && timelineData.events && timelineData.events.length > 0">
              <!-- Life span summary -->
              <div v-if="timelineData.person" class="bg-theme-tertiary rounded-lg p-4 mb-4">
                <div class="flex items-center gap-4">
                  <div v-if="timelineData.person.birth_year" class="text-center">
                    <div class="text-2xl font-bold text-accent">{{ timelineData.person.birth_year }}</div>
                    <div class="text-xs text-theme-secondary">Born</div>
                  </div>
                  <div class="flex-1 h-1 bg-accent/30 rounded relative">
                    <div class="absolute inset-0 bg-accent rounded" style="width: 100%"></div>
                  </div>
                  <div v-if="timelineData.person.death_year" class="text-center">
                    <div class="text-2xl font-bold text-accent">{{ timelineData.person.death_year }}</div>
                    <div class="text-xs text-theme-secondary">Died</div>
                  </div>
                  <div v-else-if="timelineData.person.death_date" class="text-center">
                    <div class="text-2xl font-bold text-accent">{{ timelineData.person.death_date }}</div>
                    <div class="text-xs text-theme-secondary">Died</div>
                  </div>
                  <div v-else-if="timelineData.person.living" class="text-center">
                    <div class="text-2xl font-bold text-green-400">Living</div>
                    <div class="text-xs text-theme-secondary">&nbsp;</div>
                  </div>
                  <div v-else class="text-center">
                    <div class="text-2xl font-bold text-theme-secondary">?</div>
                    <div class="text-xs text-theme-secondary">Unknown</div>
                  </div>
                </div>
              </div>

              <!-- Timeline events -->
              <div class="relative pl-6 border-l-2 border-accent/30 space-y-4">
                <div
                  v-for="event in timelineData.events"
                  :key="`${event.type}-${event.date}`"
                  class="relative"
                >
                  <!-- Timeline dot -->
                  <div class="absolute -left-[25px] w-4 h-4 rounded-full bg-accent flex items-center justify-center">
                    <span class="text-xs">{{ event.icon || '•' }}</span>
                  </div>

                  <!-- Event content -->
                  <div class="bg-theme-tertiary rounded-lg p-3">
                    <div class="flex justify-between items-start mb-1">
                      <span class="font-medium text-theme-primary">{{ event.type_label || event.type }}</span>
                      <span class="text-sm text-theme-secondary">{{ event.date || 'Unknown date' }}</span>
                    </div>
                    <p class="text-sm text-theme-secondary">{{ event.description }}</p>
                    <p v-if="event.place" class="text-sm text-accent mt-1">📍 {{ event.place }}</p>
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="text-center py-8 text-theme-secondary">
              <p>No events found for this person.</p>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showTimelineModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 6: Pedigree Chart Modal -->
      <div v-if="showPedigreeModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showPedigreeModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto print:max-w-full print:shadow-none">
          <div class="p-6 print:p-2">
            <div class="flex justify-between items-center mb-4 print:hidden">
              <h3 class="text-xl font-semibold text-theme-primary">
                Pedigree Chart: {{ pedigreeData?.root_person?.name }}
              </h3>
              <div class="flex gap-2">
                <button @click="window.print()" class="px-3 py-1 bg-accent text-white rounded-lg hover:bg-accent/80 text-sm">
                  🖨️ Print
                </button>
                <button @click="showPedigreeModal = false" class="text-theme-secondary hover:text-theme-primary">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
            </div>

            <div v-if="loadingReport" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Generating pedigree chart...</p>
            </div>

            <div v-else-if="pedigreeData" class="space-y-4">
              <!-- Root Person -->
              <div class="text-center mb-6">
                <div class="inline-block bg-accent/20 rounded-lg p-4">
                  <div class="font-bold text-lg text-accent">{{ pedigreeData.root_person.name }}</div>
                  <div class="text-sm text-theme-secondary">
                    {{ pedigreeData.root_person.birth_date || '?' }} - {{ pedigreeData.root_person.death_date || (pedigreeData.root_person.living ? 'Living' : '?') }}
                  </div>
                </div>
              </div>

              <!-- Ancestors Tree -->
              <div v-if="pedigreeData.ancestors" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <!-- Father's Side -->
                  <div v-if="pedigreeData.ancestors.father" class="space-y-2">
                    <div class="bg-blue-500/20 rounded-lg p-3 text-center">
                      <div class="font-medium text-blue-400">{{ pedigreeData.ancestors.father.name }}</div>
                      <div class="text-xs text-theme-secondary">Father</div>
                      <div class="text-xs text-theme-secondary">{{ pedigreeData.ancestors.father.birth_date || '?' }}</div>
                    </div>
                    <!-- Father's Parents -->
                    <div v-if="pedigreeData.ancestors.father.parents" class="grid grid-cols-2 gap-2 text-sm">
                      <div v-if="pedigreeData.ancestors.father.parents.father" class="bg-theme-tertiary rounded p-2 text-center">
                        <div class="text-theme-primary">{{ pedigreeData.ancestors.father.parents.father.name }}</div>
                        <div class="text-xs text-theme-secondary">{{ pedigreeData.ancestors.father.parents.father.birth_date || '?' }}</div>
                      </div>
                      <div v-if="pedigreeData.ancestors.father.parents.mother" class="bg-theme-tertiary rounded p-2 text-center">
                        <div class="text-theme-primary">{{ pedigreeData.ancestors.father.parents.mother.name }}</div>
                        <div class="text-xs text-theme-secondary">{{ pedigreeData.ancestors.father.parents.mother.birth_date || '?' }}</div>
                      </div>
                    </div>
                  </div>
                  <!-- Mother's Side -->
                  <div v-if="pedigreeData.ancestors.mother" class="space-y-2">
                    <div class="bg-pink-500/20 rounded-lg p-3 text-center">
                      <div class="font-medium text-pink-400">{{ pedigreeData.ancestors.mother.name }}</div>
                      <div class="text-xs text-theme-secondary">Mother</div>
                      <div class="text-xs text-theme-secondary">{{ pedigreeData.ancestors.mother.birth_date || '?' }}</div>
                    </div>
                    <!-- Mother's Parents -->
                    <div v-if="pedigreeData.ancestors.mother.parents" class="grid grid-cols-2 gap-2 text-sm">
                      <div v-if="pedigreeData.ancestors.mother.parents.father" class="bg-theme-tertiary rounded p-2 text-center">
                        <div class="text-theme-primary">{{ pedigreeData.ancestors.mother.parents.father.name }}</div>
                        <div class="text-xs text-theme-secondary">{{ pedigreeData.ancestors.mother.parents.father.birth_date || '?' }}</div>
                      </div>
                      <div v-if="pedigreeData.ancestors.mother.parents.mother" class="bg-theme-tertiary rounded p-2 text-center">
                        <div class="text-theme-primary">{{ pedigreeData.ancestors.mother.parents.mother.name }}</div>
                        <div class="text-xs text-theme-secondary">{{ pedigreeData.ancestors.mother.parents.mother.birth_date || '?' }}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme print:hidden">
              <button @click="showPedigreeModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- E.2 Ahnentafel Report Modal -->
      <div v-if="showAhnentafelModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showAhnentafelModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">
                Ahnentafel: {{ ahnentafelData?.root_person?.name }}
              </h3>
              <div class="flex gap-2">
                <button @click="window.print()" class="px-3 py-1 bg-accent text-white rounded-lg hover:bg-accent/80 text-sm">
                  Print
                </button>
                <button @click="showAhnentafelModal = false" class="text-theme-secondary hover:text-theme-primary">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
            </div>

            <div v-if="loadingReport" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
              <span class="ml-2 text-theme-secondary">Loading Ahnentafel...</span>
            </div>

            <div v-else-if="ahnentafelData" class="space-y-6">
              <!-- Statistics -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-2">Statistics</h4>
                <div class="grid grid-cols-3 gap-4 text-sm">
                  <div>
                    <span class="text-theme-secondary">Ancestors Found:</span>
                    <span class="text-theme-primary ml-2 font-medium">{{ ahnentafelData.statistics.total_found }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Total Possible:</span>
                    <span class="text-theme-primary ml-2 font-medium">{{ ahnentafelData.statistics.total_possible }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Completeness:</span>
                    <span class="text-theme-primary ml-2 font-medium">{{ ahnentafelData.statistics.completeness_percent }}%</span>
                  </div>
                </div>
              </div>

              <!-- Ancestors by Generation -->
              <div v-for="gen in ahnentafelData.by_generation" :key="gen.generation" class="space-y-2">
                <h4 class="font-medium text-theme-primary border-b border-theme pb-1">
                  {{ gen.label }} ({{ gen.ancestors.length }} ancestor{{ gen.ancestors.length !== 1 ? 's' : '' }})
                </h4>
                <div class="space-y-2">
                  <div v-for="ancestor in gen.ancestors" :key="ancestor.ahnentafel_number"
                       class="flex items-start gap-3 p-2 bg-theme-tertiary rounded-lg">
                    <div class="flex-shrink-0 w-8 h-8 bg-purple-500/20 rounded-full flex items-center justify-center">
                      <span class="text-sm font-bold text-purple-400">{{ ancestor.ahnentafel_number }}</span>
                    </div>
                    <div class="flex-1">
                      <div class="font-medium text-theme-primary">{{ ancestor.name }}</div>
                      <div class="text-xs text-theme-secondary">
                        {{ ancestor.position }}
                      </div>
                      <div class="text-xs text-theme-muted mt-1">
                        <span v-if="ancestor.birth_date || ancestor.birth_place">
                          b. {{ ancestor.birth_date || '?' }}{{ ancestor.birth_place ? ', ' + ancestor.birth_place : '' }}
                        </span>
                        <span v-if="ancestor.death_date || ancestor.death_place" class="ml-2">
                          d. {{ ancestor.death_date || '?' }}{{ ancestor.death_place ? ', ' + ancestor.death_place : '' }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme print:hidden">
              <button @click="showAhnentafelModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 6: Descendant Report Modal -->
      <div v-if="showDescendantModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showDescendantModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">
                Descendants of {{ descendantData?.root_person?.name }}
              </h3>
              <div class="flex gap-2">
                <button @click="window.print()" class="px-3 py-1 bg-accent text-white rounded-lg hover:bg-accent/80 text-sm">
                  🖨️ Print
                </button>
                <button @click="showDescendantModal = false" class="text-theme-secondary hover:text-theme-primary">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
            </div>

            <div v-if="loadingReport" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Generating descendant report...</p>
            </div>

            <div v-else-if="descendantData">
              <!-- Summary -->
              <div class="bg-theme-tertiary rounded-lg p-4 mb-4">
                <div class="flex items-center gap-4">
                  <div class="text-center">
                    <div class="text-2xl font-bold text-accent">{{ descendantData.total_descendants }}</div>
                    <div class="text-xs text-theme-secondary">Total Descendants</div>
                  </div>
                </div>
              </div>

              <!-- Descendant Tree -->
              <div class="space-y-2">
                <div v-for="family in descendantData.descendants" :key="family.family_id" class="border-l-2 border-accent/30 pl-4">
                  <div v-if="family.spouse" class="text-sm text-theme-secondary mb-2">
                    m. {{ family.spouse.name }} {{ family.marriage_date ? `(${family.marriage_date})` : '' }}
                  </div>
                  <div v-for="child in family.children" :key="child.id" class="mb-2">
                    <div class="bg-theme-tertiary rounded p-2">
                      <div class="font-medium text-theme-primary">{{ child.name }}</div>
                      <div class="text-xs text-theme-secondary">
                        {{ child.birth_date || '?' }} - {{ child.death_date || '?' }}
                      </div>
                    </div>
                    <!-- Recursive children display would go here -->
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showDescendantModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 6: Missing Data Modal -->
      <div v-if="showMissingDataModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showMissingDataModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Missing Data Report</h3>
              <button @click="showMissingDataModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div v-if="loadingReport" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Analyzing tree data...</p>
            </div>

            <div v-else-if="missingDataReport" class="space-y-6">
              <!-- Summary Cards -->
              <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold" :class="missingDataReport.missing_birth_date.count > 0 ? 'text-red-400' : 'text-green-400'">
                    {{ missingDataReport.missing_birth_date.count }}
                  </div>
                  <div class="text-sm text-theme-secondary">Missing Birth Date</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold" :class="missingDataReport.missing_birth_place.count > 0 ? 'text-amber-400' : 'text-green-400'">
                    {{ missingDataReport.missing_birth_place.count }}
                  </div>
                  <div class="text-sm text-theme-secondary">Missing Birth Place</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold" :class="missingDataReport.missing_death_date.count > 0 ? 'text-amber-400' : 'text-green-400'">
                    {{ missingDataReport.missing_death_date.count }}
                  </div>
                  <div class="text-sm text-theme-secondary">Missing Death Date</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold" :class="missingDataReport.missing_parents.count > 0 ? 'text-blue-400' : 'text-green-400'">
                    {{ missingDataReport.missing_parents.count }}
                  </div>
                  <div class="text-sm text-theme-secondary">No Parents Linked</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold" :class="missingDataReport.no_media.count > 0 ? 'text-purple-400' : 'text-green-400'">
                    {{ missingDataReport.no_media.count }}
                  </div>
                  <div class="text-sm text-theme-secondary">No Photos</div>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-2xl font-bold" :class="missingDataReport.missing_marriage_date.count > 0 ? 'text-pink-400' : 'text-green-400'">
                    {{ missingDataReport.missing_marriage_date.count }}
                  </div>
                  <div class="text-sm text-theme-secondary">Missing Marriage Date</div>
                </div>
              </div>

              <!-- Detailed Lists -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div v-if="missingDataReport.missing_birth_date.persons.length > 0" class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-2">Missing Birth Date</h4>
                  <div class="space-y-1 max-h-40 overflow-y-auto">
                    <div v-for="person in missingDataReport.missing_birth_date.persons.slice(0, 20)" :key="person.id" class="text-sm text-theme-secondary">
                      {{ person.name }}
                    </div>
                    <div v-if="missingDataReport.missing_birth_date.persons.length > 20" class="text-xs text-theme-secondary italic">
                      ...and {{ missingDataReport.missing_birth_date.persons.length - 20 }} more
                    </div>
                  </div>
                </div>

                <div v-if="missingDataReport.missing_parents.persons.length > 0" class="bg-theme-tertiary rounded-lg p-4">
                  <h4 class="font-medium text-theme-primary mb-2">No Parents Linked</h4>
                  <div class="space-y-1 max-h-40 overflow-y-auto">
                    <div v-for="person in missingDataReport.missing_parents.persons.slice(0, 20)" :key="person.id" class="text-sm text-theme-secondary">
                      {{ person.name }}
                    </div>
                    <div v-if="missingDataReport.missing_parents.persons.length > 20" class="text-xs text-theme-secondary italic">
                      ...and {{ missingDataReport.missing_parents.persons.length - 20 }} more
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showMissingDataModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 6: Individual Summary Modal -->
      <div v-if="showIndividualSummaryModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showIndividualSummaryModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">
                Individual Summary: {{ individualSummaryData?.person?.name }}
              </h3>
              <div class="flex gap-2">
                <button @click="window.print()" class="px-3 py-1 bg-accent text-white rounded-lg hover:bg-accent/80 text-sm">
                  🖨️ Print
                </button>
                <button @click="showIndividualSummaryModal = false" class="text-theme-secondary hover:text-theme-primary">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
            </div>

            <div v-if="loadingReport" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Generating summary...</p>
            </div>

            <div v-else-if="individualSummaryData" class="space-y-6">
              <!-- Basic Info -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3">Vital Information</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span class="text-theme-secondary">Birth:</span>
                    <span class="text-theme-primary ml-2">{{ individualSummaryData.person.birth_date || 'Unknown' }}</span>
                    <span v-if="individualSummaryData.person.birth_place" class="text-theme-secondary block ml-4">{{ individualSummaryData.person.birth_place }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Death:</span>
                    <span class="text-theme-primary ml-2">{{ individualSummaryData.person.death_date || (individualSummaryData.person.living ? 'Living' : 'Unknown') }}</span>
                    <span v-if="individualSummaryData.person.death_place" class="text-theme-secondary block ml-4">{{ individualSummaryData.person.death_place }}</span>
                  </div>
                </div>
              </div>

              <!-- Parents -->
              <div v-if="individualSummaryData.parents" class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3">Parents</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span class="text-theme-secondary">Father:</span>
                    <span class="text-theme-primary ml-2">{{ individualSummaryData.parents.father?.name || 'Unknown' }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Mother:</span>
                    <span class="text-theme-primary ml-2">{{ individualSummaryData.parents.mother?.name || 'Unknown' }}</span>
                  </div>
                </div>
              </div>

              <!-- Spouses -->
              <div v-if="individualSummaryData.spouses?.length > 0" class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3">Marriages</h4>
                <div v-for="spouse in individualSummaryData.spouses" :key="spouse.family_id" class="text-sm mb-2">
                  <span class="text-theme-primary">{{ spouse.name || 'Unknown' }}</span>
                  <span v-if="spouse.marriage_date" class="text-theme-secondary ml-2">m. {{ spouse.marriage_date }}</span>
                  <span v-if="spouse.marriage_place" class="text-theme-secondary block ml-4">{{ spouse.marriage_place }}</span>
                </div>
              </div>

              <!-- Children -->
              <div v-if="individualSummaryData.children?.length > 0" class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3">Children ({{ individualSummaryData.children.length }})</h4>
                <div class="space-y-1">
                  <div v-for="child in individualSummaryData.children" :key="child.id" class="text-sm">
                    <span class="text-theme-primary">{{ child.name }}</span>
                    <span v-if="child.birth_date" class="text-theme-secondary ml-2">(b. {{ child.birth_date }})</span>
                  </div>
                </div>
              </div>

              <!-- Siblings -->
              <div v-if="individualSummaryData.siblings?.length > 0" class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3">Siblings ({{ individualSummaryData.siblings.length }})</h4>
                <div class="space-y-1">
                  <div v-for="sibling in individualSummaryData.siblings" :key="sibling.id" class="text-sm">
                    <span class="text-theme-primary">{{ sibling.name }}</span>
                    <span v-if="sibling.birth_date" class="text-theme-secondary ml-2">(b. {{ sibling.birth_date }})</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showIndividualSummaryModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 6: Family Group Sheet Modal -->
      <div v-if="showFamilyGroupSheetModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showFamilyGroupSheetModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto print:max-w-full print:shadow-none">
          <div class="p-6 print:p-2">
            <div class="flex justify-between items-center mb-4 print:hidden">
              <h3 class="text-xl font-semibold text-theme-primary">
                Family Group Sheet
              </h3>
              <div class="flex gap-2">
                <button @click="window.print()" class="px-3 py-1 bg-accent text-white rounded-lg hover:bg-accent/80 text-sm">
                  Print
                </button>
                <button @click="showFamilyGroupSheetModal = false" class="text-theme-secondary hover:text-theme-primary">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
            </div>

            <div v-if="loadingReport" class="text-center py-8">
              <div class="animate-spin w-8 h-8 border-4 border-accent border-t-transparent rounded-full mx-auto mb-4"></div>
              <p class="text-theme-secondary">Generating family group sheet...</p>
            </div>

            <div v-else-if="familyGroupSheetData" class="space-y-6">
              <!-- Husband/Father -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3 flex items-center gap-2">
                  <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                  </svg>
                  Husband/Father
                </h4>
                <div v-if="familyGroupSheetData.husband" class="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span class="text-theme-secondary">Name:</span>
                    <span class="text-theme-primary ml-2 font-medium">{{ familyGroupSheetData.husband.name }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Birth:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.husband.birth_date || 'Unknown' }}</span>
                    <span v-if="familyGroupSheetData.husband.birth_place" class="text-theme-secondary block ml-4">{{ familyGroupSheetData.husband.birth_place }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Death:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.husband.death_date || 'Living/Unknown' }}</span>
                    <span v-if="familyGroupSheetData.husband.death_place" class="text-theme-secondary block ml-4">{{ familyGroupSheetData.husband.death_place }}</span>
                  </div>
                  <div v-if="familyGroupSheetData.husband.occupation">
                    <span class="text-theme-secondary">Occupation:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.husband.occupation }}</span>
                  </div>
                </div>
                <p v-else class="text-theme-secondary text-sm">No husband/father recorded</p>
              </div>

              <!-- Wife/Mother -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3 flex items-center gap-2">
                  <svg class="w-5 h-5 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                  </svg>
                  Wife/Mother
                </h4>
                <div v-if="familyGroupSheetData.wife" class="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span class="text-theme-secondary">Name:</span>
                    <span class="text-theme-primary ml-2 font-medium">{{ familyGroupSheetData.wife.name }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Birth:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.wife.birth_date || 'Unknown' }}</span>
                    <span v-if="familyGroupSheetData.wife.birth_place" class="text-theme-secondary block ml-4">{{ familyGroupSheetData.wife.birth_place }}</span>
                  </div>
                  <div>
                    <span class="text-theme-secondary">Death:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.wife.death_date || 'Living/Unknown' }}</span>
                    <span v-if="familyGroupSheetData.wife.death_place" class="text-theme-secondary block ml-4">{{ familyGroupSheetData.wife.death_place }}</span>
                  </div>
                  <div v-if="familyGroupSheetData.wife.occupation">
                    <span class="text-theme-secondary">Occupation:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.wife.occupation }}</span>
                  </div>
                </div>
                <p v-else class="text-theme-secondary text-sm">No wife/mother recorded</p>
              </div>

              <!-- Marriage Information -->
              <div v-if="familyGroupSheetData.marriage_date || familyGroupSheetData.marriage_place" class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3 flex items-center gap-2">
                  <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                  </svg>
                  Marriage
                </h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                  <div v-if="familyGroupSheetData.marriage_date">
                    <span class="text-theme-secondary">Date:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.marriage_date }}</span>
                  </div>
                  <div v-if="familyGroupSheetData.marriage_place">
                    <span class="text-theme-secondary">Place:</span>
                    <span class="text-theme-primary ml-2">{{ familyGroupSheetData.marriage_place }}</span>
                  </div>
                </div>
              </div>

              <!-- Children -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-3 flex items-center gap-2">
                  <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                  </svg>
                  Children ({{ familyGroupSheetData.children?.length || 0 }})
                </h4>
                <div v-if="familyGroupSheetData.children?.length > 0" class="space-y-3">
                  <div v-for="(child, index) in familyGroupSheetData.children" :key="child.id" class="border-l-2 border-accent/30 pl-4">
                    <div class="flex items-start gap-2">
                      <span class="text-accent font-medium">{{ index + 1 }}.</span>
                      <div class="flex-1">
                        <div class="font-medium text-theme-primary">{{ child.name }}</div>
                        <div class="grid grid-cols-2 gap-x-4 text-sm text-theme-secondary">
                          <span v-if="child.sex">{{ child.sex === 'M' ? 'Male' : child.sex === 'F' ? 'Female' : child.sex }}</span>
                          <span v-if="child.birth_date">b. {{ child.birth_date }}</span>
                          <span v-if="child.birth_place">{{ child.birth_place }}</span>
                          <span v-if="child.death_date">d. {{ child.death_date }}</span>
                        </div>
                        <div v-if="child.spouse_name" class="text-sm text-theme-secondary mt-1">
                          Spouse: {{ child.spouse_name }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <p v-else class="text-theme-secondary text-sm">No children recorded</p>
              </div>
            </div>

            <div v-else class="text-center py-8 text-theme-secondary">
              <p>No family data available.</p>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme print:hidden">
              <button @click="showFamilyGroupSheetModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 7: Privacy Settings Modal -->
      <div v-if="showPrivacySettingsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showPrivacySettingsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Privacy Settings</h3>
              <button @click="showPrivacySettingsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div v-if="privacySettings" class="space-y-6">
              <!-- Tree Privacy Level -->
              <div>
                <label class="block text-sm font-medium text-theme-primary mb-2">Tree Visibility</label>
                <select v-model="privacySettings.privacy" class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary">
                  <option value="private">Private - Only you can see</option>
                  <option value="shared">Shared - Collaborators can see</option>
                  <option value="public">Public - Anyone can view</option>
                </select>
              </div>

              <!-- Living Person Privacy -->
              <div>
                <label class="block text-sm font-medium text-theme-primary mb-2">Living Person Privacy</label>
                <select v-model="privacySettings.living_privacy" class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary">
                  <option value="hide_all">Hide All - Only show "Living"</option>
                  <option value="hide_details">Hide Details - Show name, hide dates/places</option>
                  <option value="show_all">Show All - No restrictions</option>
                </select>
                <p class="text-xs text-theme-secondary mt-1">Controls how living persons appear to non-privileged viewers</p>
              </div>

              <!-- Living Years Threshold -->
              <div>
                <label class="block text-sm font-medium text-theme-primary mb-2">Living Detection Threshold (years)</label>
                <input
                  v-model.number="privacySettings.living_years_threshold"
                  type="number"
                  min="80"
                  max="150"
                  class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary"
                />
                <p class="text-xs text-theme-secondary mt-1">Persons born within this many years are assumed living (default: 100)</p>
              </div>

              <!-- Default Media Privacy -->
              <div>
                <label class="block text-sm font-medium text-theme-primary mb-2">Default Media Privacy</label>
                <select v-model="privacySettings.default_media_privacy" class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary">
                  <option value="private">Private</option>
                  <option value="shared">Shared with Collaborators</option>
                  <option value="public">Public</option>
                </select>
              </div>

              <!-- Allow Public Search -->
              <div class="flex items-center gap-3">
                <input
                  v-model="privacySettings.allow_public_search"
                  type="checkbox"
                  id="allowPublicSearch"
                  class="w-5 h-5 rounded bg-theme-tertiary border border-theme text-accent"
                />
                <label for="allowPublicSearch" class="text-theme-primary">Allow tree to appear in public search results</label>
              </div>
            </div>

            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-theme">
              <button @click="showPrivacySettingsModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Cancel
              </button>
              <button @click="savePrivacySettings" class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent/80">
                Save Settings
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 7: Collaborators Modal -->
      <div v-if="showCollaboratorsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showCollaboratorsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Manage Collaborators</h3>
              <button @click="showCollaboratorsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <!-- Current Collaborators -->
            <div class="mb-6">
              <h4 class="font-medium text-theme-primary mb-3">Current Collaborators</h4>
              <div v-if="collaborators.length === 0" class="text-theme-secondary italic">
                No collaborators yet
              </div>
              <div v-else class="space-y-2">
                <div v-for="collab in collaborators" :key="collab.id" class="flex items-center justify-between bg-theme-tertiary rounded-lg p-3">
                  <div>
                    <div class="text-theme-primary font-medium">{{ collab.user_name || collab.user_email }}</div>
                    <div class="text-xs text-theme-secondary">{{ collab.role }} - added {{ formatDate(collab.invited_at) }}</div>
                  </div>
                  <div class="flex gap-2">
                    <select v-model="collab.role" @change="updateCollaboratorRole(collab)" class="px-2 py-1 bg-theme-secondary border border-theme rounded text-sm text-theme-primary">
                      <option value="viewer">Viewer</option>
                      <option value="contributor">Contributor</option>
                      <option value="editor">Editor</option>
                      <option value="admin">Admin</option>
                    </select>
                    <button @click="removeCollaboratorConfirm(collab)" class="text-red-400 hover:text-red-300">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Pending Invitations -->
            <div class="mb-6">
              <h4 class="font-medium text-theme-primary mb-3">Pending Invitations</h4>
              <div v-if="pendingInvitations.length === 0" class="text-theme-secondary italic">
                No pending invitations
              </div>
              <div v-else class="space-y-2">
                <div v-for="inv in pendingInvitations" :key="inv.id" class="flex items-center justify-between bg-theme-tertiary rounded-lg p-3">
                  <div>
                    <div class="text-theme-primary">{{ inv.email }}</div>
                    <div class="text-xs text-theme-secondary">{{ inv.role }} - expires {{ formatDate(inv.expires_at) }}</div>
                  </div>
                  <button @click="cancelInvitationConfirm(inv)" class="text-red-400 hover:text-red-300 text-sm">
                    Cancel
                  </button>
                </div>
              </div>
            </div>

            <!-- Invite New -->
            <div class="border-t border-theme pt-4">
              <h4 class="font-medium text-theme-primary mb-3">Invite New Collaborator</h4>
              <div class="flex gap-3">
                <input
                  v-model="newInviteEmail"
                  type="email"
                  placeholder="Email address"
                  class="flex-1 px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary"
                />
                <select v-model="newInviteRole" class="px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary">
                  <option value="viewer">Viewer</option>
                  <option value="contributor">Contributor</option>
                  <option value="editor">Editor</option>
                  <option value="admin">Admin</option>
                </select>
                <button @click="sendInvitation" :disabled="!newInviteEmail" class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent/80 disabled:opacity-50">
                  Invite
                </button>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showCollaboratorsModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 7: Activity Log Modal -->
      <div v-if="showPersonActivityLogModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showPersonActivityLogModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Activity Log</h3>
              <button @click="showPersonActivityLogModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div v-if="activityLog.length === 0" class="text-center py-8 text-theme-secondary">
              No activity recorded yet
            </div>
            <div v-else class="space-y-3">
              <div v-for="activity in activityLog" :key="activity.id" class="bg-theme-tertiary rounded-lg p-3">
                <div class="flex justify-between items-start">
                  <div>
                    <span class="text-theme-primary font-medium">{{ activity.user_name || 'System' }}</span>
                    <span class="text-theme-secondary ml-2">{{ formatActivityAction(activity.action) }}</span>
                    <span v-if="activity.entity_type" class="text-accent ml-1">{{ activity.entity_type }}</span>
                  </div>
                  <span class="text-xs text-theme-secondary">{{ formatDate(activity.created_at) }}</span>
                </div>
                <div v-if="activity.new_values" class="text-xs text-theme-secondary mt-1">
                  {{ JSON.stringify(activity.new_values).substring(0, 100) }}
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showPersonActivityLogModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 7: Living Statistics Modal -->
      <div v-if="showLivingStatsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showLivingStatsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-semibold text-theme-primary">Living Status Statistics</h3>
              <button @click="showLivingStatsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div v-if="livingStats" class="space-y-4">
              <div class="grid grid-cols-2 gap-4">
                <div class="bg-theme-tertiary rounded-lg p-4 text-center">
                  <div class="text-3xl font-bold text-theme-primary">{{ livingStats.total }}</div>
                  <div class="text-sm text-theme-secondary">Total Persons</div>
                </div>
                <div class="bg-green-500/20 rounded-lg p-4 text-center">
                  <div class="text-3xl font-bold text-green-400">{{ livingStats.living_explicit }}</div>
                  <div class="text-sm text-green-400/70">Marked Living</div>
                </div>
                <div class="bg-gray-500/20 rounded-lg p-4 text-center">
                  <div class="text-3xl font-bold text-gray-400">{{ livingStats.deceased_explicit }}</div>
                  <div class="text-sm text-gray-400/70">Marked Deceased</div>
                </div>
                <div class="bg-yellow-500/20 rounded-lg p-4 text-center">
                  <div class="text-3xl font-bold text-yellow-400">{{ livingStats.unknown_status }}</div>
                  <div class="text-sm text-yellow-400/70">Unknown Status</div>
                </div>
              </div>

              <div class="border-t border-theme pt-4 space-y-2">
                <div class="flex justify-between text-sm">
                  <span class="text-theme-secondary">Has death date:</span>
                  <span class="text-theme-primary">{{ livingStats.has_death_date }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-theme-secondary">Privacy: Public override:</span>
                  <span class="text-theme-primary">{{ livingStats.privacy_public }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span class="text-theme-secondary">Privacy: Private override:</span>
                  <span class="text-theme-primary">{{ livingStats.privacy_private }}</span>
                </div>
              </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t border-theme">
              <button @click="showLivingStatsModal = false" class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded-lg hover:bg-theme-primary hover:text-white">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 8: Research Hints Modal -->
      <div v-if="showResearchHintsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showResearchHintsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[85vh] flex flex-col">
          <div class="p-6 border-b border-theme flex-shrink-0">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">Research Hints</h3>
              <button @click="showResearchHintsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div class="flex items-center gap-4 mt-4">
              <select v-model="researchHintsFilter" @change="loadResearchHints" class="px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary">
                <option value="pending">Pending</option>
                <option value="accepted">Accepted</option>
                <option value="rejected">Rejected</option>
                <option value="deferred">Deferred</option>
                <option value="all">All</option>
              </select>
              <button
                @click="generateResearchHints"
                :disabled="generatingHints"
                class="px-4 py-2 bg-amber-500/20 text-amber-400 rounded-lg hover:bg-amber-500/30 disabled:opacity-50"
              >
                {{ generatingHints ? 'Generating...' : 'Generate Hints' }}
              </button>
            </div>
          </div>

          <div class="p-6 overflow-y-auto flex-1">
            <div v-if="loadingResearchHints" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="researchHints.length === 0" class="text-center py-8 text-theme-secondary">
              No research hints found. Click "Generate Hints" to analyze your tree.
            </div>

            <div v-else class="space-y-3">
              <div v-for="hint in researchHints" :key="hint.id" class="bg-theme-tertiary rounded-lg p-4">
                <div class="flex items-start gap-3">
                  <span :class="['px-2 py-1 rounded text-xs font-medium text-white', getHintTypeColor(hint.hint_type)]">
                    {{ getHintTypeLabel(hint.hint_type) }}
                  </span>
                  <div class="flex-1">
                    <h4 class="font-medium text-theme-primary">{{ hint.title }}</h4>
                    <p v-if="hint.person_name" class="text-sm text-theme-secondary mt-1">
                      Person: {{ hint.person_name }}
                    </p>
                    <p v-if="hint.description" class="text-sm text-theme-secondary mt-1">
                      {{ hint.description }}
                    </p>
                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                      <span class="text-xs text-theme-secondary">Confidence: {{ Math.round(hint.confidence * 100) }}%</span>
                      <span v-if="hint.record_source" class="px-2 py-0.5 rounded text-xs font-medium bg-blue-500/20 text-blue-400">
                        {{ hint.record_source }}
                      </span>
                      <span v-if="hint.suggested_record_type" class="px-2 py-0.5 rounded text-xs bg-purple-500/20 text-purple-400">
                        {{ hint.suggested_record_type }}
                      </span>
                      <a v-if="hint.record_url" :href="hint.record_url" target="_blank" rel="noopener"
                         class="text-xs text-accent hover:underline">
                        View Record
                      </a>
                    </div>
                    <div v-if="hint.matching_criteria && Object.keys(hint.matching_criteria).length" class="mt-2 flex gap-1 flex-wrap">
                      <span v-for="(val, key) in hint.matching_criteria" :key="key"
                            class="px-1.5 py-0.5 rounded text-xs bg-green-500/10 text-green-400 border border-green-500/20">
                        {{ formatMatchCriteria(key) }}
                      </span>
                    </div>
                  </div>
                  <div v-if="hint.status === 'pending'" class="flex gap-2">
                    <button @click="updateHintStatus(hint, 'accepted')" class="p-1 text-green-400 hover:bg-green-500/20 rounded" title="Accept">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                      </svg>
                    </button>
                    <button @click="updateHintStatus(hint, 'rejected')" class="p-1 text-red-400 hover:bg-red-500/20 rounded" title="Reject">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                    <button @click="updateHintStatus(hint, 'deferred')" class="p-1 text-yellow-400 hover:bg-yellow-500/20 rounded" title="Defer">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                      </svg>
                    </button>
                  </div>
                  <span v-else :class="['px-2 py-1 rounded text-xs', hint.status === 'accepted' ? 'bg-green-500/20 text-green-400' : hint.status === 'rejected' ? 'bg-red-500/20 text-red-400' : 'bg-yellow-500/20 text-yellow-400']">
                    {{ hint.status }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- AI Research Modal (Sprint 1: A.1) -->
      <div v-if="showAIResearchModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showAIResearchModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] flex flex-col">
          <div class="p-6 border-b border-theme flex-shrink-0">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary flex items-center gap-2">
                <span class="text-2xl">🤖</span>
                AI Genealogy Research
              </h3>
              <button @click="showAIResearchModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
            <p v-if="aiResearchPersonName" class="text-theme-secondary mt-2">
              Researching: <span class="text-theme-primary font-medium">{{ aiResearchPersonName }}</span>
            </p>

            <!-- Mode Toggle -->
            <div class="flex items-center gap-4 mt-4">
              <button
                @click="aiResearchMode = 'research'; runAIResearch()"
                :class="['px-4 py-2 rounded-lg text-sm font-medium transition-colors', aiResearchMode === 'research' ? 'bg-violet-600 text-white' : 'bg-theme-tertiary text-theme-secondary hover:text-theme-primary']"
                :disabled="aiResearchLoading"
              >
                📚 Research Strategy
              </button>
              <button
                @click="aiResearchMode = 'brick-wall'; runBrickWallAnalysis()"
                :class="['px-4 py-2 rounded-lg text-sm font-medium transition-colors', aiResearchMode === 'brick-wall' ? 'bg-amber-600 text-white' : 'bg-theme-tertiary text-theme-secondary hover:text-theme-primary']"
                :disabled="aiResearchLoading"
              >
                🧱 Brick Wall Analysis
              </button>
            </div>
          </div>

          <div class="p-6 overflow-y-auto flex-1">
            <!-- Loading State -->
            <div v-if="aiResearchLoading" class="flex flex-col items-center justify-center py-12">
              <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-violet-500 mb-4"></div>
              <p class="text-theme-secondary">
                {{ aiResearchMode === 'research' ? 'Analyzing genealogical records...' : 'Identifying brick wall solutions...' }}
              </p>
              <p class="text-theme-secondary text-sm mt-2">This may take a moment as the AI reviews historical context.</p>
            </div>

            <!-- Error State -->
            <div v-else-if="aiResearchError" class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
              <h4 class="font-medium text-red-400 mb-2">Error</h4>
              <p class="text-theme-secondary">{{ aiResearchError }}</p>
              <button
                @click="aiResearchMode === 'research' ? runAIResearch() : runBrickWallAnalysis()"
                class="mt-3 px-4 py-2 bg-red-500/20 text-red-400 rounded-lg hover:bg-red-500/30"
              >
                Try Again
              </button>
            </div>

            <!-- Results -->
            <div v-else-if="aiResearchResult" class="space-y-4">
              <!-- Person Context -->
              <div v-if="aiResearchResult.person" class="bg-theme-tertiary rounded-lg p-4">
                <h4 class="font-medium text-theme-primary mb-2">Person Context</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                  <div v-if="aiResearchResult.person.birth_date">
                    <span class="text-theme-secondary">Birth:</span>
                    <span class="text-theme-primary ml-2">{{ aiResearchResult.person.birth_date }}</span>
                    <span v-if="aiResearchResult.person.birth_place" class="text-theme-secondary ml-1">{{ aiResearchResult.person.birth_place }}</span>
                  </div>
                  <div v-if="aiResearchResult.person.death_date">
                    <span class="text-theme-secondary">Death:</span>
                    <span class="text-theme-primary ml-2">{{ aiResearchResult.person.death_date }}</span>
                    <span v-if="aiResearchResult.person.death_place" class="text-theme-secondary ml-1">{{ aiResearchResult.person.death_place }}</span>
                  </div>
                </div>
              </div>

              <!-- AI Response -->
              <div class="bg-theme-tertiary rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                  <h4 class="font-medium text-theme-primary flex items-center gap-2">
                    <span v-if="aiResearchMode === 'research'">📚 Research Strategy</span>
                    <span v-else>🧱 Brick Wall Solutions</span>
                  </h4>
                  <div class="flex gap-2">
                    <button
                      @click="copyToClipboard(getResearchContent())"
                      class="px-3 py-1 bg-theme-secondary text-theme-primary rounded text-sm hover:bg-theme-primary hover:text-theme-secondary transition-colors"
                      title="Copy to clipboard"
                    >
                      📋 Copy
                    </button>
                    <button
                      @click="saveResearchToNotes"
                      class="px-3 py-1 bg-violet-600 text-white rounded text-sm hover:bg-violet-500 transition-colors"
                      title="Save research to person's notes"
                    >
                      💾 Save to Notes
                    </button>
                    <button
                      @click="extractResearchData"
                      :disabled="extractingResearchData"
                      class="px-3 py-1 bg-emerald-600 text-white rounded text-sm hover:bg-emerald-500 transition-colors disabled:opacity-50"
                      title="Extract data to update person fields"
                    >
                      <span v-if="extractingResearchData">⏳ Extracting...</span>
                      <span v-else>🎯 Extract & Apply Data</span>
                    </button>
                  </div>
                </div>
                <div class="prose prose-sm prose-invert max-w-none text-theme-primary whitespace-pre-wrap leading-relaxed max-h-[60vh] overflow-y-auto">
                  {{ getResearchContent() }}
                </div>
              </div>

              <!-- Extracted Data Review Panel -->
              <div v-if="extractedResearchItems.length > 0" class="bg-emerald-900/30 border border-emerald-600 rounded-lg p-4 mt-4">
                <div class="flex items-center justify-between mb-3">
                  <h4 class="font-medium text-emerald-400 flex items-center gap-2">
                    🎯 Extracted Data ({{ extractedResearchItems.length }} items)
                  </h4>
                  <div class="flex gap-2">
                    <button
                      @click="selectAllExtractedItems"
                      class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-theme-primary hover:text-theme-secondary"
                    >
                      Select All
                    </button>
                    <button
                      @click="deselectAllExtractedItems"
                      class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-theme-primary hover:text-theme-secondary"
                    >
                      Deselect All
                    </button>
                  </div>
                </div>

                <div class="space-y-2 max-h-[40vh] overflow-y-auto">
                  <div
                    v-for="(item, index) in extractedResearchItems"
                    :key="index"
                    class="flex items-start gap-3 p-3 bg-theme-tertiary rounded-lg"
                  >
                    <input
                      type="checkbox"
                      v-model="item.selected"
                      class="mt-1 h-4 w-4 rounded border-gray-600"
                    />
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2 mb-1">
                        <span class="font-medium text-theme-primary">{{ item.field_label }}</span>
                        <span
                          :class="{
                            'bg-green-600': item.confidence === 'high',
                            'bg-yellow-600': item.confidence === 'medium',
                            'bg-red-600': item.confidence === 'low'
                          }"
                          class="px-2 py-0.5 text-xs rounded text-white"
                        >
                          {{ item.confidence }}
                        </span>
                        <span
                          :class="{
                            'bg-blue-600': item.action === 'add',
                            'bg-orange-600': item.action === 'update',
                            'bg-purple-600': item.action === 'note'
                          }"
                          class="px-2 py-0.5 text-xs rounded text-white"
                        >
                          {{ item.action }}
                        </span>
                      </div>
                      <div class="text-sm">
                        <span v-if="item.current_value" class="text-red-400 line-through mr-2">{{ item.current_value }}</span>
                        <span class="text-emerald-400">{{ item.value }}</span>
                      </div>
                      <div class="text-xs text-theme-secondary mt-1 italic">
                        Source: {{ item.source }}
                      </div>
                    </div>
                  </div>
                </div>

                <div class="flex justify-end gap-2 mt-4 pt-3 border-t border-emerald-600/30">
                  <button
                    @click="extractedResearchItems = []"
                    class="px-4 py-2 bg-theme-tertiary text-theme-primary rounded hover:bg-theme-primary hover:text-theme-secondary"
                  >
                    Cancel
                  </button>
                  <button
                    @click="applySelectedExtractedItems"
                    :disabled="!hasSelectedExtractedItems || applyingResearchData"
                    class="px-4 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-500 disabled:opacity-50"
                  >
                    <span v-if="applyingResearchData">⏳ Applying...</span>
                    <span v-else>✅ Apply {{ selectedExtractedItemsCount }} Selected</span>
                  </button>
                </div>
              </div>

              <!-- Token Usage -->
              <div v-if="aiResearchResult.tokens_used" class="text-xs text-theme-secondary text-right">
                Tokens used: {{ aiResearchResult.tokens_used }}
              </div>
            </div>

            <!-- Initial State -->
            <div v-else class="text-center py-12">
              <div class="text-6xl mb-4">🔍</div>
              <h4 class="text-lg font-medium text-theme-primary mb-2">Ready to Research</h4>
              <p class="text-theme-secondary mb-6">
                Choose a research mode above to get AI-powered genealogical insights.
              </p>
              <div class="grid grid-cols-2 gap-4 max-w-lg mx-auto text-left">
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h5 class="font-medium text-violet-400 mb-2">📚 Research Strategy</h5>
                  <p class="text-theme-secondary text-sm">Get a professional research plan with specific records to check, name variations, and historical context.</p>
                </div>
                <div class="bg-theme-tertiary rounded-lg p-4">
                  <h5 class="font-medium text-amber-400 mb-2">🧱 Brick Wall Analysis</h5>
                  <p class="text-theme-secondary text-sm">Identify gaps in your research and get targeted strategies to break through genealogical dead ends.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 8: Name Variations Modal -->
      <div v-if="showNameVariationsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showNameVariationsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[85vh] flex flex-col">
          <div class="p-6 border-b border-theme flex-shrink-0">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">Name Variations</h3>
              <button @click="showNameVariationsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6 overflow-y-auto flex-1">
            <!-- Add new variation -->
            <div class="bg-theme-tertiary rounded-lg p-4 mb-6">
              <h4 class="font-medium text-theme-primary mb-3">Add Variation</h4>
              <div class="grid grid-cols-2 gap-4 mb-3">
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Original Name</label>
                  <input v-model="newVariationName" type="text" class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary" placeholder="e.g., Schmidt" />
                </div>
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Name Type</label>
                  <select v-model="newVariationType" class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary">
                    <option value="surname">Surname</option>
                    <option value="given">Given Name</option>
                  </select>
                </div>
              </div>

              <div class="flex gap-2 mb-3">
                <input v-model="newVariationValue" type="text" class="flex-1 px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary" placeholder="Enter variation manually" />
                <button @click="addNameVariation()" :disabled="!newVariationName || !newVariationValue" class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-blue disabled:opacity-50">
                  Add
                </button>
              </div>

              <div class="flex gap-2">
                <button @click="generateNameSuggestions" :disabled="!newVariationName || loadingSuggestions" class="px-4 py-2 bg-violet-500/20 text-violet-400 rounded-lg hover:bg-violet-500/30 disabled:opacity-50">
                  {{ loadingSuggestions ? 'Generating...' : 'Get AI Suggestions' }}
                </button>
              </div>

              <!-- AI Suggestions -->
              <div v-if="suggestedVariations.length > 0" class="mt-4">
                <h5 class="text-sm font-medium text-theme-primary mb-2">Suggested Variations:</h5>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-for="(variation, index) in suggestedVariations"
                    :key="index"
                    @click="addNameVariation(variation)"
                    class="px-3 py-1 bg-violet-500/20 text-violet-400 rounded-lg hover:bg-violet-500/30 text-sm"
                  >
                    {{ variation.variation }} ({{ Math.round(variation.confidence * 100) }}%)
                  </button>
                </div>
              </div>
            </div>

            <!-- Existing variations -->
            <div v-if="loadingNameVariations" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="nameVariations.length === 0" class="text-center py-8 text-theme-secondary">
              No name variations saved yet.
            </div>

            <div v-else>
              <h4 class="font-medium text-theme-primary mb-3">Saved Variations</h4>
              <div class="space-y-2">
                <div v-for="variation in nameVariations" :key="variation.id" class="flex items-center justify-between bg-theme-tertiary rounded-lg p-3">
                  <div class="flex items-center gap-3">
                    <span :class="['px-2 py-1 rounded text-xs', variation.name_type === 'surname' ? 'bg-blue-500/20 text-blue-400' : 'bg-green-500/20 text-green-400']">
                      {{ variation.name_type }}
                    </span>
                    <span class="text-theme-primary">{{ variation.original_name }}</span>
                    <span class="text-theme-secondary">→</span>
                    <span class="text-theme-primary">{{ variation.variation }}</span>
                    <span v-if="variation.is_ai_generated" class="text-xs text-violet-400">(AI)</span>
                  </div>
                  <button @click="deleteNameVariation(variation)" class="p-1 text-red-400 hover:bg-red-500/20 rounded">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 8: Research Tasks Modal -->
      <div v-if="showResearchTasksModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showResearchTasksModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[85vh] flex flex-col">
          <div class="p-6 border-b border-theme flex-shrink-0">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">Research Tasks</h3>
              <button @click="showResearchTasksModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6 overflow-y-auto flex-1">
            <div v-if="loadingResearchTasks" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="researchTasks.length === 0" class="text-center py-8 text-theme-secondary">
              No research tasks queued.
            </div>

            <div v-else class="space-y-3">
              <div v-for="task in researchTasks" :key="task.id" class="bg-theme-tertiary rounded-lg p-4">
                <div class="flex items-start justify-between">
                  <div>
                    <div class="flex items-center gap-2">
                      <span :class="['px-2 py-1 rounded text-xs text-white', getTaskStatusColor(task.status)]">
                        {{ task.status }}
                      </span>
                      <span class="px-2 py-1 rounded text-xs bg-theme-secondary text-theme-primary">
                        {{ task.priority }}
                      </span>
                    </div>
                    <h4 class="font-medium text-theme-primary mt-2">{{ getTaskTypeLabel(task.task_type) }}</h4>
                    <p v-if="task.person_name" class="text-sm text-theme-secondary">
                      Person: {{ task.person_name }}
                    </p>
                  </div>
                  <div class="text-right text-sm text-theme-secondary">
                    <div>Created: {{ formatDate(task.created_at) }}</div>
                    <div v-if="task.completed_at">Completed: {{ formatDate(task.completed_at) }}</div>
                  </div>
                </div>
                <div v-if="task.error_message" class="mt-2 p-2 bg-red-500/10 rounded text-sm text-red-400">
                  {{ task.error_message }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 8: Research Statistics Modal -->
      <div v-if="showResearchStatsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showResearchStatsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4">
          <div class="p-6 border-b border-theme">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">Research Statistics</h3>
              <button @click="showResearchStatsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6">
            <div v-if="loadingResearchStats" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="researchStats" class="space-y-6">
              <!-- Hints -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">Research Hints</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total:</span>
                    <span class="text-theme-primary">{{ researchStats.hints.total }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Pending:</span>
                    <span class="text-amber-400">{{ researchStats.hints.pending }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Accepted:</span>
                    <span class="text-green-400">{{ researchStats.hints.accepted }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Rejected:</span>
                    <span class="text-red-400">{{ researchStats.hints.rejected }}</span>
                  </div>
                </div>
              </div>

              <!-- Name Variations -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">Name Variations</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total:</span>
                    <span class="text-theme-primary">{{ researchStats.name_variations.total }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Unique Names:</span>
                    <span class="text-theme-primary">{{ researchStats.name_variations.unique_names }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2 col-span-2">
                    <span class="text-theme-secondary">AI Generated:</span>
                    <span class="text-violet-400">{{ researchStats.name_variations.ai_generated }}</span>
                  </div>
                </div>
              </div>

              <!-- Tasks -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">Research Tasks</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total:</span>
                    <span class="text-theme-primary">{{ researchStats.tasks.total }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Queued:</span>
                    <span class="text-cyan-400">{{ researchStats.tasks.queued }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2 col-span-2">
                    <span class="text-theme-secondary">Completed:</span>
                    <span class="text-green-400">{{ researchStats.tasks.completed }}</span>
                  </div>
                </div>
              </div>

              <!-- Matches -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">Smart Matches</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total:</span>
                    <span class="text-theme-primary">{{ researchStats.matches.total }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Pending:</span>
                    <span class="text-amber-400">{{ researchStats.matches.pending }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 9: External Connections Modal -->
      <div v-if="showExternalConnectionsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showExternalConnectionsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6 border-b border-theme">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">External Service Connections</h3>
              <button @click="showExternalConnectionsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6">
            <!-- Add New Connection -->
            <div class="mb-6 p-4 bg-theme-tertiary rounded-lg">
              <h4 class="font-medium text-theme-primary mb-3">{{ editingConnection ? 'Edit Connection' : 'Add New Connection' }}</h4>
              <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Service</label>
                  <select
                    v-model="newConnection.service_type"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    :disabled="editingConnection !== null"
                  >
                    <option value="findmypast">FindMyPast</option>
                    <option value="myheritage">MyHeritage</option>
                    <option value="geneanet">Geneanet</option>
                    <option value="wikitree">WikiTree</option>
                    <option value="findagrave">Find A Grave</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Service User ID</label>
                  <input
                    v-model="newConnection.service_user_id"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Your ID on the service"
                  />
                </div>
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Access Token</label>
                  <input
                    v-model="newConnection.access_token"
                    type="password"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="API access token"
                  />
                </div>
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Refresh Token</label>
                  <input
                    v-model="newConnection.refresh_token"
                    type="password"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="API refresh token (optional)"
                  />
                </div>
              </div>
              <div class="flex gap-2">
                <button
                  @click="saveExternalConnection"
                  :disabled="savingExternalConnection"
                  class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent/80 disabled:opacity-50"
                >
                  {{ savingExternalConnection ? 'Saving...' : (editingConnection ? 'Update' : 'Add Connection') }}
                </button>
                <button
                  v-if="editingConnection"
                  @click="resetNewConnection"
                  class="px-4 py-2 bg-gray-500/20 text-theme-secondary rounded-lg hover:bg-gray-500/30"
                >
                  Cancel
                </button>
              </div>
            </div>

            <!-- Existing Connections -->
            <div v-if="loadingExternalConnections" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="externalConnections.length === 0" class="text-center py-8 text-theme-secondary">
              No external connections configured yet.
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="conn in externalConnections"
                :key="conn.id"
                class="flex items-center justify-between p-4 bg-theme-tertiary rounded-lg"
              >
                <div class="flex items-center gap-4">
                  <span :class="[getServiceColor(conn.service_type), 'px-3 py-1 rounded text-white text-sm font-medium']">
                    {{ getServiceLabel(conn.service_type) }}
                  </span>
                  <div>
                    <p class="text-theme-primary">{{ conn.service_user_id || 'No user ID' }}</p>
                    <p class="text-sm text-theme-secondary">
                      <span :class="[getConnectionStatusColor(conn.status), 'inline-block w-2 h-2 rounded-full mr-1']"></span>
                      {{ conn.status }}
                      <span v-if="conn.last_sync_at" class="ml-2">Last sync: {{ formatDate(conn.last_sync_at) }}</span>
                    </p>
                  </div>
                </div>
                <div class="flex gap-2">
                  <button
                    @click="openSyncHistoryModal(conn.id)"
                    class="px-3 py-1 bg-blue-500/20 text-blue-400 rounded hover:bg-blue-500/30 text-sm"
                  >
                    History
                  </button>
                  <button
                    @click="startSync(conn.id)"
                    :disabled="startingSync || conn.status !== 'active'"
                    class="px-3 py-1 bg-green-500/20 text-green-400 rounded hover:bg-green-500/30 text-sm disabled:opacity-50"
                  >
                    Sync
                  </button>
                  <button
                    @click="editConnection(conn)"
                    class="px-3 py-1 bg-amber-500/20 text-amber-400 rounded hover:bg-amber-500/30 text-sm"
                  >
                    Edit
                  </button>
                  <button
                    @click="deleteExternalConnection(conn.id)"
                    class="px-3 py-1 bg-red-500/20 text-red-400 rounded hover:bg-red-500/30 text-sm"
                  >
                    Delete
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 9: External Records Modal -->
      <div v-if="showExternalRecordsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showExternalRecordsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6 border-b border-theme">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">External Records</h3>
              <button @click="showExternalRecordsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6">
            <!-- Filter -->
            <div class="mb-4 flex gap-2">
              <button
                v-for="status in ['all', 'pending', 'matched', 'imported', 'rejected']"
                :key="status"
                @click="externalRecordsFilter = status; loadExternalRecords()"
                :class="[
                  'px-3 py-1 rounded text-sm',
                  externalRecordsFilter === status ? 'bg-accent text-white' : 'bg-theme-tertiary text-theme-secondary hover:bg-theme-tertiary/80'
                ]"
              >
                {{ status.charAt(0).toUpperCase() + status.slice(1) }}
              </button>
            </div>

            <div v-if="loadingExternalRecords" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="externalRecords.length === 0" class="text-center py-8 text-theme-secondary">
              No external records found.
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="record in externalRecords"
                :key="record.id"
                class="p-4 bg-theme-tertiary rounded-lg"
              >
                <div class="flex items-start justify-between mb-2">
                  <div class="flex items-center gap-2">
                    <span :class="[getServiceColor(record.service_type), 'px-2 py-0.5 rounded text-white text-xs font-medium']">
                      {{ getServiceLabel(record.service_type) }}
                    </span>
                    <span class="text-xs text-theme-secondary">{{ record.record_type }}</span>
                    <span :class="[getRecordStatusColor(record.status), 'px-2 py-0.5 rounded text-white text-xs']">
                      {{ record.status }}
                    </span>
                  </div>
                  <span class="text-sm text-theme-secondary">
                    {{ Math.round((record.match_confidence || 0) * 100) }}% confidence
                  </span>
                </div>
                <h4 class="text-theme-primary font-medium mb-1">{{ record.title || 'Untitled Record' }}</h4>
                <p class="text-sm text-theme-secondary mb-3">ID: {{ record.external_id }}</p>
                <div class="flex gap-2">
                  <button
                    v-if="record.status === 'pending'"
                    @click="updateExternalRecordStatus(record, 'matched')"
                    class="px-3 py-1 bg-blue-500/20 text-blue-400 rounded hover:bg-blue-500/30 text-sm"
                  >
                    Match
                  </button>
                  <button
                    v-if="record.status === 'matched'"
                    @click="updateExternalRecordStatus(record, 'imported')"
                    class="px-3 py-1 bg-green-500/20 text-green-400 rounded hover:bg-green-500/30 text-sm"
                  >
                    Import
                  </button>
                  <button
                    v-if="record.status !== 'rejected'"
                    @click="updateExternalRecordStatus(record, 'rejected')"
                    class="px-3 py-1 bg-red-500/20 text-red-400 rounded hover:bg-red-500/30 text-sm"
                  >
                    Reject
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 9: Person External Links Modal -->
      <div v-if="showPersonExternalLinksModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showPersonExternalLinksModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6 border-b border-theme">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">Person External Links</h3>
              <button @click="showPersonExternalLinksModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6">
            <!-- Add New Link -->
            <div class="mb-6 p-4 bg-theme-tertiary rounded-lg">
              <h4 class="font-medium text-theme-primary mb-3">Link to External Service</h4>
              <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Service</label>
                  <select
                    v-model="newPersonLink.service_type"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option value="familysearch">FamilySearch</option>
                    <option value="ancestry">Ancestry</option>
                    <option value="findmypast">FindMyPast</option>
                    <option value="myheritage">MyHeritage</option>
                    <option value="geneanet">Geneanet</option>
                    <option value="wikitree">WikiTree</option>
                    <option value="findagrave">Find A Grave</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">External Person ID</label>
                  <input
                    v-model="newPersonLink.external_person_id"
                    type="text"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Person ID on external service"
                  />
                </div>
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Link Type</label>
                  <select
                    v-model="newPersonLink.link_type"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option value="confirmed">Confirmed</option>
                    <option value="suggested">Suggested</option>
                  </select>
                </div>
                <div class="flex items-center">
                  <label class="flex items-center gap-2 text-theme-secondary">
                    <input
                      v-model="newPersonLink.sync_enabled"
                      type="checkbox"
                      class="rounded border-theme"
                    />
                    Enable Sync
                  </label>
                </div>
              </div>
              <button
                @click="linkPersonToService"
                :disabled="!newPersonLink.external_person_id"
                class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent/80 disabled:opacity-50"
              >
                Link Person
              </button>
            </div>

            <!-- Existing Links -->
            <div v-if="loadingPersonExternalLinks" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="personExternalLinks.length === 0" class="text-center py-8 text-theme-secondary">
              No external links for this person.
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="link in personExternalLinks"
                :key="link.service_type"
                class="flex items-center justify-between p-4 bg-theme-tertiary rounded-lg"
              >
                <div class="flex items-center gap-4">
                  <span :class="[getServiceColor(link.service_type), 'px-3 py-1 rounded text-white text-sm font-medium']">
                    {{ getServiceLabel(link.service_type) }}
                  </span>
                  <div>
                    <p class="text-theme-primary">{{ link.external_person_id }}</p>
                    <p class="text-sm text-theme-secondary">
                      <span :class="[getLinkTypeColor(link.link_type), 'inline-block w-2 h-2 rounded-full mr-1']"></span>
                      {{ getLinkTypeLabel(link.link_type) }}
                      <span v-if="link.sync_enabled" class="ml-2 text-green-400">Sync enabled</span>
                      <span v-else class="ml-2 text-gray-400">Sync disabled</span>
                    </p>
                  </div>
                </div>
                <button
                  @click="unlinkPersonFromService(link)"
                  class="px-3 py-1 bg-red-500/20 text-red-400 rounded hover:bg-red-500/30 text-sm"
                >
                  Unlink
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 9: Sync History Modal -->
      <div v-if="showSyncHistoryModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showSyncHistoryModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6 border-b border-theme">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">Sync History</h3>
              <button @click="showSyncHistoryModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6">
            <div class="mb-4">
              <button
                @click="startSync(selectedConnectionId, 'full')"
                :disabled="startingSync"
                class="px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent/80 disabled:opacity-50 mr-2"
              >
                {{ startingSync ? 'Starting...' : 'Start Full Sync' }}
              </button>
              <button
                @click="startSync(selectedConnectionId, 'incremental')"
                :disabled="startingSync"
                class="px-4 py-2 bg-green-500/20 text-green-400 rounded-lg hover:bg-green-500/30 disabled:opacity-50"
              >
                Start Incremental Sync
              </button>
            </div>

            <div v-if="loadingSyncHistory" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="syncHistory.length === 0" class="text-center py-8 text-theme-secondary">
              No sync history available.
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="sync in syncHistory"
                :key="sync.id"
                class="p-4 bg-theme-tertiary rounded-lg"
              >
                <div class="flex items-center justify-between mb-2">
                  <div class="flex items-center gap-2">
                    <span :class="[getSyncStatusColor(sync.status), 'px-2 py-0.5 rounded text-white text-xs']">
                      {{ sync.status }}
                    </span>
                    <span class="text-sm text-theme-secondary">{{ sync.sync_type }} / {{ sync.direction }}</span>
                  </div>
                  <span class="text-sm text-theme-secondary">
                    {{ sync.started_at ? formatDate(sync.started_at) : 'Not started' }}
                  </span>
                </div>
                <div class="grid grid-cols-5 gap-2 text-sm">
                  <div class="text-center p-2 bg-theme-secondary rounded">
                    <div class="text-theme-secondary">Found</div>
                    <div class="text-theme-primary font-medium">{{ sync.records_found || 0 }}</div>
                  </div>
                  <div class="text-center p-2 bg-theme-secondary rounded">
                    <div class="text-theme-secondary">Imported</div>
                    <div class="text-green-400 font-medium">{{ sync.records_imported || 0 }}</div>
                  </div>
                  <div class="text-center p-2 bg-theme-secondary rounded">
                    <div class="text-theme-secondary">Updated</div>
                    <div class="text-blue-400 font-medium">{{ sync.records_updated || 0 }}</div>
                  </div>
                  <div class="text-center p-2 bg-theme-secondary rounded">
                    <div class="text-theme-secondary">Skipped</div>
                    <div class="text-amber-400 font-medium">{{ sync.records_skipped || 0 }}</div>
                  </div>
                  <div class="text-center p-2 bg-theme-secondary rounded">
                    <div class="text-theme-secondary">Failed</div>
                    <div class="text-red-400 font-medium">{{ sync.records_failed || 0 }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Phase 9: External Integration Statistics Modal -->
      <div v-if="showExternalStatsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showExternalStatsModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4">
          <div class="p-6 border-b border-theme">
            <div class="flex justify-between items-center">
              <h3 class="text-xl font-bold text-theme-primary">Integration Statistics</h3>
              <button @click="showExternalStatsModal = false" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="p-6">
            <div v-if="loadingExternalStats" class="flex items-center justify-center py-8">
              <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="externalStats" class="space-y-6">
              <!-- Connections -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">Service Connections</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total:</span>
                    <span class="text-theme-primary">{{ externalStats.connections.total }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Active:</span>
                    <span class="text-green-400">{{ externalStats.connections.active }}</span>
                  </div>
                </div>
              </div>

              <!-- Records -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">External Records</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total:</span>
                    <span class="text-theme-primary">{{ externalStats.records.total }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Pending:</span>
                    <span class="text-amber-400">{{ externalStats.records.pending }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Imported:</span>
                    <span class="text-green-400">{{ externalStats.records.imported }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Rejected:</span>
                    <span class="text-red-400">{{ externalStats.records.rejected }}</span>
                  </div>
                </div>
              </div>

              <!-- Person Links -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">Person Links</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total:</span>
                    <span class="text-theme-primary">{{ externalStats.person_links.total }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Sync Enabled:</span>
                    <span class="text-blue-400">{{ externalStats.person_links.sync_enabled }}</span>
                  </div>
                </div>
              </div>

              <!-- Recent Syncs -->
              <div>
                <h4 class="font-medium text-theme-primary mb-2">Recent Syncs</h4>
                <div class="grid grid-cols-2 gap-2 text-sm">
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Last 24h:</span>
                    <span class="text-theme-primary">{{ externalStats.syncs.recent_count }}</span>
                  </div>
                  <div class="flex justify-between bg-theme-tertiary rounded p-2">
                    <span class="text-theme-secondary">Total Records:</span>
                    <span class="text-theme-primary">{{ externalStats.syncs.total_records_synced }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="text-center py-8 text-theme-secondary">
              No statistics available.
            </div>
          </div>
        </div>
      </div>

      <!-- Edit Person Modal (Tabbed) -->
      <div v-if="showEditPersonModal && editingPerson" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeEditPerson">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] flex flex-col">
          <!-- Header -->
          <div class="p-4 border-b border-theme flex justify-between items-center shrink-0">
            <h3 class="text-xl font-bold text-theme-primary">
              Edit: {{ editingPerson.given_name }} {{ editingPerson.surname }}
            </h3>
            <button @click="closeEditPerson" class="text-theme-secondary hover:text-theme-primary">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <!-- Tab Navigation -->
          <div class="flex border-b border-theme shrink-0">
            <button
              v-for="tab in [{id: 'basic', label: 'Basic Info'}, {id: 'events', label: 'Events'}, {id: 'residences', label: 'Residences'}, {id: 'media', label: 'Media'}, {id: 'sources', label: 'Sources'}]"
              :key="tab.id"
              @click="personEditTab = tab.id; tab.id === 'media' && loadAvailableMedia(); tab.id === 'sources' && loadAvailableSources()"
              :class="[
                'px-4 py-2 text-sm font-medium transition-colors',
                personEditTab === tab.id
                  ? 'text-accent border-b-2 border-accent'
                  : 'text-theme-secondary hover:text-theme-primary'
              ]"
            >
              {{ tab.label }}
              <span v-if="tab.id === 'events'" class="ml-1 text-xs">({{ personEvents.length }})</span>
              <span v-if="tab.id === 'residences'" class="ml-1 text-xs">({{ personResidences.length }})</span>
              <span v-if="tab.id === 'media'" class="ml-1 text-xs">({{ personMediaItems.length }})</span>
              <span v-if="tab.id === 'sources'" class="ml-1 text-xs">({{ personSources.length }})</span>
            </button>
          </div>

          <!-- Loading Indicator -->
          <div v-if="loadingPersonData" class="p-8 text-center">
            <div class="animate-spin w-8 h-8 border-2 border-accent border-t-transparent rounded-full mx-auto"></div>
            <p class="text-theme-secondary mt-2">Loading...</p>
          </div>

          <!-- Tab Content -->
          <div v-else class="flex-1 overflow-y-auto p-4">
            <!-- Basic Info Tab -->
            <div v-show="personEditTab === 'basic'">
              <form @submit.prevent="savePerson">
                <div class="space-y-4">
                  <!-- Name Fields -->
                  <div class="grid grid-cols-4 gap-4">
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Given Name(s)</label>
                      <input v-model="editingPerson.given_name" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="First and middle names" />
                    </div>
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Surname</label>
                      <input v-model="editingPerson.surname" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Family name" />
                    </div>
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Nickname</label>
                      <input v-model="editingPerson.nickname" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Known as" />
                    </div>
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Suffix</label>
                      <input v-model="editingPerson.suffix" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Jr., Sr., III" />
                    </div>
                  </div>

                  <!-- Sex & Living -->
                  <div class="grid grid-cols-3 gap-4">
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Sex</label>
                      <select v-model="editingPerson.sex"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                        <option value="U">Unknown</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Title</label>
                      <input v-model="editingPerson.title" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Dr., Rev., Prof." />
                    </div>
                    <div class="flex items-center pt-6">
                      <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" v-model="editingPerson.living"
                          class="w-4 h-4 rounded border-theme bg-theme-tertiary text-accent focus:ring-accent" />
                        <span class="text-theme-primary">Living</span>
                      </label>
                    </div>
                  </div>

                  <!-- Birth -->
                  <div class="p-4 bg-theme-tertiary rounded-lg">
                    <h4 class="text-sm font-medium text-theme-secondary mb-3">Birth</h4>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs text-theme-secondary mb-1">Date</label>
                        <input v-model="editingPerson.birth_date" type="text"
                          class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                          placeholder="e.g., 15 MAR 1850" />
                      </div>
                      <div>
                        <label class="block text-xs text-theme-secondary mb-1">Place</label>
                        <input v-model="editingPerson.birth_place" type="text"
                          class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                          placeholder="City, County, State, Country" />
                      </div>
                    </div>
                  </div>

                  <!-- Death -->
                  <div class="p-4 bg-theme-tertiary rounded-lg">
                    <h4 class="text-sm font-medium text-theme-secondary mb-3">Death</h4>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs text-theme-secondary mb-1">Date</label>
                        <input v-model="editingPerson.death_date" type="text"
                          class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                          placeholder="e.g., 20 OCT 1920" />
                      </div>
                      <div>
                        <label class="block text-xs text-theme-secondary mb-1">Place</label>
                        <input v-model="editingPerson.death_place" type="text"
                          class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                          placeholder="City, County, State, Country" />
                      </div>
                    </div>
                    <div class="mt-3">
                      <label class="block text-xs text-theme-secondary mb-1">Cause of Death</label>
                      <input v-model="editingPerson.cause_of_death" type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="Medical cause of death" />
                    </div>
                  </div>

                  <!-- Burial -->
                  <div class="p-4 bg-theme-tertiary rounded-lg">
                    <h4 class="text-sm font-medium text-theme-secondary mb-3">Burial</h4>
                    <div class="grid grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs text-theme-secondary mb-1">Date</label>
                        <input v-model="editingPerson.burial_date" type="text"
                          class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                          placeholder="e.g., 22 OCT 1920" />
                      </div>
                      <div>
                        <label class="block text-xs text-theme-secondary mb-1">Place</label>
                        <input v-model="editingPerson.burial_place" type="text"
                          class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                          placeholder="Cemetery name, City, State" />
                      </div>
                    </div>
                  </div>

                  <!-- Additional Info -->
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Occupation</label>
                      <input v-model="editingPerson.occupation" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Primary occupation" />
                    </div>
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Education</label>
                      <input v-model="editingPerson.education" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Schools, degrees" />
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Religion</label>
                      <input v-model="editingPerson.religion" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Religious affiliation" />
                    </div>
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">Nationality</label>
                      <input v-model="editingPerson.nationality" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Country of origin" />
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">SSN</label>
                      <input v-model="editingPerson.ssn" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="XXX-XX-XXXX" />
                    </div>
                    <div>
                      <label class="block text-sm text-theme-secondary mb-1">ID Number</label>
                      <input v-model="editingPerson.id_number" type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Other ID" />
                    </div>
                  </div>

                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Physical Description</label>
                    <textarea v-model="editingPerson.physical_description" rows="2"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="Height, weight, hair color, etc."></textarea>
                  </div>

                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Notes</label>
                    <textarea v-model="editingPerson.notes" rows="3"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="Additional information"></textarea>
                  </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                  <button type="button" @click="closeEditPerson" class="btn-secondary">Cancel</button>
                  <button type="submit" class="btn-primary" :disabled="savingPerson">
                    {{ savingPerson ? 'Saving...' : 'Save Changes' }}
                  </button>
                </div>
              </form>
            </div>

            <!-- Events Tab -->
            <div v-show="personEditTab === 'events'">
              <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-medium text-theme-primary">Life Events</h4>
                <button @click="openPersonEventForm()" class="btn-primary text-sm">+ Add Event</button>
              </div>

              <!-- Event Form -->
              <div v-if="showPersonEventForm" class="bg-theme-tertiary rounded-lg p-4 mb-4">
                <h5 class="font-medium text-theme-primary mb-3">{{ editingPersonEvent?.id ? 'Edit Event' : 'New Event' }}</h5>
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-theme-secondary mb-1">Event Type</label>
                    <select v-model="editingPersonEvent.event_type"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm">
                      <option value="">-- Select Type --</option>
                      <option value="BIRT">Birth</option>
                      <option value="DEAT">Death</option>
                      <option value="BURI">Burial</option>
                      <option value="BAPM">Baptism</option>
                      <option value="CHR">Christening</option>
                      <option value="CONF">Confirmation</option>
                      <option value="FCOM">First Communion</option>
                      <option value="GRAD">Graduation</option>
                      <option value="EMIG">Emigration</option>
                      <option value="IMMI">Immigration</option>
                      <option value="NATU">Naturalization</option>
                      <option value="CENS">Census</option>
                      <option value="PROB">Probate</option>
                      <option value="WILL">Will</option>
                      <option value="RETI">Retirement</option>
                      <option value="MILI">Military Service</option>
                      <option value="EVEN">Other Event</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs text-theme-secondary mb-1">Date</label>
                    <input v-model="editingPersonEvent.event_date" type="text"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="e.g., 15 MAR 1850" />
                  </div>
                </div>
                <div class="mt-3">
                  <label class="block text-xs text-theme-secondary mb-1">Place</label>
                  <input v-model="editingPersonEvent.place" type="text"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                    placeholder="City, County, State, Country" />
                </div>
                <div class="mt-3">
                  <label class="block text-xs text-theme-secondary mb-1">Description</label>
                  <textarea v-model="editingPersonEvent.description" rows="2"
                    class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                    placeholder="Event details"></textarea>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                  <button @click="showPersonEventForm = false; editingPersonEvent = null" class="btn-secondary text-sm">Cancel</button>
                  <button @click="savePersonEvent" class="btn-primary text-sm" :disabled="savingPersonEvent">
                    {{ savingPersonEvent ? 'Saving...' : 'Save Event' }}
                  </button>
                </div>
              </div>

              <!-- Events List -->
              <div v-if="personEvents.length === 0" class="text-center py-8 text-theme-secondary">
                No events recorded for this person.
              </div>
              <div v-else class="space-y-2">
                <div v-for="event in personEvents" :key="event.id"
                  class="bg-theme-tertiary rounded-lg p-3 flex justify-between items-start">
                  <div>
                    <div class="font-medium text-theme-primary">{{ event.event_type }}</div>
                    <div class="text-sm text-theme-secondary">{{ event.event_date }}</div>
                    <div v-if="event.place" class="text-sm text-theme-secondary">{{ event.place }}</div>
                    <div v-if="event.description" class="text-xs text-theme-secondary mt-1">{{ event.description }}</div>
                  </div>
                  <div class="flex gap-2">
                    <button @click="openPersonEventForm(event)" class="text-accent hover:underline text-sm">Edit</button>
                    <button @click="deletePersonEvent(event.id)" class="text-red-400 hover:underline text-sm">Delete</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Residences Tab -->
            <div v-show="personEditTab === 'residences'">
              <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-medium text-theme-primary">Residences</h4>
                <button @click="openResidenceForm()" class="btn-primary text-sm">+ Add Residence</button>
              </div>

              <!-- Residence Form -->
              <div v-if="showResidenceForm" class="bg-theme-tertiary rounded-lg p-4 mb-4">
                <h5 class="font-medium text-theme-primary mb-3">{{ editingResidence?.id ? 'Edit Residence' : 'New Residence' }}</h5>
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-theme-secondary mb-1">Date/Period</label>
                    <input v-model="editingResidence.residence_date" type="text"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="e.g., 1850 or BET 1850 AND 1860" />
                  </div>
                  <div>
                    <label class="block text-xs text-theme-secondary mb-1">Place</label>
                    <input v-model="editingResidence.place" type="text"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="Address, City, State, Country" />
                  </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-3">
                  <div>
                    <label class="block text-xs text-theme-secondary mb-1">Latitude</label>
                    <input v-model="editingResidence.latitude" type="number" step="any"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="e.g., 40.7128" />
                  </div>
                  <div>
                    <label class="block text-xs text-theme-secondary mb-1">Longitude</label>
                    <input v-model="editingResidence.longitude" type="number" step="any"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="e.g., -74.0060" />
                  </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                  <button @click="showResidenceForm = false; editingResidence = null" class="btn-secondary text-sm">Cancel</button>
                  <button @click="saveResidence" class="btn-primary text-sm" :disabled="savingResidence">
                    {{ savingResidence ? 'Saving...' : 'Save Residence' }}
                  </button>
                </div>
              </div>

              <!-- Residences List -->
              <div v-if="personResidences.length === 0" class="text-center py-8 text-theme-secondary">
                No residences recorded for this person.
              </div>
              <div v-else class="space-y-2">
                <div v-for="residence in personResidences" :key="residence.id"
                  class="bg-theme-tertiary rounded-lg p-3 flex justify-between items-start">
                  <div>
                    <div class="font-medium text-theme-primary">{{ residence.place || 'Unknown Location' }}</div>
                    <div v-if="residence.residence_date" class="text-sm text-theme-secondary">{{ residence.residence_date }}</div>
                    <div v-if="residence.latitude && residence.longitude" class="text-xs text-theme-secondary">
                      {{ residence.latitude }}, {{ residence.longitude }}
                    </div>
                  </div>
                  <div class="flex gap-2">
                    <button @click="openResidenceForm(residence)" class="text-accent hover:underline text-sm">Edit</button>
                    <button @click="deleteResidence(residence.id)" class="text-red-400 hover:underline text-sm">Delete</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Media Tab -->
            <div v-show="personEditTab === 'media'">
              <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-medium text-theme-primary">Linked Media ({{ personMediaItems.length }})</h4>
                <button
                  @click="uploadMediaForPerson"
                  class="px-3 py-1 bg-accent text-black rounded text-sm font-medium hover:brightness-110 transition-all"
                >
                  Upload Media
                </button>
              </div>

              <!-- Linked Media Grid -->
              <div v-if="personMediaItems.length === 0" class="text-center py-8 text-theme-secondary">
                No media linked to this person.
              </div>
              <div v-else class="grid grid-cols-4 gap-4 mb-4">
                <div v-for="media in paginatedEditMedia" :key="media.id"
                  class="bg-theme-tertiary rounded-lg p-2 relative group cursor-pointer hover:ring-2 hover:ring-accent transition-all"
                  @click="openMediaInEditModal(media)">
                  <!-- Image thumbnail -->
                  <img v-if="isMediaImage(media) && getMediaUrl(media)"
                    :src="getMediaUrl(media)"
                    :alt="media.title || media.filename"
                    class="w-full h-24 object-cover rounded" />
                  <!-- PDF icon -->
                  <div v-else-if="isMediaPdf(media)" class="w-full h-24 bg-red-100 dark:bg-red-900/30 rounded flex flex-col items-center justify-center">
                    <svg class="w-10 h-10 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-3 4h2.5c.28 0 .5.22.5.5v3c0 .28-.22.5-.5.5H10v-4zm-2 0h-.5c-.28 0-.5.22-.5.5v3c0 .28.22.5.5.5h.5v-4zm8 3.5c0 .28-.22.5-.5.5H14v-4h2.5c.28 0 .5.22.5.5v3z"/>
                    </svg>
                    <span class="text-xs text-red-500 mt-1">PDF</span>
                  </div>
                  <!-- HTML icon -->
                  <div v-else-if="isMediaHtml(media)" class="w-full h-24 bg-blue-100 dark:bg-blue-900/30 rounded flex flex-col items-center justify-center">
                    <svg class="w-10 h-10 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                    </svg>
                    <span class="text-xs text-blue-500 mt-1">HTML</span>
                  </div>
                  <!-- Generic document icon -->
                  <div v-else class="w-full h-24 bg-theme-secondary rounded flex flex-col items-center justify-center">
                    <svg class="w-10 h-10 text-theme-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    <span class="text-xs text-theme-secondary mt-1">{{ getFileExtension(media) }}</span>
                  </div>
                  <!-- Title always visible -->
                  <div class="text-xs text-theme-primary truncate mt-1 font-medium">{{ media.title || media.filename || 'Untitled' }}</div>
                  <!-- Unlink button -->
                  <button @click.stop="unlinkMediaInEditModal(media.id)"
                    class="absolute top-1 right-1 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                    title="Unlink from person">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>
              </div>
              <!-- Edit Media Pagination Controls -->
              <div v-if="editMediaTotalPages > 1" class="flex items-center justify-center gap-2 mt-3">
                <button
                  @click="editMediaPage = 1"
                  :disabled="editMediaPage === 1"
                  class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed"
                  title="First"
                >«</button>
                <button
                  @click="editMediaPage--"
                  :disabled="editMediaPage === 1"
                  class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed"
                  title="Previous"
                >‹</button>
                <span class="text-xs text-theme-secondary px-2">{{ editMediaPage }} / {{ editMediaTotalPages }}</span>
                <button
                  @click="editMediaPage++"
                  :disabled="editMediaPage >= editMediaTotalPages"
                  class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed"
                  title="Next"
                >›</button>
                <button
                  @click="editMediaPage = editMediaTotalPages"
                  :disabled="editMediaPage >= editMediaTotalPages"
                  class="px-2 py-1 text-xs bg-theme-tertiary text-theme-primary rounded hover:bg-accent hover:text-white disabled:opacity-40 disabled:cursor-not-allowed"
                  title="Last"
                >»</button>
              </div>

              <!-- Available Media to Link -->
              <div class="border-t border-theme pt-4">
                <h5 class="font-medium text-theme-primary mb-3">Link Existing Media</h5>
                <div v-if="availableMedia.length === 0" class="text-center py-4 text-theme-secondary text-sm">
                  No unlinked media available in this tree.
                </div>
                <div v-else class="grid grid-cols-6 gap-2 max-h-48 overflow-y-auto">
                  <div v-for="media in availableMedia.slice(0, 30)" :key="media.id"
                    @click="linkMediaInEditModal(media.id)"
                    class="bg-theme-tertiary rounded p-1 cursor-pointer hover:ring-2 hover:ring-accent transition-all"
                    :class="{ 'opacity-50 pointer-events-none': linkingMedia }">
                    <!-- Image thumbnail -->
                    <img v-if="isMediaImage(media) && getMediaUrl(media)"
                      :src="getMediaUrl(media)"
                      :alt="media.title || media.filename"
                      class="w-full h-16 object-cover rounded" />
                    <!-- PDF icon -->
                    <div v-else-if="isMediaPdf(media)" class="w-full h-16 bg-red-100 dark:bg-red-900/30 rounded flex flex-col items-center justify-center">
                      <svg class="w-6 h-6 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/>
                      </svg>
                      <span class="text-[10px] text-red-500">PDF</span>
                    </div>
                    <!-- HTML icon -->
                    <div v-else-if="isMediaHtml(media)" class="w-full h-16 bg-blue-100 dark:bg-blue-900/30 rounded flex flex-col items-center justify-center">
                      <svg class="w-6 h-6 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
                      </svg>
                      <span class="text-[10px] text-blue-500">HTML</span>
                    </div>
                    <!-- Generic icon -->
                    <div v-else class="w-full h-16 bg-theme-secondary rounded flex flex-col items-center justify-center">
                      <svg class="w-6 h-6 text-theme-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                      </svg>
                      <span class="text-[10px] text-theme-secondary">{{ getFileExtension(media) }}</span>
                    </div>
                    <div class="text-xs text-theme-primary truncate font-medium">{{ media.title || media.filename || 'Untitled' }}</div>
                  </div>
                </div>
                <div v-if="availableMedia.length > 30" class="text-xs text-theme-secondary mt-2 text-center">
                  Showing 30 of {{ availableMedia.length }} available media items
                </div>
              </div>
            </div>

            <!-- Sources Tab -->
            <div v-show="personEditTab === 'sources'">
              <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-medium text-theme-primary">Linked Sources</h4>
              </div>

              <!-- Linked Sources List -->
              <div v-if="personSources.length === 0" class="text-center py-8 text-theme-secondary">
                No sources linked to this person.
              </div>
              <div v-else class="space-y-3 mb-6">
                <div v-for="source in personSources" :key="source.id"
                  class="bg-theme-tertiary rounded-lg p-4 relative group">
                  <div class="flex justify-between items-start cursor-pointer hover:bg-theme-secondary/30 -m-2 p-2 rounded transition-colors"
                       @click="openSourceDetailPopup(source)">
                    <div class="flex-1">
                      <div class="font-medium text-theme-primary">{{ source.title || 'Untitled Source' }}</div>
                      <!-- Source Details Grid -->
                      <div class="grid grid-cols-1 gap-1 mt-2 text-sm">
                        <div v-if="source.author" class="text-theme-secondary">
                          <span class="text-theme-secondary/70">Author:</span> {{ source.author }}
                        </div>
                        <div v-if="source.publication" class="text-theme-secondary">
                          <span class="text-theme-secondary/70">Publication:</span> {{ truncateText(source.publication, 120) }}
                        </div>
                        <div v-if="source.repository" class="text-theme-secondary">
                          <span class="text-theme-secondary/70">Repository:</span> {{ source.repository }}
                        </div>
                        <div v-if="source.call_number" class="text-theme-secondary">
                          <span class="text-theme-secondary/70">Call #:</span> {{ source.call_number }}
                        </div>
                        <div v-if="source.url" class="text-theme-secondary truncate">
                          <span class="text-theme-secondary/70">URL:</span>
                          <a :href="source.url" target="_blank" @click.stop class="text-accent hover:underline ml-1">{{ truncateText(source.url, 50) }}</a>
                        </div>
                      </div>
                      <!-- Citation-specific details -->
                      <div v-if="source.citation_page || source.citation_quality" class="flex gap-3 mt-2 pt-2 border-t border-theme-secondary/30">
                        <div v-if="source.citation_page" class="text-xs text-accent">
                          <span class="text-theme-secondary/70">Page:</span> {{ source.citation_page }}
                        </div>
                        <div v-if="source.citation_quality" class="text-xs text-theme-secondary">
                          <span class="text-theme-secondary/70">Quality:</span> {{ formatQuality(source.citation_quality) }}
                        </div>
                      </div>
                      <!-- Notes preview -->
                      <div v-if="source.notes" class="mt-2 pt-2 border-t border-theme-secondary/30 text-xs text-theme-secondary italic">
                        {{ truncateText(source.notes, 100) }}
                      </div>
                    </div>
                    <button @click.stop="unlinkSourceInEditModal(source.id)"
                      class="text-red-500 hover:text-red-400 p-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0"
                      title="Unlink source from person">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </div>
                  <!-- Related Media for this Source -->
                  <div v-if="source.related_media && source.related_media.length > 0" class="mt-3 pt-3 border-t border-theme">
                    <div class="text-xs text-theme-secondary mb-2 flex items-center gap-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                      </svg>
                      Source Documents ({{ source.related_media.length }})
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <div v-for="media in getPaginatedSourceMedia(source.related_media, source.id)" :key="media.id"
                           @click.stop="viewMediaItem(media)"
                           class="w-16 h-16 rounded border border-theme cursor-pointer hover:ring-2 hover:ring-accent overflow-hidden bg-theme-secondary">
                        <img v-if="media.thumbnail_url"
                             :src="media.thumbnail_url"
                             :alt="media.title"
                             class="w-full h-full object-cover"
                             @error="$event.target.style.display='none'">
                        <div v-else class="w-full h-full flex items-center justify-center text-theme-secondary">
                          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                          </svg>
                        </div>
                      </div>
                    </div>
                    <!-- Pagination for related media -->
                    <div v-if="getSourceMediaTotalPages(source.related_media) > 1" class="flex items-center justify-center gap-1 mt-2">
                      <button @click.stop="setSourceMediaPage(source.id, 1)" :disabled="getSourceMediaPage(source.id) === 1"
                        class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary text-xs hover:bg-theme-tertiary disabled:opacity-50 disabled:cursor-not-allowed">«</button>
                      <button @click.stop="setSourceMediaPage(source.id, getSourceMediaPage(source.id) - 1)" :disabled="getSourceMediaPage(source.id) === 1"
                        class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary text-xs hover:bg-theme-tertiary disabled:opacity-50 disabled:cursor-not-allowed">‹</button>
                      <span class="text-xs text-theme-secondary px-1">{{ getSourceMediaPage(source.id) }}/{{ getSourceMediaTotalPages(source.related_media) }}</span>
                      <button @click.stop="setSourceMediaPage(source.id, getSourceMediaPage(source.id) + 1)" :disabled="getSourceMediaPage(source.id) >= getSourceMediaTotalPages(source.related_media)"
                        class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary text-xs hover:bg-theme-tertiary disabled:opacity-50 disabled:cursor-not-allowed">›</button>
                      <button @click.stop="setSourceMediaPage(source.id, getSourceMediaTotalPages(source.related_media))" :disabled="getSourceMediaPage(source.id) >= getSourceMediaTotalPages(source.related_media)"
                        class="px-2 py-0.5 rounded bg-theme-secondary text-theme-primary text-xs hover:bg-theme-tertiary disabled:opacity-50 disabled:cursor-not-allowed">»</button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Available Sources to Link -->
              <div class="border-t border-theme pt-4">
                <div class="flex justify-between items-center mb-3">
                  <h5 class="font-medium text-theme-primary">Link Existing Source</h5>
                  <button
                    @click="openCreateSourceFromEditPerson"
                    class="text-xs px-2 py-1 bg-accent text-white rounded hover:bg-accent/80 flex items-center gap-1"
                  >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Source
                  </button>
                </div>
                <div v-if="availableSources.length === 0" class="text-center py-4 text-theme-secondary text-sm">
                  No unlinked sources available in this tree.
                </div>
                <div v-else class="space-y-2 max-h-48 overflow-y-auto">
                  <div v-for="source in availableSources.slice(0, 20)" :key="source.id"
                    @click="linkSourceInEditModal(source.id)"
                    class="bg-theme-tertiary rounded p-3 cursor-pointer hover:ring-2 hover:ring-accent transition-all"
                    :class="{ 'opacity-50 pointer-events-none': linkingSource }">
                    <div class="font-medium text-theme-primary text-sm">{{ source.title || 'Untitled Source' }}</div>
                    <div v-if="source.author" class="text-xs text-theme-secondary">By: {{ source.author }}</div>
                  </div>
                </div>
                <div v-if="availableSources.length > 20" class="text-xs text-theme-secondary mt-2 text-center">
                  Showing 20 of {{ availableSources.length }} available sources
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Edit Family Modal -->
      <div v-if="showEditFamilyModal && editingFamily" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeEditFamily">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">Edit Family</h3>
              <button @click="closeEditFamily" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveFamily">
              <div class="space-y-4">
                <!-- Spouses -->
                <div class="grid grid-cols-2 gap-4">
                  <div class="p-4 bg-theme-tertiary rounded-lg">
                    <h4 class="text-sm font-medium text-theme-secondary mb-2">Husband</h4>
                    <select
                      v-model="editingFamily.husband_id"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                    >
                      <option :value="null">-- Select Person --</option>
                      <option v-for="person in sortedMalePersons" :key="person.id" :value="person.id">
                        {{ person.surname }}, {{ person.given_name }} {{ person.birth_date ? `(${extractYear(person.birth_date)})` : '' }}
                      </option>
                    </select>
                  </div>
                  <div class="p-4 bg-theme-tertiary rounded-lg">
                    <h4 class="text-sm font-medium text-theme-secondary mb-2">Wife</h4>
                    <select
                      v-model="editingFamily.wife_id"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                    >
                      <option :value="null">-- Select Person --</option>
                      <option v-for="person in sortedFemalePersons" :key="person.id" :value="person.id">
                        {{ person.surname }}, {{ person.given_name }} {{ person.birth_date ? `(${extractYear(person.birth_date)})` : '' }}
                      </option>
                    </select>
                  </div>
                </div>

                <!-- Marriage -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Marriage</h4>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Date</label>
                      <input
                        v-model="editingFamily.marriage_date"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="e.g., 15 JUN 1875"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Place</label>
                      <input
                        v-model="editingFamily.marriage_place"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="City, County, State, Country"
                      />
                    </div>
                  </div>
                </div>

                <!-- Divorce -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Divorce (if applicable)</h4>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Date</label>
                      <input
                        v-model="editingFamily.divorce_date"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="e.g., 10 DEC 1890"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Place</label>
                      <input
                        v-model="editingFamily.divorce_place"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="City, County, State, Country"
                      />
                    </div>
                  </div>
                </div>

                <!-- Children -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Children</h4>
                  <div v-if="editingFamily.children?.length > 0" class="space-y-2 mb-3">
                    <div v-for="(child, index) in editingFamily.children" :key="child.id" class="flex items-center justify-between bg-theme-secondary rounded px-3 py-2">
                      <span class="text-theme-primary text-sm">{{ child.given_name }} {{ child.surname }}</span>
                      <button type="button" @click="removeChildFromFamily(index)" class="text-red-400 hover:text-red-300 text-xs">
                        Remove
                      </button>
                    </div>
                  </div>
                  <div v-else class="text-theme-secondary text-sm mb-3">No children in this family</div>
                  <div class="flex gap-2">
                    <select
                      v-model="newChildId"
                      class="flex-1 px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                    >
                      <option :value="null">-- Add Child --</option>
                      <option v-for="person in availableChildren" :key="person.id" :value="person.id">
                        {{ person.given_name }} {{ person.surname }} {{ person.birth_date ? `(${extractYear(person.birth_date)})` : '' }}
                      </option>
                    </select>
                    <button type="button" @click="addChildToFamily" :disabled="!newChildId" class="px-3 py-2 bg-accent text-white rounded hover:bg-accent-blue disabled:opacity-50 text-sm">
                      Add
                    </button>
                  </div>
                </div>

                <!-- Family Events (Phase 2.3) -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <div class="flex justify-between items-center mb-3">
                    <h4 class="text-sm font-medium text-theme-secondary">Family Events</h4>
                    <button type="button" @click="openCreateFamilyEvent(editingFamily)" class="text-xs text-accent hover:text-accent-blue">
                      + Add Event
                    </button>
                  </div>
                  <div v-if="editingFamily.events?.length > 0" class="space-y-2">
                    <div v-for="event in editingFamily.events" :key="event.id" class="flex items-center justify-between bg-theme-secondary rounded px-3 py-2">
                      <div class="flex-1">
                        <span class="text-theme-primary text-sm font-medium">{{ getFamilyEventTypeLabel(event.event_type) }}</span>
                        <span v-if="event.event_date" class="text-theme-secondary text-xs ml-2">{{ event.event_date }}</span>
                        <div v-if="event.event_place" class="text-theme-secondary text-xs truncate">{{ event.event_place }}</div>
                      </div>
                      <div class="flex items-center gap-2 ml-2">
                        <button type="button" @click="openEditFamilyEvent(event)" class="text-theme-secondary hover:text-accent text-xs">Edit</button>
                        <button type="button" @click="confirmDeleteFamilyEvent(event)" class="text-red-400 hover:text-red-300 text-xs">Delete</button>
                      </div>
                    </div>
                  </div>
                  <div v-else class="text-theme-secondary text-sm">No additional family events recorded</div>
                  <div class="mt-2 text-xs text-theme-tertiary">
                    Record events like engagement, marriage license, settlement, etc.
                  </div>
                </div>

                <!-- Notes -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Notes</label>
                  <textarea
                    v-model="editingFamily.notes"
                    rows="3"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Additional information about this family"
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end gap-3 mt-6">
                <button type="button" @click="closeEditFamily" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="savingFamily">
                  {{ savingFamily ? 'Saving...' : 'Save Changes' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Source Detail Modal (for viewing source in edit person) -->
      <div v-if="viewingSource" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[60]" @click.self="viewingSource = null">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <!-- Header -->
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">{{ viewingSource.title || 'Source Details' }}</h3>
              <button @click="viewingSource = null" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <!-- Source Details -->
            <div class="space-y-4">
              <!-- Title -->
              <div v-if="viewingSource.title" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Title</div>
                <div class="text-theme-primary font-medium">{{ viewingSource.title }}</div>
              </div>

              <!-- Author -->
              <div v-if="viewingSource.author" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Author</div>
                <div class="text-theme-primary">{{ viewingSource.author }}</div>
              </div>

              <!-- Publisher Info -->
              <div v-if="viewingSource.publisher || viewingSource.publication_date || viewingSource.publication_place" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Publication Info</div>
                <div class="text-theme-primary">
                  <span v-if="viewingSource.publisher">{{ viewingSource.publisher }}</span>
                  <span v-if="viewingSource.publication_place">, {{ viewingSource.publication_place }}</span>
                  <span v-if="viewingSource.publication_date"> ({{ viewingSource.publication_date }})</span>
                </div>
              </div>

              <!-- Citation Details -->
              <div v-if="viewingSource.citation_page || viewingSource.citation_quality || viewingSource.citation_detail" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Citation</div>
                <div class="text-theme-primary space-y-1">
                  <div v-if="viewingSource.citation_page">Page: {{ viewingSource.citation_page }}</div>
                  <div v-if="viewingSource.citation_quality">Quality: {{ viewingSource.citation_quality }}</div>
                  <div v-if="viewingSource.citation_detail" class="text-sm">{{ viewingSource.citation_detail }}</div>
                </div>
              </div>

              <!-- Repository -->
              <div v-if="viewingSource.repository_name || viewingSource.repository_address" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Repository</div>
                <div class="text-theme-primary">
                  <div v-if="viewingSource.repository_name">{{ viewingSource.repository_name }}</div>
                  <div v-if="viewingSource.repository_address" class="text-sm text-theme-secondary">{{ viewingSource.repository_address }}</div>
                </div>
              </div>

              <!-- Call Number -->
              <div v-if="viewingSource.call_number" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Call Number</div>
                <div class="text-theme-primary">{{ viewingSource.call_number }}</div>
              </div>

              <!-- Text/Notes -->
              <div v-if="viewingSource.text" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Notes/Text</div>
                <div class="text-theme-primary text-sm whitespace-pre-wrap">{{ viewingSource.text }}</div>
              </div>

              <!-- URL -->
              <div v-if="viewingSource.url" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">URL</div>
                <a :href="viewingSource.url" target="_blank" class="text-accent hover:text-accent-blue break-all">{{ viewingSource.url }}</a>
              </div>

              <!-- Media Files -->
              <div v-if="loadingSourceDetails" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-1">Media Files</div>
                <div class="text-theme-secondary text-sm">Loading...</div>
              </div>
              <div v-else-if="viewingSourceMedia.length > 0" class="bg-theme-tertiary p-4 rounded-lg">
                <div class="text-xs text-theme-secondary mb-2">Media Files ({{ viewingSourceMedia.length }})</div>
                <div class="grid grid-cols-4 gap-2">
                  <div v-for="mediaItem in paginatedViewingSourceMedia" :key="mediaItem.id"
                    @click="openMediaDetail(mediaItem)"
                    class="aspect-square rounded-lg overflow-hidden cursor-pointer hover:ring-2 hover:ring-accent transition-all bg-theme-primary">
                    <img v-if="mediaItem.thumbnail_url || mediaItem.media_type === 'photo'"
                      :src="mediaItem.thumbnail_url || mediaItem.nextcloud_url"
                      :alt="mediaItem.title"
                      class="w-full h-full object-cover"
                      loading="lazy"
                    />
                    <div v-else class="w-full h-full flex items-center justify-center text-theme-secondary">
                      <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                      </svg>
                    </div>
                  </div>
                </div>
                <!-- Pagination controls for source detail media -->
                <div v-if="viewingSourceMediaTotalPages > 1" class="flex items-center justify-center gap-2 mt-3">
                  <button @click="viewingSourceMediaPage = 1" :disabled="viewingSourceMediaPage === 1"
                    class="px-2 py-1 rounded text-xs" :class="viewingSourceMediaPage === 1 ? 'bg-theme-primary text-theme-secondary cursor-not-allowed' : 'bg-accent text-white hover:bg-accent/80'">«</button>
                  <button @click="viewingSourceMediaPage--" :disabled="viewingSourceMediaPage === 1"
                    class="px-2 py-1 rounded text-xs" :class="viewingSourceMediaPage === 1 ? 'bg-theme-primary text-theme-secondary cursor-not-allowed' : 'bg-accent text-white hover:bg-accent/80'">‹</button>
                  <span class="text-theme-secondary text-xs px-2">Page {{ viewingSourceMediaPage }} of {{ viewingSourceMediaTotalPages }}</span>
                  <button @click="viewingSourceMediaPage++" :disabled="viewingSourceMediaPage >= viewingSourceMediaTotalPages"
                    class="px-2 py-1 rounded text-xs" :class="viewingSourceMediaPage >= viewingSourceMediaTotalPages ? 'bg-theme-primary text-theme-secondary cursor-not-allowed' : 'bg-accent text-white hover:bg-accent/80'">›</button>
                  <button @click="viewingSourceMediaPage = viewingSourceMediaTotalPages" :disabled="viewingSourceMediaPage >= viewingSourceMediaTotalPages"
                    class="px-2 py-1 rounded text-xs" :class="viewingSourceMediaPage >= viewingSourceMediaTotalPages ? 'bg-theme-primary text-theme-secondary cursor-not-allowed' : 'bg-accent text-white hover:bg-accent/80'">»</button>
                </div>
              </div>

              <!-- Source ID -->
              <div class="text-xs text-theme-secondary text-center pt-2 border-t border-theme">
                Source ID: {{ viewingSource.id }}
              </div>
            </div>

            <!-- Footer -->
            <div class="flex justify-between mt-6">
              <button
                @click="goToSourceInTab(viewingSource)"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-500"
              >
                Open in Sources Tab
              </button>
              <button @click="viewingSource = null; viewingSourceMedia = []" class="btn-secondary">Close</button>
            </div>
          </div>
        </div>
      </div>

      <!-- New Tree Modal -->
      <div v-if="showNewTreeModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showNewTreeModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Create New Family Tree</h3>
          <form @submit.prevent="createTree">
            <div class="space-y-4">
              <div>
                <label class="block text-sm text-theme-secondary mb-1">Tree Name</label>
                <input
                  v-model="newTree.name"
                  type="text"
                  required
                  class="w-full px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  placeholder="e.g., Smith Family Tree"
                />
              </div>
              <div>
                <label class="block text-sm text-theme-secondary mb-1">Description (optional)</label>
                <textarea
                  v-model="newTree.description"
                  class="w-full px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  rows="3"
                  placeholder="Notes about this family tree..."
                ></textarea>
              </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
              <button type="button" @click="showNewTreeModal = false" class="btn-secondary">Cancel</button>
              <button type="submit" class="btn-primary" :disabled="!newTree.name">Create Tree</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Import GEDCOM Modal -->
      <div v-if="showImportModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showImportModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-theme-primary mb-4">Import GEDCOM File</h3>
          <form @submit.prevent="importGedcom">
            <div class="space-y-4">
              <div>
                <label class="block text-sm text-theme-secondary mb-1">GEDCOM File</label>
                <input
                  type="file"
                  accept=".ged,.GED"
                  @change="onFileSelect"
                  class="w-full px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                />
              </div>
              <div>
                <label class="block text-sm text-theme-secondary mb-1">Import Into</label>
                <select
                  v-model="importTarget"
                  class="w-full px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                >
                  <option value="new">Create New Tree</option>
                  <option v-for="tree in trees" :key="tree.id" :value="tree.id">
                    {{ tree.name }}
                  </option>
                </select>
              </div>
              <div v-if="importTarget === 'new'">
                <label class="block text-sm text-theme-secondary mb-1">New Tree Name</label>
                <input
                  v-model="importTreeName"
                  type="text"
                  class="w-full px-4 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                  placeholder="Leave blank to use filename"
                />
              </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
              <button type="button" @click="showImportModal = false" class="btn-secondary">Cancel</button>
              <button type="submit" class="btn-primary" :disabled="!importFile || importing">
                {{ importing ? 'Importing...' : 'Import' }}
              </button>
            </div>
          </form>

          <!-- Import Progress -->
          <div v-if="importProgress" class="mt-4 p-4 bg-theme-tertiary rounded-lg">
            <div class="text-theme-primary font-medium mb-2">Import Progress</div>
            <div class="text-sm text-theme-secondary space-y-1">
              <div>Persons: {{ importProgress.persons_imported }}</div>
              <div>Families: {{ importProgress.families_imported }}</div>
              <div>Media: {{ importProgress.media_imported }}</div>
              <div>Sources: {{ importProgress.sources_imported }}</div>
            </div>
            <div v-if="importProgress.errors?.length > 0" class="mt-2 text-red-400 text-sm">
              {{ importProgress.errors.length }} errors occurred
            </div>
          </div>
        </div>
      </div>

      <!-- Create Person Modal -->
      <div v-if="showCreatePersonModal && creatingPerson" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeCreatePerson">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <div>
                <h3 class="text-xl font-bold text-theme-primary">
                  {{ addRelativeMode ? `Add ${addRelativeMode.charAt(0).toUpperCase() + addRelativeMode.slice(1)}` : 'Create New Person' }}
                </h3>
                <p v-if="addRelativeForPerson" class="text-sm text-theme-secondary">
                  For {{ addRelativeForPerson.given_name }} {{ addRelativeForPerson.surname }}
                </p>
              </div>
              <button @click="closeCreatePerson" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveNewPerson">
              <div class="space-y-4">
                <!-- Name Fields -->
                <div class="grid grid-cols-4 gap-4">
                  <div class="col-span-2">
                    <label class="block text-sm text-theme-secondary mb-1">Given Name(s) *</label>
                    <input
                      v-model="creatingPerson.given_name"
                      type="text"
                      required
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="First and middle names"
                    />
                  </div>
                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Surname *</label>
                    <input
                      v-model="creatingPerson.surname"
                      type="text"
                      required
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="Family name"
                    />
                  </div>
                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Suffix</label>
                    <input
                      v-model="creatingPerson.suffix"
                      type="text"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="Jr., Sr., III"
                    />
                  </div>
                </div>

                <!-- Nickname and Sex -->
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Nickname</label>
                    <input
                      v-model="creatingPerson.nickname"
                      type="text"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="Known as..."
                    />
                  </div>
                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Sex *</label>
                    <select
                      v-model="creatingPerson.sex"
                      required
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                      <option value="M">Male</option>
                      <option value="F">Female</option>
                      <option value="U">Unknown</option>
                    </select>
                  </div>
                </div>

                <!-- Birth -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Birth</h4>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Date</label>
                      <input
                        v-model="creatingPerson.birth_date"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="e.g., 15 MAR 1850 or ABT 1850"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Place</label>
                      <input
                        v-model="creatingPerson.birth_place"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="City, County, State, Country"
                      />
                    </div>
                  </div>
                </div>

                <!-- Death -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Death</h4>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Date</label>
                      <input
                        v-model="creatingPerson.death_date"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="e.g., 20 OCT 1920"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Place</label>
                      <input
                        v-model="creatingPerson.death_place"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="City, County, State, Country"
                      />
                    </div>
                  </div>
                </div>

                <!-- Burial -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Burial</h4>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Date</label>
                      <input
                        v-model="creatingPerson.burial_date"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="e.g., 22 OCT 1920"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Place</label>
                      <input
                        v-model="creatingPerson.burial_place"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="Cemetery, City, State"
                      />
                    </div>
                  </div>
                </div>

                <!-- Additional Info -->
                <div class="grid grid-cols-3 gap-4">
                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Occupation</label>
                    <input
                      v-model="creatingPerson.occupation"
                      type="text"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="Farmer, Teacher..."
                    />
                  </div>
                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Education</label>
                    <input
                      v-model="creatingPerson.education"
                      type="text"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="High School, College..."
                    />
                  </div>
                  <div>
                    <label class="block text-sm text-theme-secondary mb-1">Religion</label>
                    <input
                      v-model="creatingPerson.religion"
                      type="text"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                      placeholder="Baptist, Catholic..."
                    />
                  </div>
                </div>

                <!-- GEDCOM Attributes (Phase 2.2) -->
                <div class="border-t border-theme pt-4 mt-4">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Additional Attributes</h4>

                  <div class="grid grid-cols-2 gap-4">
                    <!-- Title -->
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Title (Dr., Rev., etc.)</label>
                      <input
                        v-model="creatingPerson.title"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="Dr., Rev., Prof., etc."
                      />
                    </div>

                    <!-- Nationality -->
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Nationality</label>
                      <input
                        v-model="creatingPerson.nationality"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="Country of origin"
                      />
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-4 mt-3">
                    <!-- SSN -->
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Social Security Number</label>
                      <input
                        v-model="creatingPerson.ssn"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="XXX-XX-XXXX"
                      />
                    </div>

                    <!-- ID Number -->
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">ID Number</label>
                      <input
                        v-model="creatingPerson.id_number"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="Other identification number"
                      />
                    </div>
                  </div>

                  <!-- Physical Description -->
                  <div class="mt-3">
                    <label class="block text-xs text-theme-secondary mb-1">Physical Description</label>
                    <textarea
                      v-model="creatingPerson.physical_description"
                      rows="2"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="Height, weight, hair color, eye color, etc."
                    ></textarea>
                  </div>

                  <!-- Property -->
                  <div class="mt-3">
                    <label class="block text-xs text-theme-secondary mb-1">Property/Possessions</label>
                    <textarea
                      v-model="creatingPerson.property"
                      rows="2"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="Notable property or possessions"
                    ></textarea>
                  </div>

                  <!-- Cause of Death -->
                  <div class="mt-3">
                    <label class="block text-xs text-theme-secondary mb-1">Cause of Death</label>
                    <input
                      v-model="creatingPerson.cause_of_death"
                      type="text"
                      class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                      placeholder="Medical cause of death"
                    />
                  </div>
                </div>

                <!-- Notes -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Notes</label>
                  <textarea
                    v-model="creatingPerson.notes"
                    rows="3"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Additional information about this person..."
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end gap-3 mt-6">
                <button type="button" @click="closeCreatePerson" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="savingCreate">
                  {{ savingCreate ? 'Creating...' : (addRelativeMode ? `Add ${addRelativeMode}` : 'Create Person') }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Person Confirmation Modal -->
      <div v-if="showDeletePersonModal && deletingPerson" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeDeletePerson">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-full bg-red-500/20 flex items-center justify-center flex-shrink-0">
              <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
            </div>
            <div>
              <h3 class="text-xl font-bold text-theme-primary">Delete Person</h3>
              <p class="text-theme-secondary text-sm">This action cannot be undone.</p>
            </div>
          </div>

          <p class="text-theme-primary mb-4">
            Are you sure you want to delete <strong>{{ deletingPerson.given_name }} {{ deletingPerson.surname }}</strong>?
          </p>

          <div class="bg-theme-tertiary rounded-lg p-3 mb-4 text-sm text-theme-secondary">
            <p class="font-medium text-theme-primary mb-1">This will:</p>
            <ul class="list-disc list-inside space-y-1">
              <li>Remove all family relationships</li>
              <li>Unlink all associated media</li>
              <li>Remove all event records</li>
            </ul>
          </div>

          <div class="flex justify-end gap-3">
            <button @click="closeDeletePerson" class="btn-secondary">Cancel</button>
            <button @click="deletePerson" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors" :disabled="deleting">
              {{ deleting ? 'Deleting...' : 'Delete Person' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Create Family Modal -->
      <div v-if="showCreateFamilyModal && creatingFamily" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeCreateFamily">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h3 class="text-xl font-bold text-theme-primary">Create New Family</h3>
              <button @click="closeCreateFamily" class="text-theme-secondary hover:text-theme-primary">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <form @submit.prevent="saveNewFamily">
              <div class="space-y-4">
                <!-- Spouses -->
                <div class="grid grid-cols-2 gap-4">
                  <div class="p-4 bg-theme-tertiary rounded-lg">
                    <h4 class="text-sm font-medium text-theme-secondary mb-2">Husband</h4>
                    <select
                      v-model="creatingFamily.husband_id"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                    >
                      <option :value="null">-- Select Person --</option>
                      <option v-for="person in sortedMalePersons" :key="person.id" :value="person.id">
                        {{ person.surname }}, {{ person.given_name }} {{ person.birth_date ? `(${extractYear(person.birth_date)})` : '' }}
                      </option>
                    </select>
                  </div>
                  <div class="p-4 bg-theme-tertiary rounded-lg">
                    <h4 class="text-sm font-medium text-theme-secondary mb-2">Wife</h4>
                    <select
                      v-model="creatingFamily.wife_id"
                      class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                    >
                      <option :value="null">-- Select Person --</option>
                      <option v-for="person in sortedFemalePersons" :key="person.id" :value="person.id">
                        {{ person.surname }}, {{ person.given_name }} {{ person.birth_date ? `(${extractYear(person.birth_date)})` : '' }}
                      </option>
                    </select>
                  </div>
                </div>

                <!-- Marriage -->
                <div class="p-4 bg-theme-tertiary rounded-lg">
                  <h4 class="text-sm font-medium text-theme-secondary mb-3">Marriage</h4>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Date</label>
                      <input
                        v-model="creatingFamily.marriage_date"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="e.g., 15 JUN 1875"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-theme-secondary mb-1">Place</label>
                      <input
                        v-model="creatingFamily.marriage_place"
                        type="text"
                        class="w-full px-3 py-2 bg-theme-secondary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent text-sm"
                        placeholder="City, County, State, Country"
                      />
                    </div>
                  </div>
                </div>

                <!-- Notes -->
                <div>
                  <label class="block text-sm text-theme-secondary mb-1">Notes</label>
                  <textarea
                    v-model="creatingFamily.notes"
                    rows="2"
                    class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                    placeholder="Additional notes about this family..."
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end gap-3 mt-6">
                <button type="button" @click="closeCreateFamily" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="savingCreate">
                  {{ savingCreate ? 'Creating...' : 'Create Family' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Family Confirmation Modal -->
      <div v-if="showDeleteFamilyModal && deletingFamily" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="closeDeleteFamily">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-full bg-red-500/20 flex items-center justify-center flex-shrink-0">
              <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
            </div>
            <div>
              <h3 class="text-xl font-bold text-theme-primary">Delete Family</h3>
              <p class="text-theme-secondary text-sm">This action cannot be undone.</p>
            </div>
          </div>

          <p class="text-theme-primary mb-4">
            Are you sure you want to delete this family unit?
          </p>

          <div class="bg-theme-tertiary rounded-lg p-3 mb-4 text-sm text-theme-secondary">
            <p class="font-medium text-theme-primary mb-1">This will:</p>
            <ul class="list-disc list-inside space-y-1">
              <li>Remove all parent-child links in this family</li>
              <li>Remove the marriage/divorce records</li>
              <li>NOT delete the individual persons</li>
            </ul>
          </div>

          <div class="flex justify-end gap-3">
            <button @click="closeDeleteFamily" class="btn-secondary">Cancel</button>
            <button @click="deleteFamily" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors" :disabled="deleting">
              {{ deleting ? 'Deleting...' : 'Delete Family' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Create FAN Cluster Modal -->
      <div v-if="showCreateFanClusterModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showCreateFanClusterModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4 p-6">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-theme-primary">Create FAN Cluster</h3>
            <button @click="showCreateFanClusterModal = false" class="text-theme-secondary hover:text-theme-primary">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <form @submit.prevent="createFanCluster" class="space-y-4">
            <div>
              <label class="block text-sm text-theme-secondary mb-1">Cluster Name *</label>
              <input
                v-model="newFanCluster.name"
                type="text"
                required
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="e.g., Lancaster County 1850s Neighbors"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Research Period</label>
              <input
                v-model="newFanCluster.research_period"
                type="text"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="e.g., 1840-1860"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Location</label>
              <input
                v-model="newFanCluster.location"
                type="text"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="e.g., Lancaster County, Pennsylvania"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Notes</label>
              <textarea
                v-model="newFanCluster.notes"
                rows="3"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="Research notes and context..."
              ></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
              <button type="button" @click="showCreateFanClusterModal = false" class="btn-secondary">Cancel</button>
              <button type="submit" class="btn-primary" :disabled="savingFanCluster || !newFanCluster.name">
                {{ savingFanCluster ? 'Creating...' : 'Create Cluster' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Add FAN Member Modal -->
      <div v-if="showAddFanMemberModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showAddFanMemberModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto p-6">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-theme-primary">Add FAN Member</h3>
            <button @click="showAddFanMemberModal = false" class="text-theme-secondary hover:text-theme-primary">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <form @submit.prevent="addFanMember" class="space-y-4">
            <div>
              <label class="block text-sm text-theme-secondary mb-1">Member Name *</label>
              <input
                v-model="newFanMember.member_name"
                type="text"
                required
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="Full name of the person"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Link to Person in Tree</label>
              <select
                v-model="newFanMember.member_person_id"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              >
                <option :value="null">Not linked (unknown person)</option>
                <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                  {{ person.surname }}, {{ person.given_name }} {{ person.birth_year ? `(${person.birth_year})` : '' }}
                </option>
              </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm text-theme-secondary mb-1">Relationship Type *</label>
                <select
                  v-model="newFanMember.relationship_type"
                  required
                  class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                >
                  <option v-for="(label, key) in fanRelationshipTypes" :key="key" :value="key">
                    {{ label.split(' - ')[0] }}
                  </option>
                </select>
              </div>

              <div>
                <label class="block text-sm text-theme-secondary mb-1">Confidence *</label>
                <select
                  v-model="newFanMember.confidence"
                  required
                  class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                >
                  <option v-for="(label, key) in fanConfidenceLevels" :key="key" :value="key">
                    {{ label.split(' - ')[0] }}
                  </option>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Source Record Type *</label>
              <select
                v-model="newFanMember.source_record_type"
                required
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              >
                <option v-for="(label, key) in fanSourceRecordTypes" :key="key" :value="key">
                  {{ label }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Source Citation</label>
              <input
                v-model="newFanMember.source_citation"
                type="text"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="e.g., 1850 Census, Lancaster County, PA, page 42"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Interaction Date</label>
              <input
                v-model="newFanMember.interaction_date"
                type="text"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="e.g., 1850 or 15 Jun 1850"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Description/Notes</label>
              <textarea
                v-model="newFanMember.interaction_description"
                rows="2"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                placeholder="How this person was connected..."
              ></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
              <button type="button" @click="showAddFanMemberModal = false" class="btn-secondary">Cancel</button>
              <button type="submit" class="btn-primary" :disabled="savingFanMember || !newFanMember.member_name || !newFanMember.source_record_type">
                {{ savingFanMember ? 'Adding...' : 'Add Member' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Edit FAN Member Modal -->
      <div v-if="showEditFanMemberModal && editingFanMember" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showEditFanMemberModal = false">
        <div class="bg-theme-secondary rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto p-6">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-theme-primary">Edit FAN Member</h3>
            <button @click="showEditFanMemberModal = false" class="text-theme-secondary hover:text-theme-primary">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <form @submit.prevent="saveFanMember" class="space-y-4">
            <div>
              <label class="block text-sm text-theme-secondary mb-1">Member Name *</label>
              <input
                v-model="editingFanMember.member_name"
                type="text"
                required
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Link to Person in Tree</label>
              <select
                v-model="editingFanMember.member_person_id"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              >
                <option :value="null">Not linked (unknown person)</option>
                <option v-for="person in sortedPersons" :key="person.id" :value="person.id">
                  {{ person.surname }}, {{ person.given_name }} {{ person.birth_year ? `(${person.birth_year})` : '' }}
                </option>
              </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm text-theme-secondary mb-1">Relationship Type *</label>
                <select
                  v-model="editingFanMember.relationship_type"
                  required
                  class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                >
                  <option v-for="(label, key) in fanRelationshipTypes" :key="key" :value="key">
                    {{ label.split(' - ')[0] }}
                  </option>
                </select>
              </div>

              <div>
                <label class="block text-sm text-theme-secondary mb-1">Confidence *</label>
                <select
                  v-model="editingFanMember.confidence"
                  required
                  class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
                >
                  <option v-for="(label, key) in fanConfidenceLevels" :key="key" :value="key">
                    {{ label.split(' - ')[0] }}
                  </option>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Source Record Type *</label>
              <select
                v-model="editingFanMember.source_record_type"
                required
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              >
                <option v-for="(label, key) in fanSourceRecordTypes" :key="key" :value="key">
                  {{ label }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Source Citation</label>
              <input
                v-model="editingFanMember.source_citation"
                type="text"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Interaction Date</label>
              <input
                v-model="editingFanMember.interaction_date"
                type="text"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              />
            </div>

            <div>
              <label class="block text-sm text-theme-secondary mb-1">Description/Notes</label>
              <textarea
                v-model="editingFanMember.interaction_description"
                rows="2"
                class="w-full px-3 py-2 bg-theme-tertiary border border-theme rounded-lg text-theme-primary focus:outline-none focus:ring-2 focus:ring-accent"
              ></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
              <button type="button" @click="showEditFanMemberModal = false" class="btn-secondary">Cancel</button>
              <button type="submit" class="btn-primary" :disabled="savingFanMember">
                {{ savingFanMember ? 'Saving...' : 'Save Changes' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue';
import axios from 'axios';
import * as d3 from 'd3';
import { HourglassChart, AncestorChart, DescendantChart, KinshipChart, RelativesChart, FancyChart, DetailedRenderer, SimpleRenderer, CircleRenderer, ExpanderDirection } from 'topola';
import { CompactFamilyRenderer } from '@/components/CompactFamilyRenderer.js';

/**
 * WiderDetailedRenderer - Extends DetailedRenderer with wider cards
 *
 * Design based on industry best practices (dTree, family-chart, Ancestry, FamilySearch):
 * - Minimum 200px width for readability (vs Topola default 64px)
 * - Gap between spouse cards (connector added post-render)
 *
 * Sources: github.com/ErikGartner/dTree, github.com/donatso/family-chart
 */
class WiderDetailedRenderer extends DetailedRenderer {
  constructor(options) {
    super(options);
    this.MIN_INDI_WIDTH = 200;    // Wider minimum for full names
    this.EXTRA_PADDING = 30;      // Padding on card
    this.SPOUSE_GAP = 30;         // Gap between spouse cards
  }

  getPreferredIndiSize(id) {
    const [width, height] = super.getPreferredIndiSize(id);
    const paddedWidth = width + this.EXTRA_PADDING;
    return [Math.max(paddedWidth, this.MIN_INDI_WIDTH), height];
  }

  getPreferredFamSize(id) {
    // No gap - spouse cards touch (connected by their borders)
    return [0, 0];
  }
}

// State
const loading = ref(false);
const trees = ref([]);
const selectedTreeId = ref(null);
const selectedTree = ref(null);
const stats = ref({});
const activeTab = ref('tree');
const searchQuery = ref('');
const searchResults = ref([]);
const hasSearched = ref(false);
const searchDebounceTimer = ref(null);

// Natural Language Search (RAG-powered)
const searchMode = ref('name'); // 'name' or 'natural'
const nlSearchQuery = ref('');
const nlSearchResults = ref([]);
const nlSearchLoading = ref(false);
const hasNlSearched = ref(false);
const surnames = ref([]);
const selectedSurname = ref(null);
const surnamePersons = ref([]);
const media = ref([]);
const mediaTabPage = ref(1);
const mediaTabPerPage = 42; // 6 columns x 7 rows - matches grid-cols-6
const mediaStatus = ref({ total: 0, imported: 0, pending: 0, percent_complete: 0 });
const recentAdditions = ref([]);
const selectedPerson = ref(null);
const selectedPersonId = computed(() => selectedPerson.value?.id ?? null);
const personMediaPage = ref(1);
const personMediaPerPage = 28; // 4 columns x 7 rows
// Person detail panel section collapse state
const panelSections = ref({
  vitals: true,
  biographical: true,
  nameVariants: false,
  family: true,
  residences: true,
  events: true,
  media: true,
  sources: false,
  externalLinks: false,
  notes: true,
  history: false,
});
const panelActivityLog = ref([]);
const panelActivityLogLoaded = ref(false);
const loadingPanelActivity = ref(false);
const showNewTreeModal = ref(false);
const showImportModal = ref(false);
const newTree = ref({ name: '', description: '' });
const importFile = ref(null);
const importTarget = ref('new');
const importTreeName = ref('');
const importing = ref(false);
const importProgress = ref(null);
const importingMedia = ref(false);
const syncingMediaPaths = ref(false);

// Edit modals state
const showEditPersonModal = ref(false);
const showEditFamilyModal = ref(false);
const editingPerson = ref(null);
const editingFamily = ref(null);
const savingPerson = ref(false);
const savingFamily = ref(false);
const newChildId = ref(null);

// Person edit modal tabs and data
const personEditTab = ref('basic');
const personEvents = ref([]);
const personResidences = ref([]);
const personMediaItems = ref([]);
const personSources = ref([]);
const loadingPersonData = ref(false);
const editMediaPage = ref(1);
const editMediaPerPage = 28; // 4 columns x 7 rows - matches View page
const viewingSource = ref(null); // For source detail popup
const viewingSourceMedia = ref([]); // Media attached to viewingSource via citations
const viewingSourceMediaPage = ref(1); // Paging for source media popup
const viewingSourceMediaPerPage = 8;
const loadingSourceDetails = ref(false);

// Paging for person sources related media (tracks per-source page)
const sourceMediaPages = ref({}); // { sourceId: pageNumber }
const sourceMediaPerPage = 6;

// Paging for citation related media in main Sources tab
const citationMediaPages = ref({}); // { citationId: pageNumber }
const citationMediaPerPage = 4;
const editingPersonEvent = ref(null);
const editingResidence = ref(null);
const savingPersonEvent = ref(false);
const savingResidence = ref(false);
const showPersonEventForm = ref(false);
const showResidenceForm = ref(false);
const availableMedia = ref([]);
const linkingMedia = ref(false);
const availableSources = ref([]);
const linkingSource = ref(false);
const linkSourceToPersonAfterCreate = ref(null); // Person ID to link new source to after creation

// Place autocomplete state for event forms
const showEventPlaceResults = ref(false);
const eventPlaceSearchResults = ref([]);
const selectedEventPlaceHierarchy = ref(null);
const showFamilyEventPlaceResults = ref(false);
const familyEventPlaceSearchResults = ref([]);
const selectedFamilyEventPlaceHierarchy = ref(null);
let placeSearchDebounceTimer = null;

// Create modals state
const showCreatePersonModal = ref(false);
const showCreateFamilyModal = ref(false);
const showDeletePersonModal = ref(false);
const showDeleteFamilyModal = ref(false);
const showAddRelativeModal = ref(false);
const creatingPerson = ref(null);
const creatingFamily = ref(null);
const deletingPerson = ref(null);
const deletingFamily = ref(null);
const addRelativeMode = ref(null); // 'father', 'mother', 'spouse', 'child'
const addRelativeForPerson = ref(null);
const savingCreate = ref(false);
const deleting = ref(false);

// Event modals state (Phase 2.1)
const showEventModal = ref(false);
const showDeleteEventModal = ref(false);
const editingEvent = ref(null);
const deletingEvent = ref(null);
const savingEvent = ref(false);
const deletingEventLoading = ref(false);
const eventPersonId = ref(null);

// Image Lightbox/Popup state
const showImageModal = ref(false);
const enlargedImage = ref(null);
const imageModalSize = ref({ width: 600, height: 400 });

// Family Event modals state (Phase 2.3)
const showFamilyEventModal = ref(false);
const showDeleteFamilyEventModal = ref(false);
const editingFamilyEvent = ref(null);
const deletingFamilyEvent = ref(null);
const savingFamilyEvent = ref(false);
const deletingFamilyEventLoading = ref(false);
const eventFamilyId = ref(null);

// Source management state (Phase 2.4)
const sources = ref([]);
const selectedSource = ref(null);
const showSourceModal = ref(false);
const showDeleteSourceModal = ref(false);
const editingSource = ref(null);
const deletingSource = ref(null);
const savingSource = ref(false);
const deletingSourceLoading = ref(false);
const sourceSearchQuery = ref('');
const sourceSearchResults = ref([]);

// Citation management state (Phase 2.5)
const showCitationModal = ref(false);
const showDeleteCitationModal = ref(false);
const editingCitation = ref(null);
const deletingCitation = ref(null);
const savingCitation = ref(false);
const deletingCitationLoading = ref(false);
const citationContext = ref({ type: null, id: null }); // { type: 'person'|'family'|'source', id: number }

// Citation fact types (GEDCOM standard)
const citationFactTypes = {
  // Vital Events
  'BIRT': 'Birth',
  'DEAT': 'Death',
  'BURI': 'Burial',
  'CREM': 'Cremation',
  // Religious Events
  'CHR': 'Christening',
  'BAPM': 'Baptism',
  'CONF': 'Confirmation',
  'FCOM': 'First Communion',
  'ORDN': 'Ordination',
  'BARM': 'Bar Mitzvah',
  'BASM': 'Bas Mitzvah',
  // Life Events
  'GRAD': 'Graduation',
  'RETI': 'Retirement',
  'ADOP': 'Adoption',
  'NATU': 'Naturalization',
  'EMIG': 'Emigration',
  'IMMI': 'Immigration',
  // Records
  'CENS': 'Census',
  'PROB': 'Probate',
  'WILL': 'Will',
  'MIL': 'Military Service',
  // Attributes
  'OCCU': 'Occupation',
  'EDUC': 'Education',
  'RESI': 'Residence',
  'RELI': 'Religion',
  'TITL': 'Title',
  // Family Events
  'MARR': 'Marriage',
  'DIV': 'Divorce',
  'ENGA': 'Engagement',
  'MARB': 'Marriage Bann',
  'MARC': 'Marriage Contract',
  'MARL': 'Marriage License',
  'MARS': 'Marriage Settlement',
  'ANUL': 'Annulment',
  // General
  'EVEN': 'Custom Event',
  'NOTE': 'Note',
  'PHOT': 'Photograph',
};

// Citation quality levels (GEDCOM standard)
const citationQualityLevels = {
  0: 'Unreliable evidence or estimated data',
  1: 'Questionable reliability of evidence',
  2: 'Secondary evidence, officially recorded after event',
  3: 'Direct and primary evidence',
};

// Repository management state (Phase 2.6)
const repositories = ref([]);
const selectedRepository = ref(null);
const showRepositoryModal = ref(false);
const showDeleteRepositoryModal = ref(false);
const editingRepository = ref(null);
const deletingRepository = ref(null);
const savingRepository = ref(false);
const deletingRepositoryLoading = ref(false);
const repositorySearchQuery = ref('');

// Reports state (Phase 2.7)
const missingDataTypes = ref({});
const missingDataSummary = ref(null);
const missingDataReport = ref({});
const selectedReportType = ref('missing_data');
const loadingReport = ref(false);
const expandedReportSection = ref(null);

// Media modals state (Phase 3.1, 3.2)
const showMediaUploadModal = ref(false);
const showMediaDetailModal = ref(false);
const showDeleteMediaModal = ref(false);
const showLinkMediaToPersonModal = ref(false);
const linkMediaToPersonSearch = ref('');
const selectedMedia = ref(null);
const deletingMedia = ref(null);
const mediaUploadFile = ref(null);
const mediaUploadData = ref({ title: '', date: '', description: '', person_id: null });
const isDraggingMedia = ref(false);
const uploadingMediaFile = ref(false);
const deletingMediaLoading = ref(false);
const linkPersonToMediaId = ref(null);
const linkFamilyToMediaId = ref(null);
const allFamilies = ref([]);
const mediaFileInput = ref(null);
const mediaIntakePreview = ref(null);
const mediaIntakePreviewError = ref('');
const loadingMediaIntakePreview = ref(false);

// Media Categories state (Phase 3.6)
const selectedMediaCategory = ref('all');
const mediaCategories = [
  { id: 'all', label: 'All', icon: '📁' },
  { id: 'photo', label: 'Photos', icon: '📷' },
  { id: 'document', label: 'Documents', icon: '📄' },
  { id: 'certificate', label: 'Certificates', icon: '📜' },
  { id: 'census', label: 'Census', icon: '📋' },
  { id: 'military', label: 'Military', icon: '🎖️' },
  { id: 'obituary', label: 'Obituaries', icon: '🕯️' },
  { id: 'headstone', label: 'Headstones', icon: '🪦' },
  { id: 'other', label: 'Other', icon: '📎' }
];
const mediaCategoryCounts = ref({});
const editingMediaType = ref(null);

// Document Transcription state (Phase 3.7)
const editingTranscription = ref(false);
const transcriptionText = ref('');
const savingTranscription = ref(false);
const transcriptionQueue = ref([]);
const showTranscriptionQueueModal = ref(false);
const intakeRuns = ref([]);
const showIntakeRunsModal = ref(false);
const createDefaultIntakeRunStageForm = () => ({
  root_path: '/Library/FamilyTree',
  packet_label: '',
  limit: '100',
  unprocessed_only: false,
});
const intakeRunStageForm = ref(createDefaultIntakeRunStageForm());
const stagingIntakeRun = ref(false);
const intakeRunStageError = ref('');
const selectedIntakeRun = ref(null);
const selectedIntakeRunPreview = ref(null);
const selectedIntakeRunSelectedPacket = ref(null);
const selectedIntakeRunWorkspace = ref(null);
const selectedIntakeRunProposalDraft = ref(null);
const loadingIntakeRunPreview = ref(false);
const selectedIntakeRunPacketLabel = ref('');
const intakeRunPacketSearch = ref('');
const intakeRunPacketStageFilter = ref('all');
const intakeRunPacketQuestionFilter = ref('all');
const intakeRunPacketProposalFilter = ref('all');
const intakeRunPacketSort = ref('priority');
const intakeProposalApprovedSections = ref([]);
const intakeProposalTargetPersonId = ref('');
const intakeProposalRelationshipType = ref('');
const intakeProposalRelatedPersonId = ref('');
const selectedIntakeRunApprovalDraftPreview = ref(null);
const loadingIntakeApprovalDraftPreview = ref(false);
const intakeApprovalDraftPreviewError = ref('');
const selectedIntakeRunProposalGenerationResult = ref(null);
const generatingIntakeRunProposals = ref(false);
const selectedIntakeRunGeneratedProposalReview = ref(null);
const loadingIntakeGeneratedProposals = ref(false);
const intakeGeneratedProposalsError = ref('');
const actingOnGeneratedProposalId = ref('');
const proposalRowErrors = ref({});
const bulkApprovingPendingProposals = ref(false);
const selectedIntakeRunApprovalApplyResult = ref(null);
const applyingIntakeApprovalDraft = ref(false);
const intakeRunDecisionNotes = ref('');
const savingIntakeRunDecision = ref(false);
let intakeApprovalDraftPreviewTimer = null;

// Windows SSH Import state removed 2026-01-10 - SSH access deprecated, use Nextcloud

// Phase 4: Export, Backup & Data Integrity state
const showExportModal = ref(false);
const showValidationModal = ref(false);
const showStatisticsModal = ref(false);
const exportingGedcom = ref(false);
const validatingTree = ref(false);
const loadingStatistics = ref(false);
const gedcomExportData = ref(null);
const validationResults = ref(null);
const treeStatistics = ref(null);
const backupStatus = ref(null);
const loadingBackupStatus = ref(false);

// Phase 5: Advanced Visualization & Analysis state
const showTimelineModal = ref(false);
const showRelationshipModal = ref(false);
const showPlacesModal = ref(false);
const timelineData = ref(null);
const loadingTimeline = ref(false);
const relationshipResult = ref(null);
const calculatingRelationship = ref(false);
const relationshipPerson1 = ref(null);
const relationshipPerson2 = ref(null);
const placesData = ref(null);
const loadingPlaces = ref(false);

// Person Activity Log state
const showPersonActivityLog = ref(false);
const personActivityLog = ref([]);
const loadingPersonActivityLog = ref(false);

// Name Variants state
const showNameVariants = ref(false);
const nameVariantsPersonId = ref(null);
const nameVariants = ref([]);
const loadingNameVariants = ref(false);
const showAddNameVariant = ref(false);
const newVariantGiven = ref('');
const newVariantSurname = ref('');
const newVariantType = ref('');

// Reports enhancement state
const reportPersonId = ref(null);
const generatingReport = ref(false);
const generatedReport = ref(null);
const currentTreeRootPersonId = computed(() => {
  const tree = trees.value.find(t => t.id === selectedTreeId.value);
  return tree?.root_person_id ?? null;
});

// Timeline Tab state
const timelineTabPersonId = ref(null);
const timelineTabData = ref(null);
const loadingTimelineTab = ref(false);
const timelineIncludeFamily = ref(true);
const timelineIncludeParents = ref(true);
const timelineIncludeSiblings = ref(false);
const timelineEventTypes = ref([]); // Empty = all types
const timelineStartYear = ref(null);
const timelineEndYear = ref(null);
const timelineEventConfig = ref({});

// Place Authority state
const placeSearchQuery = ref('');
const placeSearchResults = ref([]);
const searchingPlaces = ref(false);
const selectedPlaceId = ref(null);
const selectedPlaceHierarchy = ref(null);
const showPlaceNormalizationModal = ref(false);
const normalizingPlaces = ref(false);
const placeNormalizationStats = ref(null);

// Phase 6: Reports & Printing state
const showFamilyGroupSheetModal = ref(false);
const showPedigreeModal = ref(false);
const showDescendantModal = ref(false);
const showMissingDataModal = ref(false);
const showIndividualSummaryModal = ref(false);
const familyGroupSheetData = ref(null);

// Tools tab person/family selectors
const toolsTimelinePersonId = ref(null);
const toolsPedigreePersonId = ref(null);
const toolsDescendantPersonId = ref(null);
const toolsFamilyGroupSheetId = ref(null);
const toolsSummaryPersonId = ref(null);
const toolsAhnentafelPersonId = ref(null);
const pedigreeData = ref(null);
const descendantData = ref(null);
const individualSummaryData = ref(null);
const showAhnentafelModal = ref(false);
const ahnentafelData = ref(null);
const ahnentafelGenerations = ref(10);
const reportGenerations = ref(4);

// Phase 7: Privacy & Collaboration state
const showPrivacySettingsModal = ref(false);
const showCollaboratorsModal = ref(false);
const showPersonActivityLogModal = ref(false);
const showLivingStatsModal = ref(false);
const privacySettings = ref({
  privacy: 'private',
  living_privacy: 'hide_details',
  living_years_threshold: 100,
  default_media_privacy: 'shared',
  allow_public_search: false
});
const collaborators = ref([]);
const pendingInvitations = ref([]);
const activityLog = ref([]);
const activityLogOffset = ref(0);
const activityLogHasMore = ref(false);
const livingStats = ref(null);
const detectingLiving = ref(false);
const savingPrivacy = ref(false);
const loadingCollaborators = ref(false);
const loadingActivityLog = ref(false);
const loadingLivingStats = ref(false);
const newInviteEmail = ref('');
const newInviteRole = ref('viewer');
const sendingInvite = ref(false);

// Phase 8: AI-Assisted Research state
const showResearchHintsModal = ref(false);
const showNameVariationsModal = ref(false);
const showResearchTasksModal = ref(false);
const showResearchStatsModal = ref(false);
const researchHints = ref([]);
const nameVariations = ref([]);
const researchTasks = ref([]);
const researchStats = ref(null);
const loadingResearchHints = ref(false);
const loadingNameVariations = ref(false);
const loadingResearchTasks = ref(false);
const loadingResearchStats = ref(false);
const generatingHints = ref(false);
const researchHintsFilter = ref('pending');
const newVariationName = ref('');
const newVariationType = ref('surname');
const newVariationValue = ref('');
const suggestedVariations = ref([]);
const loadingSuggestions = ref(false);

// AI Research Modal state (Sprint 1: A.1)
const showAIResearchModal = ref(false);
const aiResearchPersonId = ref(null);
const aiResearchPersonName = ref('');
const aiResearchResult = ref(null);
const aiResearchLoading = ref(false);
const aiResearchMode = ref('research'); // 'research' or 'brick-wall'
const aiResearchError = ref(null);
// Extract & Apply state
const extractedResearchItems = ref([]);
const extractingResearchData = ref(false);
const applyingResearchData = ref(false);

// Phase 9: External Integrations state
const showExternalConnectionsModal = ref(false);
const showExternalRecordsModal = ref(false);
const showPersonExternalLinksModal = ref(false);
const showSyncHistoryModal = ref(false);
const showExternalStatsModal = ref(false);
const externalConnections = ref([]);
const externalRecords = ref([]);
const personExternalLinks = ref([]);
const syncHistory = ref([]);
const externalStats = ref(null);
const supportedServices = ref([]);
const loadingExternalConnections = ref(false);
const loadingExternalRecords = ref(false);
const loadingPersonExternalLinks = ref(false);
const loadingSyncHistory = ref(false);
const loadingExternalStats = ref(false);
const loadingSupportedServices = ref(false);
const savingExternalConnection = ref(false);
const startingSync = ref(false);
const selectedConnectionId = ref(null);
const externalRecordsFilter = ref('pending');
const newConnection = ref({
  service_type: 'wikitree',
  service_user_id: '',
  access_token: '',
  refresh_token: '',
  token_expires_at: '',
  settings: {}
});
const editingConnection = ref(null);
const newPersonLink = ref({
  service_type: 'familysearch',
  external_person_id: '',
  link_type: 'confirmed',
  sync_enabled: true
});

// FAN Cluster Research state
// N98: Research Search History
const researchLogs = ref([]);
const researchLogSummary = ref([]);
const researchLogFilter = ref('');
const researchLogNegative = ref('');
const loadingResearchLogs = ref(false);

const filteredResearchLogs = computed(() => {
  return researchLogs.value.filter(log => {
    if (researchLogFilter.value && log.repository_searched !== researchLogFilter.value) return false;
    if (researchLogNegative.value !== '' && String(log.negative_result) !== researchLogNegative.value) return false;
    return true;
  });
});

const fanClusters = ref([]);
const selectedFanCluster = ref(null);
const fanClusterMembers = ref([]);
const fanClusterAnalysis = ref(null);
const fanClusterSuggestions = ref([]);
const fanClusterNetwork = ref(null);
const loadingFanClusters = ref(false);
const loadingFanClusterMembers = ref(false);
const loadingFanClusterAnalysis = ref(false);
const loadingFanClusterSuggestions = ref(false);
const loadingFanClusterNetwork = ref(false);
const showCreateFanClusterModal = ref(false);
const showFanClusterDetailModal = ref(false);
const showAddFanMemberModal = ref(false);
const showEditFanMemberModal = ref(false);
const savingFanCluster = ref(false);
const savingFanMember = ref(false);
const deletingFanCluster = ref(false);
const deletingFanMember = ref(false);
const fanClusterPersonId = ref(null);
const fanCooccurrences = ref([]);
const loadingFanCooccurrences = ref(false);
const fanClusterExtractSource = ref(null); // 'census', 'witnesses', 'church'
const extractingFanMembers = ref(false);
const extractedFanMembers = ref([]);
const newFanCluster = ref({
  name: '',
  research_period: '',
  location: '',
  notes: ''
});
const newFanMember = ref({
  member_name: '',
  member_person_id: null,
  relationship_type: 'other',
  source_record_type: 'other',
  source_citation: '',
  interaction_date: '',
  interaction_description: '',
  confidence: 'medium'
});
const editingFanMember = ref(null);
const fanRelationshipTypes = {
  friend: 'Friend - social relationship',
  associate: 'Associate - business, legal, or professional relationship',
  neighbor: 'Neighbor - lived nearby (same household, next door, same street)',
  witness: 'Witness - appeared as witness on document (marriage, will, deed)',
  business: 'Business partner or associate',
  church: 'Church member - same congregation, godparent, baptism sponsor',
  other: 'Other documented relationship'
};
const fanSourceRecordTypes = {
  census: 'Census record',
  marriage: 'Marriage record or certificate',
  marriage_witness: 'Witness on marriage record',
  deed: 'Land deed or property record',
  probate: 'Probate or estate record',
  will: 'Will or testament',
  will_witness: 'Witness on will',
  church: 'Church record (baptism, confirmation, membership)',
  godparent: 'Godparent or baptism sponsor',
  military: 'Military record',
  tax: 'Tax record',
  voter: 'Voter registration',
  court: 'Court record',
  newspaper: 'Newspaper mention',
  directory: 'City directory',
  cemetery: 'Cemetery or burial record',
  bond: 'Bond (marriage, estate, other)',
  other: 'Other source'
};
const fanConfidenceLevels = {
  high: 'High - clear documentation with unambiguous identification',
  medium: 'Medium - reasonable inference from available evidence',
  low: 'Low - possible connection requiring further research'
};

// Face Tagging state (Phase 3.4)
const showFaceTaggingModal = ref(false);
const mediaPreviewImg = ref(null);
const faceTaggingImg = ref(null);
const faceTaggingContainer = ref(null);
const faceTaggingImageSize = ref({ width: 0, height: 0 });
const mediaPreviewImageSize = ref({ width: 0, height: 0 });
const isDrawingFaceRegion = ref(false);
const drawingRegion = ref(null);
const newFaceRegion = ref(null);
const newFaceRegionPersonId = ref(null);
const savingFaceRegion = ref(false);
const confirmingFaceTag = ref(false);
const unconfirmedFaces = ref([]);
const unconfirmedFaceCount = ref(0);

// GEDCOM Event Types mapping
const eventTypes = {
  'CHR': 'Christening',
  'BAPM': 'Baptism',
  'CONF': 'Confirmation',
  'BARM': 'Bar Mitzvah',
  'BASM': 'Bas Mitzvah',
  'BLES': 'Blessing',
  'CHRA': 'Adult Christening',
  'FCOM': 'First Communion',
  'ORDN': 'Ordination',
  'GRAD': 'Graduation',
  'EMIG': 'Emigration',
  'IMMI': 'Immigration',
  'NATU': 'Naturalization',
  'RETI': 'Retirement',
  'CENS': 'Census',
  'PROB': 'Probate',
  'WILL': 'Will',
  'CREM': 'Cremation',
  'ADOP': 'Adoption',
  'EVEN': 'Custom Event',
  'MIL': 'Military Service',
  'EDUC': 'Education',
  'OCCU': 'Occupation',
};

// GEDCOM Family Event Types mapping (Phase 2.3)
const familyEventTypes = {
  'MARB': 'Marriage Bann',
  'MARC': 'Marriage Contract',
  'MARL': 'Marriage License',
  'MARS': 'Marriage Settlement',
  'ENGA': 'Engagement',
  'ANUL': 'Annulment',
  'CENS': 'Census',
  'EVEN': 'Custom Event',
};

// Computed for available children in family edit
const availableChildren = computed(() => {
  if (!editingFamily.value || !allPersons.value.length) return [];
  const existingChildIds = (editingFamily.value.children || []).map(c => c.id);
  return allPersons.value.filter(p => !existingChildIds.includes(p.id));
});

// Person media pagination computed
const personMediaTotalPages = computed(() => {
  if (!selectedPerson.value?.media?.length) return 0;
  return Math.ceil(selectedPerson.value.media.length / personMediaPerPage);
});

const paginatedPersonMedia = computed(() => {
  if (!selectedPerson.value?.media?.length) return [];
  const start = (personMediaPage.value - 1) * personMediaPerPage;
  return selectedPerson.value.media.slice(start, start + personMediaPerPage);
});

// Media tab pagination computed
const mediaTabTotalPages = computed(() => {
  if (!media.value?.length) return 0;
  return Math.ceil(media.value.length / mediaTabPerPage);
});

const paginatedMedia = computed(() => {
  if (!media.value?.length) return [];
  const start = (mediaTabPage.value - 1) * mediaTabPerPage;
  return media.value.slice(start, start + mediaTabPerPage);
});

// Filtered media for linking to person (excludes already linked media)
const availableMediaForPerson = computed(() => {
  if (!media.value?.length || !selectedPerson.value) return [];
  const linkedIds = new Set((selectedPerson.value.media || []).map(m => m.id));
  let filtered = media.value.filter(m => !linkedIds.has(m.id));

  // Apply search filter
  if (linkMediaToPersonSearch.value.trim()) {
    const search = linkMediaToPersonSearch.value.toLowerCase();
    filtered = filtered.filter(m =>
      (m.title || '').toLowerCase().includes(search) ||
      (m.original_path || '').toLowerCase().includes(search)
    );
  }

  return filtered.slice(0, 50); // Limit to 50 for performance
});

// Edit modal media pagination computed
const editMediaTotalPages = computed(() => {
  if (!personMediaItems.value?.length) return 0;
  return Math.ceil(personMediaItems.value.length / editMediaPerPage);
});

const paginatedEditMedia = computed(() => {
  if (!personMediaItems.value?.length) return [];
  const start = (editMediaPage.value - 1) * editMediaPerPage;
  return personMediaItems.value.slice(start, start + editMediaPerPage);
});

// Source Detail popup media pagination
const viewingSourceMediaTotalPages = computed(() => {
  if (!viewingSourceMedia.value?.length) return 0;
  return Math.ceil(viewingSourceMedia.value.length / viewingSourceMediaPerPage);
});

const paginatedViewingSourceMedia = computed(() => {
  if (!viewingSourceMedia.value?.length) return [];
  const start = (viewingSourceMediaPage.value - 1) * viewingSourceMediaPerPage;
  return viewingSourceMedia.value.slice(start, start + viewingSourceMediaPerPage);
});

// Helper functions for per-source media paging in person edit
const getSourceMediaPage = (sourceId) => {
  return sourceMediaPages.value[sourceId] || 1;
};

const setSourceMediaPage = (sourceId, page) => {
  sourceMediaPages.value[sourceId] = page;
};

const getSourceMediaTotalPages = (mediaArray) => {
  if (!mediaArray?.length) return 0;
  return Math.ceil(mediaArray.length / sourceMediaPerPage);
};

const getPaginatedSourceMedia = (mediaArray, sourceId) => {
  if (!mediaArray?.length) return [];
  const page = getSourceMediaPage(sourceId);
  const start = (page - 1) * sourceMediaPerPage;
  return mediaArray.slice(start, start + sourceMediaPerPage);
};

// Helper functions for per-citation media paging in main Sources tab
const getCitationMediaPage = (citationId) => {
  return citationMediaPages.value[citationId] || 1;
};

const setCitationMediaPage = (citationId, page) => {
  citationMediaPages.value[citationId] = page;
};

const getCitationMediaTotalPages = (mediaArray) => {
  if (!mediaArray?.length) return 0;
  return Math.ceil(mediaArray.length / citationMediaPerPage);
};

const getPaginatedCitationMedia = (mediaArray, citationId) => {
  if (!mediaArray?.length) return [];
  const page = getCitationMediaPage(citationId);
  const start = (page - 1) * citationMediaPerPage;
  return mediaArray.slice(start, start + citationMediaPerPage);
};

// Tree view state
const treeSvg = ref(null);
const homePersonId = ref(null);
const treeViewMode = ref('hourglass');
const treeGenerations = ref(4); // Default to 4 generations
const treeLoaded = ref(false);
const allPersons = ref([]);
const sortedPersons = computed(() => {
  return [...allPersons.value].sort((a, b) => {
    const surnameA = (a.surname || '').toLowerCase();
    const surnameB = (b.surname || '').toLowerCase();
    if (surnameA !== surnameB) return surnameA.localeCompare(surnameB);
    const givenA = (a.given_name || '').toLowerCase();
    const givenB = (b.given_name || '').toLowerCase();
    return givenA.localeCompare(givenB);
  });
});
// Sorted male persons for family husband dropdowns
const sortedMalePersons = computed(() => {
  return sortedPersons.value.filter(p => p.sex === 'M' || p.sex === 'U');
});
// Sorted female persons for family wife dropdowns
const sortedFemalePersons = computed(() => {
  return sortedPersons.value.filter(p => p.sex === 'F' || p.sex === 'U');
});

// FAN Cluster computed properties
const fanMembersByType = computed(() => {
  const grouped = {};
  for (const member of fanClusterMembers.value) {
    const type = member.relationship_type || 'other';
    if (!grouped[type]) grouped[type] = [];
    grouped[type].push(member);
  }
  return grouped;
});

// Helper to get confidence badge classes
const getConfidenceBadgeClass = (confidence) => {
  switch (confidence) {
    case 'high': return 'px-2 py-0.5 rounded text-xs bg-green-500/20 text-green-400';
    case 'medium': return 'px-2 py-0.5 rounded text-xs bg-yellow-500/20 text-yellow-400';
    case 'low': return 'px-2 py-0.5 rounded text-xs bg-red-500/20 text-red-400';
    default: return 'px-2 py-0.5 rounded text-xs bg-gray-500/20 text-gray-400';
  }
};

const treeData = ref({ persons: {}, families: {} });
const hoveredPerson = ref(null);
const hoverPanelStyle = ref({});
const contextMenu = ref({ visible: false, x: 0, y: 0, personId: null });
const currentZoom = ref(null);
const chartInstance = ref(null);
const isFullscreen = ref(false);
const zoomScale = ref(1);
const zoomPercent = computed(() => Math.round(zoomScale.value * 100));

const treeModes = [
  { id: 'hourglass', label: 'Hourglass' },
  { id: 'ancestors', label: 'Ancestors' },
  { id: 'descendants', label: 'Descendants' },
  { id: 'kinship', label: 'Kinship' },
  { id: 'relatives', label: 'Relatives' },
  { id: 'fancy', label: 'Fancy' },
];

// N147: Focus navigation — click person to re-center without changing home person
const focusPersonId = ref(null);
const isFocusedAway = computed(() => focusPersonId.value && focusPersonId.value !== homePersonId.value);

// N147: Branch expanders — collapse/expand branches
const collapsedFamilies = ref(new Set());
const collapsedIndis = ref(new Set());
const collapsedSpouses = ref(new Set());

const rendererMode = ref('detailed'); // 'detailed', 'simple', 'circle'

const tabs = [
  { id: 'tree', label: 'Family Tree' },
  { id: 'search', label: 'Search' },
  { id: 'surnames', label: 'By Surname' },
  { id: 'timeline', label: 'Timeline' },
  { id: 'sources', label: 'Sources' },
  { id: 'repositories', label: 'Repositories' },
  { id: 'media', label: 'Media' },
  { id: 'fan-cluster', label: 'FAN Cluster' },
  { id: 'research-history', label: 'Search History' }, // N98
  { id: 'reports', label: 'Reports' },
  { id: 'tools', label: 'Tools' },
  { id: 'recent', label: 'Recent' },
];

// Methods
const loadTrees = async () => {
  try {
    const response = await axios.get('/api/genealogy/trees');
    trees.value = response.data.data.trees;

    // Restore saved tree selection from localStorage
    const savedTreeId = localStorage.getItem('genealogy_selected_tree');
    if (savedTreeId) {
      const treeExists = trees.value.some(t => t.id === parseInt(savedTreeId));
      if (treeExists) {
        selectedTreeId.value = parseInt(savedTreeId);
        // Load tree data (which will also restore home person)
        await loadTreeData();
      } else {
        // Clear invalid saved tree
        localStorage.removeItem('genealogy_selected_tree');
        console.warn('Saved tree no longer exists, cleared from localStorage');
      }
    }
  } catch (error) {
    console.error('Failed to load trees:', error);
  }
};

const getPersonDeepLinkId = () => {
  const raw = new URLSearchParams(window.location.search).get('person');
  if (!raw || !/^\d+$/.test(raw)) return null;
  return parseInt(raw, 10);
};

const openPersonDeepLink = async () => {
  const personId = getPersonDeepLinkId();
  if (!personId) return;

  try {
    const response = await axios.get(`/api/genealogy/persons/${personId}`);
    const person = response.data.data.person;
    if (!person?.id) return;

    activeTab.value = 'tree';

    if (person.tree_id && selectedTreeId.value !== person.tree_id) {
      selectedTreeId.value = person.tree_id;
      localStorage.setItem('genealogy_selected_tree', person.tree_id);
      await loadTreeData();
    } else if (selectedTreeId.value && !treeLoaded.value) {
      await loadTreeData();
    }

    homePersonId.value = person.id;
    focusPersonId.value = null;
    if (selectedTreeId.value) {
      localStorage.setItem(`genealogy_home_person_${selectedTreeId.value}`, person.id);
    }
    selectedPerson.value = person;
    await nextTick();
    await loadTreeChartData();
  } catch (error) {
    console.error('Failed to open person deep link:', error);
  }
};

const onTreeChange = () => {
  searchQuery.value = '';
  searchResults.value = [];
  selectedSurname.value = null;
  surnamePersons.value = [];
  homePersonId.value = null;
  treeLoaded.value = false;
  allPersons.value = [];

  // Save selected tree to localStorage
  if (selectedTreeId.value) {
    localStorage.setItem('genealogy_selected_tree', selectedTreeId.value);
  } else {
    localStorage.removeItem('genealogy_selected_tree');
  }

  loadTreeData();
};

const searchPersons = async () => {
  if (!searchQuery.value || !selectedTreeId.value) return;

  loading.value = true;
  hasSearched.value = true;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/persons/search`, {
      params: { q: searchQuery.value }
    });
    searchResults.value = response.data.data.persons;
  } catch (error) {
    console.error('Search failed:', error);
  } finally {
    loading.value = false;
  }
};

// Debounced typeahead search - auto-triggers after 300ms of typing
// N98: Lazy-load when tabs become active
watch(activeTab, (tab) => {
  if (tab === 'research-history') {
    loadResearchLogs();
  }
});

// N98: Reload research logs when selected person changes
watch(selectedPersonId, () => {
  if (activeTab.value === 'research-history') {
    researchLogs.value = [];
    researchLogSummary.value = [];
    loadResearchLogs();
  }
});

// N93: Load co-occurrences when FAN cluster person changes
watch(fanClusterPersonId, (newId) => {
  fanCooccurrences.value = [];
  if (newId) loadFanCooccurrences();
});

watch(searchQuery, (newVal) => {
  // Clear any pending search
  if (searchDebounceTimer.value) {
    clearTimeout(searchDebounceTimer.value);
  }

  // Reset results if empty
  if (!newVal || newVal.length < 2) {
    searchResults.value = [];
    hasSearched.value = false;
    return;
  }

  // Debounce: trigger search 300ms after user stops typing
  searchDebounceTimer.value = setTimeout(() => {
    searchPersons();
  }, 300);
});

// Natural Language Search (RAG-powered)
const naturalLanguageSearch = async () => {
  if (!nlSearchQuery.value || nlSearchQuery.value.trim().length < 3) return;

  nlSearchLoading.value = true;
  hasNlSearched.value = true;
  try {
    const response = await axios.post('/api/genealogy/search/natural-language', {
      query: nlSearchQuery.value,
      limit: 10
    });
    if (response.data.success) {
      nlSearchResults.value = response.data.data.persons;
    } else {
      console.error('Natural language search failed:', response.data.error?.message);
      nlSearchResults.value = [];
    }
  } catch (error) {
    console.error('Natural language search failed:', error);
    nlSearchResults.value = [];
  } finally {
    nlSearchLoading.value = false;
  }
};

// View person from natural language search result
const viewNlSearchPerson = (person) => {
  if (person.id) {
    // Create a minimal person object to pass to selectPerson
    const personForSelect = {
      id: person.id,
      given_name: person.name?.split(' ')[0] || '',
      surname: person.name?.split(' ').slice(1).join(' ') || '',
      birth_date: person.birth_date,
      death_date: person.death_date,
      birth_place: person.birth_place,
      death_place: person.death_place,
      sex: person.sex
    };
    selectPerson(personForSelect);
  }
};

const loadSurname = async (surname) => {
  selectedSurname.value = surname;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/surnames/${encodeURIComponent(surname)}`);
    surnamePersons.value = response.data.data.persons;
  } catch (error) {
    console.error('Failed to load surname:', error);
  }
};

const selectPerson = async (person) => {
  try {
    personMediaPage.value = 1; // Reset media pagination
    panelActivityLog.value = [];
    panelActivityLogLoaded.value = false;
    loadingPanelActivity.value = false;
    const response = await axios.get(`/api/genealogy/persons/${person.id}`);
    selectedPerson.value = response.data.data.person;
  } catch (error) {
    console.error('Failed to load person:', error);
  }
};

// Lazy load activity log for person detail panel
const loadPanelActivityLog = async () => {
  if (panelActivityLogLoaded.value || loadingPanelActivity.value || !selectedPerson.value) return;
  loadingPanelActivity.value = true;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedPerson.value.tree_id}/activity-log`, {
      params: { person_id: selectedPerson.value.id, limit: 20 }
    });
    panelActivityLog.value = response.data.data?.activities || [];
    panelActivityLogLoaded.value = true;
  } catch (error) {
    console.error('Failed to load activity log:', error);
  } finally {
    loadingPanelActivity.value = false;
  }
};

// Toggle panel section with lazy loading
const togglePanelSection = (section) => {
  panelSections.value[section] = !panelSections.value[section];
  if (panelSections.value[section] && section === 'history') {
    loadPanelActivityLog();
  }
};

// Helper: get external service URL
const getExternalServiceUrl = (serviceType, externalId) => {
  const urls = {
    familysearch: `https://www.familysearch.org/tree/person/details/${externalId}`,
    ancestry: `https://www.ancestry.com/family-tree/person/${externalId}`,
    wikitree: `https://www.wikitree.com/wiki/${externalId}`,
    findagrave: `https://www.findagrave.com/memorial/${externalId}`,
    myheritage: `https://www.myheritage.com/person-${externalId}`,
  };
  return urls[serviceType] || '#';
};

// Edit Person Methods
const openEditPerson = async (person) => {
  editingPerson.value = { ...person };
  personEditTab.value = 'basic';
  showEditPersonModal.value = true;

  // Load additional data for tabs
  loadingPersonData.value = true;
  try {
    const [eventsRes, residencesRes, mediaRes, sourcesRes] = await Promise.all([
      axios.get(`/api/genealogy/persons/${person.id}/events`),
      axios.get(`/api/genealogy/persons/${person.id}/residences`),
      axios.get(`/api/genealogy/persons/${person.id}/media`),
      axios.get(`/api/genealogy/persons/${person.id}/sources`)
    ]);
    personEvents.value = eventsRes.data.data?.events || eventsRes.data.data || [];
    personResidences.value = residencesRes.data.data?.residences || residencesRes.data.data || [];
    personMediaItems.value = mediaRes.data.data?.media || mediaRes.data.data || [];
    personSources.value = sourcesRes.data.data || [];
  } catch (error) {
    console.error('Failed to load person data:', error);
  } finally {
    loadingPersonData.value = false;
  }
};

const closeEditPerson = () => {
  showEditPersonModal.value = false;
  editingPerson.value = null;
  personEvents.value = [];
  personResidences.value = [];
  personMediaItems.value = [];
  personSources.value = [];
  editingPersonEvent.value = null;
  editingResidence.value = null;
  showPersonEventForm.value = false;
  showResidenceForm.value = false;
};

const savePerson = async () => {
  if (!editingPerson.value) return;
  savingPerson.value = true;
  try {
    await axios.put(`/api/genealogy/persons/${editingPerson.value.id}`, editingPerson.value);
    // Refresh person data
    if (selectedPerson.value?.id === editingPerson.value.id) {
      await selectPerson(editingPerson.value);
    }
    closeEditPerson();
    await loadTreeData();
  } catch (error) {
    console.error('Failed to save person:', error);
    alert('Failed to save person: ' + (error.response?.data?.message || error.message));
  } finally {
    savingPerson.value = false;
  }
};

// Person Edit Modal - Event CRUD
const openPersonEventForm = (event = null) => {
  if (event) {
    editingPersonEvent.value = { ...event };
  } else {
    editingPersonEvent.value = {
      id: null,
      event_type: '',
      event_date: '',
      place: '',
      description: '',
      source_id: null
    };
  }
  showPersonEventForm.value = true;
};

const savePersonEvent = async () => {
  if (!editingPerson.value || !editingPersonEvent.value) return;
  savingPersonEvent.value = true;
  try {
    if (editingPersonEvent.value.id) {
      await axios.put(`/api/genealogy/events/${editingPersonEvent.value.id}`, editingPersonEvent.value);
    } else {
      await axios.post(`/api/genealogy/persons/${editingPerson.value.id}/events`, editingPersonEvent.value);
    }
    // Reload events
    const res = await axios.get(`/api/genealogy/persons/${editingPerson.value.id}/events`);
    personEvents.value = res.data.data || [];
    showPersonEventForm.value = false;
    editingPersonEvent.value = null;
  } catch (error) {
    console.error('Failed to save event:', error);
    alert('Failed to save event: ' + (error.response?.data?.message || error.message));
  } finally {
    savingPersonEvent.value = false;
  }
};

const deletePersonEvent = async (eventId) => {
  if (!confirm('Are you sure you want to delete this event?')) return;
  try {
    await axios.delete(`/api/genealogy/events/${eventId}`);
    personEvents.value = personEvents.value.filter(e => e.id !== eventId);
  } catch (error) {
    console.error('Failed to delete event:', error);
    alert('Failed to delete event: ' + (error.response?.data?.message || error.message));
  }
};

// Person Edit Modal - Residence CRUD
const openResidenceForm = (residence = null) => {
  if (residence) {
    editingResidence.value = { ...residence };
  } else {
    editingResidence.value = {
      id: null,
      residence_date: '',
      place: '',
      latitude: null,
      longitude: null,
      source_id: null
    };
  }
  showResidenceForm.value = true;
};

const saveResidence = async () => {
  if (!editingPerson.value || !editingResidence.value) return;
  savingResidence.value = true;
  try {
    if (editingResidence.value.id) {
      await axios.put(`/api/genealogy/residences/${editingResidence.value.id}`, editingResidence.value);
    } else {
      await axios.post(`/api/genealogy/persons/${editingPerson.value.id}/residences`, editingResidence.value);
    }
    // Reload residences
    const res = await axios.get(`/api/genealogy/persons/${editingPerson.value.id}/residences`);
    personResidences.value = res.data.data || [];
    showResidenceForm.value = false;
    editingResidence.value = null;
  } catch (error) {
    console.error('Failed to save residence:', error);
    alert('Failed to save residence: ' + (error.response?.data?.message || error.message));
  } finally {
    savingResidence.value = false;
  }
};

const deleteResidence = async (residenceId) => {
  if (!confirm('Are you sure you want to delete this residence?')) return;
  try {
    await axios.delete(`/api/genealogy/residences/${residenceId}`);
    personResidences.value = personResidences.value.filter(r => r.id !== residenceId);
  } catch (error) {
    console.error('Failed to delete residence:', error);
    alert('Failed to delete residence: ' + (error.response?.data?.message || error.message));
  }
};

// Person Edit Modal - Media Link/Unlink
const loadAvailableMedia = async () => {
  if (!selectedTree.value) return;
  try {
    const res = await axios.get(`/api/genealogy/trees/${selectedTree.value.id}/media`);
    // Filter out already linked media
    const linkedIds = personMediaItems.value.map(m => m.id);
    availableMedia.value = (res.data.data || []).filter(m => !linkedIds.includes(m.id));
  } catch (error) {
    console.error('Failed to load available media:', error);
  }
};

const linkMediaInEditModal = async (mediaId) => {
  if (!editingPerson.value) return;
  linkingMedia.value = true;
  try {
    await axios.post(`/api/genealogy/media/${mediaId}/persons`, { person_id: editingPerson.value.id });
    // Reload person media
    const res = await axios.get(`/api/genealogy/persons/${editingPerson.value.id}/media`);
    personMediaItems.value = res.data.data?.media || res.data.data || [];
    // Reload available media
    await loadAvailableMedia();
  } catch (error) {
    console.error('Failed to link media:', error);
    alert('Failed to link media: ' + (error.response?.data?.message || error.message));
  } finally {
    linkingMedia.value = false;
  }
};

const unlinkMediaInEditModal = async (mediaId) => {
  if (!editingPerson.value) return;
  if (!confirm('Remove this media from this person?')) return;
  try {
    await axios.delete(`/api/genealogy/media/${mediaId}/persons/${editingPerson.value.id}`);
    personMediaItems.value = personMediaItems.value.filter(m => m.id !== mediaId);
    // Reload available media
    await loadAvailableMedia();
  } catch (error) {
    console.error('Failed to unlink media:', error);
    alert('Failed to unlink media: ' + (error.response?.data?.message || error.message));
  }
};

// Person Edit Modal - Source Link/Unlink
const loadAvailableSources = async () => {
  if (!selectedTree.value) return;
  try {
    const res = await axios.get(`/api/genealogy/trees/${selectedTree.value.id}/sources`);
    // Filter out already linked sources
    const linkedIds = personSources.value.map(s => s.id);
    availableSources.value = (res.data.data || []).filter(s => !linkedIds.includes(s.id));
  } catch (error) {
    console.error('Failed to load available sources:', error);
  }
};

const linkSourceInEditModal = async (sourceId) => {
  if (!editingPerson.value) return;
  linkingSource.value = true;
  try {
    await axios.post(`/api/genealogy/persons/${editingPerson.value.id}/sources`, { source_id: sourceId });
    // Reload person sources
    const res = await axios.get(`/api/genealogy/persons/${editingPerson.value.id}/sources`);
    personSources.value = res.data.data || [];
    // Reload available sources
    await loadAvailableSources();
  } catch (error) {
    console.error('Failed to link source:', error);
    alert('Failed to link source: ' + (error.response?.data?.message || error.message));
  } finally {
    linkingSource.value = false;
  }
};

const unlinkSourceInEditModal = async (sourceId) => {
  if (!editingPerson.value) return;
  if (!confirm('Remove this source from this person?')) return;
  try {
    await axios.delete(`/api/genealogy/persons/${editingPerson.value.id}/sources/${sourceId}`);
    personSources.value = personSources.value.filter(s => s.id !== sourceId);
    // Reload available sources
    await loadAvailableSources();
  } catch (error) {
    console.error('Failed to unlink source:', error);
    alert('Failed to unlink source: ' + (error.response?.data?.message || error.message));
  }
};

// Edit Family Methods
const openEditFamily = async (family) => {
  try {
    const response = await axios.get(`/api/genealogy/families/${family.id}`);
    editingFamily.value = { ...response.data.data.family };
    if (!editingFamily.value.children) editingFamily.value.children = [];
    showEditFamilyModal.value = true;
  } catch (error) {
    console.error('Failed to load family:', error);
  }
};

const closeEditFamily = () => {
  showEditFamilyModal.value = false;
  editingFamily.value = null;
  newChildId.value = null;
};

const saveFamily = async () => {
  if (!editingFamily.value) return;
  savingFamily.value = true;
  try {
    const payload = {
      ...editingFamily.value,
      child_ids: (editingFamily.value.children || []).map(c => c.id)
    };
    await axios.put(`/api/genealogy/families/${editingFamily.value.id}`, payload);
    closeEditFamily();
    if (selectedPerson.value) {
      await selectPerson(selectedPerson.value);
    }
    await loadTreeData();
  } catch (error) {
    console.error('Failed to save family:', error);
    alert('Failed to save family: ' + (error.response?.data?.message || error.message));
  } finally {
    savingFamily.value = false;
  }
};

const addChildToFamily = () => {
  if (!newChildId.value || !editingFamily.value) return;
  const child = allPersons.value.find(p => p.id === parseInt(newChildId.value));
  if (child && !editingFamily.value.children.find(c => c.id === child.id)) {
    editingFamily.value.children.push(child);
  }
  newChildId.value = null;
};

const removeChildFromFamily = (index) => {
  if (!editingFamily.value) return;
  editingFamily.value.children.splice(index, 1);
};

// ========================================================================
// CREATE PERSON
// ========================================================================

const getEmptyPerson = () => ({
  given_name: '',
  surname: '',
  suffix: '',
  nickname: '',
  sex: 'U',
  birth_date: '',
  birth_place: '',
  death_date: '',
  death_place: '',
  burial_date: '',
  burial_place: '',
  occupation: '',
  education: '',
  religion: '',
  notes: '',
  // Phase 2.2 GEDCOM Attributes
  title: '',
  physical_description: '',
  nationality: '',
  ssn: '',
  id_number: '',
  property: '',
  cause_of_death: '',
});

const openCreatePerson = () => {
  creatingPerson.value = getEmptyPerson();
  showCreatePersonModal.value = true;
};

const closeCreatePerson = () => {
  showCreatePersonModal.value = false;
  creatingPerson.value = null;
};

const saveNewPerson = async () => {
  if (!creatingPerson.value || !selectedTreeId.value) return;
  savingCreate.value = true;
  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/persons`, creatingPerson.value);
    const newPerson = response.data.data.person;

    // If we're adding a relative, handle the relationship
    if (addRelativeMode.value && addRelativeForPerson.value) {
      await linkNewPersonAsRelative(newPerson);
    }

    closeCreatePerson();
    await loadTreeData();

    // Select the new person
    selectPerson(newPerson);
  } catch (error) {
    console.error('Failed to create person:', error);
    alert('Failed to create person: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingCreate.value = false;
  }
};

const linkNewPersonAsRelative = async (newPerson) => {
  const targetPerson = addRelativeForPerson.value;
  const mode = addRelativeMode.value;

  try {
    if (mode === 'father' || mode === 'mother') {
      // Find or create a family where target is a child
      let familyId = targetPerson.family_as_child?.id;

      if (!familyId) {
        // Create a new family with the new parent
        const familyData = mode === 'father'
          ? { husband_id: newPerson.id }
          : { wife_id: newPerson.id };
        const famResponse = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/families`, familyData);
        familyId = famResponse.data.data.family.id;

        // Add target person as child
        await axios.post(`/api/genealogy/families/${familyId}/children`, {
          person_id: targetPerson.id
        });
      } else {
        // Update existing family with new parent
        const updateData = mode === 'father'
          ? { husband_id: newPerson.id }
          : { wife_id: newPerson.id };
        await axios.put(`/api/genealogy/families/${familyId}`, updateData);
      }
    } else if (mode === 'spouse') {
      // Create a new family with both spouses
      const familyData = targetPerson.sex === 'M'
        ? { husband_id: targetPerson.id, wife_id: newPerson.id }
        : { husband_id: newPerson.id, wife_id: targetPerson.id };
      await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/families`, familyData);
    } else if (mode === 'child') {
      // Find first family where target is a spouse, or create one
      let familyId = targetPerson.families_as_spouse?.[0]?.id;

      if (!familyId) {
        // Create a new family with target as parent
        const familyData = targetPerson.sex === 'M'
          ? { husband_id: targetPerson.id }
          : { wife_id: targetPerson.id };
        const famResponse = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/families`, familyData);
        familyId = famResponse.data.data.family.id;
      }

      // Add new person as child
      await axios.post(`/api/genealogy/families/${familyId}/children`, {
        person_id: newPerson.id
      });
    }
  } catch (error) {
    console.error('Failed to link relative:', error);
    // Don't throw - person was created, just relationship failed
  }

  // Clear the add relative mode
  addRelativeMode.value = null;
  addRelativeForPerson.value = null;
};

// ========================================================================
// DELETE PERSON
// ========================================================================

const confirmDeletePerson = (person) => {
  deletingPerson.value = person;
  showDeletePersonModal.value = true;
};

const closeDeletePerson = () => {
  showDeletePersonModal.value = false;
  deletingPerson.value = null;
};

const deletePerson = async () => {
  if (!deletingPerson.value) return;
  deleting.value = true;
  try {
    await axios.delete(`/api/genealogy/persons/${deletingPerson.value.id}`);
    closeDeletePerson();
    selectedPerson.value = null;
    await loadTreeData();
  } catch (error) {
    console.error('Failed to delete person:', error);
    alert('Failed to delete person: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    deleting.value = false;
  }
};

// ========================================================================
// CREATE FAMILY
// ========================================================================

const getEmptyFamily = () => ({
  husband_id: null,
  wife_id: null,
  marriage_date: '',
  marriage_place: '',
  divorce_date: '',
  divorce_place: '',
  notes: '',
});

const openCreateFamily = () => {
  creatingFamily.value = getEmptyFamily();
  showCreateFamilyModal.value = true;
};

const closeCreateFamily = () => {
  showCreateFamilyModal.value = false;
  creatingFamily.value = null;
};

const saveNewFamily = async () => {
  if (!creatingFamily.value || !selectedTreeId.value) return;
  if (!creatingFamily.value.husband_id && !creatingFamily.value.wife_id) {
    alert('Please select at least one spouse for the family.');
    return;
  }
  savingCreate.value = true;
  try {
    await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/families`, creatingFamily.value);
    closeCreateFamily();
    await loadTreeData();
  } catch (error) {
    console.error('Failed to create family:', error);
    alert('Failed to create family: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingCreate.value = false;
  }
};

// ========================================================================
// DELETE FAMILY
// ========================================================================

const confirmDeleteFamily = (family) => {
  deletingFamily.value = family;
  showDeleteFamilyModal.value = true;
};

const closeDeleteFamily = () => {
  showDeleteFamilyModal.value = false;
  deletingFamily.value = null;
};

const deleteFamily = async () => {
  if (!deletingFamily.value) return;
  deleting.value = true;
  try {
    await axios.delete(`/api/genealogy/families/${deletingFamily.value.id}`);
    closeDeleteFamily();
    if (selectedPerson.value) {
      await selectPerson(selectedPerson.value);
    }
    await loadTreeData();
  } catch (error) {
    console.error('Failed to delete family:', error);
    alert('Failed to delete family: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    deleting.value = false;
  }
};

// ========================================================================
// EVENT CRUD (Phase 2.1 - GEDCOM Life Events)
// ========================================================================

const getEmptyEvent = () => ({
  event_type: '',
  event_date: '',
  event_place: '',
  description: '',
  source_id: null,
});

const getEventTypeLabel = (type) => {
  return eventTypes[type] || type || 'Unknown';
};

// Extract year from GEDCOM date formats like "26 AUG 1965", "AUG 1965", "1965", "ABT 1965", etc.
const extractYear = (dateStr) => {
  if (!dateStr) return '';
  // Match a 4-digit year anywhere in the string
  const match = dateStr.match(/\b(\d{4})\b/);
  return match ? match[1] : '';
};

const openCreateEvent = (person) => {
  eventPersonId.value = person.id;
  editingEvent.value = getEmptyEvent();
  showEventModal.value = true;
};

const openEditEvent = (event) => {
  editingEvent.value = { ...event };
  showEventModal.value = true;
};

const closeEventModal = () => {
  showEventModal.value = false;
  editingEvent.value = null;
  eventPersonId.value = null;
};

// Image Lightbox/Popup methods
const openImageModal = (item) => {
  enlargedImage.value = {
    src: `/api/media/file?path=${encodeURIComponent(item.nextcloud_path)}`,
    title: item.title || item.local_filename || 'Image',
    nextcloud_path: item.nextcloud_path
  };
  showImageModal.value = true;
};

const closeImageModal = () => {
  showImageModal.value = false;
  enlargedImage.value = null;
};

// Image modal resize functionality
const startResize = (e) => {
  e.preventDefault();
  const startX = e.clientX;
  const startY = e.clientY;
  const startWidth = imageModalSize.value.width;
  const startHeight = imageModalSize.value.height;

  const doResize = (moveEvent) => {
    const newWidth = Math.max(300, Math.min(window.innerWidth * 0.95, startWidth + (moveEvent.clientX - startX)));
    const newHeight = Math.max(200, Math.min(window.innerHeight * 0.95, startHeight + (moveEvent.clientY - startY)));
    imageModalSize.value = { width: newWidth, height: newHeight };
  };

  const stopResize = () => {
    document.removeEventListener('mousemove', doResize);
    document.removeEventListener('mouseup', stopResize);
  };

  document.addEventListener('mousemove', doResize);
  document.addEventListener('mouseup', stopResize);
};

// Handle Escape key to close image modal
const handleKeydown = (e) => {
  if (e.key === 'Escape' && showImageModal.value) {
    closeImageModal();
  }
};

// Handle click outside to close place autocomplete dropdowns
const handleClickOutsidePlaceAutocomplete = (event) => {
  // Close event place autocomplete if clicking outside
  if (showEventPlaceResults.value) {
    const target = event.target;
    if (!target.closest('.relative')) {
      showEventPlaceResults.value = false;
    }
  }
  // Close family event place autocomplete if clicking outside
  if (showFamilyEventPlaceResults.value) {
    const target = event.target;
    if (!target.closest('.relative')) {
      showFamilyEventPlaceResults.value = false;
    }
  }
};

// Add keyboard listener on mount
onMounted(() => {
  document.addEventListener('keydown', handleKeydown);
  document.addEventListener('click', hideContextMenu);
  document.addEventListener('click', handleClickOutsidePlaceAutocomplete);
});

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown);
  document.removeEventListener('click', hideContextMenu);
  document.removeEventListener('click', handleClickOutsidePlaceAutocomplete);
  if (intakeApprovalDraftPreviewTimer) clearTimeout(intakeApprovalDraftPreviewTimer);
});

const saveEvent = async () => {
  if (!editingEvent.value?.event_type) {
    alert('Please select an event type');
    return;
  }

  savingEvent.value = true;
  try {
    if (editingEvent.value.id) {
      // Update existing event
      await axios.put(`/api/genealogy/events/${editingEvent.value.id}`, editingEvent.value);
    } else {
      // Create new event
      const personId = eventPersonId.value || selectedPerson.value?.id;
      if (!personId) {
        alert('No person selected for event');
        return;
      }
      await axios.post(`/api/genealogy/persons/${personId}/events`, editingEvent.value);
    }
    closeEventModal();
    // Refresh person to show updated events
    if (selectedPerson.value) {
      await selectPerson(selectedPerson.value);
    }
  } catch (error) {
    console.error('Failed to save event:', error);
    alert('Failed to save event: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingEvent.value = false;
  }
};

const confirmDeleteEvent = (event) => {
  deletingEvent.value = event;
  showDeleteEventModal.value = true;
};

const deleteEvent = async () => {
  if (!deletingEvent.value) return;
  deletingEventLoading.value = true;
  try {
    await axios.delete(`/api/genealogy/events/${deletingEvent.value.id}`);
    showDeleteEventModal.value = false;
    deletingEvent.value = null;
    // Refresh person to show updated events
    if (selectedPerson.value) {
      await selectPerson(selectedPerson.value);
    }
  } catch (error) {
    console.error('Failed to delete event:', error);
    alert('Failed to delete event: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    deletingEventLoading.value = false;
  }
};

// ========================================================================
// FAMILY EVENT CRUD (Phase 2.3 - GEDCOM Family Events)
// ========================================================================

const getEmptyFamilyEvent = () => ({
  event_type: '',
  event_date: '',
  event_place: '',
  description: '',
  source_id: null,
});

const getFamilyEventTypeLabel = (type) => {
  return familyEventTypes[type] || type || 'Unknown';
};

const openCreateFamilyEvent = (family) => {
  eventFamilyId.value = family.id;
  editingFamilyEvent.value = getEmptyFamilyEvent();
  showFamilyEventModal.value = true;
};

const openEditFamilyEvent = (event) => {
  editingFamilyEvent.value = { ...event };
  showFamilyEventModal.value = true;
};

const closeFamilyEventModal = () => {
  showFamilyEventModal.value = false;
  editingFamilyEvent.value = null;
  eventFamilyId.value = null;
};

const saveFamilyEvent = async () => {
  if (!editingFamilyEvent.value?.event_type) {
    alert('Please select an event type');
    return;
  }

  savingFamilyEvent.value = true;
  try {
    if (editingFamilyEvent.value.id) {
      // Update existing event
      await axios.put(`/api/genealogy/family-events/${editingFamilyEvent.value.id}`, editingFamilyEvent.value);
    } else {
      // Create new event
      const familyId = eventFamilyId.value || editingFamily.value?.id;
      if (!familyId) {
        alert('No family selected for event');
        return;
      }
      await axios.post(`/api/genealogy/families/${familyId}/events`, editingFamilyEvent.value);
    }
    closeFamilyEventModal();
    // Refresh family to show updated events
    if (editingFamily.value) {
      const response = await axios.get(`/api/genealogy/families/${editingFamily.value.id}`);
      editingFamily.value = response.data.data.family;
    }
  } catch (error) {
    console.error('Failed to save family event:', error);
    alert('Failed to save family event: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingFamilyEvent.value = false;
  }
};

const confirmDeleteFamilyEvent = (event) => {
  deletingFamilyEvent.value = event;
  showDeleteFamilyEventModal.value = true;
};

const deleteFamilyEvent = async () => {
  if (!deletingFamilyEvent.value) return;
  deletingFamilyEventLoading.value = true;
  try {
    await axios.delete(`/api/genealogy/family-events/${deletingFamilyEvent.value.id}`);
    showDeleteFamilyEventModal.value = false;
    deletingFamilyEvent.value = null;
    // Refresh family to show updated events
    if (editingFamily.value) {
      const response = await axios.get(`/api/genealogy/families/${editingFamily.value.id}`);
      editingFamily.value = response.data.data.family;
    }
  } catch (error) {
    console.error('Failed to delete family event:', error);
    alert('Failed to delete family event: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    deletingFamilyEventLoading.value = false;
  }
};

// ========================================================================
// SOURCE CRUD (Phase 2.4 - GEDCOM Source Management)
// ========================================================================

const loadSources = async () => {
  if (!selectedTreeId.value) return;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/sources`);
    sources.value = response.data.data.sources;
  } catch (error) {
    console.error('Failed to load sources:', error);
  }
};

const searchSources = async () => {
  if (!sourceSearchQuery.value || !selectedTreeId.value) return;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/sources/search`, {
      params: { q: sourceSearchQuery.value }
    });
    sourceSearchResults.value = response.data.data.sources;
  } catch (error) {
    console.error('Failed to search sources:', error);
  }
};

const getEmptySource = () => ({
  title: '',
  author: '',
  publication: '',
  repository: '',
  repository_address: '',
  call_number: '',
  url: '',
  notes: '',
});

const openCreateSource = () => {
  editingSource.value = getEmptySource();
  linkSourceToPersonAfterCreate.value = null;
  showSourceModal.value = true;
};

// Open create source modal from Edit Person > Sources tab - will auto-link after save
const openCreateSourceFromEditPerson = () => {
  if (!editingPerson.value) return;
  editingSource.value = getEmptySource();
  linkSourceToPersonAfterCreate.value = editingPerson.value.id;
  showSourceModal.value = true;
};

const openEditSource = (source) => {
  editingSource.value = { ...source };
  showSourceModal.value = true;
};

const closeSourceModal = () => {
  showSourceModal.value = false;
  editingSource.value = null;
};

// Open source detail popup with full data including media
const openSourceDetailPopup = async (source) => {
  loadingSourceDetails.value = true;
  viewingSourceMedia.value = [];
  viewingSourceMediaPage.value = 1; // Reset pagination
  viewingSource.value = source; // Show immediately with basic data

  try {
    // Load full source details
    const response = await axios.get(`/api/genealogy/sources/${source.id}`);
    viewingSource.value = response.data.data.source;

    // Load citations with media for this source
    const citationsResponse = await axios.get(`/api/genealogy/sources/${source.id}/citations`);
    const citations = citationsResponse.data.data.citations || [];

    // Collect all media - both directly attached and related via person-media
    const mediaById = {};

    // Add media from citations with direct media_id
    citations
      .filter(c => c.media_id && c.media)
      .forEach(c => {
        mediaById[c.media.id] = c.media;
      });

    // Also add related_media from person citations
    citations
      .filter(c => c.related_media && c.related_media.length > 0)
      .forEach(c => {
        c.related_media.forEach(m => {
          if (!mediaById[m.id]) {
            mediaById[m.id] = m;
          }
        });
      });

    // If source has related_media from the person edit context, add those too
    if (source.related_media && source.related_media.length > 0) {
      source.related_media.forEach(m => {
        if (!mediaById[m.id]) {
          mediaById[m.id] = m;
        }
      });
    }

    viewingSourceMedia.value = Object.values(mediaById);
  } catch (error) {
    console.error('Failed to load source details:', error);
  } finally {
    loadingSourceDetails.value = false;
  }
};

// Navigate to source in Sources tab with full details
const goToSourceInTab = async (source) => {
  // Close all modals first
  viewingSource.value = null;
  viewingSourceMedia.value = [];
  showEditPersonModal.value = false;

  // Switch to sources tab
  activeTab.value = 'sources';

  // Wait for Vue to update DOM, then select the source
  await nextTick();
  await selectSource(source);

  // Scroll the source into view in the list if it exists
  await nextTick();
  const sourceElement = document.querySelector(`[data-source-id="${source.id}"]`);
  if (sourceElement) {
    sourceElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
};

const selectSource = async (source) => {
  try {
    const response = await axios.get(`/api/genealogy/sources/${source.id}`);
    selectedSource.value = response.data.data.source;
    // Load citations for this source (Phase 2.5)
    citationContext.value = { type: 'source', id: source.id };
    await loadSourceCitations(source.id);
  } catch (error) {
    console.error('Failed to load source details:', error);
  }
};

const saveSource = async () => {
  if (!editingSource.value?.title) {
    alert('Please enter a source title');
    return;
  }

  savingSource.value = true;
  try {
    let newSourceId = null;
    if (editingSource.value.id) {
      // Update existing source
      await axios.put(`/api/genealogy/sources/${editingSource.value.id}`, editingSource.value);
    } else {
      // Create new source
      const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/sources`, editingSource.value);
      newSourceId = response.data.data?.id;
    }

    closeSourceModal();
    await loadSources();

    // If we need to link this new source to a person (from Edit Person > Sources tab)
    if (newSourceId && linkSourceToPersonAfterCreate.value) {
      try {
        await axios.post(`/api/genealogy/persons/${linkSourceToPersonAfterCreate.value}/sources`, { source_id: newSourceId });
        // Reload person sources in the edit modal
        const res = await axios.get(`/api/genealogy/persons/${linkSourceToPersonAfterCreate.value}/sources`);
        personSources.value = res.data.data || [];
        await loadAvailableSources();
      } catch (linkError) {
        console.error('Failed to link source to person:', linkError);
      }
      linkSourceToPersonAfterCreate.value = null;
    }

    // Refresh selected source if editing
    if (selectedSource.value && editingSource.value?.id === selectedSource.value.id) {
      await selectSource(selectedSource.value);
    }
  } catch (error) {
    console.error('Failed to save source:', error);
    alert('Failed to save source: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingSource.value = false;
  }
};

const confirmDeleteSource = (source) => {
  deletingSource.value = source;
  showDeleteSourceModal.value = true;
};

const deleteSource = async () => {
  if (!deletingSource.value) return;
  deletingSourceLoading.value = true;
  try {
    await axios.delete(`/api/genealogy/sources/${deletingSource.value.id}`);
    showDeleteSourceModal.value = false;
    deletingSource.value = null;
    selectedSource.value = null;
    await loadSources();
  } catch (error) {
    console.error('Failed to delete source:', error);
    alert('Failed to delete source: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    deletingSourceLoading.value = false;
  }
};

// ========================================================================
// CITATION CRUD METHODS (Phase 2.5)
// ========================================================================

const getCitationFactTypeLabel = (factType) => {
  return citationFactTypes[factType] || factType || 'Unknown';
};

const getCitationQualityLabel = (quality) => {
  if (quality === null || quality === undefined) return 'Not specified';
  return citationQualityLevels[quality] || `Quality ${quality}`;
};

const openAddCitation = (contextType, contextId, sourceId = null) => {
  citationContext.value = { type: contextType, id: contextId };
  editingCitation.value = {
    source_id: sourceId || null,
    person_id: contextType === 'person' ? contextId : null,
    family_id: contextType === 'family' ? contextId : null,
    media_id: contextType === 'media' ? contextId : null,
    fact_type: '',
    page: '',
    quality: null,
    text: '',
  };
  showCitationModal.value = true;
};

const openEditCitation = (citation) => {
  editingCitation.value = { ...citation };
  showCitationModal.value = true;
};

const closeCitationModal = () => {
  showCitationModal.value = false;
  editingCitation.value = null;
};

const saveCitation = async () => {
  if (!editingCitation.value?.source_id) {
    alert('Please select a source');
    return;
  }

  savingCitation.value = true;
  try {
    if (editingCitation.value.id) {
      await axios.put(`/api/genealogy/citations/${editingCitation.value.id}`, editingCitation.value);
    } else {
      await axios.post('/api/genealogy/citations', editingCitation.value);
    }
    closeCitationModal();
    // Reload the appropriate data based on context
    if (citationContext.value.type === 'person' && selectedPerson.value) {
      await loadPersonCitations(selectedPerson.value.id);
    } else if (citationContext.value.type === 'source' && selectedSource.value) {
      await loadSourceCitations(selectedSource.value.id);
    }
  } catch (error) {
    console.error('Failed to save citation:', error);
    alert('Failed to save citation: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingCitation.value = false;
  }
};

const confirmDeleteCitation = (citation) => {
  deletingCitation.value = citation;
  showDeleteCitationModal.value = true;
};

const deleteCitation = async () => {
  if (!deletingCitation.value) return;
  deletingCitationLoading.value = true;
  try {
    await axios.delete(`/api/genealogy/citations/${deletingCitation.value.id}`);
    showDeleteCitationModal.value = false;
    deletingCitation.value = null;
    // Reload the appropriate data
    if (citationContext.value.type === 'person' && selectedPerson.value) {
      await loadPersonCitations(selectedPerson.value.id);
    } else if (citationContext.value.type === 'source' && selectedSource.value) {
      await loadSourceCitations(selectedSource.value.id);
    }
  } catch (error) {
    console.error('Failed to delete citation:', error);
    alert('Failed to delete citation: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    deletingCitationLoading.value = false;
  }
};

const loadPersonCitations = async (personId) => {
  try {
    const response = await axios.get(`/api/genealogy/persons/${personId}/citations`);
    if (selectedPerson.value && selectedPerson.value.id === personId) {
      selectedPerson.value.citations = response.data.data.citations;
    }
  } catch (error) {
    console.error('Failed to load person citations:', error);
  }
};

const loadSourceCitations = async (sourceId) => {
  try {
    const response = await axios.get(`/api/genealogy/sources/${sourceId}/citations`);
    if (selectedSource.value && selectedSource.value.id === sourceId) {
      selectedSource.value.citations = response.data.data.citations;
    }
  } catch (error) {
    console.error('Failed to load source citations:', error);
  }
};

// ========================================================================
// REPOSITORY CRUD METHODS (Phase 2.6)
// ========================================================================

const loadRepositories = async () => {
  if (!selectedTreeId.value) return;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/repositories`);
    repositories.value = response.data.data.repositories;
  } catch (error) {
    console.error('Failed to load repositories:', error);
  }
};

const selectRepository = async (repository) => {
  try {
    const response = await axios.get(`/api/genealogy/repositories/${repository.id}`);
    selectedRepository.value = response.data.data.repository;
  } catch (error) {
    console.error('Failed to load repository details:', error);
  }
};

const openAddRepository = () => {
  editingRepository.value = {
    name: '',
    address: '',
    phone: '',
    email: '',
    url: '',
    notes: '',
  };
  showRepositoryModal.value = true;
};

const openEditRepository = (repository) => {
  editingRepository.value = { ...repository };
  showRepositoryModal.value = true;
};

const closeRepositoryModal = () => {
  showRepositoryModal.value = false;
  editingRepository.value = null;
};

const saveRepository = async () => {
  if (!editingRepository.value?.name) {
    alert('Please enter a repository name');
    return;
  }

  savingRepository.value = true;
  try {
    if (editingRepository.value.id) {
      await axios.put(`/api/genealogy/repositories/${editingRepository.value.id}`, editingRepository.value);
    } else {
      await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/repositories`, editingRepository.value);
    }
    closeRepositoryModal();
    await loadRepositories();
  } catch (error) {
    console.error('Failed to save repository:', error);
    alert('Failed to save repository: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingRepository.value = false;
  }
};

const confirmDeleteRepository = (repository) => {
  deletingRepository.value = repository;
  showDeleteRepositoryModal.value = true;
};

const deleteRepository = async () => {
  if (!deletingRepository.value) return;
  deletingRepositoryLoading.value = true;
  try {
    await axios.delete(`/api/genealogy/repositories/${deletingRepository.value.id}`);
    showDeleteRepositoryModal.value = false;
    deletingRepository.value = null;
    selectedRepository.value = null;
    await loadRepositories();
  } catch (error) {
    console.error('Failed to delete repository:', error);
    alert('Failed to delete repository: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    deletingRepositoryLoading.value = false;
  }
};

// ========================================================================
// REPORTS METHODS (Phase 2.7)
// ========================================================================

const loadMissingDataTypes = async () => {
  try {
    const response = await axios.get('/api/genealogy/missing-data-types');
    missingDataTypes.value = response.data.data.types;
  } catch (error) {
    console.error('Failed to load missing data types:', error);
  }
};

const loadMissingDataSummary = async () => {
  if (!selectedTreeId.value) return;
  loadingReport.value = true;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/reports/missing-data/summary`);
    missingDataSummary.value = response.data.data.summary;
  } catch (error) {
    console.error('Failed to load missing data summary:', error);
  } finally {
    loadingReport.value = false;
  }
};

const loadMissingDataReport = async (reportType = null) => {
  if (!selectedTreeId.value) return;
  loadingReport.value = true;
  try {
    let url = `/api/genealogy/trees/${selectedTreeId.value}/reports/missing-data`;
    if (reportType) {
      url += `?types=${reportType}`;
    }
    const response = await axios.get(url);
    // Merge with existing data instead of replacing (to preserve previously loaded sections)
    const newReportData = response.data.data.report;
    missingDataReport.value = {
      ...missingDataReport.value,
      ...newReportData
    };
  } catch (error) {
    console.error('Failed to load missing data report:', error);
  } finally {
    loadingReport.value = false;
  }
};

const toggleReportSection = (type) => {
  if (expandedReportSection.value === type) {
    expandedReportSection.value = null;
  } else {
    expandedReportSection.value = type;
    // Load this section's data if not already loaded
    if (!missingDataReport.value[type]) {
      loadMissingDataReport(type);
    }
  }
};

const refreshMissingDataReport = async () => {
  await loadMissingDataSummary();
  // Clear cached report data
  missingDataReport.value = {};
  expandedReportSection.value = null;
};

const goToPersonFromReport = (person) => {
  selectPerson(person);
  activeTab.value = 'tree';
};

// ========================================================================
// ADD RELATIVE QUICK ACTIONS
// ========================================================================

const openAddRelative = (person, mode) => {
  addRelativeMode.value = mode;
  addRelativeForPerson.value = person;

  // Pre-populate some fields based on the mode
  creatingPerson.value = getEmptyPerson();

  if (mode === 'father') {
    creatingPerson.value.sex = 'M';
    creatingPerson.value.surname = person.surname; // Father often shares surname
  } else if (mode === 'mother') {
    creatingPerson.value.sex = 'F';
  } else if (mode === 'spouse') {
    creatingPerson.value.sex = person.sex === 'M' ? 'F' : 'M';
  } else if (mode === 'child') {
    creatingPerson.value.surname = person.surname;
  }

  showCreatePersonModal.value = true;
};

const createTree = async () => {
  try {
    const response = await axios.post('/api/genealogy/trees', newTree.value);
    trees.value.push(response.data.data.tree);
    selectedTreeId.value = response.data.data.tree.id;
    showNewTreeModal.value = false;
    newTree.value = { name: '', description: '' };
    loadTreeData();
  } catch (error) {
    console.error('Failed to create tree:', error);
  }
};

const onFileSelect = (event) => {
  importFile.value = event.target.files[0];
};

const importGedcom = async () => {
  if (!importFile.value) return;

  importing.value = true;
  importProgress.value = null;

  try {
    const formData = new FormData();
    formData.append('file', importFile.value);
    if (importTarget.value !== 'new') {
      formData.append('tree_id', importTarget.value);
    } else if (importTreeName.value) {
      formData.append('tree_name', importTreeName.value);
    }

    const response = await axios.post('/api/genealogy/import/gedcom', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });

    importProgress.value = response.data.data;

    // Reload trees and select the imported one
    await loadTrees();
    if (response.data.data.tree_id) {
      selectedTreeId.value = response.data.data.tree_id;
      await loadTreeData();
    }

    setTimeout(() => {
      showImportModal.value = false;
      importFile.value = null;
      importProgress.value = null;
    }, 3000);
  } catch (error) {
    console.error('Import failed:', error);
    importProgress.value = { errors: [error.response?.data?.error?.message || error.message] };
  } finally {
    importing.value = false;
  }
};

const importMedia = async () => {
  if (!selectedTreeId.value) return;

  importingMedia.value = true;
  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/media/import`);
    if (response.data.success) {
      alert(`Linked ${response.data.data.results.linked} of ${response.data.data.results.total} media files (${response.data.data.results.skipped} skipped, ${response.data.data.results.failed} failed)`);
    }
    await loadMediaStatus();
    await loadMedia();
  } catch (error) {
    console.error('Media import failed:', error);
    alert('Media import failed: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    importingMedia.value = false;
  }
};

const syncMediaPaths = async () => {
  if (!selectedTreeId.value) return;

  syncingMediaPaths.value = true;
  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/media/sync-paths`);
    if (response.data.success) {
      const results = response.data.data.results;
      alert(`Updated ${results.updated} media paths from ${response.data.data.gedcom_file}`);
    }
    await loadMediaStatus();
  } catch (error) {
    console.error('Media sync failed:', error);
    alert('Media sync failed: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    syncingMediaPaths.value = false;
  }
};

const loadMediaStatus = async () => {
  if (!selectedTreeId.value) return;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/media/status`);
    mediaStatus.value = response.data.data.status;
  } catch (error) {
    console.error('Failed to load media status:', error);
  }
};

// N138: Upload media from person detail panel — pre-fills person_id
const uploadMediaForPerson = () => {
  openMediaUploadModal();
  if (editingPerson.value?.id) {
    mediaUploadData.value.person_id = editingPerson.value.id;
  }
};

// Media Upload Methods (Phase 3.1)
const openMediaUploadModal = () => {
  mediaUploadFile.value = null;
  mediaUploadData.value = { title: '', date: '', description: '', person_id: null };
  showMediaUploadModal.value = true;
};

const closeMediaUploadModal = () => {
  showMediaUploadModal.value = false;
  mediaUploadFile.value = null;
  mediaUploadData.value = { title: '', date: '', description: '', person_id: null };
};

const handleMediaDrop = (event) => {
  isDraggingMedia.value = false;
  const files = event.dataTransfer.files;
  if (files.length > 0) {
    mediaUploadFile.value = files[0];
    // Auto-fill title from filename if empty
    if (!mediaUploadData.value.title) {
      mediaUploadData.value.title = files[0].name.replace(/\.[^/.]+$/, '');
    }
  }
};

const handleMediaFileSelect = (event) => {
  const files = event.target.files;
  if (files.length > 0) {
    mediaUploadFile.value = files[0];
    // Auto-fill title from filename if empty
    if (!mediaUploadData.value.title) {
      mediaUploadData.value.title = files[0].name.replace(/\.[^/.]+$/, '');
    }
  }
};

const clearMediaUpload = () => {
  mediaUploadFile.value = null;
};

const uploadMediaFile = async () => {
  if (!mediaUploadFile.value || !selectedTreeId.value) return;

  uploadingMediaFile.value = true;
  try {
    const formData = new FormData();
    formData.append('file', mediaUploadFile.value);
    formData.append('title', mediaUploadData.value.title || '');
    formData.append('date', mediaUploadData.value.date || '');
    formData.append('description', mediaUploadData.value.description || '');
    if (mediaUploadData.value.person_id) {
      formData.append('person_id', mediaUploadData.value.person_id);
    }

    const response = await axios.post(
      `/api/genealogy/trees/${selectedTreeId.value}/media`,
      formData,
      { headers: { 'Content-Type': 'multipart/form-data' } }
    );

    // Refresh media list
    const mediaResponse = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/media?limit=24`);
    media.value = mediaResponse.data.data.media;

    // N138: Refresh person's linked media if upload was for a specific person
    if (mediaUploadData.value.person_id && editingPerson.value?.id) {
      const res = await axios.get(`/api/genealogy/persons/${editingPerson.value.id}/media`);
      personMediaItems.value = res.data.data?.media || res.data.data || [];
    }

    // Update media status
    await loadMediaStatus();

    closeMediaUploadModal();
  } catch (error) {
    console.error('Failed to upload media:', error);
    const errMsg = error.response?.data?.error;
    alert(typeof errMsg === 'object' ? errMsg?.message || JSON.stringify(errMsg) : errMsg || 'Failed to upload media');
  } finally {
    uploadingMediaFile.value = false;
  }
};

// Media Detail Methods (Phase 3.2)
const showMediaDetail = async (item) => {
  try {
    // Load full media details including linked persons
    const response = await axios.get(`/api/genealogy/media/${item.id}`);
    selectedMedia.value = response.data.data.media;
    mediaIntakePreview.value = null;
    mediaIntakePreviewError.value = '';
    linkPersonToMediaId.value = null;
    showMediaDetailModal.value = true;

    if (['document', 'certificate', 'census', 'military', 'obituary', 'headstone'].includes(selectedMedia.value?.media_type)) {
      await loadMediaIntakePreview(item.id);
    }
  } catch (error) {
    console.error('Failed to load media details:', error);
  }
};

const closeMediaDetailModal = () => {
  showMediaDetailModal.value = false;
  selectedMedia.value = null;
  mediaIntakePreview.value = null;
  mediaIntakePreviewError.value = '';
  linkPersonToMediaId.value = null;
};

const loadMediaIntakePreview = async (mediaId) => {
  if (!mediaId) return;

  loadingMediaIntakePreview.value = true;
  mediaIntakePreviewError.value = '';

  try {
    const response = await axios.get(`/api/genealogy/media/${mediaId}/intake-preview`);
    mediaIntakePreview.value = response.data.data.preview || null;
  } catch (error) {
    console.error('Failed to load media intake preview:', error);
    mediaIntakePreview.value = null;
    mediaIntakePreviewError.value = error.response?.data?.error?.message || 'Failed to load intake preview';
  } finally {
    loadingMediaIntakePreview.value = false;
  }
};

// Alias for opening media detail from various contexts (sources, citations, etc.)
const viewMediaItem = (media) => {
  if (media && media.id) {
    showMediaDetail(media);
  }
};

// Also define openMediaDetail as alias for template usage
const openMediaDetail = (media) => {
  if (media && media.id) {
    showMediaDetail(media);
  }
};

const linkMediaToPerson = async (mediaId, personId) => {
  if (!mediaId || !personId) return;

  try {
    await axios.post(`/api/genealogy/media/${mediaId}/persons`, { person_id: personId });
    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${mediaId}`);
    selectedMedia.value = response.data.data.media;
    linkPersonToMediaId.value = null;
  } catch (error) {
    console.error('Failed to link media to person:', error);
    alert(error.response?.data?.error || 'Failed to link media to person');
  }
};

const unlinkMediaFromPerson = async (mediaId, personId) => {
  if (!mediaId || !personId) return;

  try {
    await axios.delete(`/api/genealogy/media/${mediaId}/persons/${personId}`);
    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${mediaId}`);
    selectedMedia.value = response.data.data.media;
  } catch (error) {
    console.error('Failed to unlink media from person:', error);
    alert(error.response?.data?.error || 'Failed to unlink media from person');
  }
};

// Set or unset primary photo for a person
const setPersonPrimaryPhoto = async (personId, mediaId) => {
  if (!personId) return;

  try {
    const response = await axios.post(`/api/genealogy/persons/${personId}/primary-photo`, {
      media_id: mediaId
    });

    // Update the selectedPerson with new primary photo info
    if (selectedPerson.value && selectedPerson.value.id === personId) {
      const updatedPerson = response.data.data.person;
      selectedPerson.value.primary_photo_id = updatedPerson.primary_photo_id;
      selectedPerson.value.primary_photo_url = updatedPerson.primary_photo_url;
    }

    // Show success feedback
    const action = mediaId ? 'set' : 'removed';
    console.log(`Primary photo ${action} successfully`);
  } catch (error) {
    console.error('Failed to set primary photo:', error);
    alert(error.response?.data?.error || 'Failed to set primary photo');
  }
};

// Link media to person from person detail panel
const openLinkMediaToPersonModal = () => {
  linkMediaToPersonSearch.value = '';
  showLinkMediaToPersonModal.value = true;
};

const linkMediaToPersonFromPanel = async (mediaId) => {
  if (!selectedPerson.value?.id || !mediaId) return;

  try {
    await axios.post(`/api/genealogy/media/${mediaId}/persons`, { person_id: selectedPerson.value.id });

    // Refresh person data to get updated media list
    const response = await axios.get(`/api/genealogy/persons/${selectedPerson.value.id}`);
    selectedPerson.value = response.data.data;

    showLinkMediaToPersonModal.value = false;
  } catch (error) {
    console.error('Failed to link media to person:', error);
    alert(error.response?.data?.error || 'Failed to link media to person');
  }
};

const confirmDeleteMedia = (item) => {
  deletingMedia.value = item;
  showDeleteMediaModal.value = true;
};

const deleteMedia = async () => {
  if (!deletingMedia.value) return;

  deletingMediaLoading.value = true;
  try {
    await axios.delete(`/api/genealogy/media/${deletingMedia.value.id}`);

    // Refresh media list
    const mediaResponse = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/media?limit=24`);
    media.value = mediaResponse.data.data.media;

    // Update media status
    await loadMediaStatus();

    showDeleteMediaModal.value = false;
    showMediaDetailModal.value = false;
    deletingMedia.value = null;
  } catch (error) {
    console.error('Failed to delete media:', error);
    alert(error.response?.data?.error || 'Failed to delete media');
  } finally {
    deletingMediaLoading.value = false;
  }
};

// Family-Media Link Methods (Phase 3.3)
const loadAllFamilies = async () => {
  if (!selectedTreeId.value) return;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/families`);
    allFamilies.value = response.data.data.families || [];
  } catch (error) {
    console.error('Failed to load families:', error);
  }
};

const linkMediaToFamily = async (mediaId, familyId) => {
  if (!mediaId || !familyId) return;

  try {
    await axios.post(`/api/genealogy/media/${mediaId}/families`, { family_id: familyId });
    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${mediaId}`);
    selectedMedia.value = response.data.data.media;
    linkFamilyToMediaId.value = null;
  } catch (error) {
    console.error('Failed to link media to family:', error);
    alert(error.response?.data?.error || 'Failed to link media to family');
  }
};

const unlinkMediaFromFamily = async (mediaId, familyId) => {
  if (!mediaId || !familyId) return;

  try {
    await axios.delete(`/api/genealogy/media/${mediaId}/families/${familyId}`);
    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${mediaId}`);
    selectedMedia.value = response.data.data.media;
  } catch (error) {
    console.error('Failed to unlink media from family:', error);
    alert(error.response?.data?.error || 'Failed to unlink media from family');
  }
};

// Face Tagging Methods (Phase 3.4)
const personFaceRegions = computed(() => {
  if (!selectedMedia.value || !selectedMedia.value.persons) return [];
  return selectedMedia.value.persons.filter(p =>
    p.face_region_x !== null && p.face_region_y !== null &&
    p.face_region_w !== null && p.face_region_h !== null
  );
});

const openFaceTaggingModal = () => {
  showFaceTaggingModal.value = true;
  newFaceRegion.value = null;
  newFaceRegionPersonId.value = null;
  drawingRegion.value = null;
  isDrawingFaceRegion.value = false;
};

const closeFaceTaggingModal = async () => {
  showFaceTaggingModal.value = false;
  newFaceRegion.value = null;
  newFaceRegionPersonId.value = null;
  // Refresh media to get updated face regions
  if (selectedMedia.value) {
    const response = await axios.get(`/api/genealogy/media/${selectedMedia.value.id}`);
    selectedMedia.value = response.data.data.media;
  }
};

const onMediaImageLoad = () => {
  if (mediaPreviewImg.value) {
    mediaPreviewImageSize.value = {
      width: mediaPreviewImg.value.clientWidth,
      height: mediaPreviewImg.value.clientHeight
    };
  }
};

const onFaceTaggingImageLoad = () => {
  if (faceTaggingImg.value) {
    faceTaggingImageSize.value = {
      width: faceTaggingImg.value.clientWidth,
      height: faceTaggingImg.value.clientHeight
    };
  }
};

const getFaceRegionStyle = (person) => {
  const imgSize = mediaPreviewImageSize.value;
  if (!imgSize.width || !imgSize.height) return { display: 'none' };

  return {
    left: `${person.face_region_x * imgSize.width}px`,
    top: `${person.face_region_y * imgSize.height}px`,
    width: `${person.face_region_w * imgSize.width}px`,
    height: `${person.face_region_h * imgSize.height}px`
  };
};

const getFaceTaggingRegionStyle = (person) => {
  const imgSize = faceTaggingImageSize.value;
  if (!imgSize.width || !imgSize.height) return { display: 'none' };

  return {
    left: `${person.face_region_x * imgSize.width}px`,
    top: `${person.face_region_y * imgSize.height}px`,
    width: `${person.face_region_w * imgSize.width}px`,
    height: `${person.face_region_h * imgSize.height}px`
  };
};

const startDrawingFaceRegion = (event) => {
  if (newFaceRegion.value) return; // Already have a pending region

  const container = faceTaggingContainer.value;
  if (!container) return;

  const rect = container.getBoundingClientRect();
  const x = event.clientX - rect.left;
  const y = event.clientY - rect.top;

  isDrawingFaceRegion.value = true;
  drawingRegion.value = {
    startX: x,
    startY: y,
    currentX: x,
    currentY: y
  };
};

const drawFaceRegion = (event) => {
  if (!isDrawingFaceRegion.value || !drawingRegion.value) return;

  const container = faceTaggingContainer.value;
  if (!container) return;

  const rect = container.getBoundingClientRect();
  drawingRegion.value.currentX = Math.max(0, Math.min(event.clientX - rect.left, rect.width));
  drawingRegion.value.currentY = Math.max(0, Math.min(event.clientY - rect.top, rect.height));
};

const endDrawingFaceRegion = () => {
  if (!isDrawingFaceRegion.value || !drawingRegion.value) return;

  isDrawingFaceRegion.value = false;

  const imgSize = faceTaggingImageSize.value;
  if (!imgSize.width || !imgSize.height) return;

  // Calculate normalized coordinates (0-1)
  const x = Math.min(drawingRegion.value.startX, drawingRegion.value.currentX) / imgSize.width;
  const y = Math.min(drawingRegion.value.startY, drawingRegion.value.currentY) / imgSize.height;
  const w = Math.abs(drawingRegion.value.currentX - drawingRegion.value.startX) / imgSize.width;
  const h = Math.abs(drawingRegion.value.currentY - drawingRegion.value.startY) / imgSize.height;

  // Minimum size check (at least 2% of image)
  if (w < 0.02 || h < 0.02) {
    drawingRegion.value = null;
    return;
  }

  newFaceRegion.value = { x, y, w, h };
  drawingRegion.value = null;
};

const cancelDrawingFaceRegion = () => {
  if (isDrawingFaceRegion.value) {
    isDrawingFaceRegion.value = false;
    drawingRegion.value = null;
  }
};

const cancelNewFaceRegion = () => {
  newFaceRegion.value = null;
  newFaceRegionPersonId.value = null;
};

const saveNewFaceRegion = async () => {
  if (!newFaceRegion.value || !newFaceRegionPersonId.value || !selectedMedia.value) return;

  savingFaceRegion.value = true;
  try {
    await axios.post(`/api/genealogy/media/${selectedMedia.value.id}/persons`, {
      person_id: newFaceRegionPersonId.value,
      face_region_x: newFaceRegion.value.x,
      face_region_y: newFaceRegion.value.y,
      face_region_w: newFaceRegion.value.w,
      face_region_h: newFaceRegion.value.h
    });

    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${selectedMedia.value.id}`);
    selectedMedia.value = response.data.data.media;

    newFaceRegion.value = null;
    newFaceRegionPersonId.value = null;
  } catch (error) {
    console.error('Failed to save face region:', error);
    alert(error.response?.data?.error || 'Failed to save face region');
  } finally {
    savingFaceRegion.value = false;
  }
};

const removeFaceRegion = async (personId) => {
  if (!selectedMedia.value) return;

  try {
    await axios.delete(`/api/genealogy/media/${selectedMedia.value.id}/persons/${personId}`);

    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${selectedMedia.value.id}`);
    selectedMedia.value = response.data.data.media;
  } catch (error) {
    console.error('Failed to remove face region:', error);
    alert(error.response?.data?.error || 'Failed to remove face region');
  }
};

// Face Confirmation Methods (Phase 3.5)
const confirmFaceTag = async (personId, confirmed) => {
  if (!selectedMedia.value) return;

  confirmingFaceTag.value = true;
  try {
    await axios.post(`/api/genealogy/media/${selectedMedia.value.id}/persons/${personId}/confirm`, {
      confirmed: confirmed
    });

    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${selectedMedia.value.id}`);
    selectedMedia.value = response.data.data.media;

    // Also refresh unconfirmed face count
    await loadUnconfirmedFaceCount();
  } catch (error) {
    console.error('Failed to update face confirmation:', error);
    alert(error.response?.data?.error || 'Failed to update face confirmation');
  } finally {
    confirmingFaceTag.value = false;
  }
};

const loadUnconfirmedFaceCount = async () => {
  if (!selectedTreeId.value) return;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/faces/unconfirmed`);
    unconfirmedFaces.value = response.data.data.faces || [];
    unconfirmedFaceCount.value = response.data.data.count || 0;
  } catch (error) {
    console.error('Failed to load unconfirmed faces:', error);
  }
};

// Media Category Methods (Phase 3.6)
const filterMediaByCategory = async (category) => {
  selectedMediaCategory.value = category;
  await loadMedia();
};

const loadMedia = async () => {
  if (!selectedTreeId.value) return;

  try {
    const params = { limit: 100 };
    if (selectedMediaCategory.value && selectedMediaCategory.value !== 'all') {
      params.type = selectedMediaCategory.value;
    }
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/media`, { params });
    media.value = response.data.data.media;
    mediaCategoryCounts.value = response.data.data.category_counts || {};
  } catch (error) {
    console.error('Failed to load media:', error);
  }
};

const updateMediaType = async (mediaId, newType) => {
  try {
    await axios.put(`/api/genealogy/media/${mediaId}/type`, { media_type: newType });

    // Refresh media details if we're viewing this media
    if (selectedMedia.value && selectedMedia.value.id === mediaId) {
      const response = await axios.get(`/api/genealogy/media/${mediaId}`);
      selectedMedia.value = response.data.data.media;
    }

    // Refresh media list to update counts
    await loadMedia();
  } catch (error) {
    console.error('Failed to update media type:', error);
    alert(error.response?.data?.error || 'Failed to update media type');
  }
};

const getMediaTypeLabel = (type) => {
  const cat = mediaCategories.find(c => c.id === type);
  return cat ? `${cat.icon} ${cat.label}` : type || 'Photo';
};

// Document Transcription Methods (Phase 3.7)
const startEditingTranscription = () => {
  transcriptionText.value = selectedMedia.value?.transcription || '';
  editingTranscription.value = true;
};

const cancelEditingTranscription = () => {
  editingTranscription.value = false;
  transcriptionText.value = '';
};

const saveTranscription = async () => {
  if (!selectedMedia.value) return;

  savingTranscription.value = true;
  try {
    await axios.put(`/api/genealogy/media/${selectedMedia.value.id}/transcription`, {
      transcription: transcriptionText.value,
      source: 'manual'
    });

    // Refresh media details
    const response = await axios.get(`/api/genealogy/media/${selectedMedia.value.id}`);
    selectedMedia.value = response.data.data.media;

    editingTranscription.value = false;
    transcriptionText.value = '';
  } catch (error) {
    console.error('Failed to save transcription:', error);
    alert(error.response?.data?.error || 'Failed to save transcription');
  } finally {
    savingTranscription.value = false;
  }
};

const loadTranscriptionQueue = async () => {
  if (!selectedTreeId.value) return;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/media/transcription-queue`);
    transcriptionQueue.value = response.data.data.media || [];
  } catch (error) {
    console.error('Failed to load transcription queue:', error);
  }
};

const loadIntakeRuns = async () => {
  if (!selectedTreeId.value) return;

  try {
    const response = await axios.get('/api/genealogy/intake-runs', {
      params: { tree_id: selectedTreeId.value, limit: 50 }
    });
    intakeRuns.value = response.data.data.runs || [];
  } catch (error) {
    console.error('Failed to load intake runs:', error);
  }
};

const stageIntakeRun = async () => {
  if (!selectedTreeId.value) return;

  stagingIntakeRun.value = true;
  intakeRunStageError.value = '';

  try {
    const response = await axios.post('/api/genealogy/intake-runs/stage', {
      tree_id: selectedTreeId.value,
      root_path: `${intakeRunStageForm.value.root_path || ''}`.trim(),
      packet_label: `${intakeRunStageForm.value.packet_label || ''}`.trim() || null,
      limit: normalizePositiveInt(intakeRunStageForm.value.limit) || 100,
      unprocessed_only: !!intakeRunStageForm.value.unprocessed_only,
    });

    await loadIntakeRuns();

    const runKey = response.data?.data?.run?.run_key || null;
    if (runKey) {
      await selectIntakeRun(runKey);
    }
  } catch (error) {
    console.error('Failed to stage intake run:', error);
    intakeRunStageError.value = error.response?.data?.error?.message || 'Failed to stage intake run';
  } finally {
    stagingIntakeRun.value = false;
  }
};

const intakeRunPacketOptions = computed(() => {
  const packets = Array.isArray(selectedIntakeRun.value?.packets) ? selectedIntakeRun.value.packets : [];

  return packets
    .map((packet, index) => {
      const label = (packet?.packet_label || '').trim();

      return {
        value: label || `packet-${index + 1}`,
        label: label || `Untitled packet ${index + 1}`,
      };
    });
});

const selectedIntakeRunPacket = computed(() => {
  const packets = Array.isArray(selectedIntakeRun.value?.packets) ? selectedIntakeRun.value.packets : [];
  const selectedLabel = (selectedIntakeRunPacketLabel.value || selectedIntakeRunPreview.value?.packet_label || '').trim().toLowerCase();

  if (!selectedLabel) {
    return null;
  }

  return packets.find((item) => ((item?.packet_label || '').trim().toLowerCase() === selectedLabel)) || null;
});

const selectedIntakeRunWorkspaceQueueEntry = computed(() => {
  const queue = selectedIntakeRunWorkspace.value?.queue;
  const packet = selectedIntakeRunPacket.value;
  const entries = [
    ...(Array.isArray(queue?.ready_packets) ? queue.ready_packets : []),
    ...(Array.isArray(queue?.blocked_packets) ? queue.blocked_packets : []),
    ...(Array.isArray(queue?.pending_packets) ? queue.pending_packets : []),
  ];

  if (!packet || !entries.length) {
    return null;
  }

  const packetKey = (packet.packet_key || '').trim().toLowerCase();
  const packetLabel = (packet.packet_label || '').trim().toLowerCase();

  return entries.find((entry) => {
    const entryKey = (entry?.packet_key || '').trim().toLowerCase();
    const entryLabel = (entry?.packet_label || '').trim().toLowerCase();

    if (packetKey && entryKey) {
      return entryKey === packetKey;
    }

    return packetLabel && entryLabel === packetLabel;
  }) || null;
});

const intakeRunPacketList = computed(() => {
  const packets = Array.isArray(selectedIntakeRun.value?.packets) ? selectedIntakeRun.value.packets : [];
  const queue = selectedIntakeRunWorkspace.value?.queue;
  const entries = [
    ...(Array.isArray(queue?.ready_packets) ? queue.ready_packets : []),
    ...(Array.isArray(queue?.blocked_packets) ? queue.blocked_packets : []),
    ...(Array.isArray(queue?.pending_packets) ? queue.pending_packets : []),
  ];
  const selectedLabel = (selectedIntakeRunPacketLabel.value || selectedIntakeRunPreview.value?.packet_label || '').trim().toLowerCase();

  return packets.map((packet, index) => {
    const packetKey = (packet?.packet_key || '').trim().toLowerCase();
    const packetLabel = (packet?.packet_label || '').trim();
    const normalizedLabel = packetLabel.toLowerCase();
    const queueEntry = entries.find((entry) => {
      const entryKey = (entry?.packet_key || '').trim().toLowerCase();
      const entryLabel = (entry?.packet_label || '').trim().toLowerCase();

      if (packetKey && entryKey) {
        return entryKey === packetKey;
      }

      return normalizedLabel && entryLabel === normalizedLabel;
    }) || null;

    return {
      value: packetLabel || `packet-${index + 1}`,
      label: packetLabel || `Untitled packet ${index + 1}`,
      status: queueEntry?.status || null,
      reason: queueEntry?.reason || null,
      actionLabel: queueEntry?.action?.label || null,
      actionPriority: queueEntry?.action?.priority || null,
      questionCount: Array.isArray(packet?.preview_state?.questions) ? packet.preview_state.questions.length : 0,
      proposalReady: packet?.preview_state?.proposal_ready === true,
      applyStatus: packet?.approval_apply_state?.status || null,
      applyUpdatedAt: packet?.approval_apply_state?.updated_at || null,
      selected: !!selectedLabel && (packetLabel ? normalizedLabel === selectedLabel : `packet-${index + 1}` === selectedIntakeRunPacketLabel.value),
    };
  });
});

const filteredIntakeRunPacketList = computed(() => {
  const search = intakeRunPacketSearch.value.trim().toLowerCase();
  const priorityRank = { high: 3, medium: 2, low: 1, null: 0 };
  const stageRank = { blocked: 0, pending: 1, ready: 2, null: 3 };

  let rows = intakeRunPacketList.value.filter((packet) => {
    if (intakeRunPacketStageFilter.value !== 'all' && (packet.status || 'unknown') !== intakeRunPacketStageFilter.value) {
      return false;
    }

    if (intakeRunPacketQuestionFilter.value === 'yes' && packet.questionCount < 1) {
      return false;
    }

    if (intakeRunPacketQuestionFilter.value === 'no' && packet.questionCount > 0) {
      return false;
    }

    if (intakeRunPacketProposalFilter.value === 'ready' && !packet.proposalReady) {
      return false;
    }

    if (intakeRunPacketProposalFilter.value === 'not_ready' && packet.proposalReady) {
      return false;
    }

    if (!search) {
      return true;
    }

    return [packet.label, packet.reason, packet.actionLabel]
      .filter(Boolean)
      .some((value) => value.toLowerCase().includes(search));
  });

  rows = [...rows].sort((left, right) => {
    switch (intakeRunPacketSort.value) {
      case 'label':
        return left.label.localeCompare(right.label, undefined, { sensitivity: 'base' });
      case 'questions':
        return right.questionCount - left.questionCount || left.label.localeCompare(right.label, undefined, { sensitivity: 'base' });
      case 'stage':
        return (stageRank[left.status ?? 'null'] ?? 3) - (stageRank[right.status ?? 'null'] ?? 3)
          || left.label.localeCompare(right.label, undefined, { sensitivity: 'base' });
      case 'proposal':
        return Number(right.proposalReady) - Number(left.proposalReady)
          || left.label.localeCompare(right.label, undefined, { sensitivity: 'base' });
      case 'priority':
      default:
        return (priorityRank[right.actionPriority ?? 'null'] ?? 0) - (priorityRank[left.actionPriority ?? 'null'] ?? 0)
          || (stageRank[left.status ?? 'null'] ?? 3) - (stageRank[right.status ?? 'null'] ?? 3)
          || right.questionCount - left.questionCount
          || left.label.localeCompare(right.label, undefined, { sensitivity: 'base' });
    }
  });

  return rows;
});

const selectedIntakeRunPacketView = computed(() => {
  const packet = selectedIntakeRunPacket.value;
  const queueEntry = selectedIntakeRunWorkspaceQueueEntry.value;

  return {
    packet,
    previewState: packet?.preview_state || null,
    reviewDecision: packet?.review_decision || null,
    execution: packet?.reference_copy_execution || null,
    applyState: packet?.approval_apply_state || null,
    stage: queueEntry
      ? {
          status: queueEntry.status || null,
          reason: queueEntry.reason || null,
        }
      : null,
    presentation: queueEntry?.presentation || null,
    action: queueEntry?.action || null,
  };
});

const selectedIntakeRunPacketExecution = computed(() => {
  return selectedIntakeRunPacketView.value.execution;
});
const selectedIntakeRunPacketApplyState = computed(() => selectedIntakeRunPacketView.value.applyState);
const selectedIntakeRunPacketProposalGenerationState = computed(() => {
  return selectedIntakeRunPacket.value?.proposal_generation_state || null;
});
const selectedIntakeRunPacketPreviewState = computed(() => selectedIntakeRunPacketView.value.previewState);
const selectedIntakeRunPacketReviewDecision = computed(() => selectedIntakeRunPacketView.value.reviewDecision);
const selectedIntakeRunPacketStage = computed(() => selectedIntakeRunPacketView.value.stage);
const selectedIntakeRunPacketPresentation = computed(() => selectedIntakeRunPacketView.value.presentation);
const selectedIntakeRunPacketAction = computed(() => selectedIntakeRunPacketView.value.action);
const selectedIntakeRunPacketBindingSignals = computed(() => selectedIntakeRunSelectedPacket.value?.binding_signals || null);
const selectedIntakeRunPhaseSummary = computed(() => {
  const action = selectedIntakeRunPacketAction.value;
  const stage = selectedIntakeRunPacketStage.value;
  const decision = `${selectedIntakeRunPacketReviewDecision.value?.decision || ''}`.trim().toLowerCase();
  const previewState = selectedIntakeRunPacketPreviewState.value;

  if (!selectedIntakeRun.value) {
    return {
      current: 'No run selected',
      summary: 'Choose a saved run from the left column.',
      detail: 'Once a run is selected, this panel will show the current packet phase, the packet contents, and the next operator action.',
      next: 'Open a saved run',
      tone: 'info',
    };
  }

  if (!selectedIntakeRunPreview.value) {
    return {
      current: 'Loading packet preview',
      summary: 'The selected run is being prepared for review.',
      detail: 'Large packets can take longer while the packet preview and questions are generated.',
      next: 'Wait for packet preview',
      tone: 'info',
    };
  }

  if (decision === 'approved') {
    return {
      current: 'Human review approved',
      summary: 'The packet has a saved approval decision.',
      detail: 'The run still remains in a staging workspace until the FT reference-copy and apply phases are executed.',
      next: action?.label || 'Proceed to reference copy and proposal/apply phases',
      tone: 'ready',
    };
  }

  if (decision === 'deferred' || decision === 'needs_followup') {
    return {
      current: 'Human follow-up needed',
      summary: 'The packet was reviewed but still needs more operator work.',
      detail: 'Use the packet contents, summary, and open questions to resolve the uncertainty before approving the packet.',
      next: action?.label || 'Resolve deferred packet questions',
      tone: 'pending',
    };
  }

  if (decision === 'rejected') {
    return {
      current: 'Packet rejected',
      summary: 'The current packet was explicitly rejected.',
      detail: 'Rejected packets remain visible for audit and may still need cleanup or relabeling before the broader workflow continues.',
      next: action?.label || 'Review another packet or adjust the run',
      tone: 'blocked',
    };
  }

  if (stage?.status === 'blocked') {
    return {
      current: 'Blocked before apply',
      summary: selectedIntakeRunPacketPresentation.value?.headline || 'This packet is blocked.',
      detail: selectedIntakeRunPacketAction.value?.description || 'Resolve the blocking condition before continuing.',
      next: action?.label || 'Resolve packet block',
      tone: 'blocked',
    };
  }

  if (previewState?.questions?.length) {
    return {
      current: 'Question review phase',
      summary: 'The packet preview found open questions that still need a human decision.',
      detail: 'Review the packet contents and evidence below, then either answer the uncertainty with notes or defer/follow up instead of approving too early.',
      next: action?.label || 'Review packet questions',
      tone: 'pending',
    };
  }

  return {
    current: 'Staging and operator review',
    summary: selectedIntakeRunPacketPresentation.value?.headline || 'The packet is staged and waiting for human review.',
    detail: selectedIntakeRunPacketAction.value?.description || 'Review the packet contents and record the operator decision.',
    next: action?.label || selectedIntakeRun.value?.summary?.next_action || 'Review packet and capture decision',
    tone: stage?.status === 'ready' ? 'ready' : 'pending',
  };
});
const selectedIntakeRunDocumentPreviewItems = computed(() => {
  const documents = Array.isArray(selectedIntakeRunPreview.value?.registration?.documents)
    ? selectedIntakeRunPreview.value.registration.documents
    : [];

  return documents.map((document, index) => {
    const documentType = `${document?.document_type || 'document'}`.trim() || 'document';
    const copyStatus = `${document?.copy_plan?.status || 'ready'}`.trim() || 'ready';
    const classification = `${document?.classification || 'unclassified'}`.trim() || 'unclassified';
    const duplicateScope = `${document?.duplicate_scope || 'unknown'}`.trim() || 'unknown';
    const sourcePath = `${document?.source_path || ''}`.trim();
    const referenceCopyPath = `${document?.reference_copy_path || ''}`.trim();
    const previewPath = sourcePath || referenceCopyPath;
    const previewType = getDocumentPreviewType(previewPath);
    const anchors = Array.isArray(document?.pages)
      ? document.pages
        .map((page) => `${page?.anchor_label || ''}`.trim())
        .filter(Boolean)
      : [];

    return {
      documentId: `${document?.document_id || index}`,
      sourceName: `${document?.source_name || 'document'}`.trim() || 'document',
      sourcePath: sourcePath || 'No source path recorded',
      referenceCopyPath: referenceCopyPath || 'No FT target planned yet',
      documentType,
      pageCount: Number(document?.page_count || 0),
      copyStatus,
      copyStatusLabel: copyStatus.replace(/_/g, ' '),
      copyReason: `${document?.copy_plan?.reason || ''}`.trim(),
      classificationLabel: classification.replace(/_/g, ' '),
      duplicateScopeLabel: duplicateScope.replace(/_/g, ' '),
      alreadyIngested: Boolean(document?.already_ingested),
      anchorLabels: anchors.slice(0, 6),
      previewPath,
      previewType,
      previewable: Boolean(previewPath && previewType),
      previewLine: `${Number(document?.page_count || 0)} page(s) · ${documentType} · ${copyStatus.replace(/_/g, ' ')}`,
    };
  });
});
const selectedIntakeRunDocumentTypeSummary = computed(() => {
  const counts = {};
  for (const item of selectedIntakeRunDocumentPreviewItems.value) {
    counts[item.documentType] = (counts[item.documentType] || 0) + 1;
  }

  const parts = Object.entries(counts)
    .sort(([left], [right]) => left.localeCompare(right, undefined, { sensitivity: 'base' }))
    .map(([type, count]) => `${type} ${count}`);

  return parts.join(' · ');
});
const selectedIntakeRunDocumentIssueSummary = computed(() => {
  const items = selectedIntakeRunDocumentPreviewItems.value;
  if (!items.length) {
    return [];
  }

  const conflicts = items.filter((item) => item.copyStatus === 'conflict').length;
  const missingSource = items.filter((item) => item.copyStatus === 'missing_source_path').length;
  const noPreview = items.filter((item) => !item.previewable).length;
  const alreadyIngested = items.filter((item) => item.alreadyIngested).length;

  const summary = [];
  if (conflicts > 0) summary.push(`${conflicts} conflict${conflicts === 1 ? '' : 's'}`);
  if (missingSource > 0) summary.push(`${missingSource} missing source path`);
  if (noPreview > 0) summary.push(`${noPreview} without preview`);
  if (alreadyIngested > 0) summary.push(`${alreadyIngested} already ingested`);

  return summary;
});
const selectedIntakeRunLikelyMatches = computed(() => {
  const candidates = Array.isArray(selectedIntakeRunPreview.value?.preview?.person_candidates)
    ? [...selectedIntakeRunPreview.value.preview.person_candidates]
    : [];

  const score = {
    high: 3,
    medium: 2,
    low: 1,
  };

  return candidates
    .sort((left, right) => {
      const confidenceDiff = (score[right.confidence] || 0) - (score[left.confidence] || 0);
      if (confidenceDiff !== 0) return confidenceDiff;
      return `${left.name || ''}`.localeCompare(`${right.name || ''}`, undefined, { sensitivity: 'base' });
    })
    .slice(0, 3);
});
const selectedIntakeRunDecisionPrompt = computed(() => {
  const questionCount = Number(selectedIntakeRunPreview.value?.preview?.questions?.length || 0);
  const likelyMatchCount = selectedIntakeRunLikelyMatches.value.length;
  const issueCount = selectedIntakeRunDocumentIssueSummary.value.length;

  if (questionCount > 0) {
    return 'Resolve or note the open questions before approving this packet.';
  }

  if (issueCount > 0) {
    return 'Check the flagged packet items below before saving the review decision.';
  }

  if (likelyMatchCount > 0) {
    return 'Confirm the likely tree matches before approving this packet.';
  }

  return 'Approve only after the packet items, summary, and match context are clear.';
});
const selectedIntakeRunPreviewStateSummary = computed(() => {
  const state = selectedIntakeRunPacketPreviewState.value;
  if (!state) return 'No saved preview state';

  const status = `${state.status || 'unknown'}`.replace(/_/g, ' ');
  const questions = Number(state.questions?.length || 0);
  return `${status} · ${questions} question${questions === 1 ? '' : 's'}`;
});
const selectedIntakeRunBindingSignalsSummary = computed(() => {
  const signals = selectedIntakeRunPacketBindingSignals.value;
  if (!signals) return 'No match signals';

  const strength = `${signals.binding_strength || 'unknown'}`.replace(/_/g, ' ');
  const existing = Number(signals.existing_person_match_count ?? 0);
  const candidates = Number(signals.new_person_candidate_count ?? 0);
  return `${strength} · ${existing} existing · ${candidates} candidate${candidates === 1 ? '' : 's'}`;
});
const selectedIntakeRunCopyExecutionSummary = computed(() => {
  const execution = selectedIntakeRunPacketExecution.value?.execution;
  if (!execution) return 'No copy run recorded';

  const copied = Number(execution.summary?.copied || 0);
  const blocked = Number(execution.summary?.blocked_conflicts || 0);
  return `${copied} copied · ${blocked} blocked conflict${blocked === 1 ? '' : 's'}`;
});
const selectedIntakeRunWorkspaceOverview = computed(() => {
  return selectedIntakeRunWorkspace.value?.overview || null;
});
const selectedIntakeRunRecommendations = computed(() => {
  return selectedIntakeRunWorkspace.value?.recommendations || null;
});
const selectedIntakeRunRecommendationPrimary = computed(() => {
  return selectedIntakeRunRecommendations.value?.primary || null;
});
const selectedIntakeRunRecommendationShortcuts = computed(() => {
  return selectedIntakeRunRecommendations.value?.shortcuts || {};
});
const selectedIntakeRunProposalPreviews = computed(() => {
  return selectedIntakeRunWorkspace.value?.proposal_previews || { ready_packets: [], count: 0 };
});
const selectedIntakeRunDraftPlan = computed(() => {
  return selectedIntakeRunProposalDraft.value?.draft_plan || null;
});
const selectedIntakeRunProposalPreviewEntry = computed(() => {
  const selectedPacket = selectedIntakeRunPacket.value;
  const previews = Array.isArray(selectedIntakeRunProposalPreviews.value?.ready_packets)
    ? selectedIntakeRunProposalPreviews.value.ready_packets
    : [];

  if (!selectedPacket || !previews.length) {
    return null;
  }

  const packetKey = (selectedPacket.packet_key || '').trim().toLowerCase();
  const packetLabel = (selectedPacket.packet_label || '').trim().toLowerCase();

  return previews.find((entry) => {
    const entryKey = (entry?.packet_key || '').trim().toLowerCase();
    const entryLabel = (entry?.packet_label || '').trim().toLowerCase();

    if (packetKey && entryKey) {
      return entryKey === packetKey;
    }

    return packetLabel && entryLabel === packetLabel;
  }) || null;
});
const selectedIntakeRunProposalPreview = computed(() => selectedIntakeRunProposalPreviewEntry.value?.preview || null);
const selectedIntakeRunProposalSuggestedSections = computed(() => {
  return Array.isArray(selectedIntakeRunProposalPreview.value?.proposal_outline?.suggested_sections)
    ? selectedIntakeRunProposalPreview.value.proposal_outline.suggested_sections
    : [];
});
const selectedIntakeRunProposalBlockingReasons = computed(() => {
  const reasons = Array.isArray(selectedIntakeRunProposalPreview.value?.proposal_outline?.blocking_reasons)
    ? selectedIntakeRunProposalPreview.value.proposal_outline.blocking_reasons
    : [];

  return reasons
    .map((reason) => formatGenealogyProposalReason(reason))
    .filter(Boolean);
});
const selectedIntakeRunProposalEvidenceParagraphs = computed(() => {
  const text = `${selectedIntakeRunProposalPreview.value?.evidence?.summary_text || ''}`.trim();
  if (!text) {
    return [];
  }

  return text
    .split(/\n\s*\n/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean);
});
const selectedIntakeRunProposalEvidenceAnchors = computed(() => {
  const anchors = Array.isArray(selectedIntakeRunProposalPreview.value?.evidence?.anchors)
    ? selectedIntakeRunProposalPreview.value.evidence.anchors
    : [];

  return anchors
    .map((anchor) => `${anchor ?? ''}`.trim())
    .filter(Boolean);
});
const selectedIntakeRunProposalDecisionLabel = computed(() => {
  return formatGenealogyProposalDecision(selectedIntakeRunProposalPreview.value?.review_context?.decision);
});
const selectedIntakeRunProposalReviewedByLabel = computed(() => {
  const value = `${selectedIntakeRunProposalPreview.value?.review_context?.reviewed_by || ''}`.trim();
  return value || 'Not recorded';
});
const selectedIntakeRunProposalReviewNotes = computed(() => {
  const value = `${selectedIntakeRunDraftInput.value?.review_decision?.notes || ''}`.trim();
  return value || '';
});
const selectedIntakeRunProposalQuestionSummary = computed(() => {
  const count = Number(selectedIntakeRunProposalPreview.value?.review_context?.question_count || 0);
  if (count <= 0) {
    return 'No open review questions';
  }

  return `${count} question${count === 1 ? '' : 's'} still need review`;
});
const selectedIntakeRunProposalAnchorSummary = computed(() => {
  const count = Number(selectedIntakeRunProposalPreview.value?.review_context?.anchor_count || 0);
  if (count <= 0) {
    return 'No page anchors saved';
  }

  return `${count} anchor${count === 1 ? '' : 's'} available`;
});
const selectedIntakeRunProposalReviewHeadline = computed(() => {
  const preview = selectedIntakeRunProposalPreview.value;
  if (!preview) {
    return '';
  }

  if (preview.proposal_outline?.can_generate) {
    return 'This packet has enough reviewed evidence to draft a proposal. Confirm the suggested sections and target details before applying supported changes.';
  }

  const firstReason = selectedIntakeRunProposalBlockingReasons.value[0];
  return firstReason
    ? `This packet is not ready to generate a proposal yet. Start with: ${firstReason}`
    : 'This packet still needs review before a proposal can be generated.';
});
const selectedIntakeRunProposalReadinessChecklist = computed(() => {
  const preview = selectedIntakeRunProposalPreview.value;
  if (!preview) {
    return [];
  }

  const questionCount = Number(preview.review_context?.question_count || 0);
  const anchorCount = Number(preview.review_context?.anchor_count || 0);
  const decision = `${preview.review_context?.decision || ''}`.trim().toLowerCase();
  const suggestedSections = selectedIntakeRunProposalSuggestedSections.value;

  return [
    {
      label: 'Human review decision captured',
      detail: decision ? formatGenealogyProposalDecision(decision) : 'No decision saved yet.',
      ok: decision === 'approved',
    },
    {
      label: 'Evidence summary available',
      detail: selectedIntakeRunProposalEvidenceParagraphs.value.length
        ? 'A readable evidence summary is available below.'
        : 'No evidence summary text was returned for this packet.',
      ok: selectedIntakeRunProposalEvidenceParagraphs.value.length > 0,
    },
    {
      label: 'Review questions resolved',
      detail: questionCount > 0
        ? `${questionCount} question${questionCount === 1 ? '' : 's'} still need review.`
        : 'No open review questions remain.',
      ok: questionCount === 0,
    },
    {
      label: 'Evidence anchors available',
      detail: anchorCount > 0
        ? `${anchorCount} anchor${anchorCount === 1 ? '' : 's'} can help trace the source pages.`
        : 'No anchors were returned for this packet.',
      ok: anchorCount > 0,
    },
    {
      label: 'Suggested proposal sections',
      detail: suggestedSections.length
        ? suggestedSections.map((section) => formatGenealogyProposalSectionLabel(section)).join(', ')
        : 'No suggested sections were returned.',
      ok: suggestedSections.length > 0,
    },
  ];
});
const selectedIntakeRunDraftPacket = computed(() => {
  const draftPlan = selectedIntakeRunDraftPlan.value;
  const selectedPacket = selectedIntakeRunPacket.value;

  if (!draftPlan || !selectedPacket) {
    return null;
  }

  const packetKey = (selectedPacket.packet_key || '').trim().toLowerCase();
  const packetLabel = (selectedPacket.packet_label || '').trim().toLowerCase();

  return (Array.isArray(draftPlan.ready_packets) ? draftPlan.ready_packets : []).find((packet) => {
    const entryKey = (packet?.packet_key || '').trim().toLowerCase();
    const entryLabel = (packet?.packet_label || '').trim().toLowerCase();

    if (packetKey && entryKey) {
      return entryKey === packetKey;
    }

    return packetLabel && entryLabel === packetLabel;
  }) || null;
});
const selectedIntakeRunDraftInput = computed(() => selectedIntakeRunDraftPacket.value?.draft_input || null);
const selectedIntakeRunDraftInputJson = computed(() => {
  return selectedIntakeRunDraftInput.value
    ? JSON.stringify(selectedIntakeRunDraftInput.value, null, 2)
    : '';
});
const selectedIntakeRunApprovalDraftFormatted = computed(() => {
  return selectedIntakeRunApprovalDraftPreview.value?.formatted || null;
});
const selectedIntakeRunApprovalDraftPlanHash = computed(() => {
  return selectedIntakeRunApprovalDraftPreview.value?.plan_hash || null;
});
const selectedIntakeRunPacketApplyStateIsCurrent = computed(() => {
  const applyState = selectedIntakeRunPacketApplyState.value;
  const currentPlanHash = selectedIntakeRunApprovalDraftPlanHash.value;

  if (!applyState?.plan_hash) {
    return false;
  }

  if (!currentPlanHash) {
    return true;
  }

  return applyState.plan_hash === currentPlanHash;
});
const generatedProposalPendingUnifiedIds = computed(() => {
  const review = selectedIntakeRunGeneratedProposalReview.value;
  if (!review) return [];
  return (review.person_changes || []).filter((change) => change.status === 'pending').map((change) => change.unified_id);
});
const isGeneratedRelationshipProposal = (unifiedId) => `${unifiedId ?? ''}`.startsWith('proposal:');
const buildResearchHubLink = (unifiedId = null) => {
  const params = new URLSearchParams({ category: 'genealogy' });
  if (unifiedId) {
    params.set('unified_id', unifiedId);
  }

  return `/research-hub?${params.toString()}`;
};
const formatGenealogyProposalSectionLabel = (section) => {
  const value = `${section ?? ''}`.trim().toLowerCase();
  if (!value) {
    return 'Unknown';
  }

  const labels = {
    identity: 'Identity',
    relationships: 'Relationships',
    events: 'Events',
    sources: 'Sources',
    notes: 'Notes',
  };

  return labels[value] || value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
};

const formatGenealogyProposalDecision = (decision) => {
  const value = `${decision ?? ''}`.trim().toLowerCase();
  if (!value) {
    return 'Not reviewed yet';
  }

  const labels = {
    approved: 'Approved for proposal work',
    deferred: 'Deferred for later review',
    rejected: 'Rejected',
    needs_followup: 'Needs follow-up',
  };

  return labels[value] || value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
};

const formatGenealogyProposalReason = (reason) => {
  const value = `${reason ?? ''}`.trim();
  if (!value) {
    return '';
  }

  const labels = {
    approved_and_ready: 'Approved and ready for proposal generation.',
    missing_copy_execution: 'Reference-copy step has not completed for this packet.',
    missing_preview_state: 'Preview output has not been saved for this packet yet.',
    empty_preview_packet: 'The preview came back empty, so there is nothing reliable to stage.',
    missing_review_decision: 'A human review decision is still missing.',
    preview_has_questions: 'Open review questions remain and should be resolved first.',
    not_proposal_ready: 'The packet preview marked this packet as not proposal-ready.',
    decision_deferred: 'This packet was deferred and needs a later review pass.',
    decision_rejected: 'This packet was rejected and should not move forward as-is.',
    decision_needs_followup: 'This packet needs follow-up work before proposal generation.',
    copy_conflict: 'Reference-copy conflicts must be resolved before continuing.',
    copy_failed: 'The reference-copy step failed and needs attention.',
    preview_not_generatable: 'The current evidence is not strong enough to generate a proposal yet.',
  };

  if (labels[value]) {
    return labels[value];
  }

  return value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
};
const nextBlockedIntakePacket = computed(() => {
  return intakeRunPacketList.value.find((packet) => packet.status === 'blocked' && !packet.selected) || null;
});
const nextQuestionIntakePacket = computed(() => {
  return intakeRunPacketList.value.find((packet) => packet.questionCount > 0 && !packet.selected) || null;
});
const nextProposalReadyIntakePacket = computed(() => {
  return intakeRunPacketList.value.find((packet) => packet.proposalReady && !packet.selected) || null;
});
const nextUnreviewedIntakePacket = computed(() => {
  const packets = Array.isArray(selectedIntakeRun.value?.packets) ? selectedIntakeRun.value.packets : [];
  const currentLabel = (selectedIntakeRunPacketLabel.value || '').trim().toLowerCase();

  const candidate = packets.find((packet, index) => {
    const label = ((packet?.packet_label || `packet-${index + 1}`) + '').trim();
    const normalizedLabel = label.toLowerCase();
    const decision = (packet?.review_decision?.decision || '').trim();

    return !decision && normalizedLabel !== currentLabel;
  });

  if (!candidate) {
    return null;
  }

  const label = (candidate.packet_label || '').trim();

  return intakeRunPacketList.value.find((packet) => {
    return label
      ? packet.label.trim().toLowerCase() === label.toLowerCase()
      : false;
  }) || null;
});

const selectedIntakeRunPriorityPreview = computed(() => {
  const overview = selectedIntakeRunWorkspaceOverview.value;

  if (!overview) {
    return {
      high: [],
      medium: [],
      ready: [],
    };
  }

  return {
    high: Array.isArray(overview.high_priority_packets) ? overview.high_priority_packets.slice(0, 5) : [],
    medium: Array.isArray(overview.medium_priority_packets) ? overview.medium_priority_packets.slice(0, 5) : [],
    ready: Array.isArray(overview.ready_for_proposals_packets) ? overview.ready_for_proposals_packets.slice(0, 5) : [],
  };
});

const selectIntakeRun = async (runKey, previewPacket = null) => {
  if (!runKey) return;

  loadingIntakeRunPreview.value = true;
  try {
    const params = {};
    if (previewPacket) {
      params.preview_packet = previewPacket;
    }

    const response = await axios.get(`/api/genealogy/intake-runs/${encodeURIComponent(runKey)}`, {
      params,
    });
    const [workspaceResponse, proposalDraftResponse] = await Promise.all([
      axios.get(`/api/genealogy/intake-runs/${encodeURIComponent(runKey)}/workspace`),
      axios.get(`/api/genealogy/intake-runs/${encodeURIComponent(runKey)}/proposal-draft`),
    ]);
    selectedIntakeRun.value = response.data.data.run || null;
    selectedIntakeRunPreview.value = response.data.data.packet_preview || null;
    selectedIntakeRunSelectedPacket.value = response.data.data.selected_packet || null;
    selectedIntakeRunWorkspace.value = workspaceResponse.data.data || null;
    selectedIntakeRunProposalDraft.value = proposalDraftResponse.data.data || null;
    selectedIntakeRunApprovalDraftPreview.value = null;
    intakeApprovalDraftPreviewError.value = '';
    intakeRunDecisionNotes.value = selectedIntakeRunPacketReviewDecision.value?.notes || '';
    selectedIntakeRunPacketLabel.value = selectedIntakeRunPreview.value?.packet_label
      || intakeRunPacketOptions.value[0]?.value
      || '';
  } catch (error) {
    console.error('Failed to load intake run preview:', error);
    selectedIntakeRun.value = null;
    selectedIntakeRunPreview.value = null;
    selectedIntakeRunSelectedPacket.value = null;
    selectedIntakeRunWorkspace.value = null;
    selectedIntakeRunProposalDraft.value = null;
    selectedIntakeRunApprovalDraftPreview.value = null;
    intakeApprovalDraftPreviewError.value = '';
    selectedIntakeRunPacketLabel.value = '';
    intakeRunDecisionNotes.value = '';
  } finally {
    loadingIntakeRunPreview.value = false;
  }
};

const reloadSelectedIntakeRunPacket = async () => {
  if (!selectedIntakeRun.value?.run_key) return;

  await selectIntakeRun(selectedIntakeRun.value.run_key, selectedIntakeRunPacketLabel.value || null);
};

const jumpToIntakeRunPacket = async (packetValue) => {
  if (!packetValue || !selectedIntakeRun.value?.run_key) return;

  selectedIntakeRunPacketLabel.value = packetValue;
  await reloadSelectedIntakeRunPacket();
};

const submitIntakeRunDecision = async (decision) => {
  if (!selectedIntakeRun.value?.run_key || !selectedIntakeRunPacket.value) return;

  savingIntakeRunDecision.value = true;
  try {
    await axios.post(`/api/genealogy/intake-runs/${encodeURIComponent(selectedIntakeRun.value.run_key)}/review-decision`, {
      packet_key: selectedIntakeRunPacket.value.packet_key || null,
      packet_label: selectedIntakeRunPacket.value.packet_label || null,
      decision,
      notes: intakeRunDecisionNotes.value || null,
    });

    await selectIntakeRun(selectedIntakeRun.value.run_key, selectedIntakeRunPacketLabel.value || null);
    await loadIntakeRuns();
  } catch (error) {
    console.error('Failed to save intake run decision:', error);
    alert(error.response?.data?.error?.message || 'Failed to save intake run decision');
  } finally {
    savingIntakeRunDecision.value = false;
  }
};

const normalizePositiveInt = (value) => {
  const trimmed = `${value ?? ''}`.trim();
  if (!trimmed) {
    return null;
  }

  const parsed = parseInt(trimmed, 10);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
};

const loadApprovalDraftPreview = async () => {
  if (!selectedIntakeRun.value?.run_key || !selectedIntakeRunPacket.value || !selectedIntakeRunProposalPreview.value || !selectedIntakeRunDraftInput.value) {
    selectedIntakeRunApprovalDraftPreview.value = null;
    intakeApprovalDraftPreviewError.value = '';
    return;
  }

  loadingIntakeApprovalDraftPreview.value = true;
  intakeApprovalDraftPreviewError.value = '';

  try {
    const response = await axios.post(`/api/genealogy/intake-runs/${encodeURIComponent(selectedIntakeRun.value.run_key)}/approval-draft-preview`, {
      packet_key: selectedIntakeRunPacket.value.packet_key || null,
      packet_label: selectedIntakeRunPacket.value.packet_label || null,
      approved_sections: intakeProposalApprovedSections.value,
      person_id: normalizePositiveInt(intakeProposalTargetPersonId.value),
      relationship_type: intakeProposalRelationshipType.value || null,
      related_person_id: normalizePositiveInt(intakeProposalRelatedPersonId.value),
    });

    selectedIntakeRunApprovalDraftPreview.value = response.data.data?.approval_draft_preview || null;
  } catch (error) {
    console.error('Failed to load approval draft preview:', error);
    selectedIntakeRunApprovalDraftPreview.value = null;
    intakeApprovalDraftPreviewError.value = error.response?.data?.error?.message || 'Failed to load approval draft preview';
  } finally {
    loadingIntakeApprovalDraftPreview.value = false;
  }
};

const applyApprovalDraft = async () => {
  if (!selectedIntakeRun.value?.run_key || !selectedIntakeRunPacket.value) {
    return;
  }

  applyingIntakeApprovalDraft.value = true;

  try {
    const response = await axios.post(`/api/genealogy/intake-runs/${encodeURIComponent(selectedIntakeRun.value.run_key)}/approval-draft-apply`, {
      packet_key: selectedIntakeRunPacket.value.packet_key || null,
      packet_label: selectedIntakeRunPacket.value.packet_label || null,
      approved_sections: intakeProposalApprovedSections.value,
      person_id: normalizePositiveInt(intakeProposalTargetPersonId.value),
      relationship_type: intakeProposalRelationshipType.value || null,
      related_person_id: normalizePositiveInt(intakeProposalRelatedPersonId.value),
    });

    selectedIntakeRunApprovalApplyResult.value = {
      ...(response.data.data?.apply_result || {}),
      summary: response.data.data?.apply_summary || null,
    };
    await selectIntakeRun(selectedIntakeRun.value.run_key, selectedIntakeRunPacketLabel.value || null);
    await loadIntakeRuns();
  } catch (error) {
    console.error('Failed to apply approval draft:', error);
    const fallback = error.response?.data?.data?.apply_result || {
      success: false,
      applied_person_changes: [],
      applied_relationships: [],
      failed: [],
      skipped: [],
      errors: [error.response?.data?.error?.message || 'Failed to apply approval draft'],
      audit: {},
    };
    selectedIntakeRunApprovalApplyResult.value = {
      ...fallback,
      summary: error.response?.data?.data?.apply_summary || null,
    };
    if (selectedIntakeRun.value?.run_key) {
      await selectIntakeRun(selectedIntakeRun.value.run_key, selectedIntakeRunPacketLabel.value || null);
      await loadIntakeRuns();
    }
  } finally {
    applyingIntakeApprovalDraft.value = false;
  }
};

const generateIntakeRunProposals = async () => {
  if (!selectedIntakeRun.value?.run_key || !selectedIntakeRunPacket.value) {
    return;
  }

  generatingIntakeRunProposals.value = true;

  try {
    const response = await axios.post(`/api/genealogy/intake-runs/${encodeURIComponent(selectedIntakeRun.value.run_key)}/proposal-generate`, {
      packet_key: selectedIntakeRunPacket.value.packet_key || null,
      packet_label: selectedIntakeRunPacket.value.packet_label || null,
      approved_sections: intakeProposalApprovedSections.value,
      person_id: normalizePositiveInt(intakeProposalTargetPersonId.value),
      relationship_type: intakeProposalRelationshipType.value || null,
      related_person_id: normalizePositiveInt(intakeProposalRelatedPersonId.value),
    });

    selectedIntakeRunProposalGenerationResult.value = {
      ...(response.data.data?.generation_result || {}),
      summary: response.data.data?.generation_summary || null,
    };
    await selectIntakeRun(selectedIntakeRun.value.run_key, selectedIntakeRunPacketLabel.value || null);
    await loadGeneratedProposals();
  } catch (error) {
    console.error('Failed to generate intake proposals:', error);
    const fallback = error.response?.data?.data?.generation_result || {
      success: false,
      persisted_person_changes: [],
      persisted_relationships: [],
      failed: [],
      skipped: [],
      errors: [error.response?.data?.error?.message || 'Failed to generate intake proposals'],
      audit: {},
    };
    selectedIntakeRunProposalGenerationResult.value = {
      ...fallback,
      summary: error.response?.data?.data?.generation_summary || null,
    };
  } finally {
    generatingIntakeRunProposals.value = false;
  }
};

const loadGeneratedProposals = async () => {
  if (!selectedIntakeRun.value?.run_key || !selectedIntakeRunPacket.value || !selectedIntakeRunPacketProposalGenerationState.value) {
    selectedIntakeRunGeneratedProposalReview.value = null;
    intakeGeneratedProposalsError.value = '';
    return;
  }

  loadingIntakeGeneratedProposals.value = true;
  intakeGeneratedProposalsError.value = '';

  try {
    const response = await axios.get(`/api/genealogy/intake-runs/${encodeURIComponent(selectedIntakeRun.value.run_key)}/generated-proposals`, {
      params: {
        packet_key: selectedIntakeRunPacket.value.packet_key || null,
        packet_label: selectedIntakeRunPacket.value.packet_label || null,
      },
    });

    selectedIntakeRunGeneratedProposalReview.value = response.data.data?.review || null;
  } catch (error) {
    console.error('Failed to load generated proposals:', error);
    selectedIntakeRunGeneratedProposalReview.value = null;
    intakeGeneratedProposalsError.value = error.response?.data?.error?.message || 'Failed to load generated proposals';
  } finally {
    loadingIntakeGeneratedProposals.value = false;
  }
};

const resolveGeneratedProposalActionState = (unifiedId) => {
  return actingOnGeneratedProposalId.value !== '' && actingOnGeneratedProposalId.value === unifiedId;
};

const reviewGeneratedProposal = async (unifiedId, action) => {
  if (!unifiedId || !['approve', 'reject'].includes(action)) {
    return;
  }

  if (isGeneratedRelationshipProposal(unifiedId)) {
    proposalRowErrors.value[unifiedId] = 'Relationship proposals are reviewed in Research Hub.';
    return;
  }

  actingOnGeneratedProposalId.value = unifiedId;
  delete proposalRowErrors.value[unifiedId];

  try {
    const path = `/api/reviews/${encodeURIComponent(unifiedId)}/${action}`;
    const payload = action === 'approve'
      ? { notes: 'Resolved from genealogy intake generated proposal panel' }
      : { reason: 'Rejected from genealogy intake generated proposal panel' };

    await axios.post(path, payload);
    await loadGeneratedProposals();
  } catch (error) {
    console.error(`Failed to ${action} generated proposal:`, error);
    proposalRowErrors.value[unifiedId] = error.response?.data?.error || `Failed to ${action}`;
    await loadGeneratedProposals();
  } finally {
    actingOnGeneratedProposalId.value = '';
  }
};

const approveAllPendingGeneratedProposals = async () => {
  const ids = generatedProposalPendingUnifiedIds.value;
  if (!ids.length) return;

  bulkApprovingPendingProposals.value = true;
  proposalRowErrors.value = {};
  intakeGeneratedProposalsError.value = '';

  try {
    await axios.post('/api/reviews/batch/approve', {
      ids,
      notes: 'Bulk approved from genealogy intake generated proposal panel',
    });
    await loadGeneratedProposals();
  } catch (error) {
    console.error('Failed to bulk approve generated proposals:', error);
    intakeGeneratedProposalsError.value = error.response?.data?.message || 'Bulk approve failed';
  } finally {
    bulkApprovingPendingProposals.value = false;
  }
};

const scheduleApprovalDraftPreview = () => {
  if (intakeApprovalDraftPreviewTimer) {
    clearTimeout(intakeApprovalDraftPreviewTimer);
  }

  if (!selectedIntakeRun.value?.run_key || !selectedIntakeRunPacket.value || !selectedIntakeRunProposalPreview.value || !selectedIntakeRunDraftInput.value) {
    selectedIntakeRunApprovalDraftPreview.value = null;
    intakeApprovalDraftPreviewError.value = '';
    return;
  }

  intakeApprovalDraftPreviewTimer = setTimeout(() => {
    loadApprovalDraftPreview();
  }, 250);
};

const openIntakeRunsModal = async () => {
  showIntakeRunsModal.value = true;
  intakeRunStageError.value = '';
  await loadIntakeRuns();
  if (intakeRuns.value.length > 0) {
    await selectIntakeRun(intakeRuns.value[0].run_key);
  } else {
    selectedIntakeRun.value = null;
    selectedIntakeRunPreview.value = null;
    selectedIntakeRunSelectedPacket.value = null;
    selectedIntakeRunWorkspace.value = null;
    selectedIntakeRunProposalDraft.value = null;
    selectedIntakeRunPacketLabel.value = '';
  }
};

watch(
  () => {
    const packet = selectedIntakeRunPacket.value;
    return `${packet?.packet_key || ''}|${packet?.packet_label || ''}`;
  },
  () => {
    const suggestedSections = Array.isArray(selectedIntakeRunProposalPreview.value?.proposal_outline?.suggested_sections)
      ? [...selectedIntakeRunProposalPreview.value.proposal_outline.suggested_sections]
      : [];

    intakeProposalApprovedSections.value = suggestedSections;
    intakeProposalTargetPersonId.value = '';
    intakeProposalRelationshipType.value = '';
    intakeProposalRelatedPersonId.value = '';
    selectedIntakeRunApprovalDraftPreview.value = null;
    selectedIntakeRunProposalGenerationResult.value = null;
    selectedIntakeRunGeneratedProposalReview.value = null;
    selectedIntakeRunApprovalApplyResult.value = null;
    intakeApprovalDraftPreviewError.value = '';
    intakeGeneratedProposalsError.value = '';

    scheduleApprovalDraftPreview();
    if (selectedIntakeRunPacketProposalGenerationState.value) {
      loadGeneratedProposals();
    }
  }
);

watch(
  [
    selectedIntakeRunPacket,
    selectedIntakeRunProposalPreview,
    selectedIntakeRunDraftInput,
    intakeProposalApprovedSections,
    intakeProposalTargetPersonId,
    intakeProposalRelationshipType,
    intakeProposalRelatedPersonId,
  ],
  () => {
    scheduleApprovalDraftPreview();
  },
  { deep: true }
);

// Windows SSH Import Methods removed 2026-01-10 - SSH access deprecated, use Nextcloud

// Utility function for copying to clipboard (retained for other features)
const copyToClipboard = async (text) => {
  try {
    await navigator.clipboard.writeText(text);
    // Could add a toast notification here
  } catch (error) {
    console.error('Failed to copy to clipboard:', error);
    // Fallback for older browsers
    const textArea = document.createElement('textarea');
    textArea.value = text;
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);
  }
};

// ========================================================================
// PHASE 4: EXPORT, BACKUP & DATA INTEGRITY
// ========================================================================

const exportGedcom = async () => {
  if (!selectedTreeId.value) return;

  exportingGedcom.value = true;
  gedcomExportData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/export/gedcom`);
    gedcomExportData.value = response.data.data;
  } catch (error) {
    console.error('Failed to export GEDCOM:', error);
    alert(error.response?.data?.error?.message || 'Failed to export GEDCOM');
  } finally {
    exportingGedcom.value = false;
  }
};

const downloadGedcom = () => {
  if (!gedcomExportData.value) return;

  const blob = new Blob([gedcomExportData.value.content], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = gedcomExportData.value.filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
};

// Person Activity Log
const loadActivityLog = async (personId) => {
  showPersonActivityLog.value = true;
  loadingPersonActivityLog.value = true;
  personActivityLog.value = [];

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/persons/${personId}/activity-log`);
    personActivityLog.value = response.data.data || [];
  } catch (error) {
    console.error('Failed to load activity log:', error);
  } finally {
    loadingPersonActivityLog.value = false;
  }
};

const formatActivityDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

// Name Variants
const toggleNameVariants = async (personId) => {
  if (showNameVariants.value && nameVariantsPersonId.value === personId) {
    showNameVariants.value = false;
    return;
  }
  showNameVariants.value = true;
  nameVariantsPersonId.value = personId;
  showAddNameVariant.value = false;
  loadingNameVariants.value = true;
  nameVariants.value = [];

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/persons/${personId}/name-variations`);
    nameVariants.value = response.data.data || [];
  } catch (error) {
    console.error('Failed to load name variants:', error);
  } finally {
    loadingNameVariants.value = false;
  }
};

const addNameVariant = async () => {
  if (!newVariantGiven.value && !newVariantSurname.value) return;

  try {
    await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/persons/${nameVariantsPersonId.value}/name-variations`, {
      given_name: newVariantGiven.value,
      surname: newVariantSurname.value,
      variant_type: newVariantType.value || null
    });
    newVariantGiven.value = '';
    newVariantSurname.value = '';
    newVariantType.value = '';
    showAddNameVariant.value = false;
    await toggleNameVariants(nameVariantsPersonId.value);
    showNameVariants.value = true; // Keep open after add
  } catch (error) {
    console.error('Failed to add name variant:', error);
    alert(error.response?.data?.error?.message || 'Failed to add name variant');
  }
};

const deleteNameVariant = async (variantId) => {
  try {
    await axios.delete(`/api/genealogy/trees/${selectedTreeId.value}/persons/${nameVariantsPersonId.value}/name-variations/${variantId}`);
    nameVariants.value = nameVariants.value.filter(v => v.id !== variantId);
  } catch (error) {
    console.error('Failed to delete name variant:', error);
  }
};

// Reports
const generateReport = async () => {
  if (!reportPersonId.value || !selectedTreeId.value) return;

  generatingReport.value = true;
  generatedReport.value = null;

  try {
    const typeMap = {
      ahnentafel: 'ahnentafel',
      descendant: 'descendants',
      pedigree: 'pedigree',
      family_group: 'family-group-sheet'
    };
    const endpoint = typeMap[selectedReportType.value];
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/reports/${endpoint}/${reportPersonId.value}`);
    generatedReport.value = response.data.data;
  } catch (error) {
    console.error('Failed to generate report:', error);
    alert(error.response?.data?.error?.message || 'Failed to generate report');
  } finally {
    generatingReport.value = false;
  }
};

const downloadReportPDF = async () => {
  if (!reportPersonId.value || !selectedTreeId.value) return;

  try {
    const typeMap = {
      ahnentafel: 'ahnentafel',
      descendant: 'descendants',
      pedigree: 'pedigree',
      family_group: 'family-group-sheet'
    };
    const endpoint = typeMap[selectedReportType.value];
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/reports/${endpoint}/${reportPersonId.value}/pdf`, {
      responseType: 'blob'
    });
    const url = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `${selectedReportType.value}_report.pdf`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  } catch (error) {
    console.error('Failed to download PDF:', error);
    alert('Failed to download PDF');
  }
};

const openExportModal = () => {
  gedcomExportData.value = null;
  showExportModal.value = true;
};

const validateTree = async () => {
  if (!selectedTreeId.value) return;

  validatingTree.value = true;
  validationResults.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/validate`);
    validationResults.value = response.data.data;
  } catch (error) {
    console.error('Failed to validate tree:', error);
    alert(error.response?.data?.error?.message || 'Failed to validate tree');
  } finally {
    validatingTree.value = false;
  }
};

const openValidationModal = () => {
  validationResults.value = null;
  showValidationModal.value = true;
};

const loadTreeStatistics = async () => {
  if (!selectedTreeId.value) return;

  loadingStatistics.value = true;
  treeStatistics.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/statistics`);
    treeStatistics.value = response.data.data;

    // Also load backup status
    loadBackupStatus();
  } catch (error) {
    console.error('Failed to load statistics:', error);
  } finally {
    loadingStatistics.value = false;
  }
};

const loadBackupStatus = async () => {
  if (!selectedTreeId.value) return;

  loadingBackupStatus.value = true;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/backup-status`);
    backupStatus.value = response.data.data;
  } catch (error) {
    console.error('Failed to load backup status:', error);
  } finally {
    loadingBackupStatus.value = false;
  }
};

const openStatisticsModal = () => {
  showStatisticsModal.value = true;
  loadTreeStatistics();
};

const formatBytes = (bytes) => {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let size = bytes;
  let unitIndex = 0;
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }
  return `${size.toFixed(2)} ${units[unitIndex]}`;
};

// Helper to truncate text with ellipsis
const truncateText = (text, maxLength) => {
  if (!text) return '';
  if (text.length <= maxLength) return text;
  return text.substring(0, maxLength) + '...';
};

// Helper to format citation quality rating
const formatQuality = (quality) => {
  if (!quality) return '';
  const qualityMap = {
    '0': 'Unreliable',
    '1': 'Questionable',
    '2': 'Secondary',
    '3': 'Primary',
    'unreliable': 'Unreliable',
    'questionable': 'Questionable',
    'secondary': 'Secondary',
    'primary': 'Primary'
  };
  return qualityMap[quality?.toString()?.toLowerCase()] || quality;
};

// Phase 5: Advanced Visualization & Analysis methods
const openRelationshipModal = () => {
  relationshipPerson1.value = null;
  relationshipPerson2.value = null;
  relationshipResult.value = null;
  showRelationshipModal.value = true;
};

const calculateRelationship = async () => {
  if (!relationshipPerson1.value || !relationshipPerson2.value || !selectedTreeId.value) return;

  calculatingRelationship.value = true;
  relationshipResult.value = null;

  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/relationship`, {
      person_id_1: relationshipPerson1.value,
      person_id_2: relationshipPerson2.value
    });
    relationshipResult.value = response.data.data;
  } catch (error) {
    console.error('Failed to calculate relationship:', error);
    relationshipResult.value = {
      message: 'Failed to calculate relationship. Please try again.'
    };
  } finally {
    calculatingRelationship.value = false;
  }
};

const openPlacesModal = async () => {
  if (!selectedTreeId.value) return;

  showPlacesModal.value = true;
  loadingPlaces.value = true;
  placesData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/places`);
    placesData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load places data:', error);
  } finally {
    loadingPlaces.value = false;
  }
};

const loadPersonTimeline = async (personId) => {
  if (!personId) return;

  showTimelineModal.value = true;
  loadingTimeline.value = true;
  timelineData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/persons/${personId}/timeline`);
    timelineData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load timeline:', error);
  } finally {
    loadingTimeline.value = false;
  }
};

// Timeline Tab methods
const loadTimelineForTab = async () => {
  if (!timelineTabPersonId.value) {
    timelineTabData.value = null;
    return;
  }

  loadingTimelineTab.value = true;
  timelineTabData.value = null;

  try {
    const params = new URLSearchParams();
    params.append('include_family', timelineIncludeFamily.value ? '1' : '0');
    params.append('include_parents', timelineIncludeParents.value ? '1' : '0');
    params.append('include_siblings', timelineIncludeSiblings.value ? '1' : '0');
    if (timelineStartYear.value) params.append('start_year', timelineStartYear.value);
    if (timelineEndYear.value) params.append('end_year', timelineEndYear.value);
    if (timelineEventTypes.value.length > 0) {
      timelineEventTypes.value.forEach(t => params.append('event_types[]', t));
    }

    const response = await axios.get(`/api/genealogy/timeline/${timelineTabPersonId.value}?${params.toString()}`);
    if (response.data.success) {
      timelineTabData.value = response.data;
      timelineEventConfig.value = response.data.event_config || {};
    }
  } catch (error) {
    console.error('Failed to load timeline:', error);
  } finally {
    loadingTimelineTab.value = false;
  }
};

const getTimelineEventColor = (eventType) => {
  const config = timelineEventConfig.value[eventType] || timelineEventConfig.value['other'] || {};
  return config.color || '#757575';
};

// Place Authority methods
const searchPlacesForEvent = async () => {
  const query = editingEvent.value?.event_place;
  if (!query || query.length < 2) {
    eventPlaceSearchResults.value = [];
    return;
  }

  // Debounce
  if (placeSearchDebounceTimer) clearTimeout(placeSearchDebounceTimer);
  placeSearchDebounceTimer = setTimeout(async () => {
    try {
      const response = await axios.get('/api/genealogy/places/search', {
        params: { q: query, limit: 10 }
      });
      eventPlaceSearchResults.value = response.data.data || [];
    } catch (error) {
      console.error('Place search failed:', error);
      eventPlaceSearchResults.value = [];
    }
  }, 300);
};

const selectPlaceForEvent = (place) => {
  if (editingEvent.value) {
    editingEvent.value.event_place = place.name;
    editingEvent.value.place_id = place.id;
    selectedEventPlaceHierarchy.value = place.hierarchy_path || place.name;
  }
  showEventPlaceResults.value = false;
  eventPlaceSearchResults.value = [];
};

const searchPlacesForFamilyEvent = async () => {
  const query = editingFamilyEvent.value?.event_place;
  if (!query || query.length < 2) {
    familyEventPlaceSearchResults.value = [];
    return;
  }

  // Debounce
  if (placeSearchDebounceTimer) clearTimeout(placeSearchDebounceTimer);
  placeSearchDebounceTimer = setTimeout(async () => {
    try {
      const response = await axios.get('/api/genealogy/places/search', {
        params: { q: query, limit: 10 }
      });
      familyEventPlaceSearchResults.value = response.data.data || [];
    } catch (error) {
      console.error('Place search failed:', error);
      familyEventPlaceSearchResults.value = [];
    }
  }, 300);
};

const selectPlaceForFamilyEvent = (place) => {
  if (editingFamilyEvent.value) {
    editingFamilyEvent.value.event_place = place.name;
    editingFamilyEvent.value.place_id = place.id;
    selectedFamilyEventPlaceHierarchy.value = place.hierarchy_path || place.name;
  }
  showFamilyEventPlaceResults.value = false;
  familyEventPlaceSearchResults.value = [];
};

const getPlaceHierarchy = async (placeId) => {
  if (!placeId) return null;
  try {
    const response = await axios.get(`/api/genealogy/places/${placeId}`);
    return response.data.data?.full_path || null;
  } catch (error) {
    console.error('Failed to get place hierarchy:', error);
    return null;
  }
};

const normalizePlaceNames = async () => {
  normalizingPlaces.value = true;
  try {
    const response = await axios.post('/api/genealogy/places/normalize', {
      limit: 500
    });
    if (response.data.success) {
      placeNormalizationStats.value = response.data.data;
      if (response.data.data.linked > 0) {
        alert(`Normalized ${response.data.data.linked} places out of ${response.data.data.processed} processed.`);
      } else if (response.data.data.processed === 0) {
        alert('All places are already normalized!');
      } else {
        alert(`Processed ${response.data.data.processed} events, ${response.data.data.failed} could not be normalized.`);
      }
    }
  } catch (error) {
    console.error('Failed to normalize places:', error);
    alert('Failed to normalize places. See console for details.');
  } finally {
    normalizingPlaces.value = false;
  }
};

// Phase 6: Reports & Printing methods
const loadPedigreeChart = async (personId) => {
  if (!personId) return;

  showPedigreeModal.value = true;
  loadingReport.value = true;
  pedigreeData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/persons/${personId}/pedigree?generations=${reportGenerations.value}`);
    pedigreeData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load pedigree chart:', error);
  } finally {
    loadingReport.value = false;
  }
};

const loadAhnentafelReport = async (personId) => {
  if (!personId) return;

  showAhnentafelModal.value = true;
  loadingReport.value = true;
  ahnentafelData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/persons/${personId}/ahnentafel?generations=${ahnentafelGenerations.value}`);
    ahnentafelData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load ahnentafel report:', error);
  } finally {
    loadingReport.value = false;
  }
};

const loadDescendantReport = async (personId) => {
  if (!personId) return;

  showDescendantModal.value = true;
  loadingReport.value = true;
  descendantData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/persons/${personId}/descendants?generations=10`);
    descendantData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load descendant report:', error);
  } finally {
    loadingReport.value = false;
  }
};

const openMissingDataModal = async () => {
  if (!selectedTreeId.value) return;

  showMissingDataModal.value = true;
  loadingReport.value = true;
  missingDataReport.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/missing-data`);
    missingDataReport.value = response.data.data;
  } catch (error) {
    console.error('Failed to load missing data report:', error);
  } finally {
    loadingReport.value = false;
  }
};

const loadIndividualSummary = async (personId) => {
  if (!personId) return;

  showIndividualSummaryModal.value = true;
  loadingReport.value = true;
  individualSummaryData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/persons/${personId}/summary`);
    individualSummaryData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load individual summary:', error);
  } finally {
    loadingReport.value = false;
  }
};

const loadFamilyGroupSheet = async (familyId) => {
  if (!familyId) return;

  showFamilyGroupSheetModal.value = true;
  loadingReport.value = true;
  familyGroupSheetData.value = null;

  try {
    const response = await axios.get(`/api/genealogy/families/${familyId}/group-sheet`);
    familyGroupSheetData.value = response.data.data;
  } catch (error) {
    console.error('Failed to load family group sheet:', error);
  } finally {
    loadingReport.value = false;
  }
};

const formatFileSize = (bytes) => {
  if (!bytes) return '';
  const units = ['B', 'KB', 'MB', 'GB'];
  let size = bytes;
  let unitIndex = 0;
  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }
  return `${size.toFixed(1)} ${units[unitIndex]}`;
};

const isImage = (format) => {
  if (!format) return false;
  return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'].includes(format.toLowerCase());
};

// Media type helpers for edit modal
const getFileExtension = (media) => {
  const filename = media.filename || media.local_filename || media.nextcloud_path || '';
  const ext = filename.split('.').pop();
  return ext ? ext.toUpperCase() : 'FILE';
};

// Get URL for media - generates from nextcloud_path if no direct URL
const getMediaUrl = (media) => {
  if (media.nextcloud_url) return media.nextcloud_url;
  if (media.url) return media.url;
  if (media.nextcloud_path) return `/api/media/file?path=${encodeURIComponent(media.nextcloud_path)}`;
  return null;
};

const isMediaImage = (media) => {
  const filename = media.filename || media.local_filename || media.nextcloud_path || '';
  const ext = filename.split('.').pop()?.toLowerCase() || '';
  return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'].includes(ext);
};

const isMediaPdf = (media) => {
  const filename = media.filename || media.local_filename || media.nextcloud_path || '';
  const ext = filename.split('.').pop()?.toLowerCase() || '';
  return ext === 'pdf';
};

const isMediaHtml = (media) => {
  const filename = media.filename || media.local_filename || media.nextcloud_path || '';
  const ext = filename.split('.').pop()?.toLowerCase() || '';
  return ['htm', 'html'].includes(ext);
};

// Open media in popup modal - handles images, PDFs, HTML
const openMediaInEditModal = (media) => {
  const url = media.nextcloud_url || media.url || (media.nextcloud_path ? `/api/media/file?path=${encodeURIComponent(media.nextcloud_path)}` : null);
  if (!url) return;

  if (isMediaImage(media)) {
    // Use existing image modal
    enlargedImage.value = {
      src: url,
      title: media.title || media.filename || 'Image',
      nextcloud_path: media.nextcloud_path,
      type: 'image'
    };
    showImageModal.value = true;
  } else {
    // For PDFs, HTML, and other files - open in new tab or use iframe modal
    enlargedImage.value = {
      src: url,
      title: media.title || media.filename || 'Document',
      nextcloud_path: media.nextcloud_path,
      type: isMediaPdf(media) ? 'pdf' : isMediaHtml(media) ? 'html' : 'document'
    };
    showImageModal.value = true;
  }
};

const getDocumentPreviewType = (path) => {
  const normalized = `${path || ''}`.trim().toLowerCase();
  if (!normalized || normalized.startsWith('no ')) {
    return null;
  }

  const ext = normalized.split('.').pop() || '';
  if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'].includes(ext)) {
    return 'image';
  }
  if (ext === 'pdf') {
    return 'pdf';
  }
  if (['htm', 'html'].includes(ext)) {
    return 'html';
  }

  return 'document';
};

const openIntakeRunDocumentPreview = (document) => {
  const previewPath = `${document?.previewPath || ''}`.trim();
  if (!previewPath) return;

  enlargedImage.value = {
    src: `/api/media/file?path=${encodeURIComponent(previewPath)}`,
    title: document?.sourceName || 'Document Preview',
    nextcloud_path: previewPath,
    type: document?.previewType || getDocumentPreviewType(previewPath) || 'document',
  };
  showImageModal.value = true;
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const formatDateTime = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  return date.toLocaleString();
};

// Tree View Methods
const loadAllPersons = async () => {
  if (!selectedTreeId.value) return;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/persons`, {
      params: { limit: 5000 }
    });
    allPersons.value = response.data.data.persons || [];
  } catch (error) {
    console.error('Failed to load persons for tree:', error);
  }
};

const loadTreeData = async () => {
  if (!selectedTreeId.value) return;

  loading.value = true;
  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}`);
    selectedTree.value = response.data.data.tree;
    stats.value = response.data.data.statistics;
    mediaStatus.value = response.data.data.media_status;

    // Load home person: saved localStorage → tree root_person_id → null
    const savedHomePerson = localStorage.getItem(`genealogy_home_person_${selectedTreeId.value}`);
    let resolvedHomePersonId = null;
    const currentTree = response.data.data.tree || trees.value.find(t => t.id === selectedTreeId.value);

    if (savedHomePerson) {
      const savedId = parseInt(savedHomePerson);
      if (savedId) {
        resolvedHomePersonId = savedId;
      }
    }

    // Fall back to the tree root person when one is set.
    if (!resolvedHomePersonId) {
      if (currentTree?.root_person_id) {
        resolvedHomePersonId = currentTree.root_person_id;
      }
    }

    void Promise.allSettled([
      axios.get(`/api/genealogy/trees/${selectedTreeId.value}/surnames`).then((surnamesResponse) => {
        surnames.value = surnamesResponse.data.data.surnames;
      }),
      axios.get(`/api/genealogy/trees/${selectedTreeId.value}/recent`).then((recentResponse) => {
        recentAdditions.value = recentResponse.data.data.recent;
      }),
      axios.get(`/api/genealogy/trees/${selectedTreeId.value}/media?limit=24`).then((mediaResponse) => {
        media.value = mediaResponse.data.data.media;
      }),
      loadAllPersons(),
      loadAllFamilies(),
      loadSources(),
      loadRepositories(),
      loadMissingDataTypes(),
      loadMediaStatus(),
      loadUnconfirmedFaceCount(),
    ]);

    if (resolvedHomePersonId) {
      homePersonId.value = resolvedHomePersonId;
      // Default report person to root person (human can change it per-report)
      if (!reportPersonId.value) {
        reportPersonId.value = resolvedHomePersonId;
      }
      await nextTick();
      await loadTreeChartData();
    }
  } catch (error) {
    console.error('Failed to load tree data:', error);
  } finally {
    loading.value = false;
  }
};

const loadTreeChartData = async () => {
  if (!selectedTreeId.value || !homePersonId.value) return;

  // N147: Use focus person if set, otherwise home person
  const centeredPersonId = focusPersonId.value || homePersonId.value;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/tree-data`, {
      params: {
        person_id: centeredPersonId,
        mode: treeViewMode.value,
        generations: treeGenerations.value
      }
    });
    treeData.value = response.data.data;
    await nextTick();
    renderTree();
  } catch (error) {
    console.error('Failed to load tree chart data:', error);
  }
};

const onHomePersonChange = () => {
  if (homePersonId.value && selectedTreeId.value) {
    localStorage.setItem(`genealogy_home_person_${selectedTreeId.value}`, homePersonId.value);
    focusPersonId.value = null; // Reset focus when home changes
    collapsedFamilies.value.clear();
    collapsedIndis.value.clear();
    collapsedSpouses.value.clear();
    loadTreeChartData();
  }
};

// N147: Click person to temporarily re-center tree (does NOT change home person)
const focusPerson = (personId) => {
  if (!personId || personId === focusPersonId.value) return;
  focusPersonId.value = personId;
  // Reload data centered on focus person, then re-render
  loadTreeChartData();
};

// N147: Return to home person after temporary focus
const returnHome = () => {
  if (!isFocusedAway.value) return;
  focusPersonId.value = null;
  collapsedFamilies.value.clear();
  collapsedIndis.value.clear();
  collapsedSpouses.value.clear();
  loadTreeChartData();
};

const onGenerationsChange = () => {
  // Clamp value to valid range
  if (treeGenerations.value < 1) treeGenerations.value = 1;
  if (treeGenerations.value > 20) treeGenerations.value = 20;
  treeGenerations.value = Math.floor(treeGenerations.value); // Ensure integer

  if (homePersonId.value && selectedTreeId.value) {
    loadTreeChartData();
  }
};

const setHomePerson = (personId) => {
  homePersonId.value = personId;
  hoveredPerson.value = null;
  onHomePersonChange();
};

// Topola DataProvider implementation
// All IDs must be strings for Topola compatibility
// NOTE: Must return stub objects for missing persons/families to prevent Topola errors
const createDataProvider = () => {
  const persons = treeData.value.persons || {};
  const families = treeData.value.families || {};

  return {
    getIndi: (id) => {
      const person = persons[String(id)];
      // Return null for missing persons - Topola handles this gracefully
      if (!person) {
        return null;
      }
      // Parse date string to mm/dd/yyyy format
      // GEDCOM month abbreviations
      const gedcomMonths = {
        'JAN': 1, 'FEB': 2, 'MAR': 3, 'APR': 4, 'MAY': 5, 'JUN': 6,
        'JUL': 7, 'AUG': 8, 'SEP': 9, 'OCT': 10, 'NOV': 11, 'DEC': 12
      };

      const formatDateFull = (dateStr) => {
        if (!dateStr) return null;
        // Try to parse various date formats
        let year, month, day;

        // Try YYYY-MM-DD format first (ISO)
        const isoMatch = dateStr.match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (isoMatch) {
          year = parseInt(isoMatch[1]);
          month = parseInt(isoMatch[2]);
          day = parseInt(isoMatch[3]);
        } else {
          // Try GEDCOM format: "DD MMM YYYY" or "MMM YYYY" (e.g., "1 APR 1775", "SEP 1980")
          const gedcomFullMatch = dateStr.match(/(\d{1,2})\s+(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\s+(\d{4})/i);
          if (gedcomFullMatch) {
            day = parseInt(gedcomFullMatch[1]);
            month = gedcomMonths[gedcomFullMatch[2].toUpperCase()];
            year = parseInt(gedcomFullMatch[3]);
          } else {
            // Try GEDCOM month-year only: "MMM YYYY" (e.g., "SEP 1980")
            const gedcomMonthYearMatch = dateStr.match(/(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\s+(\d{4})/i);
            if (gedcomMonthYearMatch) {
              month = gedcomMonths[gedcomMonthYearMatch[1].toUpperCase()];
              year = parseInt(gedcomMonthYearMatch[2]);
            } else {
              // Try MM/DD/YYYY
              const usMatch = dateStr.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
              if (usMatch) {
                month = parseInt(usMatch[1]);
                day = parseInt(usMatch[2]);
                year = parseInt(usMatch[3]);
              } else {
                // Just extract year as fallback
                const yearMatch = dateStr.match(/(\d{4})/);
                if (yearMatch) {
                  year = parseInt(yearMatch[1]);
                }
              }
            }
          }
        }

        if (year && month && day) {
          return { date: { year, month, day } };
        } else if (year && month) {
          return { date: { year, month } };
        } else if (year) {
          return { date: { year } };
        }
        return null;
      };

      // Format date for display in GEDCOM standard format (e.g., "1 APR 1775")
      const monthNames = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
      const formatDateDisplay = (dateStr) => {
        if (!dateStr) return null;
        const parsed = formatDateFull(dateStr);
        if (!parsed || !parsed.date) return null;
        const d = parsed.date;
        if (d.year && d.month && d.day) {
          return `${d.day} ${monthNames[d.month - 1]} ${d.year}`;
        } else if (d.year && d.month) {
          return `${monthNames[d.month - 1]} ${d.year}`;
        } else if (d.year) {
          return String(d.year);
        }
        return null;
      };

      return {
        // Core Indi interface
        getId: () => String(id),
        getFamiliesAsSpouse: () => (person.families_as_spouse || []).map(fid => String(fid)),
        getFamilyAsChild: () => person.family_as_child ? String(person.family_as_child) : null,
        // IndiDetails interface (required by DetailedRenderer)
        // Full names - WiderDetailedRenderer handles dynamic width calculation
        getFirstName: () => person.given_name || '',
        getLastName: () => person.surname || '',
        getMaidenName: () => null,
        getNumberOfChildren: () => null,
        getNumberOfMarriages: () => (person.families_as_spouse || []).length || null,
        getBirthDate: () => {
          // Return formatted string that will display as "DOB: mm/dd/yyyy"
          const dateDisplay = formatDateDisplay(person.birth_date);
          if (dateDisplay) {
            return { date: { text: `DOB: ${dateDisplay}` } };
          }
          return null;
        },
        getBirthPlace: () => null, // Hide place - shown in detail panel
        getDeathDate: () => {
          // Return formatted string that will display as "DOD: mm/dd/yyyy"
          const dateDisplay = formatDateDisplay(person.death_date);
          if (dateDisplay) {
            return { date: { text: `DOD: ${dateDisplay}` } };
          }
          return null;
        },
        getDeathPlace: () => {
          // Show "Living" if no death date
          if (!person.death_date && person.living !== false) {
            return 'Living';
          }
          return null;
        },
        isConfirmedDeath: () => !!person.death_date,
        getSex: () => person.sex || 'U',
        getImageUrl: () => person.photo || null,
        getImages: () => person.photo ? [{ url: person.photo }] : null,
        getNotes: () => null,
        getEvents: () => null,
        showId: () => false,
        showSex: () => true,
      };
    },
    getFam: (id) => {
      const family = families[String(id)];
      if (!family) return null;
      // Only return children that exist in our persons data
      const existingChildren = (family.children || [])
        .filter(cid => persons[String(cid)])
        .map(cid => String(cid));
      return {
        getId: () => String(id),
        getFather: () => family.husband_id && persons[String(family.husband_id)] ? String(family.husband_id) : null,
        getMother: () => family.wife_id && persons[String(family.wife_id)] ? String(family.wife_id) : null,
        getChildren: () => existingChildren,
        getMarriageDate: () => null, // Hide marriage date - cleaner design without union boxes
        getMarriagePlace: () => null, // Hide place - cleaner design
      };
    },
  };
};

const renderTree = async () => {
  if (!homePersonId.value) return;

  // Wait for SVG ref to be available (may take a few ticks after v-if renders)
  let retries = 0;
  while (!treeSvg.value && retries < 10) {
    await nextTick();
    retries++;
  }

  if (!treeSvg.value) {
    console.error('renderTree: SVG ref not available after retries');
    return;
  }

  treeLoaded.value = false;

  // Clear existing content
  d3.select(treeSvg.value).selectAll('*').remove();

  const dataProvider = createDataProvider();

  // N147: Use focus person if set, otherwise home person
  const centeredPersonId = focusPersonId.value || homePersonId.value;
  const centeredPerson = dataProvider.getIndi(String(centeredPersonId));
  if (!centeredPerson) {
    console.error('Centered person not found in tree data');
    treeLoaded.value = true;
    return;
  }

  try {
    // Create renderer with callbacks
    const rendererOptions = {
      data: dataProvider,
      horizontal: false,
      // N147: Left-click re-centers tree on clicked person (temporary focus)
      indiCallback: (info) => {
        const personData = treeData.value.persons[info.id];
        if (personData) {
          // Show detail panel AND re-center tree on this person
          selectPerson(personData);
          focusPerson(Number(info.id));
        }
      },
      famCallback: (info) => {
        // Family click — find husband or wife and focus on them
        const famData = treeData.value.families[info.id];
        if (famData) {
          const targetId = famData.husband_id || famData.wife_id;
          if (targetId) focusPerson(targetId);
        }
      },
    };

    let renderer;
    if (rendererMode.value === 'simple') {
      renderer = new SimpleRenderer(rendererOptions);
    } else if (rendererMode.value === 'circle') {
      renderer = new CircleRenderer(rendererOptions);
    } else {
      renderer = new WiderDetailedRenderer(rendererOptions);
    }

    // N147: Expander callback — toggle collapsed branches
    const expanderCallback = (id, direction) => {
      let targetSet;
      if (direction === ExpanderDirection.FAMILY) {
        targetSet = collapsedFamilies.value;
      } else if (direction === ExpanderDirection.SPOUSE) {
        targetSet = collapsedSpouses.value;
      } else {
        targetSet = collapsedIndis.value;
      }

      if (targetSet.has(id)) {
        targetSet.delete(id);
      } else {
        targetSet.add(id);
      }
      // Re-render with updated collapsed state (no data reload needed)
      renderTree();
    };

    // Create chart based on mode
    const chartOptions = {
      data: dataProvider,
      renderer: renderer,
      svgSelector: '#tree-svg',
      startIndi: String(centeredPersonId),
      horizontal: false,
      animate: true,
      // N147: Enable branch expanders (+/- controls on leaf nodes)
      expanders: true,
      expanderCallback: expanderCallback,
      collapsedFamily: collapsedFamilies.value,
      collapsedIndi: collapsedIndis.value,
      collapsedSpouse: collapsedSpouses.value,
    };

    // Select chart type based on view mode
    let chart;
    if (treeViewMode.value === 'ancestors') {
      chart = new AncestorChart(chartOptions);
    } else if (treeViewMode.value === 'descendants') {
      chart = new DescendantChart(chartOptions);
    } else if (treeViewMode.value === 'kinship') {
      // N147: KinshipChart — shows siblings, spouse parents, spouse siblings
      chart = new KinshipChart(chartOptions);
    } else if (treeViewMode.value === 'relatives') {
      // N147: RelativesChart — descendants of ancestors (aunts/uncles/cousins)
      chart = new RelativesChart(chartOptions);
    } else if (treeViewMode.value === 'fancy') {
      // FancyChart — decorative descendant tree with curved SVG branches
      chart = new FancyChart(chartOptions);
    } else {
      // Hourglass — needs startFam. Find the person's spouse family or child-of family.
      const personObj = centeredPerson;
      const spouseFams = personObj.getFamiliesAsSpouse();
      const childFam = personObj.getFamilyAsChild();
      const startFam = spouseFams.length > 0 ? spouseFams[0] : childFam;

      if (startFam) {
        chartOptions.startFam = String(startFam);
        delete chartOptions.startIndi;
        chart = new HourglassChart(chartOptions);
      } else {
        // Fallback to ancestors if no family found
        chart = new AncestorChart(chartOptions);
      }
    }
    chartInstance.value = chart;

    // Render the chart
    const chartInfo = chart.render();

    // Add spouse connector lines and setup event handlers
    // Wait for animation to complete
    setTimeout(() => {
      addSpouseConnectors();
      setupPersonCardEvents();
    }, 800);

    // Setup zoom/pan
    const svg = d3.select(treeSvg.value);
    const zoom = d3.zoom()
      .scaleExtent([0.1, 4])
      .on('zoom', (event) => {
        svg.select('g').attr('transform', event.transform);
        zoomScale.value = event.transform.k;
      });

    svg.call(zoom);
    currentZoom.value = zoom;

    // Center the chart
    const svgNode = treeSvg.value;
    const bbox = svgNode.getBBox();
    const containerWidth = svgNode.clientWidth;
    const containerHeight = svgNode.clientHeight;

    const scale = Math.min(
      containerWidth / (bbox.width + 100),
      containerHeight / (bbox.height + 100),
      1
    );

    const translateX = (containerWidth - bbox.width * scale) / 2 - bbox.x * scale;
    const translateY = (containerHeight - bbox.height * scale) / 2 - bbox.y * scale;

    svg.call(zoom.transform, d3.zoomIdentity.translate(translateX, translateY).scale(scale));
    zoomScale.value = scale;

    treeLoaded.value = true;
  } catch (error) {
    console.error('Failed to render tree:', error);
    treeLoaded.value = true;
  }
};

// Add horizontal connector lines between spouse cards
const addSpouseConnectors = () => {
  if (!treeSvg.value) return;

  const svg = d3.select(treeSvg.value);

  // Find all node groups that have two indi elements (spouse pairs)
  svg.selectAll('g.node').each(function() {
    const node = d3.select(this);
    const indiGroups = node.selectAll('g.detailed g.indi');

    if (indiGroups.size() === 2) {
      // Get the two indi cards
      const indis = indiGroups.nodes();
      const indi1 = d3.select(indis[0]);
      const indi2 = d3.select(indis[1]);

      // Get transforms to find positions
      const transform1 = indi1.attr('transform');
      const transform2 = indi2.attr('transform');

      // Parse transforms (format: "translate(x, y)")
      const match1 = transform1 && transform1.match(/translate\(([^,]+),\s*([^)]+)\)/);
      const match2 = transform2 && transform2.match(/translate\(([^,]+),\s*([^)]+)\)/);

      if (match1 && match2) {
        const x1 = parseFloat(match1[1]);
        const x2 = parseFloat(match2[1]);

        // Get card dimensions from the rect
        const rect1 = indi1.select('rect.border');
        const rect2 = indi2.select('rect.border');
        const width1 = parseFloat(rect1.attr('width')) || 200;
        const height1 = parseFloat(rect1.attr('height')) || 90;

        // Draw connector line from right edge of first card to left edge of second
        const lineY = height1 / 2;
        const lineX1 = x1 + width1;
        const lineX2 = x2;

        // Only draw if there's a gap
        if (lineX2 > lineX1) {
          node.select('g.detailed')
            .append('line')
            .attr('class', 'spouse-connector')
            .attr('x1', lineX1)
            .attr('y1', lineY)
            .attr('x2', lineX2)
            .attr('y2', lineY)
            .attr('stroke', '#555')
            .attr('stroke-width', 3);
        }
      }
    }
  });
};

// Setup right-click context menu on person cards using event delegation
const setupPersonCardEvents = () => {
  if (!treeSvg.value) return;

  treeSvg.value.addEventListener('contextmenu', (event) => {
    // Find the closest g.indi ancestor of the clicked element
    let target = event.target;
    let indiGroup = null;

    while (target && target !== treeSvg.value) {
      if (target.classList && target.classList.contains('indi')) {
        indiGroup = target;
        break;
      }
      target = target.parentElement;
    }

    if (!indiGroup) return;

    // Get person ID from D3 data - Topola stores it as nodeData.indi.id
    const d3Selection = d3.select(indiGroup);
    const nodeData = d3Selection.datum();
    const personId = nodeData?.indi?.id || nodeData?.id;

    if (!personId) return;

    const personData = treeData.value.persons[personId];
    if (!personData) return;

    event.preventDefault();
    event.stopPropagation();

    contextMenu.value = {
      visible: true,
      x: event.clientX,
      y: event.clientY,
      personId: personId
    };
  });
};

// Hide context menu when clicking elsewhere
const hideContextMenu = () => {
  contextMenu.value.visible = false;
};

// N147: Get focused person's name for the overlay label
const getFocusPersonName = () => {
  if (!focusPersonId.value || !treeData.value?.persons) return '';
  const p = treeData.value.persons[String(focusPersonId.value)];
  if (!p) return `Person #${focusPersonId.value}`;
  return `${p.given_name || ''} ${p.surname || ''}`.trim();
};

// Set home person from context menu
const setHomePersonFromContextMenu = () => {
  if (contextMenu.value.personId) {
    setHomePerson(contextMenu.value.personId);
  }
  hideContextMenu();
};

// View person details from context menu
const viewPersonFromContextMenu = () => {
  if (contextMenu.value.personId) {
    const personData = treeData.value.persons[contextMenu.value.personId];
    if (personData) {
      selectPerson(personData);
    }
  }
  hideContextMenu();
};

const zoomIn = () => {
  if (!treeSvg.value || !currentZoom.value) return;
  const svg = d3.select(treeSvg.value);
  svg.transition().duration(300).call(currentZoom.value.scaleBy, 1.3);
};

const zoomOut = () => {
  if (!treeSvg.value || !currentZoom.value) return;
  const svg = d3.select(treeSvg.value);
  svg.transition().duration(300).call(currentZoom.value.scaleBy, 0.7);
};

const resetZoom = () => {
  if (!treeSvg.value || !currentZoom.value) return;
  const svg = d3.select(treeSvg.value);
  svg.transition().duration(500).call(currentZoom.value.transform, d3.zoomIdentity);
  zoomScale.value = 1;
  // Re-center
  renderTree();
};

const toggleFullscreen = () => {
  isFullscreen.value = !isFullscreen.value;
  if (isFullscreen.value) {
    document.addEventListener('keydown', handleEscKey);
  } else {
    document.removeEventListener('keydown', handleEscKey);
  }
  // Re-render after DOM update for proper sizing
  nextTick(() => {
    if (homePersonId.value) renderTree();
  });
};

const handleEscKey = (e) => {
  if (e.key === 'Escape' && isFullscreen.value) {
    toggleFullscreen();
  }
};

const getInitials = (person) => {
  const first = person?.given_name?.[0] || '';
  const last = person?.surname?.[0] || '';
  return (first + last).toUpperCase();
};

// ========================================================================
// Phase 7: Privacy & Collaboration Methods
// ========================================================================

const openPrivacySettingsModal = async () => {
  if (!selectedTreeId.value) return;
  showPrivacySettingsModal.value = true;
  savingPrivacy.value = false;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/privacy`);
    if (response.data.success) {
      privacySettings.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to load privacy settings:', error);
  }
};

const savePrivacySettings = async () => {
  if (!selectedTreeId.value) return;
  savingPrivacy.value = true;

  try {
    await axios.put(`/api/genealogy/trees/${selectedTreeId.value}/privacy`, privacySettings.value);
    showPrivacySettingsModal.value = false;
  } catch (error) {
    console.error('Failed to save privacy settings:', error);
    alert('Failed to save privacy settings: ' + (error.response?.data?.message || error.message));
  } finally {
    savingPrivacy.value = false;
  }
};

const autoDetectLiving = async () => {
  if (!selectedTreeId.value) return;
  detectingLiving.value = true;

  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/auto-detect-living`);
    if (response.data.success) {
      const result = response.data.data;
      alert(`Auto-detection complete!\n\nMarked as living: ${result.marked_living}\nMarked as deceased: ${result.marked_deceased}\nUnable to determine: ${result.unable_to_determine}`);
    }
  } catch (error) {
    console.error('Failed to auto-detect living persons:', error);
    alert('Failed to auto-detect living persons: ' + (error.response?.data?.message || error.message));
  } finally {
    detectingLiving.value = false;
  }
};

const openCollaboratorsModal = async () => {
  if (!selectedTreeId.value) return;
  showCollaboratorsModal.value = true;
  loadingCollaborators.value = true;
  newInviteEmail.value = '';
  newInviteRole.value = 'viewer';

  try {
    const [collabResponse, inviteResponse] = await Promise.all([
      axios.get(`/api/genealogy/trees/${selectedTreeId.value}/collaborators`),
      axios.get(`/api/genealogy/trees/${selectedTreeId.value}/invitations`)
    ]);

    collaborators.value = collabResponse.data.data || [];
    pendingInvitations.value = inviteResponse.data.data || [];
  } catch (error) {
    console.error('Failed to load collaborators:', error);
  } finally {
    loadingCollaborators.value = false;
  }
};

const updateCollaboratorRole = async (collaborator, newRole) => {
  try {
    await axios.put(`/api/genealogy/collaborators/${collaborator.id}`, { role: newRole });
    collaborator.role = newRole;
  } catch (error) {
    console.error('Failed to update collaborator:', error);
    alert('Failed to update collaborator: ' + (error.response?.data?.message || error.message));
  }
};

const removeCollaboratorConfirm = async (collaborator) => {
  if (!confirm(`Remove ${collaborator.user_name || collaborator.user_email} from this tree?`)) return;

  try {
    await axios.delete(`/api/genealogy/collaborators/${collaborator.id}`);
    collaborators.value = collaborators.value.filter(c => c.id !== collaborator.id);
  } catch (error) {
    console.error('Failed to remove collaborator:', error);
    alert('Failed to remove collaborator: ' + (error.response?.data?.message || error.message));
  }
};

const sendInvitation = async () => {
  if (!selectedTreeId.value || !newInviteEmail.value) return;
  sendingInvite.value = true;

  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/invitations`, {
      email: newInviteEmail.value,
      role: newInviteRole.value
    });

    if (response.data.success) {
      pendingInvitations.value.push(response.data.data);
      newInviteEmail.value = '';
      newInviteRole.value = 'viewer';
    }
  } catch (error) {
    console.error('Failed to send invitation:', error);
    alert('Failed to send invitation: ' + (error.response?.data?.message || error.message));
  } finally {
    sendingInvite.value = false;
  }
};

const cancelInvitationConfirm = async (invitation) => {
  if (!confirm(`Cancel invitation for ${invitation.email}?`)) return;

  try {
    await axios.delete(`/api/genealogy/invitations/${invitation.id}`);
    pendingInvitations.value = pendingInvitations.value.filter(i => i.id !== invitation.id);
  } catch (error) {
    console.error('Failed to cancel invitation:', error);
    alert('Failed to cancel invitation: ' + (error.response?.data?.message || error.message));
  }
};

const openActivityLogModal = async () => {
  if (!selectedTreeId.value) return;
  showPersonActivityLogModal.value = true;
  loadingActivityLog.value = true;
  activityLog.value = [];
  activityLogOffset.value = 0;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/activity-log`, {
      params: { limit: 50, offset: 0 }
    });

    if (response.data.success) {
      activityLog.value = response.data.data.activities || [];
      activityLogHasMore.value = response.data.data.has_more || false;
      activityLogOffset.value = activityLog.value.length;
    }
  } catch (error) {
    console.error('Failed to load activity log:', error);
  } finally {
    loadingActivityLog.value = false;
  }
};

const loadMoreActivityLog = async () => {
  if (!selectedTreeId.value || loadingActivityLog.value) return;
  loadingActivityLog.value = true;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/activity-log`, {
      params: { limit: 50, offset: activityLogOffset.value }
    });

    if (response.data.success) {
      const newActivities = response.data.data.activities || [];
      activityLog.value.push(...newActivities);
      activityLogHasMore.value = response.data.data.has_more || false;
      activityLogOffset.value += newActivities.length;
    }
  } catch (error) {
    console.error('Failed to load more activity log:', error);
  } finally {
    loadingActivityLog.value = false;
  }
};

const formatActivityAction = (activity) => {
  const actions = {
    'create_person': 'Created person',
    'update_person': 'Updated person',
    'delete_person': 'Deleted person',
    'create_family': 'Created family',
    'update_family': 'Updated family',
    'delete_family': 'Deleted family',
    'add_media': 'Added media',
    'delete_media': 'Deleted media',
    'link_media': 'Linked media',
    'add_event': 'Added event',
    'update_event': 'Updated event',
    'delete_event': 'Deleted event',
    'add_source': 'Added source',
    'update_source': 'Updated source',
    'delete_source': 'Deleted source',
    'import_gedcom': 'Imported GEDCOM',
    'export_gedcom': 'Exported GEDCOM',
    'update_privacy': 'Updated privacy settings',
    'add_collaborator': 'Added collaborator',
    'remove_collaborator': 'Removed collaborator',
    'update_collaborator': 'Updated collaborator permissions'
  };

  let text = actions[activity.action] || activity.action;

  if (activity.entity_type && activity.entity_id) {
    text += ` (${activity.entity_type} #${activity.entity_id})`;
  }

  return text;
};

const formatActivityTime = (timestamp) => {
  if (!timestamp) return '';
  const date = new Date(timestamp);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMins < 1) return 'Just now';
  if (diffMins < 60) return `${diffMins}m ago`;
  if (diffHours < 24) return `${diffHours}h ago`;
  if (diffDays < 7) return `${diffDays}d ago`;

  return date.toLocaleDateString();
};

const openLivingStatsModal = async () => {
  if (!selectedTreeId.value) return;
  showLivingStatsModal.value = true;
  loadingLivingStats.value = true;
  livingStats.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/living-statistics`);
    if (response.data.success) {
      livingStats.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to load living statistics:', error);
  } finally {
    loadingLivingStats.value = false;
  }
};

// ========================================================================
// Phase 8: AI-Assisted Research Methods
// ========================================================================

const openResearchHintsModal = async () => {
  if (!selectedTreeId.value) return;
  showResearchHintsModal.value = true;
  await loadResearchHints();
};

const loadResearchHints = async () => {
  if (!selectedTreeId.value) return;
  loadingResearchHints.value = true;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/research-hints`, {
      params: { status: researchHintsFilter.value }
    });
    if (response.data.success) {
      researchHints.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load research hints:', error);
  } finally {
    loadingResearchHints.value = false;
  }
};

const generateResearchHints = async () => {
  if (!selectedTreeId.value) return;
  generatingHints.value = true;

  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/research-hints/generate`);
    if (response.data.success) {
      alert(`Generated ${response.data.data.hints_generated} new research hints!`);
      await loadResearchHints();
    }
  } catch (error) {
    console.error('Failed to generate research hints:', error);
    alert('Failed to generate hints: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    generatingHints.value = false;
  }
};

// AI Research Methods (Sprint 1: A.1)
const openAIResearch = (personId) => {
  // Get person name from selectedPerson if available
  if (selectedPerson.value && selectedPerson.value.id === personId) {
    aiResearchPersonName.value = `${selectedPerson.value.given_names || ''} ${selectedPerson.value.surname || ''}`.trim();
  } else {
    aiResearchPersonName.value = '';
  }
  aiResearchPersonId.value = personId;
  aiResearchResult.value = null;
  aiResearchError.value = null;
  aiResearchMode.value = 'research';
  showAIResearchModal.value = true;
};

const runAIResearch = async () => {
  if (!aiResearchPersonId.value) return;
  aiResearchLoading.value = true;
  aiResearchError.value = null;

  try {
    const response = await axios.post(`/api/genealogy/persons/${aiResearchPersonId.value}/ai-research`, {
      focus: 'comprehensive'
    });
    if (response.data.success) {
      aiResearchResult.value = response.data.data;
    } else {
      aiResearchError.value = response.data.error?.message || 'Failed to get AI research';
    }
  } catch (error) {
    console.error('AI Research failed:', error);
    aiResearchError.value = error.response?.data?.error?.message || error.message || 'Failed to connect to AI service';
  } finally {
    aiResearchLoading.value = false;
  }
};

const runBrickWallAnalysis = async () => {
  if (!aiResearchPersonId.value) return;
  aiResearchLoading.value = true;
  aiResearchError.value = null;

  try {
    const response = await axios.post(`/api/genealogy/persons/${aiResearchPersonId.value}/brick-wall-suggestions`, {});
    if (response.data.success) {
      aiResearchResult.value = response.data.data;
    } else {
      aiResearchError.value = response.data.error?.message || 'Failed to get brick wall analysis';
    }
  } catch (error) {
    console.error('Brick wall analysis failed:', error);
    aiResearchError.value = error.response?.data?.error?.message || error.message || 'Failed to connect to AI service';
  } finally {
    aiResearchLoading.value = false;
  }
};

// Helper to get research content from either research or brick wall results
const getResearchContent = () => {
  if (!aiResearchResult.value) return '';
  // Research mode returns research_suggestions, brick wall mode returns brick_wall_strategies
  return aiResearchResult.value.research_suggestions || aiResearchResult.value.brick_wall_strategies || '';
};

// Save AI research to person's notes
const saveResearchToNotes = async () => {
  if (!aiResearchResult.value || !aiResearchPersonId.value) return;

  try {
    const researchContent = getResearchContent();
    if (!researchContent) {
      alert('No research content to save');
      return;
    }

    const researchType = aiResearchMode.value === 'research' ? 'Research Strategy' : 'Brick Wall Analysis';
    const today = new Date().toISOString().split('T')[0];

    // Prepend to existing notes or create new
    const notePrefix = `\n\n---\n## AI ${researchType} (${today})\n\n`;
    const newNote = notePrefix + researchContent;

    const response = await axios.put(`/api/genealogy/persons/${aiResearchPersonId.value}`, {
      notes: newNote,
      append_notes: true  // Signal to append rather than replace
    });

    if (response.data.success) {
      alert('Research saved to person notes!');
      // Refresh person data if viewing this person
      if (selectedPerson.value?.id === aiResearchPersonId.value) {
        await selectPerson({ id: aiResearchPersonId.value });
      }
    } else {
      alert('Failed to save research: ' + (response.data.error?.message || 'Unknown error'));
    }
  } catch (error) {
    console.error('Failed to save research to notes:', error);
    alert('Failed to save research: ' + (error.response?.data?.error?.message || error.message));
  }
};

// Extract structured data from research results
const extractResearchData = async () => {
  if (!aiResearchResult.value || !aiResearchPersonId.value) return;

  const researchContent = getResearchContent();
  if (!researchContent) {
    alert('No research content to extract');
    return;
  }

  extractingResearchData.value = true;
  extractedResearchItems.value = [];

  try {
    const response = await axios.post(`/api/genealogy/persons/${aiResearchPersonId.value}/extract-research-data`, {
      research_text: researchContent
    });

    if (response.data.success && response.data.data.extracted_items) {
      // Add selected property to each item (default to high confidence items)
      extractedResearchItems.value = response.data.data.extracted_items.map(item => ({
        ...item,
        selected: item.confidence === 'high' || item.confidence === 'medium'
      }));

      if (extractedResearchItems.value.length === 0) {
        alert('No actionable data items found in research results. The research appears to be strategy/recommendations rather than specific facts to add.');
      }
    } else {
      alert('Failed to extract data: ' + (response.data.error?.message || 'Unknown error'));
    }
  } catch (error) {
    console.error('Failed to extract research data:', error);
    alert('Failed to extract data: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    extractingResearchData.value = false;
  }
};

// Computed properties for extracted items
const hasSelectedExtractedItems = computed(() => {
  return extractedResearchItems.value.some(item => item.selected);
});

const selectedExtractedItemsCount = computed(() => {
  return extractedResearchItems.value.filter(item => item.selected).length;
});

// Select/deselect all extracted items
const selectAllExtractedItems = () => {
  extractedResearchItems.value.forEach(item => item.selected = true);
};

const deselectAllExtractedItems = () => {
  extractedResearchItems.value.forEach(item => item.selected = false);
};

// Apply selected extracted items to person record
const applySelectedExtractedItems = async () => {
  const selectedItems = extractedResearchItems.value.filter(item => item.selected);

  if (selectedItems.length === 0) {
    alert('No items selected');
    return;
  }

  applyingResearchData.value = true;

  try {
    const response = await axios.post(`/api/genealogy/persons/${aiResearchPersonId.value}/apply-research-data`, {
      items: selectedItems.map(item => ({
        field: item.field,
        value: item.value
      }))
    });

    if (response.data.success) {
      const result = response.data.data;
      alert(`Successfully applied ${result.applied_count} items!\n\nFields updated: ${result.fields_updated.join(', ') || 'None'}\nNotes added: ${result.notes_added}`);

      // Clear extracted items
      extractedResearchItems.value = [];

      // Refresh person data if viewing this person
      if (selectedPerson.value?.id === aiResearchPersonId.value) {
        await selectPerson({ id: aiResearchPersonId.value });
      }
    } else {
      alert('Failed to apply data: ' + (response.data.error?.message || 'Unknown error'));
    }
  } catch (error) {
    console.error('Failed to apply research data:', error);
    alert('Failed to apply data: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    applyingResearchData.value = false;
  }
};

const updateHintStatus = async (hint, status) => {
  try {
    await axios.put(`/api/genealogy/research-hints/${hint.id}`, { status });
    hint.status = status;
  } catch (error) {
    console.error('Failed to update hint status:', error);
  }
};

const getHintTypeLabel = (type) => {
  const labels = {
    'record_match': 'Record Match',
    'name_variation': 'Name Variation',
    'location_suggestion': 'Location',
    'date_correction': 'Date Correction',
    'relationship_suggestion': 'Relationship',
    'missing_info': 'Missing Info',
    'duplicate_warning': 'Duplicate'
  };
  return labels[type] || type;
};

const getHintTypeColor = (type) => {
  const colors = {
    'record_match': 'bg-blue-500',
    'name_variation': 'bg-purple-500',
    'location_suggestion': 'bg-green-500',
    'date_correction': 'bg-yellow-500',
    'relationship_suggestion': 'bg-pink-500',
    'missing_info': 'bg-orange-500',
    'duplicate_warning': 'bg-red-500'
  };
  return colors[type] || 'bg-gray-500';
};

const formatMatchCriteria = (key) => {
  const labels = {
    'name_exact': 'Name Match',
    'name_soundex': 'Soundex Match',
    'given_name_partial': 'Given Name',
    'birth_year': 'Birth Year',
    'birth_place': 'Birth Place',
    'death_year': 'Death Year',
    'relationship': 'Family Match',
  };
  return labels[key] || key.replace(/_/g, ' ');
};

const openNameVariationsModal = async () => {
  if (!selectedTreeId.value) return;
  showNameVariationsModal.value = true;
  newVariationName.value = '';
  newVariationType.value = 'surname';
  newVariationValue.value = '';
  suggestedVariations.value = [];
  await loadNameVariations();
};

const loadNameVariations = async () => {
  if (!selectedTreeId.value) return;
  loadingNameVariations.value = true;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/name-variations`);
    if (response.data.success) {
      nameVariations.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load name variations:', error);
  } finally {
    loadingNameVariations.value = false;
  }
};

const generateNameSuggestions = async () => {
  if (!selectedTreeId.value || !newVariationName.value) return;
  loadingSuggestions.value = true;

  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/name-suggestions`, {
      name: newVariationName.value,
      name_type: newVariationType.value
    });
    if (response.data.success) {
      suggestedVariations.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to generate suggestions:', error);
  } finally {
    loadingSuggestions.value = false;
  }
};

const addNameVariation = async (variation = null) => {
  if (!selectedTreeId.value) return;

  const data = variation ? {
    original_name: newVariationName.value,
    name_type: newVariationType.value,
    variation: variation.variation,
    is_ai_generated: true
  } : {
    original_name: newVariationName.value,
    name_type: newVariationType.value,
    variation: newVariationValue.value
  };

  if (!data.original_name || !data.variation) return;

  try {
    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/name-variations`, data);
    if (response.data.success) {
      await loadNameVariations();
      if (!variation) {
        newVariationValue.value = '';
      }
    }
  } catch (error) {
    console.error('Failed to add variation:', error);
    if (error.response?.status === 409) {
      alert('This variation already exists.');
    }
  }
};

const deleteNameVariation = async (variation) => {
  if (!confirm(`Delete variation "${variation.variation}" for "${variation.original_name}"?`)) return;

  try {
    await axios.delete(`/api/genealogy/name-variations/${variation.id}`);
    nameVariations.value = nameVariations.value.filter(v => v.id !== variation.id);
  } catch (error) {
    console.error('Failed to delete variation:', error);
  }
};

const openResearchTasksModal = async () => {
  if (!selectedTreeId.value) return;
  showResearchTasksModal.value = true;
  await loadResearchTasks();
};

const loadResearchTasks = async () => {
  if (!selectedTreeId.value) return;
  loadingResearchTasks.value = true;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/research-tasks`);
    if (response.data.success) {
      researchTasks.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load research tasks:', error);
  } finally {
    loadingResearchTasks.value = false;
  }
};

const getTaskTypeLabel = (type) => {
  const labels = {
    'find_records': 'Find Records',
    'verify_facts': 'Verify Facts',
    'find_relatives': 'Find Relatives',
    'analyze_dna': 'Analyze DNA',
    'suggest_sources': 'Suggest Sources',
    'transcribe_document': 'Transcribe Document'
  };
  return labels[type] || type;
};

const getTaskStatusColor = (status) => {
  const colors = {
    'queued': 'bg-gray-500',
    'processing': 'bg-blue-500',
    'completed': 'bg-green-500',
    'failed': 'bg-red-500',
    'cancelled': 'bg-yellow-500'
  };
  return colors[status] || 'bg-gray-500';
};

const openResearchStatsModal = async () => {
  if (!selectedTreeId.value) return;
  showResearchStatsModal.value = true;
  loadingResearchStats.value = true;
  researchStats.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/research-statistics`);
    if (response.data.success) {
      researchStats.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to load research statistics:', error);
  } finally {
    loadingResearchStats.value = false;
  }
};

// ========================================================================
// FAN Cluster Research Methods
// ========================================================================

// N98: Research History — per-person when person selected, tree-wide otherwise
const loadResearchLogs = async () => {
  if (!selectedTreeId.value) return;
  loadingResearchLogs.value = true;
  try {
    let url, params;
    if (selectedPersonId.value) {
      url = `/api/genealogy/persons/${selectedPersonId.value}/research-logs`;
      params = { limit: 300 };
    } else {
      url = `/api/genealogy/trees/${selectedTreeId.value}/research-logs`;
      params = { limit: 300 };
    }
    const response = await axios.get(url, { params });
    researchLogs.value = response.data.data.logs || [];
    researchLogSummary.value = response.data.data.repository_summary || [];
  } catch (e) {
    console.error('Failed to load research logs', e);
  } finally {
    loadingResearchLogs.value = false;
  }
};

const exportResearchLogsCsv = () => {
  if (!selectedTreeId.value) return;
  if (selectedPersonId.value) {
    window.location.href = `/api/genealogy/persons/${selectedPersonId.value}/research-logs?format=csv&limit=1000`;
  } else {
    window.location.href = `/api/genealogy/trees/${selectedTreeId.value}/research-logs?format=csv&limit=1000`;
  }
};

// N93: FAN Co-occurrences
const loadFanCooccurrences = async () => {
  if (!fanClusterPersonId.value) return;
  loadingFanCooccurrences.value = true;
  try {
    const response = await axios.get(`/api/genealogy/persons/${fanClusterPersonId.value}/fan-cooccurrences`, { params: { limit: 50, min_confidence: 0.4 } });
    fanCooccurrences.value = response.data.data.cooccurrences || [];
  } catch (e) {
    console.error('Failed to load FAN co-occurrences', e);
  } finally {
    loadingFanCooccurrences.value = false;
  }
};

const loadFanClustersForPerson = async () => {
  if (!fanClusterPersonId.value) {
    fanClusters.value = [];
    selectedFanCluster.value = null;
    fanClusterMembers.value = [];
    return;
  }

  loadingFanClusters.value = true;
  try {
    const response = await axios.get(`/api/genealogy/persons/${fanClusterPersonId.value}/fan-clusters`);
    if (response.data.success) {
      fanClusters.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load FAN clusters:', error);
    fanClusters.value = [];
  } finally {
    loadingFanClusters.value = false;
  }
};

const selectFanCluster = async (cluster) => {
  selectedFanCluster.value = cluster;
  fanClusterAnalysis.value = null;
  fanClusterSuggestions.value = [];
  await loadFanClusterMembers();
};

const loadFanClusterMembers = async () => {
  if (!selectedFanCluster.value) {
    fanClusterMembers.value = [];
    return;
  }

  loadingFanClusterMembers.value = true;
  try {
    // GET /fan-clusters/{id} returns both cluster and members
    const response = await axios.get(`/api/genealogy/fan-clusters/${selectedFanCluster.value.id}`);
    if (response.data.success) {
      fanClusterMembers.value = response.data.data?.members || [];
    }
  } catch (error) {
    console.error('Failed to load FAN cluster members:', error);
    fanClusterMembers.value = [];
  } finally {
    loadingFanClusterMembers.value = false;
  }
};

const openCreateFanCluster = () => {
  newFanCluster.value = {
    name: '',
    research_period: '',
    location: '',
    notes: ''
  };
  showCreateFanClusterModal.value = true;
};

const createFanCluster = async () => {
  if (!fanClusterPersonId.value || !newFanCluster.value.name) return;

  savingFanCluster.value = true;
  try {
    const response = await axios.post(`/api/genealogy/fan-clusters`, {
      person_id: fanClusterPersonId.value,
      name: newFanCluster.value.name,
      research_period: newFanCluster.value.research_period,
      location: newFanCluster.value.location,
      notes: newFanCluster.value.notes
    });

    if (response.data.success) {
      showCreateFanClusterModal.value = false;
      await loadFanClustersForPerson();
      // Auto-select the new cluster
      const newClusterId = response.data.data?.id;
      if (newClusterId) {
        const cluster = fanClusters.value.find(c => c.id === newClusterId);
        if (cluster) {
          await selectFanCluster(cluster);
        }
      }
    }
  } catch (error) {
    console.error('Failed to create FAN cluster:', error);
    alert('Failed to create cluster: ' + (error.response?.data?.message || error.message));
  } finally {
    savingFanCluster.value = false;
  }
};

const confirmDeleteFanCluster = async () => {
  if (!selectedFanCluster.value) return;
  if (!confirm(`Delete cluster "${selectedFanCluster.value.cluster_name}"? This will remove all members.`)) return;

  deletingFanCluster.value = true;
  try {
    await axios.delete(`/api/genealogy/fan-clusters/${selectedFanCluster.value.id}`);
    selectedFanCluster.value = null;
    fanClusterMembers.value = [];
    await loadFanClustersForPerson();
  } catch (error) {
    console.error('Failed to delete FAN cluster:', error);
    alert('Failed to delete cluster: ' + (error.response?.data?.message || error.message));
  } finally {
    deletingFanCluster.value = false;
  }
};

const openAddFanMember = () => {
  newFanMember.value = {
    member_name: '',
    member_person_id: null,
    relationship_type: 'other',
    source_record_type: 'other',
    source_citation: '',
    interaction_date: '',
    interaction_description: '',
    confidence: 'medium'
  };
  showAddFanMemberModal.value = true;
};

const addFanMember = async () => {
  if (!selectedFanCluster.value || !newFanMember.value.member_name) return;

  savingFanMember.value = true;
  try {
    const response = await axios.post(`/api/genealogy/fan-clusters/${selectedFanCluster.value.id}/members`, newFanMember.value);
    if (response.data.success) {
      showAddFanMemberModal.value = false;
      await loadFanClusterMembers();
      // Update member count in cluster list
      await loadFanClustersForPerson();
    }
  } catch (error) {
    console.error('Failed to add FAN member:', error);
    alert('Failed to add member: ' + (error.response?.data?.message || error.message));
  } finally {
    savingFanMember.value = false;
  }
};

const openEditFanMember = (member) => {
  editingFanMember.value = { ...member };
  showEditFanMemberModal.value = true;
};

const saveFanMember = async () => {
  if (!editingFanMember.value) return;

  savingFanMember.value = true;
  try {
    const response = await axios.put(`/api/genealogy/fan-cluster-members/${editingFanMember.value.id}`, editingFanMember.value);
    if (response.data.success) {
      showEditFanMemberModal.value = false;
      editingFanMember.value = null;
      await loadFanClusterMembers();
    }
  } catch (error) {
    console.error('Failed to update FAN member:', error);
    alert('Failed to update member: ' + (error.response?.data?.message || error.message));
  } finally {
    savingFanMember.value = false;
  }
};

const confirmDeleteFanMember = async (member) => {
  if (!confirm(`Remove "${member.member_name}" from this cluster?`)) return;

  deletingFanMember.value = true;
  try {
    await axios.delete(`/api/genealogy/fan-cluster-members/${member.id}`);
    await loadFanClusterMembers();
    await loadFanClustersForPerson();
  } catch (error) {
    console.error('Failed to delete FAN member:', error);
    alert('Failed to remove member: ' + (error.response?.data?.message || error.message));
  } finally {
    deletingFanMember.value = false;
  }
};

const extractFanFromSource = async (sourceType) => {
  if (!fanClusterPersonId.value) return;

  fanClusterExtractSource.value = sourceType;
  extractingFanMembers.value = true;
  extractedFanMembers.value = [];

  try {
    const response = await axios.get(`/api/genealogy/persons/${fanClusterPersonId.value}/fan-extract/${sourceType}`);
    if (response.data.success) {
      extractedFanMembers.value = response.data.data || [];
      if (extractedFanMembers.value.length === 0) {
        alert(`No potential FAN members found in ${sourceType} records.`);
      }
    }
  } catch (error) {
    console.error('Failed to extract FAN members:', error);
    alert('Failed to extract members: ' + (error.response?.data?.message || error.message));
  } finally {
    extractingFanMembers.value = false;
    fanClusterExtractSource.value = null;
  }
};

const addExtractedMembersToCluster = async () => {
  if (!selectedFanCluster.value || extractedFanMembers.value.length === 0) return;

  savingFanMember.value = true;
  let addedCount = 0;

  try {
    for (const member of extractedFanMembers.value) {
      try {
        await axios.post(`/api/genealogy/fan-clusters/${selectedFanCluster.value.id}/members`, member);
        addedCount++;
      } catch (e) {
        console.warn('Failed to add member:', member.member_name, e);
      }
    }

    extractedFanMembers.value = [];
    await loadFanClusterMembers();
    await loadFanClustersForPerson();
    alert(`Added ${addedCount} members to cluster.`);
  } catch (error) {
    console.error('Failed to add extracted members:', error);
  } finally {
    savingFanMember.value = false;
  }
};

const loadFanClusterAnalysis = async () => {
  if (!selectedFanCluster.value) return;

  loadingFanClusterAnalysis.value = true;
  try {
    const [analysisRes, suggestionsRes] = await Promise.all([
      axios.get(`/api/genealogy/fan-clusters/${selectedFanCluster.value.id}/analysis`),
      axios.get(`/api/genealogy/fan-clusters/${selectedFanCluster.value.id}/research-suggestions`)
    ]);

    if (analysisRes.data.success) {
      fanClusterAnalysis.value = analysisRes.data.data;
    }
    if (suggestionsRes.data.success) {
      fanClusterSuggestions.value = suggestionsRes.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load FAN cluster analysis:', error);
  } finally {
    loadingFanClusterAnalysis.value = false;
  }
};

// ========================================================================
// Phase 9: External Integrations Methods
// ========================================================================

const loadSupportedServices = async () => {
  if (supportedServices.value.length > 0) return;
  loadingSupportedServices.value = true;

  try {
    const response = await axios.get('/api/genealogy/external-services');
    if (response.data.success) {
      supportedServices.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load supported services:', error);
  } finally {
    loadingSupportedServices.value = false;
  }
};

const openExternalConnectionsModal = async () => {
  if (!selectedTreeId.value) return;
  showExternalConnectionsModal.value = true;
  await Promise.all([loadSupportedServices(), loadExternalConnections()]);
};

const loadExternalConnections = async () => {
  if (!selectedTreeId.value) return;
  loadingExternalConnections.value = true;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/external-connections`);
    if (response.data.success) {
      externalConnections.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load external connections:', error);
  } finally {
    loadingExternalConnections.value = false;
  }
};

const getServiceLabel = (serviceType) => {
  const labels = {
    'familysearch': 'FamilySearch',
    'ancestry': 'Ancestry',
    'findmypast': 'FindMyPast',
    'myheritage': 'MyHeritage',
    'geneanet': 'Geneanet',
    'wikitree': 'WikiTree',
    'findagrave': 'Find A Grave'
  };
  return labels[serviceType] || serviceType;
};

const getServiceColor = (serviceType) => {
  const colors = {
    'familysearch': 'bg-green-600',
    'ancestry': 'bg-green-700',
    'findmypast': 'bg-purple-600',
    'myheritage': 'bg-blue-600',
    'geneanet': 'bg-blue-500',
    'wikitree': 'bg-teal-600',
    'findagrave': 'bg-gray-600'
  };
  return colors[serviceType] || 'bg-gray-500';
};

const getConnectionStatusColor = (status) => {
  const colors = {
    'active': 'bg-green-500',
    'expired': 'bg-yellow-500',
    'revoked': 'bg-red-500',
    'error': 'bg-red-600'
  };
  return colors[status] || 'bg-gray-500';
};

const resetNewConnection = () => {
  newConnection.value = {
    service_type: 'wikitree',
    service_user_id: '',
    access_token: '',
    refresh_token: '',
    token_expires_at: '',
    settings: {}
  };
  editingConnection.value = null;
};

const editConnection = (connection) => {
  editingConnection.value = connection.id;
  newConnection.value = {
    service_type: connection.service_type,
    service_user_id: connection.service_user_id || '',
    access_token: '', // Don't show existing tokens
    refresh_token: '',
    token_expires_at: connection.token_expires_at || '',
    settings: connection.settings || {}
  };
};

const saveExternalConnection = async () => {
  if (!selectedTreeId.value) return;
  savingExternalConnection.value = true;

  try {
    const data = { ...newConnection.value };
    // Clean up empty fields
    Object.keys(data).forEach(key => {
      if (data[key] === '') delete data[key];
    });

    const response = await axios.post(`/api/genealogy/trees/${selectedTreeId.value}/external-connections`, data);
    if (response.data.success) {
      await loadExternalConnections();
      resetNewConnection();
    }
  } catch (error) {
    console.error('Failed to save external connection:', error);
    alert('Failed to save connection: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    savingExternalConnection.value = false;
  }
};

const deleteExternalConnection = async (connectionId) => {
  if (!confirm('Delete this external connection? This will also remove all sync history.')) return;

  try {
    await axios.delete(`/api/genealogy/external-connections/${connectionId}`);
    externalConnections.value = externalConnections.value.filter(c => c.id !== connectionId);
  } catch (error) {
    console.error('Failed to delete connection:', error);
  }
};

const openExternalRecordsModal = async () => {
  if (!selectedTreeId.value) return;
  showExternalRecordsModal.value = true;
  await loadExternalRecords();
};

const loadExternalRecords = async () => {
  if (!selectedTreeId.value) return;
  loadingExternalRecords.value = true;

  try {
    const params = {};
    if (externalRecordsFilter.value !== 'all') {
      params.status = externalRecordsFilter.value;
    }
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/external-records`, { params });
    if (response.data.success) {
      externalRecords.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load external records:', error);
  } finally {
    loadingExternalRecords.value = false;
  }
};

const updateExternalRecordStatus = async (record, status) => {
  try {
    await axios.put(`/api/genealogy/external-records/${record.id}/status`, { status });
    record.status = status;
    if (status !== externalRecordsFilter.value && externalRecordsFilter.value !== 'all') {
      externalRecords.value = externalRecords.value.filter(r => r.id !== record.id);
    }
  } catch (error) {
    console.error('Failed to update record status:', error);
  }
};

const getRecordStatusColor = (status) => {
  const colors = {
    'pending': 'bg-yellow-500',
    'matched': 'bg-blue-500',
    'rejected': 'bg-red-500',
    'imported': 'bg-green-500'
  };
  return colors[status] || 'bg-gray-500';
};

const openPersonExternalLinksModal = async () => {
  if (!selectedPersonId.value) {
    alert('Please select a person first.');
    return;
  }
  showPersonExternalLinksModal.value = true;
  await Promise.all([loadSupportedServices(), loadPersonExternalLinks()]);
};

const loadPersonExternalLinks = async () => {
  if (!selectedPersonId.value) return;
  loadingPersonExternalLinks.value = true;

  try {
    const response = await axios.get(`/api/genealogy/persons/${selectedPersonId.value}/external-links`);
    if (response.data.success) {
      personExternalLinks.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load person external links:', error);
  } finally {
    loadingPersonExternalLinks.value = false;
  }
};

const resetNewPersonLink = () => {
  newPersonLink.value = {
    service_type: 'familysearch',
    external_person_id: '',
    link_type: 'confirmed',
    sync_enabled: true
  };
};

const linkPersonToService = async () => {
  if (!selectedPersonId.value || !newPersonLink.value.external_person_id) return;

  try {
    const response = await axios.post(`/api/genealogy/persons/${selectedPersonId.value}/external-links`, newPersonLink.value);
    if (response.data.success) {
      await loadPersonExternalLinks();
      resetNewPersonLink();
    }
  } catch (error) {
    console.error('Failed to link person:', error);
    if (error.response?.status === 409) {
      alert('This person is already linked to this service.');
    }
  }
};

const unlinkPersonFromService = async (link) => {
  if (!confirm(`Unlink this person from ${getServiceLabel(link.service_type)}?`)) return;

  try {
    await axios.delete(`/api/genealogy/persons/${selectedPersonId.value}/external-links/${link.service_type}`);
    personExternalLinks.value = personExternalLinks.value.filter(l => l.service_type !== link.service_type);
  } catch (error) {
    console.error('Failed to unlink person:', error);
  }
};

const getLinkTypeLabel = (type) => {
  const labels = {
    'confirmed': 'Confirmed',
    'suggested': 'Suggested',
    'rejected': 'Rejected'
  };
  return labels[type] || type;
};

const getLinkTypeColor = (type) => {
  const colors = {
    'confirmed': 'bg-green-500',
    'suggested': 'bg-yellow-500',
    'rejected': 'bg-red-500'
  };
  return colors[type] || 'bg-gray-500';
};

const openSyncHistoryModal = async (connectionId) => {
  selectedConnectionId.value = connectionId;
  showSyncHistoryModal.value = true;
  await loadSyncHistory(connectionId);
};

const loadSyncHistory = async (connectionId) => {
  loadingSyncHistory.value = true;

  try {
    const response = await axios.get(`/api/genealogy/external-connections/${connectionId}/sync-history`);
    if (response.data.success) {
      syncHistory.value = response.data.data || [];
    }
  } catch (error) {
    console.error('Failed to load sync history:', error);
  } finally {
    loadingSyncHistory.value = false;
  }
};

const startSync = async (connectionId, syncType = 'incremental') => {
  startingSync.value = true;

  try {
    const response = await axios.post(`/api/genealogy/external-connections/${connectionId}/sync`, {
      sync_type: syncType,
      direction: 'import'
    });
    if (response.data.success) {
      alert('Sync started successfully. Check sync history for progress.');
      if (showSyncHistoryModal.value && selectedConnectionId.value === connectionId) {
        await loadSyncHistory(connectionId);
      }
    }
  } catch (error) {
    console.error('Failed to start sync:', error);
    alert('Failed to start sync: ' + (error.response?.data?.error?.message || error.message));
  } finally {
    startingSync.value = false;
  }
};

const getSyncStatusColor = (status) => {
  const colors = {
    'pending': 'bg-gray-500',
    'running': 'bg-blue-500',
    'completed': 'bg-green-500',
    'failed': 'bg-red-500',
    'cancelled': 'bg-yellow-500'
  };
  return colors[status] || 'bg-gray-500';
};

const openExternalStatsModal = async () => {
  if (!selectedTreeId.value) return;
  showExternalStatsModal.value = true;
  loadingExternalStats.value = true;
  externalStats.value = null;

  try {
    const response = await axios.get(`/api/genealogy/trees/${selectedTreeId.value}/external-integration-stats`);
    if (response.data.success) {
      externalStats.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to load external integration stats:', error);
  } finally {
    loadingExternalStats.value = false;
  }
};

// Watch for tree mode changes
watch(treeViewMode, () => {
  if (homePersonId.value) {
    loadTreeChartData();
  }
});

// Lifecycle
onMounted(async () => {
  await loadTrees();
  await openPersonDeepLink();
});
</script>

<style scoped>
/* N135: Person detail slide-in panel transition */
.slide-panel-enter-active,
.slide-panel-leave-active {
  transition: opacity 0.25s ease;
}
.slide-panel-enter-active > div:last-child,
.slide-panel-leave-active > div:last-child {
  transition: transform 0.25s ease;
}
.slide-panel-enter-from,
.slide-panel-leave-to {
  opacity: 0;
}
.slide-panel-enter-from > div:last-child,
.slide-panel-leave-to > div:last-child {
  transform: translateX(100%);
}
.btn-primary {
  padding: 0.5rem 1rem;
  color: white;
  border-radius: 0.5rem;
  transition: background-color 0.15s ease-in-out;
  background-color: var(--color-accent);
}
.btn-primary:hover {
  background-color: var(--color-accent-blue);
}
.btn-secondary {
  padding: 0.5rem 1rem;
  background-color: var(--color-bg-tertiary);
  color: var(--color-text-primary);
  border-radius: 0.5rem;
  border: 1px solid var(--color-border);
  transition: background-color 0.15s ease-in-out;
}
.btn-secondary:hover {
  background-color: var(--color-bg-secondary);
}
.card {
  padding: 1rem;
  background-color: var(--color-bg-secondary);
  border-radius: 0.5rem;
  border: 1px solid var(--color-border);
}
.selected-item {
  color: white;
  background-color: var(--color-accent);
}
.progress-bar {
  background-color: var(--color-accent);
}

/* ============================================
   Topola Family Tree Container
   Light background for compatibility with Topola's default styles
   Text styling (stroke, fonts) handled in global app.css
   ============================================ */

.tree-container {
  background-color: #f8f9fa;
}

/* Fullscreen tree mode */
.tree-fullscreen {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  width: 100vw !important;
  height: 100vh !important;
  z-index: 9999 !important;
  border-radius: 0 !important;
  margin: 0 !important;
}

/* ============================================
   Priority 4.5: Mobile-Responsive Design
   Breakpoints:
   - sm: 640px (mobile landscape / large phones)
   - md: 768px (tablets)
   - lg: 1024px (small laptops)
   ============================================ */

/* Mobile-first responsive utilities */
.genealogy-view {
  width: 100%;
}

/* Mobile: < 640px */
@media (max-width: 639px) {
  /* Header stacks vertically on mobile */
  .flex.justify-between.items-center.mb-6 {
    flex-direction: column;
    align-items: stretch;
    gap: 1rem;
  }

  /* Action buttons wrap on mobile */
  .flex.items-center.gap-3 {
    flex-wrap: wrap;
    justify-content: center;
  }

  /* Full width tree selector on mobile */
  .flex.items-center.gap-3 select {
    width: 100%;
    min-width: unset;
  }

  /* Stats grid: single column on mobile */
  .grid.grid-cols-4.gap-4 {
    grid-template-columns: 1fr;
    gap: 0.75rem;
  }

  /* Surname grid: 2 columns on mobile */
  .grid.grid-cols-4.gap-2 {
    grid-template-columns: repeat(2, 1fr);
  }

  /* Tabs: horizontal scroll on mobile */
  .flex.border-b.border-theme.mb-4 {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }
  .flex.border-b.border-theme.mb-4::-webkit-scrollbar {
    display: none;
  }
  .flex.border-b.border-theme.mb-4 button {
    white-space: nowrap;
    flex-shrink: 0;
  }

  /* Tree controls stack on mobile */
  .flex.items-center.gap-4 {
    flex-direction: column;
    align-items: stretch;
  }

  /* View mode buttons scroll horizontally */
  .flex.gap-2 {
    flex-wrap: wrap;
    justify-content: center;
  }

  /* Tree container reduced height on mobile */
  .tree-container {
    height: 400px !important;
  }

  /* Home person selector full width */
  .min-w-\[300px\] {
    min-width: 100% !important;
    width: 100%;
  }

  /* Card reduced padding on mobile */
  .card {
    padding: 0.75rem;
  }

  /* Page title smaller on mobile */
  h2.text-3xl {
    font-size: 1.5rem;
  }

  /* Buttons slightly smaller on mobile */
  .btn-primary, .btn-secondary {
    padding: 0.4rem 0.75rem;
    font-size: 0.875rem;
  }

  /* Search results compact view */
  .p-4.bg-theme-tertiary.rounded-lg {
    padding: 0.75rem;
  }

  /* Modal dialog full width on mobile */
  .fixed.inset-0 .bg-theme-secondary {
    max-width: 95vw;
    max-height: 90vh;
    overflow-y: auto;
  }

  /* Person detail sidebar becomes bottom sheet on mobile */
  .person-detail-panel {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    top: auto;
    max-height: 70vh;
    border-radius: 1rem 1rem 0 0;
    overflow-y: auto;
  }

  /* Media gallery: 2 columns on mobile */
  .grid-cols-3, .grid-cols-4, .grid-cols-5, .grid-cols-6 {
    grid-template-columns: repeat(2, 1fr) !important;
  }
}

/* Tablet: 640px - 1023px */
@media (min-width: 640px) and (max-width: 1023px) {
  /* Stats grid: 2x2 on tablet */
  .grid.grid-cols-4.gap-4 {
    grid-template-columns: repeat(2, 1fr);
  }

  /* Surname grid: 3 columns on tablet */
  .grid.grid-cols-4.gap-2 {
    grid-template-columns: repeat(3, 1fr);
  }

  /* Tree container medium height on tablet */
  .tree-container {
    height: 500px !important;
  }

  /* Header buttons can wrap if needed */
  .flex.items-center.gap-3 {
    flex-wrap: wrap;
  }

  /* Media gallery: 3 columns on tablet */
  .grid-cols-4, .grid-cols-5, .grid-cols-6 {
    grid-template-columns: repeat(3, 1fr) !important;
  }
}

/* Touch-friendly interaction improvements */
@media (hover: none) and (pointer: coarse) {
  /* Larger touch targets */
  button, .cursor-pointer {
    min-height: 44px;
    min-width: 44px;
  }

  /* Tab buttons larger for touch */
  .flex.border-b.border-theme.mb-4 button {
    padding: 0.75rem 1rem;
  }

  /* Better tap feedback */
  button:active, .cursor-pointer:active {
    opacity: 0.7;
    transform: scale(0.98);
  }
}

/* Landscape orientation adjustments */
@media (max-height: 500px) and (orientation: landscape) {
  /* Compact header in landscape */
  .mb-6 {
    margin-bottom: 0.5rem;
  }

  /* Tree container uses available height */
  .tree-container {
    height: calc(100vh - 200px) !important;
    min-height: 300px;
  }

  /* Hide stats in landscape mobile to save space */
  .grid.grid-cols-4.gap-4 {
    display: none;
  }
}

/* Print styles */
@media print {
  /* Hide UI controls when printing */
  .btn-primary, .btn-secondary, select, input {
    display: none !important;
  }

  /* Tree view expands for print */
  .tree-container {
    height: auto !important;
    overflow: visible !important;
    page-break-inside: avoid;
  }

  /* Cards have visible borders for print */
  .card {
    border: 1px solid #ccc;
    background: white;
  }
}

/* Reduced motion preference */
@media (prefers-reduced-motion: reduce) {
  * {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
