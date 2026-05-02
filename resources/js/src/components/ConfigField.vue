<template>
  <div class="config-field">
    <label :for="fieldId" class="block text-sm font-medium text-gray-300 mb-1">
      {{ formatLabel(config.key) }}
    </label>
    <p v-if="config.description" class="text-xs text-gray-400 mb-2">
      {{ config.description }}
    </p>

    <!-- String Input -->
    <input
      v-if="config.data_type === 'string'"
      :id="fieldId"
      type="text"
      :value="modelValue"
      @input="handleInput"
      :class="inputClasses"
      class="form-input"
    />

    <!-- Number Input -->
    <input
      v-else-if="config.data_type === 'number'"
      :id="fieldId"
      type="number"
      :value="modelValue"
      @input="handleInput"
      :class="inputClasses"
      class="form-input"
      step="any"
    />

    <!-- Boolean Toggle -->
    <div v-else-if="config.data_type === 'boolean'" class="flex items-center">
      <button
        type="button"
        :id="fieldId"
        @click="toggleBoolean"
        :class="toggleClasses"
        role="switch"
        :aria-checked="modelValue"
      >
        <span
          :class="[
            modelValue ? 'translate-x-5' : 'translate-x-0',
            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out'
          ]"
        />
      </button>
      <span class="ml-3 text-sm">
        <span class="font-medium text-gray-100">{{ modelValue ? 'Enabled' : 'Disabled' }}</span>
      </span>
    </div>

    <!-- JSON Textarea -->
    <textarea
      v-else-if="config.data_type === 'json'"
      :id="fieldId"
      :value="jsonValue"
      @input="handleJsonInput"
      :class="inputClasses"
      class="form-textarea"
      rows="4"
    />
    <p v-if="config.data_type === 'json' && jsonError" class="mt-1 text-xs text-red-600">
      {{ jsonError }}
    </p>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  config: {
    type: Object,
    required: true
  },
  modelValue: {
    required: true
  }
});

const emit = defineEmits(['update:modelValue']);

const jsonError = ref('');

const fieldId = computed(() => `config-${props.config.id}`);

const inputClasses = computed(() => {
  return 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm';
});

const toggleClasses = computed(() => {
  return [
    props.modelValue ? 'bg-primary-600' : 'bg-gray-200',
    'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2'
  ];
});

const jsonValue = computed(() => {
  if (props.config.data_type === 'json') {
    return typeof props.modelValue === 'string'
      ? props.modelValue
      : JSON.stringify(props.modelValue, null, 2);
  }
  return '';
});

function formatLabel(key) {
  return key
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

function handleInput(event) {
  let value = event.target.value;

  if (props.config.data_type === 'number') {
    value = parseFloat(value);
    if (isNaN(value)) value = 0;
  }

  emit('update:modelValue', value);
}

function handleJsonInput(event) {
  const value = event.target.value;
  jsonError.value = '';

  try {
    const parsed = JSON.parse(value);
    emit('update:modelValue', parsed);
  } catch (e) {
    jsonError.value = 'Invalid JSON format';
    emit('update:modelValue', value); // Still emit the string value
  }
}

function toggleBoolean() {
  emit('update:modelValue', !props.modelValue);
}
</script>

<style scoped>
.form-input,
.form-textarea {
  @apply mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm;
}
</style>
