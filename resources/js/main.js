import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './src/router';
import App from './src/App.vue';
import '../css/app.css';
import '../css/decision-desk.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.mount('#app');
