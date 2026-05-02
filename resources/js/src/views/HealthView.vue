<template>
  <div class="min-h-screen bg-[#1a1a1a]">
    <div class="max-w-7xl mx-auto px-4 py-6">
      <h2 class="text-3xl font-bold mb-6 text-[#e0e0e0] border-b-2 border-[#e74c3c] pb-2">Health Management</h2>

      <!-- Coming Soon Notice -->
      <div class="bg-gradient-to-br from-[#e74c3c] to-[#c0392b] border border-[#e74c3c] rounded-lg p-6 mb-6 shadow-lg">
        <div class="flex items-start gap-4">
          <svg class="w-12 h-12 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <div class="flex-1">
            <h3 class="text-xl font-semibold text-white mb-2">Coming in Phase 1.5</h3>
            <p class="text-white opacity-90 mb-4">The Health Management hub will help you track medications, appointments, and health metrics. This feature is currently in development.</p>
            <div class="bg-[#2d2d2d] rounded-lg p-4 border border-[#444]">
              <h4 class="font-semibold text-[#e0e0e0] mb-2">Planned Features:</h4>
              <ul class="space-y-2 text-[#95a5a6]">
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Medication tracker with reminders and refill alerts</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Doctor appointment manager with prep checklists</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Health metrics dashboard (blood pressure, weight, glucose)</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Emergency medical information quick-access</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Insurance card and policy information storage</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
      </div>

      <!-- Placeholder Content -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="card border-l-4 border-[#e74c3c]">
          <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Medications</h3>
          <p class="text-[#95a5a6] text-center py-8">Medication tracking will appear here</p>
        </div>

        <div class="card border-l-4 border-accent">
          <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Appointments</h3>
          <p class="text-[#95a5a6] text-center py-8">Doctor appointments will appear here</p>
        </div>

        <div class="card border-l-4 border-[#27ae60]">
          <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Health Metrics</h3>
          <p class="text-[#95a5a6] text-center py-8">Health tracking will appear here</p>
        </div>
      </div>

      <!-- Implementation Timeline -->
      <div class="mt-6 card">
        <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Implementation Timeline</h3>
        <div class="space-y-2">
          <p class="text-[#e0e0e0]"><strong class="text-[#e74c3c]">Week 3-4:</strong> Core health tracking features</p>
          <p class="text-[#e0e0e0]"><strong class="text-[#e74c3c]">Priority:</strong> CRITICAL - Health is top priority at 60</p>
          <p class="text-[#e0e0e0]"><strong class="text-[#e74c3c]">Estimated Effort:</strong> Large (1 week)</p>
          <p class="text-[#95a5a6] mt-4 text-sm">See <code class="bg-[#1a1a1a] px-2 py-1 rounded text-[#e0e0e0]">docs/README.md</code> for the public documentation map.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
// Stub view - implementation coming in Phase 1.5
</script>
