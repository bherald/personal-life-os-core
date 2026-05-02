<template>
  <div class="min-h-screen bg-[#1a1a1a]">
    <div class="max-w-7xl mx-auto px-4 py-6">
      <h2 class="text-3xl font-bold mb-6 text-[#e0e0e0] border-b-2 border-[#27ae60] pb-2">Finance & Bills</h2>

      <!-- Coming Soon Notice -->
      <div class="bg-gradient-to-br from-[#27ae60] to-[#229954] border border-[#27ae60] rounded-lg p-6 mb-6 shadow-lg">
        <div class="flex items-start gap-4">
          <svg class="w-12 h-12 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <div class="flex-1">
            <h3 class="text-xl font-semibold text-white mb-2">Coming in Phase 1.5</h3>
            <p class="text-white opacity-90 mb-4">The Financial Management hub will help you track bills, expenses, and important financial dates. This feature is currently in development.</p>
            <div class="bg-[#2d2d2d] rounded-lg p-4 border border-[#444]">
              <h4 class="font-semibold text-[#e0e0e0] mb-2">Planned Features:</h4>
              <ul class="space-y-2 text-[#95a5a6]">
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Payment calendar with auto-reminders (3 days, 1 day, overdue)</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Email auto-categorization for bills (detect amounts, due dates)</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Monthly spending dashboard with budget tracking</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Subscription tracking (find and cancel forgotten services)</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Tax document checklist (W-2, 1099, deductions throughout year)</span>
              </li>
              <li class="flex items-start gap-2">
                <svg class="w-5 h-5 text-[#27ae60] mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                <span>Important reminders (insurance renewals, retirement RMDs)</span>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Placeholder Content -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="card border-l-4 border-[#27ae60]">
          <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Bills Due</h3>
          <p class="text-[#95a5a6] text-center py-8">Payment tracking will appear here</p>
        </div>

        <div class="card border-l-4 border-accent">
          <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Monthly Budget</h3>
          <p class="text-[#95a5a6] text-center py-8">Budget tracking will appear here</p>
        </div>

        <div class="card border-l-4 border-[#f39c12]">
          <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Subscriptions</h3>
          <p class="text-[#95a5a6] text-center py-8">Subscription management will appear here</p>
        </div>
      </div>

      <!-- Implementation Timeline -->
      <div class="mt-6 card">
        <h3 class="text-lg font-semibold mb-4 text-[#e0e0e0]">Implementation Timeline</h3>
        <div class="space-y-2">
          <p class="text-[#e0e0e0]"><strong class="text-[#27ae60]">Week 3-4:</strong> Financial tracking and bill management</p>
          <p class="text-[#e0e0e0]"><strong class="text-[#27ae60]">Priority:</strong> High - Financial security critical</p>
          <p class="text-[#e0e0e0]"><strong class="text-[#27ae60]">Estimated Effort:</strong> Large (1 week)</p>
          <p class="text-[#95a5a6] mt-4 text-sm">See <code class="bg-[#1a1a1a] px-2 py-1 rounded text-[#e0e0e0]">docs/README.md</code> for the public documentation map.</p>
        </div>
      </div>
    </div>
  </div>
</div>
</template>

<script setup>
// Stub view - implementation coming in Phase 1.5
</script>
