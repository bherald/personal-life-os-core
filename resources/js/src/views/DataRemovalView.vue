<template>
  <div class="min-h-screen bg-[#1a1a1a]">
    <div class="max-w-7xl mx-auto px-4 py-6">
      <!-- Header -->
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-[#e0e0e0] border-b-2 border-accent pb-2">Privacy Protection</h2>
        <div class="flex gap-2">
          <button @click="refreshData" class="btn-secondary" :disabled="loading">
            <svg class="w-4 h-4 inline mr-1" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
            </svg>
            Refresh
          </button>
          <button @click="triggerScan" class="btn-primary" :disabled="scanning">
            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
            {{ scanning ? 'Scanning...' : 'Run Scan' }}
          </button>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="card border-l-4 border-accent">
          <p class="text-sm text-[#95a5a6]">Protected People</p>
          <p class="text-2xl font-bold text-[#e0e0e0]">{{ stats.subjects?.total || 0 }}</p>
        </div>
        <div class="card border-l-4 border-[#9b59b6]">
          <p class="text-sm text-[#95a5a6]">Data Brokers</p>
          <p class="text-2xl font-bold text-[#e0e0e0]">{{ stats.brokers?.total || 0 }}</p>
        </div>
        <div class="card border-l-4 border-[#f39c12]">
          <p class="text-sm text-[#95a5a6]">Pending Removals</p>
          <p class="text-2xl font-bold text-[#e0e0e0]">{{ stats.requests?.pending || 0 }}</p>
        </div>
        <div class="card border-l-4 border-[#27ae60]">
          <p class="text-sm text-[#95a5a6]">Verified Removed</p>
          <p class="text-2xl font-bold text-[#e0e0e0]">{{ stats.requests?.verified_removed || 0 }}</p>
        </div>
        <div class="card border-l-4 border-[#e74c3c]">
          <p class="text-sm text-[#95a5a6]">Needs Review</p>
          <p class="text-2xl font-bold text-[#e0e0e0]">{{ stats.requests?.needs_review || 0 }}</p>
        </div>
      </div>

      <!-- Tabs -->
      <div class="border-b border-[#444] mb-6">
        <nav class="flex space-x-4">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            class="px-4 py-2 text-sm font-medium transition-colors"
            :class="activeTab === tab.id ? 'text-accent border-b-2 border-accent' : 'text-[#95a5a6] hover:text-[#e0e0e0]'"
          >
            {{ tab.name }}
            <span v-if="tab.count" class="ml-1 px-2 py-0.5 rounded-full text-xs" :class="tab.countClass">
              {{ tab.count }}
            </span>
          </button>
        </nav>
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
        <p class="mt-2 text-[#95a5a6]">Loading data...</p>
      </div>

      <!-- Tab Content -->
      <div v-else>
        <!-- Subjects Tab -->
        <div v-if="activeTab === 'subjects'">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-[#e0e0e0]">Protected People</h3>
            <button @click="openSubjectModal()" class="btn-primary">
              <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
              </svg>
              Add Person
            </button>
          </div>

          <div v-if="subjects.length === 0" class="text-center py-12 bg-[#2d2d2d] rounded-lg border-2 border-[#444]">
            <svg class="w-16 h-16 mx-auto text-[#95a5a6] mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            <p class="text-[#e0e0e0] font-medium">No people to protect yet</p>
            <p class="text-sm text-[#95a5a6] mt-2">Add family members to start protecting their privacy</p>
            <button @click="openSubjectModal()" class="btn-primary mt-4">Add First Person</button>
          </div>

          <div v-else class="space-y-4">
            <div v-for="subject in subjects" :key="subject.id" class="bg-[#2d2d2d] rounded-lg border-2 border-[#444] p-4 hover:border-accent transition">
              <div class="flex justify-between items-start">
                <div>
                  <h4 class="text-lg font-bold text-[#e0e0e0]">{{ subject.name }}</h4>
                  <div class="flex items-center gap-4 mt-2 text-sm text-[#95a5a6]">
                    <span v-if="subject.email">{{ subject.email }}</span>
                    <span v-if="subject.city && subject.state">{{ subject.city }}, {{ subject.state }}</span>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <span v-if="!subject.is_active" class="badge-warning">Inactive</span>
                  <button @click="openSubjectModal(subject)" class="btn-icon" title="Edit">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                  </button>
                  <button @click="confirmDeleteSubject(subject)" class="btn-icon text-[#e74c3c]" title="Delete">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Brokers Tab -->
        <div v-if="activeTab === 'brokers'">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-[#e0e0e0]">Data Brokers ({{ brokers.length }})</h3>
            <div class="flex gap-2">
              <select v-model="brokerFilter" class="form-select-sm">
                <option value="">All Categories</option>
                <option value="people_search">People Search</option>
                <option value="background_check">Background Check</option>
                <option value="data_aggregator">Data Aggregator</option>
                <option value="marketing">Marketing</option>
              </select>
              <button @click="openBrokerModal()" class="btn-primary">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add Broker
              </button>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="broker in filteredBrokers" :key="broker.id" class="bg-[#2d2d2d] rounded-lg border-2 border-[#444] p-4 hover:border-accent transition">
              <div class="flex justify-between items-start mb-2">
                <div class="flex items-center gap-2">
                  <span class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
                        :class="broker.health_status === 'healthy' ? 'bg-[#27ae60]' : broker.health_status === 'degraded' ? 'bg-[#f39c12]' : broker.health_status === 'broken' ? 'bg-[#e74c3c]' : 'bg-[#95a5a6]'"
                        :title="'Health: ' + (broker.health_status || 'unknown')"></span>
                  <div>
                    <h4 class="font-bold text-[#e0e0e0]">{{ broker.name }}</h4>
                    <p class="text-sm text-[#95a5a6]">{{ broker.domain }}</p>
                  </div>
                </div>
                <span class="text-xs px-2 py-1 rounded" :class="tierClass(broker.automation_tier)" :title="tierDescription(broker.automation_tier)">
                  T{{ broker.automation_tier }}: {{ tierLabel(broker.automation_tier) }}
                </span>
              </div>
              <div class="flex items-center gap-2 text-xs text-[#95a5a6] mt-2">
                <span class="badge-category">{{ formatCategory(broker.category) }}</span>
                <span>{{ broker.removal_method }}</span>
              </div>
              <div class="flex justify-between items-center mt-3 pt-3 border-t border-[#444]">
                <div class="text-xs text-[#95a5a6]">
                  Success: {{ broker.success_rate }}%
                </div>
                <div class="flex gap-1">
                  <button @click="openBrokerModal(broker)" class="btn-icon-sm" title="Edit">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Requests Tab -->
        <div v-if="activeTab === 'requests'">
          <!-- Relisting Alert Banner -->
          <div v-if="relistings.length > 0" class="mb-4 bg-[#e74c3c]/10 border-2 border-[#e74c3c] rounded-lg p-4 flex items-center gap-3">
            <span class="text-2xl">&#9888;</span>
            <div>
              <div class="font-bold text-[#e74c3c]">{{ relistings.length }} Relisting{{ relistings.length > 1 ? 's' : '' }} Detected</div>
              <div class="text-sm text-[#95a5a6]">Previously removed data has reappeared on {{ relistings.map(r => r.broker_name).join(', ') }}</div>
            </div>
          </div>

          <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-[#e0e0e0]">Removal Requests</h3>
            <select v-model="requestFilter" class="form-select-sm">
              <option value="">All Status</option>
              <option value="pending">Pending</option>
              <option value="submitted">Submitted</option>
              <option value="awaiting_confirmation">Awaiting Confirmation</option>
              <option value="confirmed">Confirmed</option>
              <option value="verified_removed">Verified Removed</option>
              <option value="failed">Failed</option>
              <option value="reappeared">Reappeared</option>
            </select>
          </div>

          <div v-if="filteredRequests.length === 0" class="text-center py-12 bg-[#2d2d2d] rounded-lg border-2 border-[#444]">
            <p class="text-[#95a5a6]">No removal requests found</p>
          </div>

          <div v-else class="space-y-3">
            <div v-for="request in filteredRequests" :key="request.id" class="bg-[#2d2d2d] rounded-lg border-2 border-[#444] p-4">
              <div class="flex justify-between items-start">
                <div>
                  <div class="flex items-center gap-2">
                    <span class="font-bold text-[#e0e0e0]">{{ request.subject_name }}</span>
                    <span class="text-[#95a5a6]">on</span>
                    <span class="font-medium text-accent">{{ request.broker_name }}</span>
                  </div>
                  <div class="flex items-center gap-3 mt-2 text-sm text-[#95a5a6]">
                    <span :class="statusClass(request.status)">{{ formatStatus(request.status) }}</span>
                    <span :class="tierClass(request.automation_tier)" class="px-2 py-0.5 rounded text-xs" :title="tierDescription(request.automation_tier)">
                      Tier {{ request.automation_tier }} - {{ tierLabel(request.automation_tier) }}
                    </span>
                    <span v-if="request.ai_confidence">AI: {{ request.ai_confidence }}%</span>
                  </div>
                  <!-- Data to be submitted for removal (privacy-filtered) -->
                  <div class="mt-3 p-2 bg-[#252525] rounded border border-[#3d3d3d]">
                    <div class="flex justify-between items-center mb-1.5">
                      <span class="text-xs text-[#7f8c8d] font-medium">Data to be submitted:</span>
                      <button v-if="request.status === 'pending'" @click="openFieldsModal(request)" class="text-xs text-accent hover:text-[#5dade2]" title="Configure which data to submit">
                        Privacy Controls
                      </button>
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                      <!-- Only show fields that will actually be submitted -->
                      <template v-for="field in getRequestFieldsToSubmit(request)" :key="field">
                        <span v-if="field === 'name'" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-accent" title="Full Name">{{ request.subject_name }}</span>
                        <span v-else-if="field === 'email' && request.subject_email" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#9b59b6]" title="Email Address">{{ request.subject_email }}</span>
                        <span v-else-if="field === 'phone' && request.subject_phone" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#1abc9c]" title="Phone Number">{{ request.subject_phone }}</span>
                        <span v-else-if="field === 'address' && request.subject_address" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="Street Address">{{ request.subject_address }}</span>
                        <span v-else-if="field === 'city' && request.subject_city" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="City">{{ request.subject_city }}</span>
                        <span v-else-if="field === 'state' && request.subject_state" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="State">{{ request.subject_state }}</span>
                        <span v-else-if="field === 'zip' && request.subject_zip" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="ZIP">{{ request.subject_zip }}</span>
                        <span v-else-if="field === 'dob' && request.subject_dob" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e74c3c]" title="Date of Birth">DOB: {{ formatDate(request.subject_dob) }}</span>
                        <span v-else-if="field === 'aliases' && request.subject_aliases" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#f39c12]" title="Known Aliases">{{ formatAliases(request.subject_aliases) }}</span>
                      </template>
                    </div>
                    <!-- Show submitted indicator if already submitted -->
                    <div v-if="request.fields_submitted" class="mt-2 text-xs text-[#27ae60]">
                      Submitted: {{ JSON.parse(request.fields_submitted).map(f => getFieldLabel(f)).join(', ') }}
                    </div>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <button v-if="request.status === 'pending'" @click="openFieldsModal(request)" class="btn-icon" title="Privacy Controls">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                  </button>
                  <button v-if="request.status === 'pending'" @click="submitRequest(request)" class="btn-success-sm" :disabled="submitting === request.id">
                    {{ submitting === request.id ? 'Submitting...' : 'Submit' }}
                  </button>
                  <button v-if="['submitted', 'confirmed'].includes(request.status)" @click="verifyRemoval(request)" class="btn-primary-sm">
                    Verify
                  </button>
                  <button @click="viewRequestActivity(request)" class="btn-icon" title="View Activity">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Review Queue Tab -->
        <div v-if="activeTab === 'review'">
          <h3 class="text-lg font-medium text-[#e0e0e0] mb-4">Items Requiring Review</h3>

          <div v-if="reviewQueue.length === 0" class="text-center py-12 bg-[#2d2d2d] rounded-lg border-2 border-[#444]">
            <svg class="w-16 h-16 mx-auto text-[#27ae60] mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-[#e0e0e0] font-medium">All caught up!</p>
            <p class="text-sm text-[#95a5a6] mt-2">No items require manual review at this time</p>
          </div>

          <div v-else class="space-y-4">
            <div v-for="item in reviewQueue" :key="item.id" class="bg-[#2d2d2d] rounded-lg border-2 border-[#f39c12] p-4">
              <div class="flex justify-between items-start">
                <div>
                  <div class="flex items-center gap-2">
                    <span class="font-bold text-[#e0e0e0]">{{ item.subject_name }}</span>
                    <span class="text-[#95a5a6]">on</span>
                    <span class="font-medium text-accent">{{ item.broker_name }}</span>
                  </div>
                  <!-- Data to be submitted for removal (privacy-filtered) -->
                  <div class="mt-3 p-2 bg-[#252525] rounded border border-[#3d3d3d]">
                    <div class="flex justify-between items-center mb-1.5">
                      <span class="text-xs text-[#7f8c8d] font-medium">Data to be submitted:</span>
                      <button @click="openFieldsModal(item)" class="text-xs text-accent hover:text-[#5dade2]" title="Configure which data to submit">
                        Privacy Controls
                      </button>
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                      <!-- Only show fields that will actually be submitted -->
                      <template v-for="field in getRequestFieldsToSubmit(item)" :key="field">
                        <span v-if="field === 'name'" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-accent" title="Full Name">{{ item.subject_name }}</span>
                        <span v-else-if="field === 'email' && item.subject_email" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#9b59b6]" title="Email Address">{{ item.subject_email }}</span>
                        <span v-else-if="field === 'phone' && item.subject_phone" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#1abc9c]" title="Phone Number">{{ item.subject_phone }}</span>
                        <span v-else-if="field === 'address' && item.subject_address" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="Street Address">{{ item.subject_address }}</span>
                        <span v-else-if="field === 'city' && item.subject_city" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="City">{{ item.subject_city }}</span>
                        <span v-else-if="field === 'state' && item.subject_state" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="State">{{ item.subject_state }}</span>
                        <span v-else-if="field === 'zip' && item.subject_zip" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e67e22]" title="ZIP">{{ item.subject_zip }}</span>
                        <span v-else-if="field === 'dob' && item.subject_dob" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#e74c3c]" title="Date of Birth">DOB: {{ formatDate(item.subject_dob) }}</span>
                        <span v-else-if="field === 'aliases' && item.subject_aliases" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-[#f39c12]" title="Known Aliases">{{ formatAliases(item.subject_aliases) }}</span>
                      </template>
                    </div>
                    <!-- Show submitted indicator if already submitted -->
                    <div v-if="item.fields_submitted" class="mt-2 text-xs text-[#27ae60]">
                      Submitted: {{ JSON.parse(item.fields_submitted).map(f => getFieldLabel(f)).join(', ') }}
                    </div>
                  </div>
                  <p v-if="item.ai_notes" class="text-sm text-[#95a5a6] mt-2">{{ item.ai_notes }}</p>
                </div>
                <div class="flex gap-2">
                  <button @click="approveReview(item)" class="btn-success-sm">Approve</button>
                  <button @click="rejectReview(item)" class="btn-danger-sm">Reject</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Tools Tab -->
        <!-- Analytics Tab -->
        <div v-if="activeTab === 'analytics'">
          <div v-if="!analyticsData" class="text-center py-12 text-[#95a5a6]">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            <p class="mt-2">Loading analytics...</p>
          </div>
          <div v-else class="space-y-6">
            <!-- Effectiveness Table -->
            <div class="bg-[#2d2d2d] rounded-lg border-2 border-[#444]">
              <div class="p-4 border-b border-[#444]">
                <h3 class="text-lg font-medium text-[#e0e0e0]">Broker Effectiveness</h3>
              </div>
              <table class="min-w-full">
                <thead class="bg-[#1a1a1a]">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-[#95a5a6] uppercase">Broker</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-[#95a5a6] uppercase">Total</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-[#95a5a6] uppercase">Success</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-[#95a5a6] uppercase">Failed</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-[#95a5a6] uppercase">Rate</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-[#95a5a6] uppercase">Avg Days</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[#444]">
                  <tr v-for="eff in analyticsData.effectiveness" :key="eff.broker_id" class="hover:bg-[#333]">
                    <td class="px-4 py-3 text-sm text-[#e0e0e0]">{{ eff.broker_name }}</td>
                    <td class="px-4 py-3 text-sm text-[#95a5a6]">{{ eff.total_requests }}</td>
                    <td class="px-4 py-3 text-sm text-[#27ae60]">{{ eff.successful }}</td>
                    <td class="px-4 py-3 text-sm text-[#e74c3c]">{{ eff.failed }}</td>
                    <td class="px-4 py-3 text-sm" :class="eff.total_requests > 0 && eff.successful / eff.total_requests > 0.7 ? 'text-[#27ae60]' : 'text-[#f39c12]'">
                      {{ eff.total_requests > 0 ? Math.round(eff.successful / eff.total_requests * 100) : 0 }}%
                    </td>
                    <td class="px-4 py-3 text-sm text-[#95a5a6]">{{ eff.avg_days ? Math.round(eff.avg_days) : '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Timeline -->
            <div class="bg-[#2d2d2d] rounded-lg border-2 border-[#444] p-4">
              <h3 class="text-lg font-medium text-[#e0e0e0] mb-4">Submission Timeline ({{ analyticsData.period_days }}d)</h3>
              <div class="space-y-1">
                <div v-for="day in (analyticsData.timeline || []).slice(-14)" :key="day.date" class="flex items-center gap-3">
                  <span class="text-xs text-[#95a5a6] w-20">{{ day.date }}</span>
                  <div class="flex-1 bg-[#444] rounded-full h-3 overflow-hidden">
                    <div class="bg-accent h-full rounded-full" :style="{ width: getAnalyticsBarWidth(day.submitted, analyticsData.timeline) }"></div>
                  </div>
                  <span class="text-xs text-[#95a5a6] w-10 text-right">{{ day.submitted }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'tools'">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Sync BADBOOL Card -->
            <div class="bg-[#2d2d2d] rounded-lg border-2 border-[#9b59b6] p-6">
              <div class="flex items-start gap-4">
                <div class="p-3 bg-[#9b59b6] rounded-lg">
                  <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                  </svg>
                </div>
                <div class="flex-1">
                  <h3 class="text-xl font-bold text-[#e0e0e0] mb-2">BADBOOL Sync</h3>
                  <p class="text-[#95a5a6] mb-4">
                    Sync with the BADBOOL data broker database to discover new brokers and update existing entries.
                  </p>
                  <button @click="syncBadbool" :disabled="syncingBadbool"
                    class="px-4 py-2 bg-[#9b59b6] text-white rounded-lg hover:bg-[#8e44ad] disabled:opacity-50 transition">
                    {{ syncingBadbool ? 'Syncing...' : 'Sync Now' }}
                  </button>
                </div>
              </div>
            </div>

            <!-- Browser Extension Card -->
            <div class="bg-[#2d2d2d] rounded-lg border-2 border-accent p-6">
              <div class="flex items-start gap-4">
                <div class="p-3 bg-accent rounded-lg">
                  <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                  </svg>
                </div>
                <div class="flex-1">
                  <h3 class="text-xl font-bold text-[#e0e0e0] mb-2">Firefox Extension</h3>
                  <p class="text-[#95a5a6] mb-4">
                    Install the Data Removal Assistant extension to help complete removal requests that require manual action (CAPTCHA, etc.).
                    The extension auto-fills forms and guides you through the process.
                  </p>
                  <div class="space-y-3">
                    <a
                      href="/browser-extensions/firefox-data-removal-assistant.xpi"
                      download
                      class="inline-flex items-center gap-2 px-4 py-2 bg-accent hover:bg-accent-dark text-white rounded-lg transition"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                      </svg>
                      Download Extension
                    </a>
                    <div class="text-sm text-[#7f8c8d]">
                      <p class="font-medium text-[#e74c3c] mb-2">Note: Use "Temporary Add-on" method below (Firefox blocks unsigned extensions)</p>
                      <p class="font-medium text-[#95a5a6] mb-1">For Firefox Developer Edition:</p>
                      <ol class="list-decimal list-inside space-y-1">
                        <li>Download the extension file (.xpi)</li>
                        <li>Open Firefox, go to <code class="bg-[#1a1a1a] px-1 rounded">about:config</code></li>
                        <li>Set <code class="bg-[#1a1a1a] px-1 rounded">xpinstall.signatures.required</code> to <code class="text-[#27ae60]">false</code></li>
                        <li>Go to <code class="bg-[#1a1a1a] px-1 rounded">about:addons</code> → gear icon → "Install Add-on From File..."</li>
                        <li>Configure your server URL in extension settings</li>
                      </ol>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Temporary Add-on Card (Recommended) -->
            <div class="bg-[#2d2d2d] rounded-lg border-2 border-[#27ae60] p-6">
              <div class="flex items-start gap-4">
                <div class="p-3 bg-[#27ae60] rounded-lg">
                  <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                </div>
                <div class="flex-1">
                  <h3 class="text-xl font-bold text-[#e0e0e0] mb-2">Temporary Add-on <span class="text-sm font-normal text-[#27ae60]">(Recommended)</span></h3>
                  <p class="text-[#95a5a6] mb-4">
                    Load the extension as a temporary add-on. Works on all Firefox versions. Extension stays active until Firefox restarts.
                  </p>
                  <div class="text-sm text-[#7f8c8d]">
                    <p class="font-medium text-[#95a5a6] mb-1">Steps:</p>
                    <ol class="list-decimal list-inside space-y-1">
                      <li>Open Firefox, go to <code class="bg-[#1a1a1a] px-1 rounded">about:debugging#/runtime/this-firefox</code></li>
                      <li>Click "Load Temporary Add-on..."</li>
                      <li>Navigate to the extension folder on your server</li>
                      <li>Select <code class="bg-[#1a1a1a] px-1 rounded">manifest.json</code> from the extension folder</li>
                      <li>The extension icon will appear in toolbar - click to configure server URL</li>
                    </ol>
                    <p class="mt-2 text-[#f39c12]">Path: <code class="bg-[#1a1a1a] px-1 rounded text-xs">&lt;repo&gt;/browser-extensions/firefox-data-removal-assistant/</code></p>
                  </div>
                </div>
              </div>
            </div>

            <!-- How It Works Card -->
            <div class="bg-[#2d2d2d] rounded-lg border-2 border-[#27ae60] p-6 md:col-span-2">
              <h3 class="text-xl font-bold text-[#e0e0e0] mb-4">How the Extension Works</h3>
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="text-center p-4">
                  <div class="w-12 h-12 mx-auto mb-2 rounded-full bg-[#27ae60] flex items-center justify-center text-white font-bold text-xl">1</div>
                  <p class="text-[#e0e0e0] font-medium">View Tasks</p>
                  <p class="text-sm text-[#7f8c8d] mt-1">See pending removals that need manual action</p>
                </div>
                <div class="text-center p-4">
                  <div class="w-12 h-12 mx-auto mb-2 rounded-full bg-[#27ae60] flex items-center justify-center text-white font-bold text-xl">2</div>
                  <p class="text-[#e0e0e0] font-medium">Navigate to Site</p>
                  <p class="text-sm text-[#7f8c8d] mt-1">Extension opens the broker's removal page</p>
                </div>
                <div class="text-center p-4">
                  <div class="w-12 h-12 mx-auto mb-2 rounded-full bg-[#27ae60] flex items-center justify-center text-white font-bold text-xl">3</div>
                  <p class="text-[#e0e0e0] font-medium">Auto-Fill & Solve</p>
                  <p class="text-sm text-[#7f8c8d] mt-1">Form fields are filled; you solve the CAPTCHA</p>
                </div>
                <div class="text-center p-4">
                  <div class="w-12 h-12 mx-auto mb-2 rounded-full bg-[#27ae60] flex items-center justify-center text-white font-bold text-xl">4</div>
                  <p class="text-[#e0e0e0] font-medium">Mark Complete</p>
                  <p class="text-sm text-[#7f8c8d] mt-1">Confirm submission to update the system</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Subject Modal -->
      <div v-if="showSubjectModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-[#2d2d2d] rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <h3 class="text-xl font-bold text-[#e0e0e0] mb-4">
              {{ editingSubject ? 'Edit Person' : 'Add Person to Protect' }}
            </h3>

            <form @submit.prevent="saveSubject">
              <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <div class="col-span-2">
                    <label class="label">Full Name *</label>
                    <input v-model="subjectForm.name" type="text" class="form-input" required>
                  </div>
                  <div>
                    <label class="label">Email</label>
                    <input v-model="subjectForm.email" type="email" class="form-input">
                  </div>
                  <div>
                    <label class="label">Phone</label>
                    <input v-model="subjectForm.phone" type="tel" class="form-input">
                  </div>
                  <div class="col-span-2">
                    <label class="label">Address Line 1</label>
                    <input v-model="subjectForm.address_line1" type="text" class="form-input">
                  </div>
                  <div class="col-span-2">
                    <label class="label">Address Line 2</label>
                    <input v-model="subjectForm.address_line2" type="text" class="form-input">
                  </div>
                  <div>
                    <label class="label">City</label>
                    <input v-model="subjectForm.city" type="text" class="form-input">
                  </div>
                  <div>
                    <label class="label">State</label>
                    <input v-model="subjectForm.state" type="text" class="form-input" maxlength="2">
                  </div>
                  <div>
                    <label class="label">ZIP</label>
                    <input v-model="subjectForm.zip" type="text" class="form-input">
                  </div>
                  <div>
                    <label class="label">Date of Birth</label>
                    <input v-model="subjectForm.date_of_birth" type="date" class="form-input">
                  </div>
                </div>

                <div>
                  <label class="label">Notes</label>
                  <textarea v-model="subjectForm.notes" class="form-textarea" rows="3"></textarea>
                </div>

                <div class="flex items-center">
                  <input v-model="subjectForm.is_active" type="checkbox" id="subject_active" class="form-checkbox">
                  <label for="subject_active" class="ml-2 text-[#e0e0e0]">Active (include in scans)</label>
                </div>
              </div>

              <div class="flex justify-end gap-3 mt-6">
                <button type="button" @click="showSubjectModal = false" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="savingSubject">
                  {{ savingSubject ? 'Saving...' : (editingSubject ? 'Update' : 'Create') }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Broker Modal -->
      <div v-if="showBrokerModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-[#2d2d2d] rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <h3 class="text-xl font-bold text-[#e0e0e0] mb-4">
              {{ editingBroker ? 'Edit Data Broker' : 'Add Data Broker' }}
            </h3>

            <form @submit.prevent="saveBroker">
              <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="label">Name *</label>
                    <input v-model="brokerForm.name" type="text" class="form-input" required>
                  </div>
                  <div>
                    <label class="label">Domain *</label>
                    <input v-model="brokerForm.domain" type="text" class="form-input" required placeholder="example.com">
                  </div>
                  <div>
                    <label class="label">Category</label>
                    <select v-model="brokerForm.category" class="form-select">
                      <option value="people_search">People Search</option>
                      <option value="background_check">Background Check</option>
                      <option value="data_aggregator">Data Aggregator</option>
                      <option value="marketing">Marketing</option>
                      <option value="other">Other</option>
                    </select>
                  </div>
                  <div>
                    <label class="label">Removal Method</label>
                    <select v-model="brokerForm.removal_method" class="form-select">
                      <option value="web_form">Web Form</option>
                      <option value="email">Email</option>
                      <option value="api">API</option>
                      <option value="postal">Postal Mail</option>
                      <option value="phone">Phone</option>
                      <option value="unknown">Unknown</option>
                    </select>
                  </div>
                  <div class="col-span-2">
                    <label class="label">Removal URL</label>
                    <input v-model="brokerForm.removal_url" type="url" class="form-input">
                  </div>
                  <div>
                    <label class="label">Removal Email</label>
                    <input v-model="brokerForm.removal_email" type="email" class="form-input">
                  </div>
                  <div>
                    <label class="label">Automation Tier</label>
                    <select v-model="brokerForm.automation_tier" class="form-select">
                      <option :value="1">Tier 1 - Fully Automated</option>
                      <option :value="2">Tier 2 - AI Review</option>
                      <option :value="3">Tier 3 - Manual</option>
                    </select>
                  </div>
                </div>

                <div class="flex items-center gap-4">
                  <label class="flex items-center">
                    <input v-model="brokerForm.requires_captcha" type="checkbox" class="form-checkbox">
                    <span class="ml-2 text-[#e0e0e0]">Requires CAPTCHA</span>
                  </label>
                  <label class="flex items-center">
                    <input v-model="brokerForm.requires_auth" type="checkbox" class="form-checkbox">
                    <span class="ml-2 text-[#e0e0e0]">Requires Auth</span>
                  </label>
                  <label class="flex items-center">
                    <input v-model="brokerForm.uses_javascript" type="checkbox" class="form-checkbox">
                    <span class="ml-2 text-[#e0e0e0]">Uses JavaScript</span>
                  </label>
                </div>
              </div>

              <div class="flex justify-end gap-3 mt-6">
                <button type="button" @click="showBrokerModal = false" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="savingBroker">
                  {{ savingBroker ? 'Saving...' : (editingBroker ? 'Update' : 'Create') }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Activity Modal -->
      <div v-if="showActivityModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-[#2d2d2d] rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xl font-bold text-[#e0e0e0]">Activity Log</h3>
              <button @click="showActivityModal = false" class="btn-icon">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <div v-if="loadingActivity" class="text-center py-8">
              <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-accent"></div>
            </div>

            <div v-else-if="activityLog.length === 0" class="text-center py-8 text-[#95a5a6]">
              No activity recorded yet
            </div>

            <div v-else class="space-y-3">
              <div v-for="activity in activityLog" :key="activity.id" class="border-l-2 border-[#444] pl-4 py-2">
                <div class="flex justify-between items-start">
                  <span class="font-medium text-[#e0e0e0]">{{ formatActivityType(activity.activity_type) }}</span>
                  <span class="text-xs text-[#95a5a6]">{{ formatDate(activity.created_at) }}</span>
                </div>
                <p v-if="activity.description" class="text-sm text-[#95a5a6] mt-1">{{ activity.description }}</p>
                <p v-if="activity.error_message" class="text-sm text-[#e74c3c] mt-1">{{ activity.error_message }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div v-if="showDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-[#2d2d2d] rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
          <h3 class="text-xl font-bold text-[#e0e0e0] mb-4">Confirm Delete</h3>
          <p class="text-[#95a5a6] mb-6">
            Are you sure you want to delete "<strong class="text-[#e0e0e0]">{{ deletingItem?.name }}</strong>"?
            This action cannot be undone.
          </p>
          <div class="flex justify-end gap-3">
            <button @click="showDeleteModal = false" class="btn-secondary">Cancel</button>
            <button @click="confirmDelete" class="btn-danger" :disabled="deleting">
              {{ deleting ? 'Deleting...' : 'Delete' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Field Selection Modal -->
      <div v-if="showFieldsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-[#2d2d2d] rounded-lg shadow-xl max-w-lg w-full mx-4">
          <div class="p-6">
            <h3 class="text-xl font-bold text-[#e0e0e0] mb-2">Privacy Controls</h3>
            <p class="text-sm text-[#95a5a6] mb-4">Select which personal data to submit for this removal request. Only checked fields will be sent to the data broker.</p>

            <div class="space-y-3">
              <div class="text-sm text-[#7f8c8d] mb-2">
                <span class="text-[#e74c3c]">*</span> Required by broker |
                <span class="text-[#f39c12]">~</span> Optional (improves matching)
              </div>

              <div v-for="field in allFields" :key="field.key" class="flex items-center gap-3">
                <input
                  type="checkbox"
                  :id="'field-' + field.key"
                  :checked="selectedFields.includes(field.key)"
                  :disabled="field.key === 'name' || !fieldAvailable(field.key)"
                  @change="toggleField(field.key)"
                  class="form-checkbox"
                >
                <label :for="'field-' + field.key" class="flex-1">
                  <span class="text-[#e0e0e0]">{{ field.label }}</span>
                  <span v-if="fieldIsRequired(field.key)" class="text-[#e74c3c] ml-1">*</span>
                  <span v-else-if="fieldIsOptional(field.key)" class="text-[#f39c12] ml-1">~</span>
                  <span v-if="!fieldAvailable(field.key)" class="text-[#7f8c8d] ml-2 text-xs">(no data)</span>
                  <span v-else class="text-[#95a5a6] ml-2 text-xs">{{ getFieldPreview(field.key) }}</span>
                </label>
              </div>
            </div>

            <div class="mt-4 p-3 bg-[#252525] rounded border border-[#3d3d3d]">
              <div class="text-xs text-[#7f8c8d] mb-1">Fields that will be submitted:</div>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="field in selectedFields" :key="field" class="bg-[#3d3d3d] px-2 py-0.5 rounded text-xs text-[#27ae60]">
                  {{ getFieldLabel(field) }}
                </span>
              </div>
            </div>

            <div class="flex justify-end gap-3 mt-6">
              <button @click="showFieldsModal = false" class="btn-secondary">Cancel</button>
              <button @click="saveFieldSelection" class="btn-primary" :disabled="savingFields">
                {{ savingFields ? 'Saving...' : 'Save & Close' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';

// State
const loading = ref(true);
const scanning = ref(false);
const activeTab = ref('subjects');
const stats = ref({});
const subjects = ref([]);
const brokers = ref([]);
const requests = ref([]);
const reviewQueue = ref([]);
const activityLog = ref([]);

// Filters
const brokerFilter = ref('');
const requestFilter = ref('');

// Modals
const showSubjectModal = ref(false);
const showBrokerModal = ref(false);
const showActivityModal = ref(false);
const showDeleteModal = ref(false);
const showFieldsModal = ref(false);
const editingSubject = ref(null);
const editingBroker = ref(null);
const deletingItem = ref(null);
const deleteType = ref('');
const editingRequest = ref(null);

// Loading states
const savingSubject = ref(false);
const savingBroker = ref(false);
const deleting = ref(false);
const submitting = ref(null);
const loadingActivity = ref(false);
const savingFields = ref(false);

// Analytics & monitoring state
const relistings = ref([]);
const analyticsData = ref(null);
const syncingBadbool = ref(false);

// Field selection state
const selectedFields = ref(['name']);
const brokerRequiredFields = ref(['name']);
const brokerOptionalFields = ref([]);
const allFields = [
  { key: 'name', label: 'Full Name' },
  { key: 'email', label: 'Email Address' },
  { key: 'phone', label: 'Phone Number' },
  { key: 'address', label: 'Street Address' },
  { key: 'city', label: 'City' },
  { key: 'state', label: 'State' },
  { key: 'zip', label: 'ZIP Code' },
  { key: 'dob', label: 'Date of Birth' },
  { key: 'aliases', label: 'Known Aliases' },
];

// Forms
const subjectForm = reactive({
  name: '',
  email: '',
  phone: '',
  address_line1: '',
  address_line2: '',
  city: '',
  state: '',
  zip: '',
  date_of_birth: '',
  notes: '',
  is_active: true,
});

const brokerForm = reactive({
  name: '',
  domain: '',
  category: 'people_search',
  removal_method: 'web_form',
  removal_url: '',
  removal_email: '',
  automation_tier: 2,
  requires_captcha: false,
  requires_auth: false,
  uses_javascript: true,
});

// Computed
const tabs = computed(() => [
  { id: 'subjects', name: 'Protected People', count: subjects.value.length, countClass: 'bg-accent text-white' },
  { id: 'brokers', name: 'Data Brokers', count: brokers.value.length, countClass: 'bg-[#9b59b6] text-white' },
  { id: 'requests', name: 'Removal Requests', count: requests.value.length, countClass: 'bg-[#34495e] text-white' },
  { id: 'review', name: 'Review Queue', count: reviewQueue.value.length, countClass: reviewQueue.value.length > 0 ? 'bg-[#f39c12] text-white' : 'bg-[#34495e] text-white' },
  { id: 'analytics', name: 'Analytics', count: null, countClass: '' },
  { id: 'tools', name: 'Tools', count: null, countClass: '' },
]);

const filteredBrokers = computed(() => {
  if (!brokerFilter.value) return brokers.value;
  return brokers.value.filter(b => b.category === brokerFilter.value);
});

const filteredRequests = computed(() => {
  if (!requestFilter.value) return requests.value;
  return requests.value.filter(r => r.status === requestFilter.value);
});

// Methods
const fetchData = async () => {
  loading.value = true;
  try {
    const [statsRes, subjectsRes, brokersRes, requestsRes, reviewRes] = await Promise.all([
      fetch('/api/data-removal/stats'),
      fetch('/api/data-removal/subjects'),
      fetch('/api/data-removal/brokers'),
      fetch('/api/data-removal/requests'),
      fetch('/api/data-removal/review-queue'),
    ]);

    const statsData = await statsRes.json();
    stats.value = statsData.data || {};
    const subjectsData = await subjectsRes.json();
    subjects.value = subjectsData.data || [];
    const brokersData = await brokersRes.json();
    brokers.value = brokersData.data || [];
    const requestsData = await requestsRes.json();
    requests.value = requestsData.data || [];
    const reviewData = await reviewRes.json();
    reviewQueue.value = reviewData.data || [];
  } catch (error) {
    console.error('Failed to fetch data:', error);
  }
  loading.value = false;
};

const refreshData = () => {
  fetchData();
  loadRelistings();
};

// Analytics & monitoring
const loadRelistings = async () => {
  try {
    const response = await fetch('/api/data-removal/relistings');
    const data = await response.json();
    if (data.success) relistings.value = data.data || [];
  } catch (err) { console.error('Failed to load relistings:', err); }
};

const loadAnalytics = async () => {
  try {
    const response = await fetch('/api/data-removal/analytics?days=90');
    const data = await response.json();
    if (data.success) analyticsData.value = data.data;
  } catch (err) { console.error('Failed to load analytics:', err); }
};

const syncBadbool = async () => {
  syncingBadbool.value = true;
  try {
    const response = await fetch('/api/data-removal/tools/sync-badbool', { method: 'POST', headers: { 'Content-Type': 'application/json' } });
    const data = await response.json();
    if (data.success) alert(`Sync complete: ${data.data.existing_brokers} brokers in database`);
  } catch (err) { console.error('Failed to sync BADBOOL:', err); }
  finally { syncingBadbool.value = false; }
};

const getAnalyticsBarWidth = (count, days) => {
  if (!days?.length) return '0%';
  const max = Math.max(...days.map(d => d.submitted || 0));
  return max > 0 ? `${(count / max) * 100}%` : '0%';
};

const triggerScan = async () => {
  scanning.value = true;
  try {
    const response = await fetch('/api/data-removal/scan', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({})  // Empty body - will scan all active subjects
    });
    const data = await response.json();
    if (response.ok && data.success) {
      alert('Data removal scan queued. Refresh the dashboard after the long-running worker completes.');
    } else {
      alert('Failed to trigger scan: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Failed to trigger scan:', error);
    alert('Failed to trigger scan: ' + error.message);
  } finally {
    scanning.value = false;
  }
};

// Subject methods
const openSubjectModal = (subject = null) => {
  editingSubject.value = subject;
  if (subject) {
    Object.assign(subjectForm, {
      name: subject.name || '',
      email: subject.email || '',
      phone: subject.phone || '',
      address_line1: subject.address_line1 || '',
      address_line2: subject.address_line2 || '',
      city: subject.city || '',
      state: subject.state || '',
      zip: subject.zip || '',
      date_of_birth: subject.date_of_birth || '',
      notes: subject.notes || '',
      is_active: subject.is_active ?? true,
    });
  } else {
    Object.assign(subjectForm, {
      name: '', email: '', phone: '', address_line1: '', address_line2: '',
      city: '', state: '', zip: '', date_of_birth: '', notes: '', is_active: true,
    });
  }
  showSubjectModal.value = true;
};

const saveSubject = async () => {
  savingSubject.value = true;
  try {
    const url = editingSubject.value
      ? `/api/data-removal/subjects/${editingSubject.value.id}`
      : '/api/data-removal/subjects';
    const method = editingSubject.value ? 'PUT' : 'POST';

    const response = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(subjectForm),
    });

    if (response.ok) {
      showSubjectModal.value = false;
      await fetchData();
    } else {
      const error = await response.json();
      alert(error.error || 'Failed to save');
    }
  } catch (error) {
    console.error('Failed to save subject:', error);
  }
  savingSubject.value = false;
};

const confirmDeleteSubject = (subject) => {
  deletingItem.value = subject;
  deleteType.value = 'subject';
  showDeleteModal.value = true;
};

// Broker methods
const openBrokerModal = (broker = null) => {
  editingBroker.value = broker;
  if (broker) {
    Object.assign(brokerForm, {
      name: broker.name || '',
      domain: broker.domain || '',
      category: broker.category || 'people_search',
      removal_method: broker.removal_method || 'web_form',
      removal_url: broker.removal_url || '',
      removal_email: broker.removal_email || '',
      automation_tier: broker.automation_tier || 2,
      requires_captcha: broker.requires_captcha ?? false,
      requires_auth: broker.requires_auth ?? false,
      uses_javascript: broker.uses_javascript ?? true,
    });
  } else {
    Object.assign(brokerForm, {
      name: '', domain: '', category: 'people_search', removal_method: 'web_form',
      removal_url: '', removal_email: '', automation_tier: 2,
      requires_captcha: false, requires_auth: false, uses_javascript: true,
    });
  }
  showBrokerModal.value = true;
};

const saveBroker = async () => {
  savingBroker.value = true;
  try {
    const url = editingBroker.value
      ? `/api/data-removal/brokers/${editingBroker.value.id}`
      : '/api/data-removal/brokers';
    const method = editingBroker.value ? 'PUT' : 'POST';

    const response = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(brokerForm),
    });

    if (response.ok) {
      showBrokerModal.value = false;
      await fetchData();
    } else {
      const error = await response.json();
      alert(error.error || 'Failed to save');
    }
  } catch (error) {
    console.error('Failed to save broker:', error);
  }
  savingBroker.value = false;
};

// Request methods
const submitRequest = async (request) => {
  submitting.value = request.id;
  try {
    const response = await fetch(`/api/data-removal/requests/${request.id}/submit`, {
      method: 'POST',
      headers: { 'Accept': 'application/json' }
    });
    const data = await response.json();

    if (response.ok && data.success) {
      // Success - show confirmation
      alert(`Success! Removal request submitted to ${request.broker_name}`);
      await fetchData();
    } else if (data.data?.requires_email) {
      // Email-based removal - offer to open email client
      const email = data.data.removal_email;
      const subject = encodeURIComponent(`Data Removal Request - ${request.subject_name}`);
      const body = encodeURIComponent(
        `To Whom It May Concern,\n\n` +
        `I am requesting the removal of my personal information from your database.\n\n` +
        `Name: ${request.subject_name}\n` +
        (request.subject_city ? `City: ${request.subject_city}\n` : '') +
        (request.subject_state ? `State: ${request.subject_state}\n` : '') +
        `\nPlease confirm once my data has been removed.\n\n` +
        `Thank you.`
      );

      if (confirm(`${request.broker_name} requires email removal.\n\nEmail: ${email}\n\nClick OK to open your email client with a pre-filled message, or Cancel to copy the email address.`)) {
        window.open(`mailto:${email}?subject=${subject}&body=${body}`, '_blank');
      } else {
        // Copy email to clipboard
        navigator.clipboard.writeText(email).then(() => {
          alert(`Email address copied to clipboard: ${email}`);
        }).catch(() => {
          alert(`Email address: ${email}`);
        });
      }
      await fetchData();
    } else if (data.data?.needs_captcha) {
      // CAPTCHA required - open the broker's removal page automatically
      const brokerUrl = data.data.removal_url || request.broker_removal_url || `https://${request.broker_domain}`;
      alert(`${request.broker_name} requires CAPTCHA verification.\n\nOpening the removal page now. Use the Data Removal Assistant browser extension to help complete the form.`);
      window.open(brokerUrl, '_blank');
      await fetchData();
    } else if (data.data?.requires_research) {
      // No removal URL
      alert(`No removal URL configured for ${request.broker_name}.\n\nMarked for manual research. You may need to search for their opt-out page.`);
      await fetchData();
    } else {
      // Other error
      const errorMsg = data.error || data.data?.error || 'Unknown error';
      alert(`Failed to submit: ${errorMsg}`);
      await fetchData();
    }
  } catch (error) {
    console.error('Failed to submit request:', error);
    alert('Network error: ' + error.message);
  }
  submitting.value = null;
};

const verifyRemoval = async (request) => {
  try {
    const response = await fetch(`/api/data-removal/requests/${request.id}/verify`, { method: 'POST' });
    if (response.ok) {
      await fetchData();
    } else {
      alert('Failed to verify removal');
    }
  } catch (error) {
    console.error('Failed to verify:', error);
  }
};

const viewRequestActivity = async (request) => {
  showActivityModal.value = true;
  loadingActivity.value = true;
  try {
    const response = await fetch(`/api/data-removal/requests/${request.id}/activity`);
    const data = await response.json();
    activityLog.value = data.activity || [];
  } catch (error) {
    console.error('Failed to load activity:', error);
    activityLog.value = [];
  }
  loadingActivity.value = false;
};

// Field selection methods
const openFieldsModal = (request) => {
  editingRequest.value = request;

  // Parse broker required/optional fields
  try {
    brokerRequiredFields.value = JSON.parse(request.broker_required_fields || '["name"]') || ['name'];
  } catch {
    brokerRequiredFields.value = ['name'];
  }
  try {
    brokerOptionalFields.value = JSON.parse(request.broker_optional_fields || '[]') || [];
  } catch {
    brokerOptionalFields.value = [];
  }

  // Set initially selected fields
  if (request.fields_to_submit) {
    try {
      selectedFields.value = JSON.parse(request.fields_to_submit) || [...brokerRequiredFields.value, ...brokerOptionalFields.value];
    } catch {
      selectedFields.value = [...brokerRequiredFields.value, ...brokerOptionalFields.value];
    }
  } else {
    // Default to broker required + optional
    selectedFields.value = [...brokerRequiredFields.value, ...brokerOptionalFields.value];
  }

  // Ensure 'name' is always included
  if (!selectedFields.value.includes('name')) {
    selectedFields.value.push('name');
  }

  showFieldsModal.value = true;
};

const fieldAvailable = (fieldKey) => {
  if (!editingRequest.value) return false;
  const fieldMap = {
    name: 'subject_name',
    email: 'subject_email',
    phone: 'subject_phone',
    address: 'subject_address',
    city: 'subject_city',
    state: 'subject_state',
    zip: 'subject_zip',
    dob: 'subject_dob',
    aliases: 'subject_aliases',
  };
  const dbField = fieldMap[fieldKey];
  return dbField && editingRequest.value[dbField];
};

const fieldIsRequired = (fieldKey) => brokerRequiredFields.value.includes(fieldKey);
const fieldIsOptional = (fieldKey) => brokerOptionalFields.value.includes(fieldKey);

const getFieldLabel = (fieldKey) => {
  const field = allFields.find(f => f.key === fieldKey);
  return field ? field.label : fieldKey;
};

const getFieldPreview = (fieldKey) => {
  if (!editingRequest.value) return '';
  const fieldMap = {
    name: 'subject_name',
    email: 'subject_email',
    phone: 'subject_phone',
    address: 'subject_address',
    city: 'subject_city',
    state: 'subject_state',
    zip: 'subject_zip',
    dob: 'subject_dob',
    aliases: 'subject_aliases',
  };
  const value = editingRequest.value[fieldMap[fieldKey]];
  if (!value) return '';
  if (fieldKey === 'dob') return formatDate(value);
  if (fieldKey === 'aliases') return formatAliases(value);
  // Truncate long values
  const str = String(value);
  return str.length > 30 ? str.substring(0, 30) + '...' : str;
};

const toggleField = (fieldKey) => {
  if (fieldKey === 'name') return; // Name is always required
  const idx = selectedFields.value.indexOf(fieldKey);
  if (idx >= 0) {
    selectedFields.value.splice(idx, 1);
  } else {
    selectedFields.value.push(fieldKey);
  }
};

const saveFieldSelection = async () => {
  if (!editingRequest.value) return;
  savingFields.value = true;
  try {
    const response = await fetch(`/api/data-removal/requests/${editingRequest.value.id}/fields`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ fields: selectedFields.value }),
    });
    if (response.ok) {
      await fetchData();
      showFieldsModal.value = false;
    } else {
      alert('Failed to save field selection');
    }
  } catch (error) {
    console.error('Failed to save fields:', error);
    alert('Failed to save field selection');
  }
  savingFields.value = false;
};

// Get fields that will be submitted for a request (for display)
const getRequestFieldsToSubmit = (request) => {
  // If user has selected fields, use those
  if (request.fields_to_submit) {
    try {
      return JSON.parse(request.fields_to_submit);
    } catch {
      // Fall through to defaults
    }
  }
  // Otherwise use broker defaults
  let fields = ['name'];
  try {
    const required = JSON.parse(request.broker_required_fields || '["name"]') || ['name'];
    const optional = JSON.parse(request.broker_optional_fields || '[]') || [];
    fields = [...new Set([...required, ...optional])];
  } catch {
    // Keep default
  }
  return fields;
};

// Review methods
const approveReview = async (item) => {
  try {
    const response = await fetch(`/api/data-removal/requests/${item.id}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'approve' }),
    });
    if (response.ok) {
      await fetchData();
    }
  } catch (error) {
    console.error('Failed to approve:', error);
  }
};

const rejectReview = async (item) => {
  try {
    const response = await fetch(`/api/data-removal/requests/${item.id}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'reject' }),
    });
    if (response.ok) {
      await fetchData();
    }
  } catch (error) {
    console.error('Failed to reject:', error);
  }
};

// Delete methods
const confirmDelete = async () => {
  deleting.value = true;
  try {
    const url = deleteType.value === 'subject'
      ? `/api/data-removal/subjects/${deletingItem.value.id}`
      : `/api/data-removal/brokers/${deletingItem.value.id}`;

    const response = await fetch(url, { method: 'DELETE' });
    if (response.ok) {
      showDeleteModal.value = false;
      await fetchData();
    } else {
      alert('Failed to delete');
    }
  } catch (error) {
    console.error('Failed to delete:', error);
  }
  deleting.value = false;
};

// Formatters
const formatCategory = (category) => {
  const map = {
    people_search: 'People Search',
    background_check: 'Background Check',
    data_aggregator: 'Data Aggregator',
    marketing: 'Marketing',
    other: 'Other',
  };
  return map[category] || category;
};

const formatStatus = (status) => {
  const map = {
    pending: 'Pending',
    submitted: 'Submitted',
    awaiting_confirmation: 'Awaiting Confirmation',
    confirmed: 'Confirmed',
    verified_removed: 'Verified Removed',
    failed: 'Failed',
    reappeared: 'Reappeared',
  };
  return map[status] || status;
};

const formatActivityType = (type) => {
  const map = {
    discovered: 'Discovered',
    analyzed: 'Analyzed',
    submitted: 'Submitted',
    email_sent: 'Email Sent',
    email_received: 'Email Received',
    captcha_solved: 'CAPTCHA Solved',
    verification_started: 'Verification Started',
    verified: 'Verified',
    failed: 'Failed',
    reappeared: 'Reappeared',
    manual_action: 'Manual Action',
    ai_decision: 'AI Decision',
    followup_sent: 'Follow-up Sent',
  };
  return map[type] || type;
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  // For DOB, just show date without time
  if (dateStr.length === 10 || !dateStr.includes('T')) {
    return date.toLocaleDateString();
  }
  return date.toLocaleString();
};

const formatAliases = (aliasesJson) => {
  if (!aliasesJson) return '';
  try {
    const aliases = typeof aliasesJson === 'string' ? JSON.parse(aliasesJson) : aliasesJson;
    if (Array.isArray(aliases)) {
      return aliases.join(', ');
    }
    return String(aliases);
  } catch {
    return String(aliasesJson);
  }
};

const tierClass = (tier) => {
  switch (tier) {
    case 1: return 'bg-[#27ae60] text-white';
    case 2: return 'bg-[#f39c12] text-white';
    case 3: return 'bg-[#e74c3c] text-white';
    default: return 'bg-[#95a5a6] text-white';
  }
};

const tierLabel = (tier) => {
  switch (tier) {
    case 1: return 'Auto';
    case 2: return 'AI Assist';
    case 3: return 'Manual';
    default: return 'Unknown';
  }
};

const tierDescription = (tier) => {
  switch (tier) {
    case 1: return 'Tier 1: Fully automated - No human intervention needed';
    case 2: return 'Tier 2: AI-assisted - May need browser automation or review';
    case 3: return 'Tier 3: Manual - Requires human action (CAPTCHA, email, etc.)';
    default: return 'Unknown tier';
  }
};

const statusClass = (status) => {
  const classes = {
    pending: 'text-[#f39c12]',
    submitted: 'text-accent',
    awaiting_confirmation: 'text-[#9b59b6]',
    confirmed: 'text-[#27ae60]',
    verified_removed: 'text-[#27ae60] font-bold',
    failed: 'text-[#e74c3c]',
    reappeared: 'text-[#e74c3c] font-bold',
  };
  return classes[status] || 'text-[#95a5a6]';
};

// Lifecycle
watch(activeTab, (tab) => {
  if (tab === 'analytics' && !analyticsData.value) loadAnalytics();
  if (tab === 'requests' && relistings.value.length === 0) loadRelistings();
});

onMounted(() => {
  fetchData();
  loadRelistings();
});
</script>

<style scoped>
.card {
  @apply bg-[#2d2d2d] rounded-lg p-4 shadow-lg border border-[#444];
}

.label {
  @apply block text-sm font-medium text-[#95a5a6] mb-1;
}

.form-input {
  @apply w-full px-4 py-2 bg-[#1a1a1a] border border-[#444] rounded-lg text-[#e0e0e0] focus:ring-2 focus:ring-accent focus:border-transparent;
}

.form-textarea {
  @apply w-full px-4 py-2 bg-[#1a1a1a] border border-[#444] rounded-lg text-[#e0e0e0] focus:ring-2 focus:ring-accent focus:border-transparent resize-y;
}

.form-select {
  @apply w-full px-4 py-2 bg-[#1a1a1a] border border-[#444] rounded-lg text-[#e0e0e0] focus:ring-2 focus:ring-accent focus:border-transparent;
}

.form-select-sm {
  @apply px-3 py-1 bg-[#1a1a1a] border border-[#444] rounded text-sm text-[#e0e0e0] focus:ring-2 focus:ring-accent focus:border-transparent;
}

.form-checkbox {
  @apply w-4 h-4 text-accent bg-[#1a1a1a] border-[#444] rounded focus:ring-accent;
}

.btn-primary {
  @apply px-4 py-2 bg-accent text-white rounded-lg hover:bg-accent-dark transition font-medium disabled:opacity-50;
}

.btn-primary-sm {
  @apply px-3 py-1 bg-accent text-white rounded text-sm hover:bg-accent-dark transition;
}

.btn-secondary {
  @apply px-4 py-2 bg-[#34495e] text-[#e0e0e0] rounded-lg hover:bg-[#2c3e50] transition disabled:opacity-50;
}

.btn-danger {
  @apply px-4 py-2 bg-[#e74c3c] text-white rounded-lg hover:bg-[#c0392b] transition disabled:opacity-50;
}

.btn-icon {
  @apply p-2 rounded-lg hover:bg-[#34495e] transition text-[#95a5a6] hover:text-[#e0e0e0];
}

.btn-icon-sm {
  @apply p-1 rounded hover:bg-[#34495e] transition text-[#95a5a6] hover:text-[#e0e0e0];
}

.btn-success-sm {
  @apply px-3 py-1 bg-[#27ae60] text-white rounded text-sm hover:bg-[#229954] transition disabled:opacity-50;
}

.btn-danger-sm {
  @apply px-3 py-1 bg-[#e74c3c] text-white rounded text-sm hover:bg-[#c0392b] transition disabled:opacity-50;
}

.badge-warning {
  @apply px-2 py-0.5 bg-[#f39c12] text-white rounded-full text-xs font-medium;
}

.badge-category {
  @apply px-2 py-0.5 bg-[#34495e] text-[#e0e0e0] rounded text-xs;
}
</style>
