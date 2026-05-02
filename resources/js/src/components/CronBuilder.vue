<template>
  <div class="space-y-4">
    <div class="flex items-center gap-4">
      <button
        type="button"
        @click="showBuilder = !showBuilder"
        class="text-sm px-3 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded transition"
      >
        {{ showBuilder ? 'Hide Builder' : 'Show Builder' }}
      </button>
      <span class="text-xs text-gray-400">Current: {{ cronExpression || 'Not set' }}</span>
    </div>

    <!-- Visual Builder -->
    <div v-if="showBuilder" class="bg-gray-50 p-4 rounded-lg border border-gray-200 space-y-4">
      <!-- Preset Schedules -->
      <div>
        <label class="block text-sm font-medium text-gray-300 mb-2">Quick Presets</label>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="preset in presets"
            :key="preset.value"
            type="button"
            @click="applyPreset(preset)"
            class="px-3 py-1 text-sm bg-white border border-gray-300 rounded hover:bg-gray-100 transition"
          >
            {{ preset.label }}
          </button>
        </div>
      </div>

      <!-- Custom Builder -->
      <div class="grid grid-cols-5 gap-3">
        <!-- Minute -->
        <div>
          <label class="block text-xs font-medium text-gray-300 mb-1">Minute</label>
          <select v-model="parts.minute" @change="updateCron" class="w-full px-2 py-1 text-sm border border-gray-300 rounded">
            <option value="*">Every minute (*)</option>
            <option value="0">:00</option>
            <option value="15">:15</option>
            <option value="30">:30</option>
            <option value="45">:45</option>
            <option value="*/5">Every 5 min</option>
            <option value="*/10">Every 10 min</option>
            <option value="*/15">Every 15 min</option>
            <option value="*/30">Every 30 min</option>
          </select>
        </div>

        <!-- Hour -->
        <div>
          <label class="block text-xs font-medium text-gray-300 mb-1">Hour</label>
          <select v-model="parts.hour" @change="updateCron" class="w-full px-2 py-1 text-sm border border-gray-300 rounded">
            <option value="*">Every hour (*)</option>
            <option v-for="h in 24" :key="h-1" :value="h-1">{{ String(h-1).padStart(2, '0') }}:00</option>
            <option value="*/2">Every 2 hours</option>
            <option value="*/3">Every 3 hours</option>
            <option value="*/6">Every 6 hours</option>
            <option value="*/12">Every 12 hours</option>
          </select>
        </div>

        <!-- Day of Month -->
        <div>
          <label class="block text-xs font-medium text-gray-300 mb-1">Day</label>
          <select v-model="parts.day" @change="updateCron" class="w-full px-2 py-1 text-sm border border-gray-300 rounded">
            <option value="*">Every day (*)</option>
            <option v-for="d in 31" :key="d" :value="d">{{ d }}</option>
            <option value="*/2">Every 2 days</option>
            <option value="1,15">1st & 15th</option>
          </select>
        </div>

        <!-- Month -->
        <div>
          <label class="block text-xs font-medium text-gray-300 mb-1">Month</label>
          <select v-model="parts.month" @change="updateCron" class="w-full px-2 py-1 text-sm border border-gray-300 rounded">
            <option value="*">Every month (*)</option>
            <option value="1">January</option>
            <option value="2">February</option>
            <option value="3">March</option>
            <option value="4">April</option>
            <option value="5">May</option>
            <option value="6">June</option>
            <option value="7">July</option>
            <option value="8">August</option>
            <option value="9">September</option>
            <option value="10">October</option>
            <option value="11">November</option>
            <option value="12">December</option>
          </select>
        </div>

        <!-- Day of Week -->
        <div>
          <label class="block text-xs font-medium text-gray-300 mb-1">Weekday</label>
          <select v-model="parts.dayOfWeek" @change="updateCron" class="w-full px-2 py-1 text-sm border border-gray-300 rounded">
            <option value="*">Every day (*)</option>
            <option value="1">Monday</option>
            <option value="2">Tuesday</option>
            <option value="3">Wednesday</option>
            <option value="4">Thursday</option>
            <option value="5">Friday</option>
            <option value="6">Saturday</option>
            <option value="0">Sunday</option>
            <option value="1-5">Weekdays</option>
            <option value="0,6">Weekends</option>
          </select>
        </div>
      </div>

      <!-- Description -->
      <div class="text-sm text-gray-400 bg-white p-3 rounded border border-gray-200">
        <strong>Description:</strong> {{ description }}
      </div>
    </div>

    <!-- Manual Input -->
    <div>
      <label class="block text-sm font-medium text-gray-300 mb-1">
        Manual Cron Expression
        <span class="text-xs text-gray-400 font-normal">(minute hour day month weekday)</span>
      </label>
      <input
        :value="cronExpression"
        @input="handleManualInput"
        type="text"
        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent font-mono text-sm"
        placeholder="0 0 * * *"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['update:modelValue']);

