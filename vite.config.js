import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/main.js'],
      refresh: true,
    }),
    vue(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './resources/js/src'),
    },
  },
  build: {
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      output: {
        manualChunks: {
          // Vendor chunks - split large dependencies
          'vendor-vue': ['vue', 'vue-router', 'pinia'],
          'vendor-vueflow': ['@vue-flow/core', '@vue-flow/background', '@vue-flow/controls', '@vue-flow/minimap'],
          'vendor-calendar': ['@fullcalendar/core', '@fullcalendar/daygrid', '@fullcalendar/timegrid', '@fullcalendar/list', '@fullcalendar/interaction', '@fullcalendar/vue3'],
          'vendor-d3': ['d3'],
          'vendor-markdown': ['markdown-it', 'marked', 'dompurify'],
          'vendor-topola': ['topola'],
          'vendor-pdf': ['pdfjs-dist'],
        },
      },
    },
  },
  server: {
    host: '0.0.0.0', // Bind to all network interfaces
    port: 5173,
    strictPort: true,
    hmr: {
      host: '0.0.0.0', // Allow HMR from any interface
      protocol: 'ws',
      port: 5173,
    },
    cors: {
      origin: true, // Allow all origins
      credentials: true,
    },
    watch: {
      usePolling: false,
    },
  },
});
