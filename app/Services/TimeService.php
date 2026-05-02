<?php

namespace App\Services;

use DateTime;
use DateTimeZone;
use Exception;

/**
 * Time Service - Internal MCP Server
 *
 * Provides time and timezone conversion tools matching the
 * functionality of @modelcontextprotocol/server-time
 */
class TimeService
{
    private string $localTimezone;

    public function __construct()
    {
        $this->localTimezone = config('app.timezone', 'America/New_York');
    }

    /**
     * Get current time in specified timezone
     *
     * @param array $params ['timezone' => 'America/New_York']
     * @return array
     */
    public function get_current_time(array $params = []): array
    {
        try {
            $timezone = $params['timezone'] ?? $this->localTimezone;

            // Validate timezone
            if (!in_array($timezone, DateTimeZone::listIdentifiers())) {
                throw new Exception("Invalid timezone: {$timezone}");
            }

            $date = new DateTime('now', new DateTimeZone($timezone));

            return [
                'timezone' => $timezone,
                'datetime' => $date->format('Y-m-d H:i:s T'),
                'iso8601' => $date->format('c'),
                'timestamp' => $date->getTimestamp(),
                'date' => $date->format('Y-m-d'),
                'time' => $date->format('H:i:s'),
                'day_of_week' => $date->format('l'),
                'timezone_offset' => $date->format('P'),
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'timezone' => $timezone ?? 'unknown',
            ];
        }
    }

    /**
     * Convert time between timezones
     *
     * @param array $params ['time' => 'Y-m-d H:i:s', 'from_timezone' => 'UTC', 'to_timezone' => 'America/New_York']
     * @return array
     */
    public function convert_time(array $params = []): array
    {
        try {
            $time = $params['time'] ?? date('Y-m-d H:i:s');
            $fromTimezone = $params['from_timezone'] ?? 'UTC';
            $toTimezone = $params['to_timezone'] ?? $this->localTimezone;

            // Validate timezones
            $validTimezones = DateTimeZone::listIdentifiers();
            if (!in_array($fromTimezone, $validTimezones)) {
                throw new Exception("Invalid source timezone: {$fromTimezone}");
            }
            if (!in_array($toTimezone, $validTimezones)) {
                throw new Exception("Invalid target timezone: {$toTimezone}");
            }

            // Create DateTime object in source timezone
            $date = new DateTime($time, new DateTimeZone($fromTimezone));

            // Convert to target timezone
            $date->setTimezone(new DateTimeZone($toTimezone));

            return [
                'original_time' => $time,
                'from_timezone' => $fromTimezone,
                'to_timezone' => $toTimezone,
                'converted_datetime' => $date->format('Y-m-d H:i:s T'),
                'converted_iso8601' => $date->format('c'),
                'converted_timestamp' => $date->getTimestamp(),
                'timezone_offset' => $date->format('P'),
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'original_time' => $time ?? 'unknown',
                'from_timezone' => $fromTimezone ?? 'unknown',
                'to_timezone' => $toTimezone ?? 'unknown',
            ];
        }
    }
}
