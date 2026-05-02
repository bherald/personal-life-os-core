<template>
  <div class="node-config-form">
    <div v-if="schema" class="form-mode">
      <div class="form-header">
        <h4>{{ nodeType }} Configuration</h4>
        <button @click="toggleMode" class="btn-toggle" title="Switch to JSON mode">
          { } JSON
        </button>
      </div>

      <div class="form-fields">
        <div v-for="field in schema.fields" :key="field.name" class="form-field">
          <label :for="`field-${field.name}`">
            {{ field.label }}
            <span v-if="field.required" class="required">*</span>
          </label>

          <!-- Text Input -->
          <input
            v-if="field.type === 'text'"
            :id="`field-${field.name}`"
            v-model="formValues[field.name]"
            type="text"
            :placeholder="field.placeholder"
            @input="onFieldChange"
            class="form-input"
          />

          <!-- Number Input -->
          <input
            v-else-if="field.type === 'number'"
            :id="`field-${field.name}`"
            v-model.number="formValues[field.name]"
            type="number"
            :min="field.min"
            :max="field.max"
            :step="field.step"
            :placeholder="field.placeholder"
            @input="onFieldChange"
            class="form-input"
          />

          <!-- Textarea -->
          <textarea
            v-else-if="field.type === 'textarea'"
            :id="`field-${field.name}`"
            v-model="formValues[field.name]"
            :placeholder="field.placeholder"
            @input="onFieldChange"
            class="form-textarea"
            :rows="field.rows || 4"
          ></textarea>

          <!-- Select -->
          <select
            v-else-if="field.type === 'select'"
            :id="`field-${field.name}`"
            v-model="formValues[field.name]"
            @change="onFieldChange"
            class="form-select"
          >
            <option value="" disabled>Select {{ field.label }}</option>
            <option v-for="option in field.options" :key="option" :value="option">
              {{ option }}
            </option>
          </select>

          <!-- Boolean (Checkbox) -->
          <div v-else-if="field.type === 'boolean'" class="form-checkbox-wrapper">
            <input
              :id="`field-${field.name}`"
              v-model="formValues[field.name]"
              type="checkbox"
              @change="onFieldChange"
              class="form-checkbox"
            />
            <span class="checkbox-label">{{ field.placeholder || 'Enable' }}</span>
          </div>
        </div>
      </div>

      <!-- Validation Errors -->
      <div v-if="validationErrors.length > 0" class="validation-errors">
        <div v-for="(error, index) in validationErrors" :key="index" class="error-message">
          ⚠️ {{ error }}
        </div>
      </div>

      <!-- Form Actions -->
      <div class="form-actions">
        <button @click="resetForm" class="btn-reset">
          Reset to Defaults
        </button>
      </div>
    </div>

    <!-- JSON Mode Fallback -->
    <div v-else class="json-mode">
      <div class="json-header">
        <h4>Configuration (JSON)</h4>
        <p class="json-hint">No form schema available for this node type. Edit JSON directly.</p>
      </div>
      <textarea
        v-model="jsonConfig"
        @input="onJsonChange"
        rows="15"
        placeholder="{}"
        class="json-textarea"
      ></textarea>
      <button @click="formatJson" class="btn-format">
        Format JSON
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue';
import {
  getNodeSchema,
  validateNodeConfig,
  configToFormValues,
  formValuesToConfig
} from '../utils/nodeSchemas.js';

const props = defineProps({
  nodeType: {
    type: String,
    required: true
  },
  config: {
    type: String,
    required: true
  }
});

const emit = defineEmits(['update:config']);

// State
const schema = ref(null);
const formValues = ref({});
const jsonConfig = ref('{}');
const validationErrors = ref([]);
const mode = ref('form'); // 'form' or 'json'

// Load schema and initialize form
onMounted(() => {
  loadSchema();
});

// Watch for node type changes
watch(() => props.nodeType, () => {
  loadSchema();
});

// Watch for external config changes
watch(() => props.config, (newConfig) => {
  if (mode.value === 'json') {
    jsonConfig.value = newConfig;
  } else if (schema.value) {
    formValues.value = configToFormValues(props.nodeType, newConfig);
  }
});

