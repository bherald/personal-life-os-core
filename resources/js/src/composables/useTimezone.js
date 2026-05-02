import { ref, computed } from 'vue';

/**
 * Timezone composable for handling date/time display and conversion
 *
 * Database stores all timestamps in UTC
 * Display timezone is configured via APP_TIMEZONE in .env
 */

// Global state - shared across all component instances
const appTimezone = ref('UTC'); // Default, will be set from API
const isInitialized = ref(false);

export function useTimezone() {
  /**
   * Initialize timezone from API config
   * Should be called once at app startup
   */
  const initializeTimezone = async (timezone) => {
    if (timezone) {
      appTimezone.value = timezone;
      isInitialized.value = true;
    }
  };

  /**
   * Get the current configured timezone
   */
  const getTimezone = computed(() => appTimezone.value);

  /**
   * Format a UTC date string to the configured timezone
   *
   * @param {string} dateString - UTC date string from database
   * @param {object} options - Intl.DateTimeFormat options
   * @returns {string} Formatted date string in configured timezone
   */
  const formatDate = (dateString, options = {}) => {
    if (!dateString) return 'N/A';

    try {
      const date = new Date(dateString);

      // Check if date is valid
      if (isNaN(date.getTime())) {
        return 'Invalid Date';
      }

      // Default formatting options
      const defaultOptions = {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: appTimezone.value,
      };

      // Merge with provided options
      const formatOptions = { ...defaultOptions, ...options };

      return new Intl.DateTimeFormat('en-US', formatOptions).format(date);
    } catch (error) {
      console.error('Error formatting date:', error);
      return 'Error';
    }
  };

  /**
   * Format a date with time and timezone abbreviation
   *
   * @param {string} dateString - UTC date string from database
   * @returns {string} Formatted date with timezone
   */
  const formatDateWithTimezone = (dateString) => {
    if (!dateString) return 'N/A';

    try {
      const date = new Date(dateString);

      if (isNaN(date.getTime())) {
        return 'Invalid Date';
      }

      const formatted = new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: appTimezone.value,
        timeZoneName: 'short',
      }).format(date);

      return formatted;
    } catch (error) {
      console.error('Error formatting date with timezone:', error);
      return 'Error';
    }
  };

  /**
   * Format date for display in short format (no time)
   *
   * @param {string} dateString - UTC date string from database
   * @returns {string} Formatted date (no time)
   */
  const formatDateShort = (dateString) => {
    return formatDate(dateString, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  /**
   * Format date for display in time only format
   *
   * @param {string} dateString - UTC date string from database
   * @returns {string} Formatted time only
   */
  const formatTime = (dateString) => {
    return formatDate(dateString, {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  };

  /**
   * Format relative time (e.g., "2 hours ago", "in 3 days")
   *
   * @param {string} dateString - UTC date string from database
   * @returns {string} Relative time string
   */
  const formatRelative = (dateString) => {
    if (!dateString) return 'N/A';

    try {
      const date = new Date(dateString);

      if (isNaN(date.getTime())) {
        return 'Invalid Date';
      }

      const now = new Date();
      const diffMs = date - now;
      const diffSeconds = Math.floor(diffMs / 1000);
      const diffMinutes = Math.floor(diffSeconds / 60);
      const diffHours = Math.floor(diffMinutes / 60);
      const diffDays = Math.floor(diffHours / 24);

      // Past
      if (diffSeconds < 0) {
        const absDiffSeconds = Math.abs(diffSeconds);
        const absDiffMinutes = Math.abs(diffMinutes);
        const absDiffHours = Math.abs(diffHours);
        const absDiffDays = Math.abs(diffDays);

        if (absDiffSeconds < 60) {
          return 'just now';
        } else if (absDiffMinutes < 60) {
          return `${absDiffMinutes} ${absDiffMinutes === 1 ? 'minute' : 'minutes'} ago`;
        } else if (absDiffHours < 24) {
          return `${absDiffHours} ${absDiffHours === 1 ? 'hour' : 'hours'} ago`;
        } else if (absDiffDays < 30) {
          return `${absDiffDays} ${absDiffDays === 1 ? 'day' : 'days'} ago`;
        } else {
          return formatDate(dateString);
        }
      }

      // Future
      if (diffSeconds < 60) {
        return 'in a moment';
      } else if (diffMinutes < 60) {
        return `in ${diffMinutes} ${diffMinutes === 1 ? 'minute' : 'minutes'}`;
      } else if (diffHours < 24) {
        return `in ${diffHours} ${diffHours === 1 ? 'hour' : 'hours'}`;
      } else if (diffDays < 30) {
        return `in ${diffDays} ${diffDays === 1 ? 'day' : 'days'}`;
      } else {
        return formatDate(dateString);
      }
    } catch (error) {
      console.error('Error formatting relative date:', error);
      return 'Error';
    }
  };

  /**
   * Calculate duration between two dates
   *
   * @param {string} startDateString - Start date (UTC)
   * @param {string} endDateString - End date (UTC), defaults to now
   * @returns {string} Formatted duration
   */
  const formatDuration = (startDateString, endDateString = null) => {
    if (!startDateString) return 'N/A';

    try {
      const start = new Date(startDateString);
      const end = endDateString ? new Date(endDateString) : new Date();

      if (isNaN(start.getTime()) || isNaN(end.getTime())) {
        return 'Invalid Date';
      }

      const durationMs = end - start;

      if (durationMs < 0) {
        return 'Not started';
      }

      const seconds = Math.floor(durationMs / 1000);
      const minutes = Math.floor(seconds / 60);
      const hours = Math.floor(minutes / 60);
      const days = Math.floor(hours / 24);

      if (days > 0) {
        const remainingHours = hours % 24;
        const remainingMinutes = minutes % 60;
        return `${days}d ${remainingHours}h ${remainingMinutes}m`;
      } else if (hours > 0) {
        const remainingMinutes = minutes % 60;
        const remainingSeconds = seconds % 60;
        return `${hours}h ${remainingMinutes}m ${remainingSeconds}s`;
      } else if (minutes > 0) {
        const remainingSeconds = seconds % 60;
        return `${minutes}m ${remainingSeconds}s`;
      } else {
        return `${seconds}s`;
      }
    } catch (error) {
      console.error('Error calculating duration:', error);
      return 'Error';
    }
  };

  return {
    // State
    isInitialized,

    // Methods
    initializeTimezone,
    getTimezone,
    formatDate,
    formatDateWithTimezone,
    formatDateShort,
    formatTime,
    formatRelative,
    formatDuration,
  };
}
