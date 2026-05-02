// Node configuration schemas
// Each schema defines the configuration fields for a specific node type

export const nodeSchemas = {
  // Source Nodes
  RSSFeed: {
    fields: [
      { name: 'feed_url', type: 'text', label: 'Feed URL', required: true, placeholder: 'https://example.com/feed.xml' },
      { name: 'refresh_interval', type: 'number', label: 'Refresh Interval (minutes)', required: false, default: 60 },
      { name: 'max_items', type: 'number', label: 'Max Items', required: false, default: 10 }
    ]
  },

  WebScraper: {
    fields: [
      { name: 'url', type: 'text', label: 'URL', required: true, placeholder: 'https://example.com' },
      { name: 'selector', type: 'text', label: 'CSS Selector', required: false, placeholder: '.article-content' },
      { name: 'extract_links', type: 'boolean', label: 'Extract Links', required: false, default: false }
    ]
  },

  EmailFetch: {
    fields: [
      { name: 'email_address', type: 'text', label: 'Email Address', required: true, placeholder: 'user@example.com' },
      { name: 'folder', type: 'text', label: 'Folder', required: false, default: 'INBOX' },
      { name: 'unread_only', type: 'boolean', label: 'Unread Only', required: false, default: true },
      { name: 'max_emails', type: 'number', label: 'Max Emails', required: false, default: 10 }
    ]
  },

  // AI Processing Nodes
  AIFormatter: {
    fields: [
      { name: 'prompt', type: 'textarea', label: 'Prompt', required: true, placeholder: 'Format this content as...', rows: 10 },
      { name: 'temperature', type: 'number', label: 'Temperature', required: false, default: 0.1, min: 0, max: 2, step: 0.1 }
    ]
  },

  BatchProcessor: {
    fields: [
      { name: 'batch_size', type: 'number', label: 'Batch Size', required: false, default: 10, min: 1, max: 100 },
      { name: 'prompt', type: 'textarea', label: 'Prompt', required: true, placeholder: 'Process each item by...', rows: 10 },
      { name: 'temperature', type: 'number', label: 'Temperature', required: false, default: 0.1, min: 0, max: 2, step: 0.1 },
      { name: 'parallel', type: 'boolean', label: 'Parallel Processing', required: false, default: false }
    ]
  },

  BiasRatingEnrich: {
    fields: [
      { name: 'rating_scale', type: 'select', label: 'Rating Scale', required: false, options: ['1-5', '1-10', 'low-medium-high'], default: '1-5' },
      { name: 'include_explanation', type: 'boolean', label: 'Include Explanation', required: false, default: true }
    ]
  },

  // Notification Nodes
  Pushover: {
    fields: [
      { name: 'user_key', type: 'text', label: 'User Key', required: true, placeholder: 'Your Pushover user key' },
      { name: 'title', type: 'text', label: 'Title', required: false, placeholder: 'Notification title' },
      { name: 'priority', type: 'select', label: 'Priority', required: false, options: ['-2', '-1', '0', '1', '2'], default: '0' },
      { name: 'sound', type: 'select', label: 'Sound', required: false, options: ['pushover', 'bike', 'bugle', 'cashregister', 'classical', 'cosmic', 'falling', 'gamelan', 'incoming', 'intermission', 'magic', 'mechanical', 'pianobar', 'siren', 'spacealarm', 'tugboat', 'alien', 'climb', 'persistent', 'echo', 'updown', 'vibrate', 'none'], default: 'pushover' }
    ]
  },

  EmailNotification: {
    fields: [
      { name: 'to', type: 'text', label: 'To Address', required: true, placeholder: 'recipient@example.com' },
      { name: 'subject', type: 'text', label: 'Subject', required: true, placeholder: 'Email subject' },
      { name: 'cc', type: 'text', label: 'CC', required: false, placeholder: 'cc@example.com' },
      { name: 'from_name', type: 'text', label: 'From Name', required: false, placeholder: 'Workflow System' }
    ]
  },

  SlackNotification: {
    fields: [
      { name: 'webhook_url', type: 'text', label: 'Webhook URL', required: true, placeholder: 'https://hooks.slack.com/services/...' },
      { name: 'channel', type: 'text', label: 'Channel', required: false, placeholder: '#general' },
      { name: 'username', type: 'text', label: 'Username', required: false, placeholder: 'Workflow Bot' },
      { name: 'icon_emoji', type: 'text', label: 'Icon Emoji', required: false, placeholder: ':robot_face:' }
    ]
  },

  // Logic Nodes
  Conditional: {
    fields: [
      { name: 'condition', type: 'text', label: 'Condition', required: true, placeholder: 'e.g., data.value > 10' },
      { name: 'operator', type: 'select', label: 'Operator', required: false, options: ['equals', 'not_equals', 'greater_than', 'less_than', 'contains', 'regex'], default: 'equals' },
      { name: 'value', type: 'text', label: 'Value', required: true, placeholder: 'Comparison value' }
    ]
  },

  Filter: {
    fields: [
      { name: 'field', type: 'text', label: 'Field', required: true, placeholder: 'e.g., category' },
      { name: 'operator', type: 'select', label: 'Operator', required: false, options: ['equals', 'not_equals', 'contains', 'starts_with', 'ends_with', 'regex'], default: 'equals' },
      { name: 'value', type: 'text', label: 'Value', required: true, placeholder: 'Filter value' },
      { name: 'case_sensitive', type: 'boolean', label: 'Case Sensitive', required: false, default: false }
    ]
  },

  Delay: {
    fields: [
      { name: 'duration', type: 'number', label: 'Duration (seconds)', required: true, default: 60, min: 1 },
      { name: 'random_variance', type: 'number', label: 'Random Variance (%)', required: false, default: 0, min: 0, max: 100 }
    ]
  },

  // Transform Nodes
  Merge: {
    fields: [
      { name: 'merge_key', type: 'text', label: 'Merge Key', required: true, placeholder: 'e.g., id' },
      { name: 'strategy', type: 'select', label: 'Merge Strategy', required: false, options: ['overwrite', 'keep_first', 'concat', 'deep_merge'], default: 'overwrite' }
    ]
  },

  Split: {
    fields: [
      { name: 'split_by', type: 'text', label: 'Split By', required: true, placeholder: 'e.g., category' },
      { name: 'create_batches', type: 'boolean', label: 'Create Batches', required: false, default: false },
      { name: 'batch_size', type: 'number', label: 'Batch Size', required: false, default: 10 }
    ]
  },

  Transform: {
    fields: [
      { name: 'mapping', type: 'textarea', label: 'Field Mapping (JSON)', required: true, placeholder: '{"old_field": "new_field"}' },
      { name: 'remove_fields', type: 'text', label: 'Remove Fields (comma-separated)', required: false, placeholder: 'temp,debug' },
      { name: 'add_timestamp', type: 'boolean', label: 'Add Timestamp', required: false, default: false }
    ]
  }
};

