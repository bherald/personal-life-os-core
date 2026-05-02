<template>
  <div class="json-viewer">
    <div class="flex justify-between items-center mb-2">
      <span class="text-sm font-medium text-gray-300">{{ label }}</span>
      <div class="flex gap-2">
        <button
          @click="toggleExpand"
          class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded transition"
        >
          {{ isExpanded ? 'Collapse' : 'Expand' }}
        </button>
        <button
          @click="copyToClipboard"
          class="text-xs px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded transition"
        >
          {{ copied ? '✓ Copied' : 'Copy' }}
        </button>
      </div>
    </div>

    <div
      v-if="isExpanded"
      class="bg-gray-50 rounded-lg p-3 overflow-x-auto border border-gray-200"
    >
      <pre class="text-xs text-gray-200 font-mono">{{ formattedJson }}</pre>
    </div>

    <div
      v-else
      class="bg-gray-50 rounded-lg p-3 border border-gray-200 cursor-pointer hover:bg-gray-100 transition"
      @click="toggleExpand"
    >
      <div class="text-xs text-gray-400 truncate">
        {{ preview }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  data: {
    type: [String, Object, Array],
    required: true
  },
  label: {
    type: String,
    default: 'JSON Data'
  },
  defaultExpanded: {
    type: Boolean,
    default: false
  }
});

const isExpanded = ref(props.defaultExpanded);
const copied = ref(false);

const parsedData = computed(() => {
  if (typeof props.data === 'string') {
    try {
      return JSON.parse(props.data);
    } catch {
      return props.data;
    }
  }
  return props.data;
});

const formattedJson = computed(() => {
  try {
    return JSON.stringify(parsedData.value, null, 2);
  } catch {
    return String(props.data);
  }
});

const preview = computed(() => {
  const str = formattedJson.value;
  if (str.length <= 100) return str;
  return str.substring(0, 100) + '...';
});

const toggleExpand = () => {
  isExpanded.value = !isExpanded.value;
};

const copyToClipboard = async () => {
  try {
    await navigator.clipboard.writeText(formattedJson.value);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy:', err);
  }
};
</script>

<style scoped>
.json-viewer pre {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
