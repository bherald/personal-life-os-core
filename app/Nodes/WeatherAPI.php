<?php

namespace App\Nodes;

use Illuminate\Support\Facades\Http;
use Exception;

class WeatherAPI extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $location = $this->getConfigValue('location');
            $units = $this->getConfigValue('units', 'imperial');
            $apiKey = config('services.weather.api_key');
            $apiUrl = config('services.weather.api_url', 'https://api.openweathermap.org/data/2.5');

            if (!$location) {
                throw new Exception('Location configuration is required');
            }

            if (!$apiKey) {
                throw new Exception('WEATHER_API_KEY not configured');
            }

            // Parse location (lat,lon format)
            [$lat, $lon] = explode(',', $location);

            // Fetch current weather
            $currentResponse = Http::connectTimeout(5)->timeout(15)->get("{$apiUrl}/weather", [
                'lat' => trim($lat),
                'lon' => trim($lon),
                'units' => $units,
                'appid' => $apiKey
            ]);

            if (!$currentResponse->successful()) {
                throw new Exception('Current weather API request failed: ' . $currentResponse->status());
            }

            // Fetch 5-day forecast
            $forecastResponse = Http::connectTimeout(5)->timeout(15)->get("{$apiUrl}/forecast", [
                'lat' => trim($lat),
                'lon' => trim($lon),
                'units' => $units,
                'appid' => $apiKey
            ]);

            if (!$forecastResponse->successful()) {
                throw new Exception('Forecast API request failed: ' . $forecastResponse->status());
            }

            $currentData = $currentResponse->json();
            $forecastData = $forecastResponse->json();

            // Process forecast into daily summaries (today + next 5 days = 6 days total)
            $timezoneOffset = (int) ($forecastData['city']['timezone'] ?? $currentData['timezone'] ?? 0);
            $dailyForecast = $this->processDailyForecast($forecastData['list'] ?? [], $timezoneOffset);

            // Round current values
            $currentTemp = round($currentData['main']['temp'] ?? 0);
            $currentFeels = round($currentData['main']['feels_like'] ?? 0);
            $currentHumidity = $currentData['main']['humidity'] ?? 0;
            $currentWind = round($currentData['wind']['speed'] ?? 0);
            $currentGust = isset($currentData['wind']['gust']) ? round($currentData['wind']['gust']) : null;
            $currentDesc = $currentData['weather'][0]['description'] ?? 'N/A';
            $locationName = $currentData['name'] ?? 'Unknown';

            // Build pre-formatted text so LLM doesn't mess up formatting
            $lines = [];
            $lines[] = "☀️  WEATHER - {$locationName}";
            $lines[] = "--------------------------------------------------------------";
            $gustStr = $currentGust && $currentGust > 20 ? " gusts {$currentGust}mph" : '';
            $lines[] = "| {$currentTemp}°F (feels {$currentFeels}°F) {$currentHumidity}% {$currentWind}mph{$gustStr} {$currentDesc}";
            $lines[] = "--------------------------------------------------------------";

            $emojiMap = [
                'clear sky' => '☀️', 'sunny' => '☀️',
                'few clouds' => '🌤️', 'partly cloudy' => '🌤️', 'scattered clouds' => '🌤️',
                'mostly cloudy' => '⛅',
                'broken clouds' => '☁️', 'overcast clouds' => '☁️',
                'light rain' => '🌧️', 'moderate rain' => '🌧️', 'heavy rain' => '🌧️', 'rain' => '🌧️',
                'thunderstorm' => '⛈️',
                'light snow' => '❄️', 'moderate snow' => '❄️', 'heavy snow' => '❄️', 'snow' => '❄️',
                'mist' => '🌫️', 'fog' => '🌫️', 'haze' => '🌫️',
            ];

            foreach ($dailyForecast as $day) {
                $emoji = $emojiMap[$day['condition'] ?? ''] ?? '☁️';
                $dayStr = str_pad($day['day_name'], 10);
                $dateStr = date('m/d', strtotime($day['date']));

                // Build extras on same line
                $extras = [];
                if (($day['pop'] ?? 0) > 30) {
                    $extras[] = "{$day['pop']}% precip";
                }
                if (!empty($day['precip_summary'])) {
                    $extras[] = $day['precip_summary'];
                }
                if (($day['wind_gust'] ?? 0) > 25) {
                    $extras[] = "gusts {$day['wind_gust']}mph";
                }
                $extrasStr = !empty($extras) ? ', ' . implode(' ', $extras) : '';

                $lines[] = "| {$emoji} {$dayStr} {$dateStr} {$day['high']}°F/{$day['low']}°F {$day['condition']}{$extrasStr}";
            }

            $lines[] = "--------------------------------------------------------------";
            $formattedText = implode("\n", $lines);

            $output = [
                'current' => [
                    'temperature' => $currentTemp,
                    'feels_like' => $currentFeels,
                    'humidity' => $currentHumidity,
                    'pressure' => $currentData['main']['pressure'] ?? null,
                    'description' => $currentDesc,
                    'wind_speed' => $currentWind,
                    'location' => $locationName,
                ],
                'daily_forecast' => $dailyForecast,
                'preformatted_weather' => $formattedText,
                'units' => $units
            ];

            return $this->standardOutput($output, [
                'location' => $location,
                'units' => $units
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    /**
     * Process 3-hour forecast intervals into daily summaries
     * Returns 6 days (today + next 5 days) with high/low temps, conditions,
     * precipitation amounts, wind, and probability of precipitation
     */
    private function processDailyForecast(array $forecastList, int $timezoneOffsetSeconds = 0, ?int $referenceTimestamp = null): array
    {
        $dailyData = [];
        $referenceTimestamp ??= time();
        $todayLocal = gmdate('Y-m-d', $referenceTimestamp + $timezoneOffsetSeconds);

        foreach ($forecastList as $item) {
            $timestamp = $item['dt'];
            $localTimestamp = $timestamp + $timezoneOffsetSeconds;
            $date = gmdate('Y-m-d', $localTimestamp);
            $dayName = gmdate('l', $localTimestamp);

            if ($date < $todayLocal) {
                continue;
            }

            if (!isset($dailyData[$date])) {
                $dailyData[$date] = [
                    'date' => $date,
                    'day_name' => $dayName,
                    'temps' => [],
                    'feels_like' => [],
                    'conditions' => [],
                    'wind_speeds' => [],
                    'wind_gusts' => [],
                    'pops' => [],
                    'rain_mm' => 0,
                    'snow_mm' => 0,
                    'high' => null,
                    'low' => null,
                    'condition' => null,
                ];
            }

            $temp = $item['main']['temp'] ?? null;
            if ($temp !== null) {
                $dailyData[$date]['temps'][] = $temp;
            }

            $feelsLike = $item['main']['feels_like'] ?? null;
            if ($feelsLike !== null) {
                $dailyData[$date]['feels_like'][] = $feelsLike;
            }

            $condition = $item['weather'][0]['description'] ?? null;
            if ($condition) {
                $dailyData[$date]['conditions'][] = $condition;
            }

            $windSpeed = $item['wind']['speed'] ?? null;
            if ($windSpeed !== null) {
                $dailyData[$date]['wind_speeds'][] = $windSpeed;
            }

            $windGust = $item['wind']['gust'] ?? null;
            if ($windGust !== null) {
                $dailyData[$date]['wind_gusts'][] = $windGust;
            }

            $pop = $item['pop'] ?? 0;
            $dailyData[$date]['pops'][] = $pop;

            // Accumulate precipitation (mm over 3h periods)
            if (isset($item['rain']['3h'])) {
                $dailyData[$date]['rain_mm'] += $item['rain']['3h'];
            }
            if (isset($item['snow']['3h'])) {
                $dailyData[$date]['snow_mm'] += $item['snow']['3h'];
            }
        }

        foreach ($dailyData as &$day) {
            if (!empty($day['temps'])) {
                $day['high'] = round(max($day['temps']));
                $day['low'] = round(min($day['temps']));
            }

            if (!empty($day['conditions'])) {
                $conditionCounts = array_count_values($day['conditions']);
                arsort($conditionCounts);
                $day['condition'] = array_key_first($conditionCounts);
            }

            // Max probability of precipitation for the day
            $day['pop'] = !empty($day['pops']) ? round(max($day['pops']) * 100) : 0;

            // Wind: max speed and max gust
            $day['wind_max'] = !empty($day['wind_speeds']) ? round(max($day['wind_speeds'])) : null;
            $day['wind_gust'] = !empty($day['wind_gusts']) ? round(max($day['wind_gusts'])) : null;

            // Convert precip mm to inches (1mm = 0.0394in)
            $day['rain_in'] = round($day['rain_mm'] * 0.0394, 1);
            $day['snow_in'] = round($day['snow_mm'] * 0.0394, 1);

            // Build precipitation summary string
            $precipParts = [];
            if ($day['snow_in'] > 0) {
                $precipParts[] = "snow {$day['snow_in']}\"";
            }
            if ($day['rain_in'] > 0) {
                $precipParts[] = "rain {$day['rain_in']}\"";
            }
            $day['precip_summary'] = implode(', ', $precipParts) ?: '';

            unset($day['temps'], $day['feels_like'], $day['conditions'], $day['wind_speeds'], $day['wind_gusts'], $day['pops'], $day['rain_mm'], $day['snow_mm']);
        }

        ksort($dailyData);

        return array_slice(array_values($dailyData), 0, 6);
    }
}
