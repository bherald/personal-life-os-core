<template>
  <div class="space-y-2">
    <div
      v-for="item in items"
      :key="`${item.type}-${item.id}`"
      class="flex items-center gap-4 p-3 bg-theme-tertiary rounded-lg"
    >
      <span
        :class="[
          'px-2 py-1 rounded text-xs font-medium',
          item.type === 'person' ? 'bg-blue-500/20 text-blue-400' : 'bg-purple-500/20 text-purple-400'
        ]"
      >
        {{ item.type }}
      </span>
      <span class="text-theme-primary flex-1">{{ displayName(item) }}</span>
      <span class="text-theme-secondary text-sm">{{ formatDate(item.created_at) }}</span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  items: {
    type: Array,
    default: () => [],
  },
});

const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString();
};

const displayName = (item) => {
  const name = typeof item?.name === 'string' ? item.name.trim() : '';
  if (name) return name;

  return item?.type === 'person' ? 'Unnamed person' : 'Untitled media';
};
</script>
