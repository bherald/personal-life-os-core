<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
      <button
        v-for="surname in surnames"
        :key="surname.surname"
        type="button"
        class="min-w-0 px-3 py-2 rounded text-left transition-colors"
        :class="selectedSurname === surname.surname
          ? 'selected-item'
          : 'bg-theme-tertiary text-theme-primary hover:bg-theme-secondary'"
        @click="$emit('select-surname', surname.surname)"
      >
        <span class="block truncate font-medium">{{ surname.surname }}</span>
        <span class="block text-xs opacity-75">{{ surname.person_count }} people</span>
      </button>
    </div>

    <div v-if="persons.length > 0" class="space-y-2">
      <h4 class="font-semibold text-theme-primary">
        {{ selectedSurname }} Family ({{ persons.length }})
      </h4>
      <button
        v-for="person in persons"
        :key="person.id"
        type="button"
        class="flex w-full min-w-0 items-baseline gap-2 rounded-lg bg-theme-tertiary p-3 text-left transition-colors hover:bg-theme-secondary"
        @click="$emit('select-person', person)"
      >
        <span class="truncate font-medium text-theme-primary">{{ person.given_name }}</span>
        <span class="shrink-0 text-theme-secondary">{{ lifespan(person) }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  surnames: {
    type: Array,
    default: () => [],
  },
  selectedSurname: {
    type: String,
    default: null,
  },
  persons: {
    type: Array,
    default: () => [],
  },
});

defineEmits(['select-surname', 'select-person']);

const extractYear = (dateString) => dateString?.match(/\b(\d{4})\b/)?.[1] || '';

const lifespan = (person) => {
  const birthYear = extractYear(person.birth_date);
  const deathYear = extractYear(person.death_date);

  if (!birthYear && !deathYear) return '';
  if (!deathYear) return `(${birthYear})`;

  return `(${birthYear || '?'} - ${deathYear})`;
};
</script>