// Get schema for a specific node type
export function getNodeSchema(nodeType) {
  return nodeSchemas[nodeType] || null;
}

// Validate node config against schema
export function validateNodeConfig(nodeType, config) {
  const schema = getNodeSchema(nodeType);
  if (!schema) return { valid: true, errors: [] };

  const errors = [];
  const configObj = typeof config === 'string' ? JSON.parse(config) : config;

  schema.fields.forEach(field => {
    if (field.required && !configObj[field.name]) {
      errors.push(`${field.label} is required`);
    }

    if (field.type === 'number' && configObj[field.name] !== undefined) {
      const value = parseFloat(configObj[field.name]);
      if (isNaN(value)) {
        errors.push(`${field.label} must be a number`);
      } else {
        if (field.min !== undefined && value < field.min) {
          errors.push(`${field.label} must be >= ${field.min}`);
        }
        if (field.max !== undefined && value > field.max) {
          errors.push(`${field.label} must be <= ${field.max}`);
        }
      }
    }
  });

  return { valid: errors.length === 0, errors };
}

// Convert config object to form values
export function configToFormValues(nodeType, config) {
  const schema = getNodeSchema(nodeType);
  if (!schema) return {};

  const configObj = typeof config === 'string' ? JSON.parse(config) : config;
  const formValues = {};

  schema.fields.forEach(field => {
    formValues[field.name] = configObj[field.name] !== undefined
      ? configObj[field.name]
      : field.default !== undefined ? field.default : '';
  });

  return formValues;
}

// Convert form values to config JSON string
export function formValuesToConfig(formValues) {
  return JSON.stringify(formValues, null, 2);
}
