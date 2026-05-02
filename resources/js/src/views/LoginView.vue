<template>
  <div class="min-h-screen flex items-center justify-center bg-theme-primary">
    <div class="card max-w-md w-full">
      <h1 class="text-2xl font-bold mb-6 text-center text-theme-primary">Personal Life OS</h1>

      <form @submit.prevent="handleLogin">
        <div class="mb-4">
          <label class="label">Master Password</label>
          <input
            v-model="password"
            type="password"
            class="input"
            placeholder="Enter master password"
            required
          />
        </div>

        <div v-if="error" class="mb-4 text-red-500 text-sm">
          {{ error }}
        </div>

        <button type="submit" class="btn-primary w-full" :disabled="loading">
          {{ loading ? 'Logging in...' : 'Login' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useThemeStore } from '../stores/theme';

const router = useRouter();
const authStore = useAuthStore();
const themeStore = useThemeStore();

const password = ref('');
const error = ref('');
const loading = ref(false);

// Initialize theme on login page (in case user lands here directly)
onMounted(() => {
  themeStore.init();
});

const handleLogin = async () => {
  loading.value = true;
  error.value = '';

  const result = await authStore.login(password.value);

  if (result.success) {
    router.push('/');
  } else {
    error.value = result.error;
  }

  loading.value = false;
};
</script>

<style scoped>
.bg-theme-primary {
  background-color: var(--color-bg-primary);
}
.text-theme-primary {
  color: var(--color-text-primary);
}
</style>