function loadSchema() {
  schema.value = getNodeSchema(props.nodeType);

  if (schema.value) {
    mode.value = 'form';
    formValues.value = configToFormValues(props.nodeType, props.config);
  } else {
    mode.value = 'json';
    jsonConfig.value = props.config;
  }
}

function onFieldChange() {
  // Validate and emit updated config
  const validation = validateNodeConfig(props.nodeType, formValues.value);
  validationErrors.value = validation.errors;

  const newConfig = formValuesToConfig(formValues.value);
  emit('update:config', newConfig);
}

function onJsonChange() {
  emit('update:config', jsonConfig.value);
}

function resetForm() {
  if (confirm('Reset all fields to their default values?')) {
    formValues.value = configToFormValues(props.nodeType, '{}');
    onFieldChange();
  }
}

function toggleMode() {
  if (mode.value === 'form') {
    mode.value = 'json';
    jsonConfig.value = formValuesToConfig(formValues.value);
  } else {
    mode.value = 'form';
    try {
      formValues.value = configToFormValues(props.nodeType, jsonConfig.value);
    } catch (error) {
      alert('Invalid JSON. Please fix the JSON before switching to form mode.');
      return;
    }
  }
}

function formatJson() {
  try {
    const parsed = JSON.parse(jsonConfig.value);
    jsonConfig.value = JSON.stringify(parsed, null, 2);
    onJsonChange();
  } catch (error) {
    alert('Invalid JSON');
  }
}
</script>

<style scoped>
.node-config-form {
  padding: 1rem;
  background: #2d2d2d;
  border-radius: 8px;
}

.form-header, .json-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
  padding-bottom: 0.5rem;
  border-bottom: 2px solid #3498db;
}

.form-header h4, .json-header h4 {
  color: #3498db;
  margin: 0;
}

.btn-toggle {
  padding: 0.25rem 0.75rem;
  background: #34495e;
  color: #e0e0e0;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-family: 'Courier New', monospace;
  font-size: 0.85rem;
  transition: all 0.2s;
}

.btn-toggle:hover {
  background: #3498db;
}

.json-hint {
  color: #95a5a6;
  font-size: 0.85rem;
  margin: 0.5rem 0 0 0;
}

.form-fields {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.form-field label {
  color: #95a5a6;
  font-size: 0.9rem;
  font-weight: 600;
}

.required {
  color: #e74c3c;
  font-weight: bold;
}

.form-input, .form-textarea, .form-select, .json-textarea {
  width: 100%;
  padding: 0.5rem;
  background: #1a1a1a;
  border: 1px solid #444;
  border-radius: 4px;
  color: #e0e0e0;
  font-family: 'Courier New', monospace;
  font-size: 0.9rem;
  transition: border-color 0.2s;
}

.form-input:focus, .form-textarea:focus, .form-select:focus, .json-textarea:focus {
  outline: none;
  border-color: #3498db;
}

.form-textarea {
  resize: vertical;
  min-height: 200px;
  font-size: 0.8rem;
  line-height: 1.4;
}

.json-textarea {
  resize: vertical;
  min-height: 300px;
}

.form-select {
  cursor: pointer;
}

.form-checkbox-wrapper {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.form-checkbox {
  width: auto;
  cursor: pointer;
  transform: scale(1.2);
}

.checkbox-label {
  color: #e0e0e0;
  font-size: 0.9rem;
}

.validation-errors {
  margin-top: 1rem;
  padding: 0.75rem;
  background: rgba(231, 76, 60, 0.1);
  border: 1px solid #e74c3c;
  border-radius: 4px;
}

.error-message {
  color: #e74c3c;
  font-size: 0.85rem;
  margin: 0.25rem 0;
}

.form-actions {
  margin-top: 1rem;
  display: flex;
  gap: 0.5rem;
}

.btn-reset, .btn-format {
  padding: 0.5rem 1rem;
  background: #95a5a6;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.2s;
}

.btn-reset:hover, .btn-format:hover {
  background: #7f8c8d;
}
</style>
