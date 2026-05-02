<template>
  <div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h2 class="text-3xl font-bold text-gray-200">Email Queue & Suggestions</h2>
        <p class="text-gray-400 mt-1">Emails and AI suggestions pending human approval</p>
      </div>
      <div class="flex items-center space-x-3">
        <!-- Connection Status -->
        <div class="flex items-center space-x-2 px-3 py-2 rounded-r-full border-2"
             :class="thunderbirdAvailable ? 'border-ops-green bg-ops-green/10 text-ops-green' : 'border-ops-alert bg-ops-alert/10 text-ops-alert'">
          <span class="text-lg">{{ thunderbirdAvailable ? '&#9889;' : '&#10060;' }}</span>
          <span class="text-sm font-medium uppercase">
            Thunderbird {{ thunderbirdAvailable ? 'Connected' : 'Offline' }}
          </span>
        </div>
        <button @click="loadData"
                class="px-4 py-2 bg-ops-violet text-black rounded-r-full hover:bg-ops-lilac flex items-center font-semibold uppercase">
          <span class="mr-2">&#8635;</span> Refresh
        </button>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="stats.pending > 0 ? 'text-ops-sky' : 'text-ops-gray'">
          {{ stats.pending || 0 }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Emails</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="suggestionStats.total > 0 ? 'text-ops-grape' : 'text-ops-gray'">
          {{ suggestionStats.total || 0 }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Suggestions</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold" :class="stats.urgent > 0 ? 'text-ops-alert' : 'text-ops-gray'">
          {{ stats.urgent || 0 }}
        </div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Urgent</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-green">{{ stats.by_status?.sent || 0 }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Sent</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-orange">{{ stats.by_status?.failed || 0 }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Failed</div>
      </div>
      <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
        <div class="text-3xl font-bold text-ops-gray">{{ stats.by_status?.rejected || 0 }}</div>
        <div class="text-sm text-ops-text-muted uppercase tracking-wide">Rejected</div>
      </div>
    </div>

    <!-- Main View Tabs -->
    <div class="mb-6">
      <div class="border-b-2 border-ops-plum">
        <nav class="-mb-px flex space-x-8">
          <button @click="viewMode = 'emails'"
                  :class="viewMode === 'emails' ? 'border-ops-orange text-ops-orange' : 'border-transparent text-ops-text-muted hover:text-ops-peach'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Email Queue
            <span v-if="stats.pending > 0" class="ml-2 bg-ops-sky/20 text-ops-sky px-2 py-0.5 rounded-full text-xs">
              {{ stats.pending }}
            </span>
          </button>
          <button @click="viewMode = 'suggestions'"
                  :class="viewMode === 'suggestions' ? 'border-ops-grape text-ops-grape' : 'border-transparent text-ops-text-muted hover:text-ops-lavender'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            AI Suggestions
            <span v-if="suggestionStats.total > 0" class="ml-2 bg-ops-grape/20 text-ops-grape px-2 py-0.5 rounded-full text-xs">
              {{ suggestionStats.total }}
            </span>
          </button>
          <button @click="viewMode = 'scheduled'"
                  :class="viewMode === 'scheduled' ? 'border-ops-sky text-ops-sky' : 'border-transparent text-ops-text-muted hover:text-ops-peach'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Scheduled
            <span v-if="scheduledEmails.length > 0" class="ml-2 bg-ops-sky/20 text-ops-sky px-2 py-0.5 rounded-full text-xs">
              {{ scheduledEmails.length }}
            </span>
          </button>
          <button @click="viewMode = 'analytics'"
                  :class="viewMode === 'analytics' ? 'border-ops-lilac text-ops-lilac' : 'border-transparent text-ops-text-muted hover:text-ops-lavender'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            Analytics
          </button>
        </nav>
      </div>
    </div>

    <!-- Email Source Filter Tabs (only when viewing emails) -->
    <div v-if="viewMode === 'emails'" class="mb-6">
      <div class="border-b-2 border-ops-violet">
        <nav class="-mb-px flex space-x-8">
          <button @click="sourceFilter = null"
                  :class="sourceFilter === null ? 'border-ops-sky text-ops-sky' : 'border-transparent text-ops-text-muted hover:text-ops-peach'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            All Sources
          </button>
          <button @click="sourceFilter = 'data_removal'"
                  :class="sourceFilter === 'data_removal' ? 'border-ops-sky text-ops-sky' : 'border-transparent text-ops-text-muted hover:text-ops-peach'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Data Removal
            <span v-if="getSourceCount('data_removal') > 0" class="ml-2 bg-ops-grape/20 text-ops-grape px-2 py-0.5 rounded-full text-xs">
              {{ getSourceCount('data_removal') }}
            </span>
          </button>
          <button @click="sourceFilter = 'workflow'"
                  :class="sourceFilter === 'workflow' ? 'border-ops-sky text-ops-sky' : 'border-transparent text-ops-text-muted hover:text-ops-peach'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Workflow
            <span v-if="getSourceCount('workflow') > 0" class="ml-2 bg-ops-sky/20 text-ops-sky px-2 py-0.5 rounded-full text-xs">
              {{ getSourceCount('workflow') }}
            </span>
          </button>
          <button @click="sourceFilter = 'ai_reply'"
                  :class="sourceFilter === 'ai_reply' ? 'border-ops-sky text-ops-sky' : 'border-transparent text-ops-text-muted hover:text-ops-peach'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            AI Reply
            <span v-if="getSourceCount('ai_reply') > 0" class="ml-2 bg-ops-green/20 text-ops-green px-2 py-0.5 rounded-full text-xs">
              {{ getSourceCount('ai_reply') }}
            </span>
          </button>
        </nav>
      </div>
    </div>

    <!-- Suggestion Type Filter Tabs (only when viewing suggestions) -->
    <div v-if="viewMode === 'suggestions'" class="mb-6">
      <div class="border-b-2 border-ops-violet">
        <nav class="-mb-px flex space-x-8">
          <button @click="suggestionFilter = null"
                  :class="suggestionFilter === null ? 'border-ops-grape text-ops-grape' : 'border-transparent text-ops-text-muted hover:text-ops-lavender'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm uppercase tracking-wide">
            All Types
          </button>
          <button @click="suggestionFilter = 'contact'"
                  :class="suggestionFilter === 'contact' ? 'border-ops-grape text-ops-grape' : 'border-transparent text-ops-text-muted hover:text-ops-lavender'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Contacts
            <span v-if="suggestionStats.contact > 0" class="ml-2 bg-ops-sky/20 text-ops-sky px-2 py-0.5 rounded-full text-xs">
              {{ suggestionStats.contact }}
            </span>
          </button>
          <button @click="suggestionFilter = 'calendar'"
                  :class="suggestionFilter === 'calendar' ? 'border-ops-grape text-ops-grape' : 'border-transparent text-ops-text-muted hover:text-ops-lavender'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Calendar
            <span v-if="suggestionStats.calendar > 0" class="ml-2 bg-ops-green/20 text-ops-green px-2 py-0.5 rounded-full text-xs">
              {{ suggestionStats.calendar }}
            </span>
          </button>
          <button @click="suggestionFilter = 'bill'"
                  :class="suggestionFilter === 'bill' ? 'border-ops-grape text-ops-grape' : 'border-transparent text-ops-text-muted hover:text-ops-lavender'"
                  class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center uppercase tracking-wide">
            Bills
            <span v-if="suggestionStats.bill > 0" class="ml-2 bg-ops-alert/20 text-ops-alert px-2 py-0.5 rounded-full text-xs">
              {{ suggestionStats.bill }}
            </span>
          </button>
        </nav>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="text-center py-12">
      <div class="text-gray-400">Loading...</div>
    </div>

    <!-- Error State -->
    <div v-else-if="loadError" class="bg-ops-alert/20 border-2 border-ops-alert rounded-r-lg p-6 text-center">
      <div class="text-4xl mb-4">&#9888;&#65039;</div>
      <h3 class="text-xl font-semibold text-ops-alert uppercase">Error Loading Data</h3>
      <p class="text-ops-peach mt-2">{{ loadError }}</p>
      <button @click="loadData" class="mt-4 px-4 py-2 bg-ops-alert text-black rounded-r-full hover:bg-red-400 font-semibold uppercase">
        Retry
      </button>
    </div>

    <!-- ==================== SUGGESTIONS VIEW ==================== -->
    <template v-else-if="viewMode === 'suggestions'">
      <!-- Empty Suggestions State -->
      <div v-if="filteredSuggestions.length === 0" class="bg-black border-2 border-ops-plum rounded-r-lg p-12 text-center">
        <div class="text-6xl mb-4">&#128161;</div>
        <h3 class="text-xl font-semibold text-ops-grape uppercase">No Pending Suggestions</h3>
        <p class="text-ops-text-muted mt-2">
          {{ suggestionFilter ? `No ${suggestionFilter} suggestions` : 'AI will suggest contacts, calendar events, and bills from your emails' }}
        </p>
        <button @click="scanForSuggestions" :disabled="scanningEmails" class="mt-4 px-4 py-2 bg-ops-grape text-black rounded-r-full hover:bg-ops-lavender disabled:opacity-50 font-semibold uppercase">
          {{ scanningEmails ? 'Scanning...' : 'Scan Emails Now' }}
        </button>
      </div>

      <!-- Suggestions List -->
      <div v-else class="space-y-4">
        <div v-for="suggestion in filteredSuggestions" :key="suggestion.id"
             class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden transition-all duration-200"
             :class="{'ring-2 ring-ops-grape': expandedSuggestion === suggestion.id}">

          <!-- Suggestion Header -->
          <div class="p-4 cursor-pointer hover:bg-ops-plum/20" @click="toggleExpandSuggestion(suggestion.id)">
            <div class="flex items-start justify-between">
              <div class="flex items-start space-x-3">
                <!-- Type Icon -->
                <div class="text-2xl flex-shrink-0">
                  {{ getSuggestionTypeIcon(suggestion.type) }}
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center space-x-2 flex-wrap">
                    <h3 class="font-semibold text-ops-peach truncate">{{ suggestion.title }}</h3>
                    <span :class="getSuggestionTypeClass(suggestion.type)"
                          class="px-2 py-0.5 rounded-full text-xs font-medium">
                      {{ suggestion.type }}
                    </span>
                    <span v-if="suggestion.confidence" class="px-2 py-0.5 bg-ops-violet/20 text-ops-lilac rounded-full text-xs">
                      {{ Math.round(suggestion.confidence * 100) }}% confidence
                    </span>
                  </div>
                  <div class="flex items-center space-x-4 mt-1 text-sm text-ops-text-muted">
                    <span>{{ getSuggestionSubtitle(suggestion) }}</span>
                    <span>&#8226;</span>
                    <span>{{ formatDate(suggestion.created_at) }}</span>
                  </div>
                </div>
              </div>

              <!-- Expand Icon -->
              <div class="text-ops-violet text-xl">
                {{ expandedSuggestion === suggestion.id ? '&#9660;' : '&#9654;' }}
              </div>
            </div>
          </div>

          <!-- Expanded Suggestion Content -->
          <div v-if="expandedSuggestion === suggestion.id" class="border-t-2 border-ops-plum">
            <div class="p-4 bg-ops-plum/10">
              <!-- Description -->
              <div class="mb-3">
                <label class="text-xs text-ops-lilac uppercase tracking-wide">AI Analysis</label>
                <div class="text-ops-text mt-1">{{ suggestion.description }}</div>
              </div>

              <!-- Source Email Info -->
              <div class="mb-3 p-3 bg-black rounded-r-lg border-2 border-ops-violet">
                <label class="text-xs text-ops-lilac uppercase tracking-wide">Source Email</label>
                <div class="mt-1 text-sm text-ops-text">
                  <div><strong class="text-ops-text-muted">From:</strong> {{ suggestion.from_name || suggestion.from_address }}</div>
                  <div><strong class="text-ops-text-muted">Subject:</strong> {{ suggestion.email_subject }}</div>
                  <div><strong class="text-ops-text-muted">Date:</strong> {{ formatDate(suggestion.email_date) }}</div>
                </div>
              </div>

              <!-- Type-specific details -->
              <div class="mb-3">
                <!-- Contact Details -->
                <template v-if="suggestion.type === 'contact'">
                  <label class="text-xs text-ops-lilac uppercase tracking-wide">Contact Details</label>
                  <div class="mt-1 p-3 bg-ops-sky/10 border-2 border-ops-sky/30 rounded-r-lg text-ops-sky-light">
                    <div><strong class="text-ops-sky">Email:</strong> {{ suggestion.contact_email }}</div>
                    <div><strong class="text-ops-sky">Name:</strong> {{ suggestion.contact_name || 'Unknown' }}</div>
                    <div><strong class="text-ops-sky">Emails from sender:</strong> {{ suggestion.email_count }}</div>
                  </div>
                </template>

                <!-- Calendar Details -->
                <template v-else-if="suggestion.type === 'calendar'">
                  <label class="text-xs text-ops-lilac uppercase tracking-wide">Event Details</label>
                  <div class="mt-1 p-3 bg-ops-green/10 border-2 border-ops-green/30 rounded-r-lg text-ops-green">
                    <div><strong>Event:</strong> {{ suggestion.event_title }}</div>
                    <div><strong>Date:</strong> {{ formatDate(suggestion.event_date) }}</div>
                    <div v-if="suggestion.event_location"><strong>Location:</strong> {{ suggestion.event_location }}</div>
                  </div>
                </template>

                <!-- Bill Details -->
                <template v-else-if="suggestion.type === 'bill'">
                  <label class="text-xs text-ops-lilac uppercase tracking-wide">Bill Details</label>
                  <div class="mt-1 p-3 bg-ops-alert/10 border-2 border-ops-alert/30 rounded-r-lg text-ops-peach">
                    <div><strong class="text-ops-alert">From:</strong> {{ suggestion.bill_from }}</div>
                    <div><strong class="text-ops-alert">Amount:</strong> ${{ suggestion.bill_amount }}</div>
                    <div><strong class="text-ops-alert">Due Date:</strong> {{ formatDate(suggestion.bill_due_date) }}</div>
                    <div v-if="suggestion.bill_account"><strong class="text-ops-alert">Account:</strong> {{ suggestion.bill_account }}</div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Actions -->
            <div class="p-4 bg-black border-t-2 border-ops-plum flex items-center justify-end space-x-2">
              <button @click="rejectSuggestion(suggestion.id)"
                      :disabled="suggestionActionInProgress"
                      class="px-4 py-2 text-ops-alert border-2 border-ops-alert rounded-r-full hover:bg-ops-alert/20 disabled:opacity-50 uppercase font-semibold">
                Reject
              </button>
              <button @click="approveSuggestion(suggestion.id)"
                      :disabled="suggestionActionInProgress"
                      class="px-4 py-2 bg-ops-green text-black rounded-r-full hover:bg-ops-green-bright disabled:opacity-50 flex items-center uppercase font-semibold">
                <span v-if="suggestionActionInProgress && actionSuggestionId === suggestion.id" class="mr-2">&#8987;</span>
                {{ getApproveButtonText(suggestion.type) }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ==================== EMAIL QUEUE VIEW ==================== -->
    <template v-else>
      <!-- Empty Email State -->
      <div v-if="filteredDrafts.length === 0" class="bg-black border-2 border-ops-plum rounded-r-lg p-12 text-center">
        <div class="text-6xl mb-4">&#9993;</div>
        <h3 class="text-xl font-semibold text-ops-sky uppercase">No Pending Emails</h3>
        <p class="text-ops-text-muted mt-2">
          {{ sourceFilter ? `No ${sourceFilter.replace('_', ' ')} emails in queue` : 'All emails have been processed' }}
        </p>
      </div>

      <!-- Drafts List -->
    <div v-else class="space-y-4">
      <div v-for="draft in filteredDrafts" :key="draft.id"
           class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden transition-all duration-200"
           :class="{'ring-2 ring-ops-orange': expandedDraft === draft.id}">

        <!-- Draft Header -->
        <div class="p-4 cursor-pointer hover:bg-ops-plum/20" @click="toggleExpand(draft.id)">
          <div class="flex items-start justify-between">
            <div class="flex items-start space-x-3">
              <!-- Priority/Source Icon -->
              <div class="text-2xl flex-shrink-0">
                {{ getSourceIcon(draft.source) }}
              </div>

              <!-- Content -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-2 flex-wrap">
                  <h3 class="font-semibold text-ops-peach truncate">{{ draft.subject }}</h3>
                  <span :class="getPriorityClass(draft.priority)"
                        class="px-2 py-0.5 rounded-full text-xs font-medium">
                    {{ draft.priority }}
                  </span>
                  <span class="px-2 py-0.5 bg-ops-violet/20 text-ops-lilac rounded-full text-xs">
                    {{ formatSource(draft.source) }}
                  </span>
                </div>
                <div class="flex items-center space-x-4 mt-1 text-sm text-ops-text-muted">
                  <span>To: {{ draft.to }}</span>
                  <span>&#8226;</span>
                  <span>{{ formatDate(draft.created_at) }}</span>
                </div>
              </div>
            </div>

            <!-- Expand Icon -->
            <div class="text-ops-violet text-xl">
              {{ expandedDraft === draft.id ? '&#9660;' : '&#9654;' }}
            </div>
          </div>
        </div>

        <!-- Expanded Content -->
        <div v-if="expandedDraft === draft.id" class="border-t-2 border-ops-plum">
          <!-- Email Preview -->
          <div class="p-4 bg-ops-plum/10">
            <div class="mb-3">
              <label class="text-xs text-ops-lilac uppercase tracking-wide">From</label>
              <div class="text-ops-text">{{ draft.from_address || 'Default mailbox' }}</div>
            </div>
            <div class="mb-3">
              <label class="text-xs text-ops-lilac uppercase tracking-wide">To</label>
              <div class="text-ops-text">{{ draft.to }}</div>
            </div>
            <div v-if="draft.cc" class="mb-3">
              <label class="text-xs text-ops-lilac uppercase tracking-wide">CC</label>
              <div class="text-ops-text">{{ draft.cc }}</div>
            </div>
            <div class="mb-3">
              <label class="text-xs text-ops-lilac uppercase tracking-wide">Subject</label>
              <div class="text-ops-peach font-medium">{{ draft.subject }}</div>
            </div>
            <div>
              <label class="text-xs text-ops-lilac uppercase tracking-wide">Body</label>
              <div class="mt-1 p-3 bg-black rounded-r-lg border-2 border-ops-violet whitespace-pre-wrap text-sm text-ops-text max-h-64 overflow-y-auto ops-scroll">
                {{ draft.body }}
              </div>
            </div>

            <!-- AI Suggestions -->
            <div v-if="draft.ai_suggestions" class="mt-4 p-3 bg-ops-sky/10 border-2 border-ops-sky/30 rounded-r-lg">
              <label class="text-xs text-ops-sky uppercase tracking-wide">AI Suggestions</label>
              <div class="text-ops-sky-light text-sm mt-1">{{ draft.ai_suggestions }}</div>
            </div>

            <!-- Related Info -->
            <div v-if="draft.related_type" class="mt-3 text-xs text-ops-text-muted">
              Related: {{ draft.related_type }} #{{ draft.related_id }}
            </div>
          </div>

          <!-- Actions -->
          <div class="p-4 bg-black border-t-2 border-ops-plum flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <button @click="editDraft(draft)"
                      class="px-4 py-2 text-ops-text border-2 border-ops-violet rounded-r-full hover:bg-ops-plum/30 uppercase font-semibold">
                Edit
              </button>
              <button @click="loadDraftVersions(draft.id)"
                      class="px-4 py-2 text-ops-lilac border-2 border-ops-lilac rounded-r-full hover:bg-ops-lilac/20 uppercase font-semibold">
                History
              </button>
            </div>
            <div class="flex items-center space-x-2">
              <button @click="rejectDraft(draft.id)"
                      :disabled="actionInProgress"
                      class="px-4 py-2 text-ops-alert border-2 border-ops-alert rounded-r-full hover:bg-ops-alert/20 disabled:opacity-50 uppercase font-semibold">
                Reject
              </button>
              <button @click="approveDraft(draft.id)"
                      :disabled="actionInProgress || !thunderbirdAvailable"
                      class="px-4 py-2 bg-ops-green text-black rounded-r-full hover:bg-ops-green-bright disabled:opacity-50 flex items-center uppercase font-semibold">
                <span v-if="actionInProgress && actionDraftId === draft.id" class="mr-2">&#8987;</span>
                Approve & Send
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
    </template>

    <!-- ==================== SCHEDULED VIEW ==================== -->
    <template v-if="viewMode === 'scheduled'">
      <div v-if="scheduledEmails.length === 0" class="bg-black border-2 border-ops-plum rounded-r-lg p-12 text-center">
        <div class="text-6xl mb-4">&#128197;</div>
        <h3 class="text-xl font-semibold text-ops-sky uppercase">No Scheduled Emails</h3>
        <p class="text-ops-text-muted mt-2">Schedule emails to be sent at a future time</p>
      </div>
      <div v-else class="bg-black border-2 border-ops-plum rounded-r-lg overflow-hidden">
        <table class="min-w-full">
          <thead class="border-b-2 border-ops-plum">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wide">To</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wide">Subject</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wide">Send At</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wide">Timezone</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-ops-lilac uppercase tracking-wide">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-ops-plum">
            <tr v-for="sched in scheduledEmails" :key="sched.id" class="hover:bg-ops-plum/20">
              <td class="px-4 py-3 text-sm text-ops-peach">{{ sched.recipient_email }}</td>
              <td class="px-4 py-3 text-sm text-ops-text">{{ sched.subject }}</td>
              <td class="px-4 py-3 text-sm text-ops-sky">{{ formatDate(sched.scheduled_at) }}</td>
              <td class="px-4 py-3 text-sm text-ops-text-muted">{{ sched.timezone || 'UTC' }}</td>
              <td class="px-4 py-3">
                <button @click="cancelScheduled(sched.id)"
                        class="px-3 py-1 text-xs text-ops-alert border border-ops-alert rounded-r-full hover:bg-ops-alert/20 uppercase font-semibold">
                  Cancel
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ==================== ANALYTICS VIEW ==================== -->
    <template v-if="viewMode === 'analytics'">
      <div v-if="!analyticsData" class="text-center py-12 text-ops-text-muted">Loading analytics...</div>
      <div v-else class="space-y-6">
        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
            <div class="text-3xl font-bold text-ops-sky">{{ analyticsData.approval_rate?.total || 0 }}</div>
            <div class="text-sm text-ops-text-muted uppercase tracking-wide">Total</div>
          </div>
          <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
            <div class="text-3xl font-bold text-ops-green">{{ analyticsData.approval_rate?.approved || 0 }}</div>
            <div class="text-sm text-ops-text-muted uppercase tracking-wide">Approved</div>
          </div>
          <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
            <div class="text-3xl font-bold text-ops-alert">{{ analyticsData.approval_rate?.rejected || 0 }}</div>
            <div class="text-sm text-ops-text-muted uppercase tracking-wide">Rejected</div>
          </div>
          <div class="bg-black border-2 border-ops-plum rounded-r-full p-4 text-center">
            <div class="text-3xl font-bold text-ops-lilac">
              {{ analyticsData.approval_rate?.total > 0 ? Math.round((analyticsData.approval_rate.approved / analyticsData.approval_rate.total) * 100) : 0 }}%
            </div>
            <div class="text-sm text-ops-text-muted uppercase tracking-wide">Approval Rate</div>
          </div>
        </div>

        <!-- Volume by Day -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg p-4">
          <h3 class="text-lg font-semibold text-ops-peach uppercase mb-4">Volume by Day</h3>
          <div class="space-y-2">
            <div v-for="day in (analyticsData.volume_by_day || []).slice(-14)" :key="day.date" class="flex items-center gap-3">
              <div class="text-xs text-ops-text-muted w-24">{{ day.date }}</div>
              <div class="flex-1 bg-ops-plum/30 rounded-full h-4 overflow-hidden">
                <div class="bg-ops-sky h-full rounded-full" :style="{ width: getBarWidth(day.count, analyticsData.volume_by_day) }"></div>
              </div>
              <div class="text-xs text-ops-sky w-8 text-right">{{ day.count }}</div>
            </div>
          </div>
        </div>

        <!-- Top Recipients -->
        <div class="bg-black border-2 border-ops-plum rounded-r-lg p-4">
          <h3 class="text-lg font-semibold text-ops-peach uppercase mb-4">Top Recipients</h3>
          <div v-for="recip in (analyticsData.top_recipients || [])" :key="recip.recipient_email" class="flex justify-between items-center py-2 border-b border-ops-plum/50 last:border-0">
            <span class="text-sm text-ops-text">{{ recip.recipient_email }}</span>
            <span class="text-sm text-ops-sky font-bold">{{ recip.count }}</span>
          </div>
        </div>
      </div>
    </template>

    <!-- Draft Version History Modal -->
    <div v-if="draftVersions.length > 0 && showVersionHistory" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center z-50">
      <div class="bg-black border-2 border-ops-lilac rounded-r-lg max-w-lg w-full mx-4 max-h-[70vh] overflow-y-auto ops-scroll">
        <div class="p-4 border-b-2 border-ops-plum flex justify-between items-center sticky top-0 bg-black">
          <h3 class="text-lg font-semibold text-ops-lilac uppercase tracking-wide">Draft Version History</h3>
          <button @click="showVersionHistory = false" class="text-ops-text-muted hover:text-ops-lilac text-xl">&times;</button>
        </div>
        <div class="p-4 space-y-3">
          <div v-for="ver in draftVersions" :key="ver.id" class="border-l-2 border-ops-violet pl-4 py-2">
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="{
                      'bg-ops-sky/20 text-ops-sky': ver.change_type === 'created',
                      'bg-ops-orange/20 text-ops-orange': ver.change_type === 'edited',
                      'bg-ops-green/20 text-ops-green': ver.change_type === 'approved',
                      'bg-ops-alert/20 text-ops-alert': ver.change_type === 'rejected'
                    }">
                {{ ver.change_type }}
              </span>
              <span class="text-xs text-ops-text-muted">{{ formatDate(ver.created_at) }}</span>
            </div>
            <div v-if="ver.changed_by" class="text-xs text-ops-text-muted mt-1">by {{ ver.changed_by }}</div>
            <div v-if="ver.changed_fields && Object.keys(ver.changed_fields).length" class="text-xs text-ops-lilac mt-1">
              Changed: {{ Object.keys(ver.changed_fields).join(', ') }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Modal -->
    <div v-if="editingDraft" class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center z-50">
      <div class="bg-black border-2 border-ops-orange rounded-r-lg max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto ops-scroll">
        <div class="p-4 border-b-2 border-ops-plum flex items-center justify-between">
          <h3 class="text-lg font-semibold text-ops-peach uppercase tracking-wide">Edit Draft</h3>
          <button @click="editingDraft = null" class="text-ops-text-muted hover:text-ops-orange text-xl">&times;</button>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">To</label>
            <input v-model="editForm.to" type="email"
                   class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange">
          </div>
          <div>
            <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Subject</label>
            <input v-model="editForm.subject" type="text"
                   class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange">
          </div>
          <div>
            <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Priority</label>
            <select v-model="editForm.priority"
                    class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-full text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-ops-lilac mb-1 uppercase">Body</label>
            <textarea v-model="editForm.body" rows="10"
                      class="w-full px-3 py-2 bg-black border-2 border-ops-violet rounded-r-lg text-ops-text focus:ring-2 focus:ring-ops-orange focus:border-ops-orange font-mono text-sm"></textarea>
          </div>
        </div>
        <div class="p-4 border-t-2 border-ops-plum flex justify-end space-x-2">
          <button @click="editingDraft = null"
                  class="px-4 py-2 text-ops-text border-2 border-ops-violet rounded-r-full hover:bg-ops-plum/30 uppercase font-semibold">
            Cancel
          </button>
          <button @click="saveDraft"
                  :disabled="actionInProgress"
                  class="px-4 py-2 bg-ops-orange text-black rounded-r-full hover:bg-ops-peach disabled:opacity-50 uppercase font-semibold">
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <!-- Toast Notifications -->
    <div v-if="toast.show"
         class="fixed bottom-4 right-4 px-4 py-3 rounded-lg shadow-lg z-50"
         :class="toast.type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'">
      {{ toast.message }}
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'

export default {
  name: 'EmailQueueView',
  setup() {
    const loading = ref(true)
    const loadError = ref(null)
    const drafts = ref([])
    const stats = ref({})
    const thunderbirdAvailable = ref(false)
    const sourceFilter = ref(null)
    const expandedDraft = ref(null)
    const editingDraft = ref(null)
    const editForm = ref({})
    const actionInProgress = ref(false)
    const actionDraftId = ref(null)
    const toast = ref({ show: false, message: '', type: 'success' })

    // New suggestion-related state
    const viewMode = ref('emails')
    const suggestions = ref([])
    const suggestionStats = ref({ total: 0, contact: 0, calendar: 0, bill: 0 })
    const suggestionFilter = ref(null)
    const expandedSuggestion = ref(null)
    const suggestionActionInProgress = ref(false)
    const actionSuggestionId = ref(null)
    const scanningEmails = ref(false)

    // Scheduled emails state
    const scheduledEmails = ref([])

    // Analytics state
    const analyticsData = ref(null)

    // Draft version history state
    const draftVersions = ref([])
    const showVersionHistory = ref(false)

    const filteredDrafts = computed(() => {
      if (!sourceFilter.value) return drafts.value
      return drafts.value.filter(d => d.source === sourceFilter.value)
    })

    const filteredSuggestions = computed(() => {
      if (!suggestionFilter.value) return suggestions.value
      return suggestions.value.filter(s => s.type === suggestionFilter.value)
    })

    const getSourceCount = (source) => {
      return drafts.value.filter(d => d.source === source).length
    }

    const loadData = async () => {
      loading.value = true
      loadError.value = null

      try {
        const [queueRes, statusRes, suggestionsRes, suggestionsStatsRes] = await Promise.all([
          axios.get('/api/email/v2/queue'),
          axios.get('/api/email/v2/status'),
          axios.get('/api/email/v2/suggestions').catch(() => ({ data: { success: false } })),
          axios.get('/api/email/v2/suggestions/stats').catch(() => ({ data: { success: false } }))
        ])

        if (queueRes.data.success) {
          drafts.value = queueRes.data.data || []
          stats.value = queueRes.data.stats || {}
        }

        if (statusRes.data.success) {
          thunderbirdAvailable.value = statusRes.data.data?.available || false
        }

        if (suggestionsRes.data.success) {
          suggestions.value = suggestionsRes.data.data || []
        }

        if (suggestionsStatsRes.data.success) {
          suggestionStats.value = suggestionsStatsRes.data.data || { total: 0, contact: 0, calendar: 0, bill: 0 }
        }
      } catch (err) {
        loadError.value = err.response?.data?.error || err.message
      } finally {
        loading.value = false
      }
    }

    const toggleExpand = (id) => {
      expandedDraft.value = expandedDraft.value === id ? null : id
    }

    const getSourceIcon = (source) => {
      const icons = {
        'data_removal': '&#128274;',  // lock
        'workflow': '&#9881;',         // gear
        'ai_reply': '&#129302;',       // robot
        'manual': '&#9997;'            // writing hand
      }
      return icons[source] || '&#9993;' // envelope
    }

    const formatSource = (source) => {
      const names = {
        'data_removal': 'Data Removal',
        'workflow': 'Workflow',
        'ai_reply': 'AI Reply',
        'manual': 'Manual'
      }
      return names[source] || source
    }

    const getPriorityClass = (priority) => {
      const classes = {
        'urgent': 'bg-red-100 text-red-700',
        'high': 'bg-orange-100 text-orange-700',
        'normal': 'bg-gray-100 text-gray-400',
        'low': 'bg-gray-50 text-gray-400'
      }
      return classes[priority] || classes.normal
    }

    const formatDate = (dateStr) => {
      if (!dateStr) return ''
      const date = new Date(dateStr)
      return date.toLocaleString()
    }

    const editDraft = (draft) => {
      editingDraft.value = draft
      editForm.value = {
        to: draft.to,
        subject: draft.subject,
        body: draft.body,
        priority: draft.priority || 'normal'
      }
    }

    const saveDraft = async () => {
      actionInProgress.value = true
      try {
        const res = await axios.put(`/api/email/v2/queue/${editingDraft.value.id}`, editForm.value)
        if (res.data.success) {
          showToast('Draft updated successfully', 'success')
          editingDraft.value = null
          await loadData()
        } else {
          showToast(res.data.error || 'Failed to update draft', 'error')
        }
      } catch (err) {
        showToast(err.response?.data?.error || err.message, 'error')
      } finally {
        actionInProgress.value = false
      }
    }

    const approveDraft = async (id) => {
      if (!confirm('Approve and send this email?')) return

      actionInProgress.value = true
      actionDraftId.value = id

      try {
        const res = await axios.post(`/api/email/v2/queue/${id}/approve`)
        if (res.data.success) {
          showToast('Email sent successfully!', 'success')
          await loadData()
          expandedDraft.value = null
        } else {
          showToast(res.data.error || 'Failed to send email', 'error')
        }
      } catch (err) {
        showToast(err.response?.data?.error || err.message, 'error')
      } finally {
        actionInProgress.value = false
        actionDraftId.value = null
      }
    }

    const rejectDraft = async (id) => {
      const reason = prompt('Rejection reason (optional):')
      if (reason === null) return // cancelled

      actionInProgress.value = true

      try {
        const res = await axios.post(`/api/email/v2/queue/${id}/reject`, { reason })
        if (res.data.success) {
          showToast('Draft rejected', 'success')
          await loadData()
          expandedDraft.value = null
        } else {
          showToast(res.data.error || 'Failed to reject draft', 'error')
        }
      } catch (err) {
        showToast(err.response?.data?.error || err.message, 'error')
      } finally {
        actionInProgress.value = false
      }
    }

    const showToast = (message, type = 'success') => {
      toast.value = { show: true, message, type }
      setTimeout(() => {
        toast.value.show = false
      }, 3000)
    }

    // Suggestion-related methods
    const toggleExpandSuggestion = (id) => {
      expandedSuggestion.value = expandedSuggestion.value === id ? null : id
    }

    const getSuggestionTypeIcon = (type) => {
      const icons = {
        'contact': '&#128100;',   // bust
        'calendar': '&#128197;',  // calendar
        'bill': '&#128181;',      // dollar
        'reply': '&#9993;'        // envelope
      }
      return icons[type] || '&#128161;' // lightbulb
    }

    const getSuggestionTypeClass = (type) => {
      const classes = {
        'contact': 'bg-blue-100 text-blue-700',
        'calendar': 'bg-green-100 text-green-700',
        'bill': 'bg-red-100 text-red-700',
        'reply': 'bg-purple-100 text-purple-700'
      }
      return classes[type] || 'bg-gray-100 text-gray-400'
    }

    const getSuggestionSubtitle = (suggestion) => {
      switch (suggestion.type) {
        case 'contact':
          return suggestion.contact_email || 'New contact'
        case 'calendar':
          return suggestion.event_title || 'New event'
        case 'bill':
          return suggestion.bill_from ? `$${suggestion.bill_amount} from ${suggestion.bill_from}` : 'Bill detected'
        default:
          return ''
      }
    }

    const getApproveButtonText = (type) => {
      const texts = {
        'contact': 'Create Contact',
        'calendar': 'Add to Calendar',
        'bill': 'Set Reminder',
        'reply': 'Send Reply'
      }
      return texts[type] || 'Approve'
    }

    const scanForSuggestions = async () => {
      scanningEmails.value = true
      try {
        const res = await axios.post('/api/email/v2/suggestions/scan', {
          folder: 'Inbox',
          limit: 50
        })
        if (res.data.success) {
          showToast('Suggestion scan queued. Refresh after the worker completes.', 'success')
        } else {
          showToast(res.data.error || 'Scan failed', 'error')
        }
      } catch (err) {
        showToast(err.response?.data?.error || err.message, 'error')
      } finally {
        scanningEmails.value = false
      }
    }

    const approveSuggestion = async (id) => {
      suggestionActionInProgress.value = true
      actionSuggestionId.value = id

      try {
        const res = await axios.post(`/api/email/v2/suggestions/${id}/approve`)
        if (res.data.success) {
          showToast(res.data.message || 'Suggestion approved!', 'success')
          await loadData()
          expandedSuggestion.value = null
        } else {
          showToast(res.data.error || 'Failed to approve suggestion', 'error')
        }
      } catch (err) {
        showToast(err.response?.data?.error || err.message, 'error')
      } finally {
        suggestionActionInProgress.value = false
        actionSuggestionId.value = null
      }
    }

    const rejectSuggestion = async (id) => {
      const reason = prompt('Rejection reason (optional):')
      if (reason === null) return // cancelled

      suggestionActionInProgress.value = true
      actionSuggestionId.value = id

      try {
        const res = await axios.post(`/api/email/v2/suggestions/${id}/reject`, { reason })
        if (res.data.success) {
          showToast('Suggestion rejected', 'success')
          await loadData()
          expandedSuggestion.value = null
        } else {
          showToast(res.data.error || 'Failed to reject suggestion', 'error')
        }
      } catch (err) {
        showToast(err.response?.data?.error || err.message, 'error')
      } finally {
        suggestionActionInProgress.value = false
        actionSuggestionId.value = null
      }
    }

    // Scheduled emails methods
    const loadScheduled = async () => {
      try {
        const res = await axios.get('/api/email/v2/scheduled')
        if (res.data.success) scheduledEmails.value = res.data.data || []
      } catch (err) { console.error('Failed to load scheduled:', err) }
    }

    const cancelScheduled = async (id) => {
      if (!confirm('Cancel this scheduled email?')) return
      try {
        const res = await axios.delete(`/api/email/v2/scheduled/${id}`)
        if (res.data.success) {
          showToast('Scheduled email cancelled', 'success')
          loadScheduled()
        }
      } catch (err) { showToast(err.response?.data?.error || err.message, 'error') }
    }

    // Analytics methods
    const loadAnalytics = async () => {
      try {
        const res = await axios.get('/api/email/v2/analytics', { params: { days: 30 } })
        if (res.data.success) analyticsData.value = res.data.data
      } catch (err) { console.error('Failed to load analytics:', err) }
    }

    const getBarWidth = (count, days) => {
      if (!days?.length) return '0%'
      const max = Math.max(...days.map(d => d.count))
      return max > 0 ? `${(count / max) * 100}%` : '0%'
    }

    // Draft version history
    const loadDraftVersions = async (draftId) => {
      try {
        const res = await axios.get(`/api/email/v2/queue/${draftId}/versions`)
        if (res.data.success) {
          draftVersions.value = res.data.data || []
          showVersionHistory.value = true
        }
      } catch (err) { console.error('Failed to load draft versions:', err) }
    }

    // Watch viewMode for lazy loading
    watch(viewMode, (mode) => {
      if (mode === 'scheduled' && scheduledEmails.value.length === 0) loadScheduled()
      if (mode === 'analytics' && !analyticsData.value) loadAnalytics()
    })

    onMounted(() => {
      loadData()
    })

    return {
      loading,
      loadError,
      drafts,
      stats,
      thunderbirdAvailable,
      sourceFilter,
      expandedDraft,
      editingDraft,
      editForm,
      actionInProgress,
      actionDraftId,
      toast,
      filteredDrafts,
      getSourceCount,
      loadData,
      toggleExpand,
      getSourceIcon,
      formatSource,
      getPriorityClass,
      formatDate,
      editDraft,
      saveDraft,
      approveDraft,
      rejectDraft,
      // Suggestion-related exports
      viewMode,
      suggestions,
      suggestionStats,
      suggestionFilter,
      expandedSuggestion,
      suggestionActionInProgress,
      actionSuggestionId,
      scanningEmails,
      filteredSuggestions,
      toggleExpandSuggestion,
      getSuggestionTypeIcon,
      getSuggestionTypeClass,
      getSuggestionSubtitle,
      getApproveButtonText,
      scanForSuggestions,
      approveSuggestion,
      rejectSuggestion,
      // Scheduled
      scheduledEmails,
      loadScheduled,
      cancelScheduled,
      // Analytics
      analyticsData,
      loadAnalytics,
      getBarWidth,
      // Draft versions
      draftVersions,
      showVersionHistory,
      loadDraftVersions
    }
  }
}
</script>