const showBuilder = ref(false);
const parts = ref({
  minute: '*',
  hour: '*',
  day: '*',
  month: '*',
  dayOfWeek: '*'
});

const presets = [
  { label: 'Every minute', value: '* * * * *' },
  { label: 'Every hour', value: '0 * * * *' },
  { label: 'Daily at midnight', value: '0 0 * * *' },
  { label: 'Daily at noon', value: '0 12 * * *' },
  { label: 'Weekly (Sunday)', value: '0 0 * * 0' },
  { label: 'Monthly (1st)', value: '0 0 1 * *' },
  { label: 'Weekdays at 9 AM', value: '0 9 * * 1-5' },
  { label: 'Every 15 minutes', value: '*/15 * * * *' },
];

const cronExpression = computed(() => {
  return props.modelValue || '';
});

const description = computed(() => {
  const m = parts.value.minute;
  const h = parts.value.hour;
  const d = parts.value.day;
  const mon = parts.value.month;
  const dow = parts.value.dayOfWeek;

  let desc = 'Runs ';

  // Minute
  if (m === '*') desc += 'every minute ';
  else if (m.startsWith('*/')) desc += `every ${m.slice(2)} minutes `;
  else desc += `at minute ${m} `;

  // Hour
  if (h === '*') desc += 'of every hour ';
  else if (h.startsWith('*/')) desc += `of every ${h.slice(2)} hours `;
  else desc += `of hour ${h} `;

  // Day
  if (d === '*') desc += 'every day ';
  else if (d.startsWith('*/')) desc += `every ${d.slice(2)} days `;
  else if (d.includes(',')) desc += `on days ${d} `;
  else desc += `on day ${d} `;

  // Month
  if (mon !== '*') {
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    desc += `in ${monthNames[parseInt(mon) - 1]} `;
  }

  // Day of Week
  if (dow !== '*') {
    const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    if (dow === '1-5') desc += 'on weekdays';
    else if (dow === '0,6') desc += 'on weekends';
    else if (dow.includes(',')) desc += `on ${dow.split(',').map(d => dayNames[d]).join(', ')}`;
    else desc += `on ${dayNames[dow]}`;
  }

  return desc.trim();
});

// Parse initial value
watch(() => props.modelValue, (newVal) => {
  if (newVal) {
    const p = newVal.split(' ');
    if (p.length === 5) {
      parts.value = {
        minute: p[0],
        hour: p[1],
        day: p[2],
        month: p[3],
        dayOfWeek: p[4]
      };
    }
  }
}, { immediate: true });

const updateCron = () => {
  const cron = `${parts.value.minute} ${parts.value.hour} ${parts.value.day} ${parts.value.month} ${parts.value.dayOfWeek}`;
  emit('update:modelValue', cron);
};

const applyPreset = (preset) => {
  emit('update:modelValue', preset.value);
};

const handleManualInput = (event) => {
  emit('update:modelValue', event.target.value);
};
</script>
