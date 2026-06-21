<?php

namespace App\Nodes;

use App\Services\SystemConfigService;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WeatherAPI extends BaseNode
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'weather.node_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 15,
    ];

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function execute(array $input): array
    {
        try {
            $location = $this->getConfigValue('location');
            $units = $this->getConfigValue('units', 'imperial');
            $provider = strtolower((string) $this->getConfigValue('provider', config('services.weather.provider', 'nws')));
            $fallbackProvider = strtolower((string) $this->getConfigValue('fallback_provider', config('services.weather.fallback_provider', 'openweather')));

            if (! $location) {
                throw new Exception('Location configuration is required');
            }

            [$lat, $lon] = explode(',', $location);
            $lat = trim($lat);
            $lon = trim($lon);

            if ($provider === 'nws' || $provider === 'weather.gov') {
                try {
                    return $this->executeNws($lat, $lon, $units, $location);
                } catch (Exception $nwsException) {
                    if ($fallbackProvider === 'openweather' && config('services.weather.api_key')) {
                        try {
                            $fallback = $this->executeOpenWeather($lat, $lon, $units, $location);
                            $fallback['meta']['fallback_from'] = 'nws';
                            $fallback['meta']['fallback_reason'] = $nwsException->getMessage();

                            return $fallback;
                        } catch (Exception $fallbackException) {
                            throw new Exception('NWS weather API request failed: '.$nwsException->getMessage().'; OpenWeather fallback failed: '.$fallbackException->getMessage());
                        }
                    }

                    throw new Exception('NWS weather API request failed: '.$nwsException->getMessage());
                }
            }

            if ($provider === 'openweather') {
                return $this->executeOpenWeather($lat, $lon, $units, $location);
            }

            throw new Exception("Unsupported weather provider: {$provider}");
        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function executeOpenWeather(string $lat, string $lon, string $units, string $location): array
    {
        $apiKey = config('services.weather.api_key');
        $apiUrl = config('services.weather.api_url', 'https://api.openweathermap.org/data/2.5');

        if (! $apiKey) {
            throw new Exception('WEATHER_API_KEY not configured');
        }

        $currentResponse = Http::connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->requestTimeoutSeconds())
            ->get("{$apiUrl}/weather", [
                'lat' => $lat,
                'lon' => $lon,
                'units' => $units,
                'appid' => $apiKey,
            ]);

        if (! $currentResponse->successful()) {
            throw new Exception('Current weather API request failed: '.$currentResponse->status());
        }

        $forecastResponse = Http::connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->requestTimeoutSeconds())
            ->get("{$apiUrl}/forecast", [
                'lat' => $lat,
                'lon' => $lon,
                'units' => $units,
                'appid' => $apiKey,
            ]);

        if (! $forecastResponse->successful()) {
            throw new Exception('Forecast API request failed: '.$forecastResponse->status());
        }

        $currentData = $currentResponse->json();
        $forecastData = $forecastResponse->json();

        $timezoneOffset = (int) ($forecastData['city']['timezone'] ?? $currentData['timezone'] ?? 0);
        $dailyForecast = $this->processDailyForecast($forecastData['list'] ?? [], $timezoneOffset);

        $currentTemp = round($currentData['main']['temp'] ?? 0);
        $currentFeels = round($currentData['main']['feels_like'] ?? 0);
        $currentHumidity = $currentData['main']['humidity'] ?? 0;
        $currentWind = round($currentData['wind']['speed'] ?? 0);
        $currentDesc = $currentData['weather'][0]['description'] ?? 'N/A';
        $locationName = $currentData['name'] ?? ($forecastData['city']['name'] ?? 'Weather');

        $formattedText = $this->buildPreformattedWeather($locationName, $dailyForecast);

        return $this->standardOutput([
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
            'units' => $units,
            'provider' => 'openweather',
        ], [
            'location' => $location,
            'units' => $units,
            'provider' => 'openweather',
        ]);
    }

    private function executeNws(string $lat, string $lon, string $units, string $location): array
    {
        $apiUrl = rtrim((string) config('services.weather.nws_api_url', 'https://api.weather.gov'), '/');
        $pointsResponse = $this->nwsGet("{$apiUrl}/points/{$lat},{$lon}");

        if (! $pointsResponse->successful()) {
            throw new Exception('Points lookup failed: '.$pointsResponse->status());
        }

        $points = $pointsResponse->json();
        $properties = $points['properties'] ?? [];
        $forecastUrl = $properties['forecast'] ?? null;

        if (! is_string($forecastUrl) || $forecastUrl === '') {
            throw new Exception('Points lookup did not include a forecast URL');
        }

        $forecastResponse = $this->nwsGet($forecastUrl);
        if (! $forecastResponse->successful()) {
            throw new Exception('Forecast request failed: '.$forecastResponse->status());
        }

        $periods = $forecastResponse->json('properties.periods') ?? [];
        if (! is_array($periods) || $periods === []) {
            throw new Exception('Forecast request returned no periods');
        }

        $dailyForecast = $this->processNwsForecastPeriods($periods);
        $locationName = $this->nwsLocationName($properties);
        $current = $this->nwsCurrentConditions($properties, $periods, $units, $locationName);
        $formattedText = $this->buildPreformattedWeather($locationName, $dailyForecast);

        return $this->standardOutput([
            'current' => $current,
            'daily_forecast' => $dailyForecast,
            'preformatted_weather' => $formattedText,
            'units' => $units,
            'provider' => 'nws',
        ], [
            'location' => $location,
            'units' => $units,
            'provider' => 'nws',
            'forecast_url' => $forecastUrl,
        ]);
    }

    private function nwsGet(string $url): Response
    {
        return Http::withHeaders([
            'Accept' => 'application/geo+json',
            'User-Agent' => (string) config('services.weather.nws_user_agent', 'PLOS Morning Weather Workflow'),
        ])->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->requestTimeoutSeconds())
            ->get($url);
    }

    private function nwsLocationName(array $pointProperties): string
    {
        $relative = $pointProperties['relativeLocation']['properties'] ?? [];
        $city = trim((string) ($relative['city'] ?? ''));
        $state = trim((string) ($relative['state'] ?? ''));

        if ($city !== '' && $state !== '') {
            return "{$city}, {$state}";
        }

        return $city !== '' ? $city : 'Weather';
    }

    private function nwsCurrentConditions(array $pointProperties, array $periods, string $units, string $locationName): array
    {
        $fallbackPeriod = $periods[0] ?? [];
        $current = [
            'temperature' => isset($fallbackPeriod['temperature']) ? (int) $fallbackPeriod['temperature'] : 0,
            'feels_like' => isset($fallbackPeriod['temperature']) ? (int) $fallbackPeriod['temperature'] : 0,
            'humidity' => null,
            'pressure' => null,
            'description' => $fallbackPeriod['shortForecast'] ?? 'NWS forecast',
            'wind_speed' => $this->windMaxFromText((string) ($fallbackPeriod['windSpeed'] ?? '')),
            'location' => $locationName,
        ];

        $stationsUrl = $pointProperties['observationStations'] ?? null;
        if (! is_string($stationsUrl) || $stationsUrl === '') {
            return $current;
        }

        try {
            $stationsResponse = $this->nwsGet($stationsUrl);
            if (! $stationsResponse->successful()) {
                return $current;
            }

            $stationFeatures = $stationsResponse->json('features') ?? [];
            $stationUrl = $stationFeatures[0]['id'] ?? null;
            if (! is_string($stationUrl) || $stationUrl === '') {
                return $current;
            }

            $observationResponse = $this->nwsGet($stationUrl.'/observations/latest');
            if (! $observationResponse->successful()) {
                return $current;
            }

            $observation = $observationResponse->json('properties') ?? [];
            $temperature = $this->nwsTemperature($observation['temperature']['value'] ?? null, $units);
            $feelsLike = $this->nwsTemperature($observation['heatIndex']['value'] ?? $observation['windChill']['value'] ?? null, $units);
            $windSpeed = $this->nwsWindSpeed($observation['windSpeed']['value'] ?? null, $units);
            $humidity = $observation['relativeHumidity']['value'] ?? null;
            $pressure = $observation['barometricPressure']['value'] ?? null;

            if ($temperature !== null) {
                $current['temperature'] = round($temperature);
            }
            if ($feelsLike !== null) {
                $current['feels_like'] = round($feelsLike);
            } elseif ($temperature !== null) {
                $current['feels_like'] = round($temperature);
            }
            if ($humidity !== null) {
                $current['humidity'] = round((float) $humidity);
            }
            if ($windSpeed !== null) {
                $current['wind_speed'] = round($windSpeed);
            }
            if ($pressure !== null) {
                $current['pressure'] = round(((float) $pressure) / 100);
            }
            if (! empty($observation['textDescription'])) {
                $current['description'] = $observation['textDescription'];
            }
        } catch (\Throwable) {
            return $current;
        }

        return $current;
    }

    private function nwsTemperature(mixed $celsius, string $units): ?float
    {
        if ($celsius === null || $celsius === '') {
            return null;
        }

        $value = (float) $celsius;

        return $units === 'imperial' ? ($value * 9 / 5) + 32 : $value;
    }

    private function nwsWindSpeed(mixed $kph, string $units): ?float
    {
        if ($kph === null || $kph === '') {
            return null;
        }

        $value = (float) $kph;

        return $units === 'imperial' ? $value * 0.621371 : $value;
    }

    /**
     * Convert NWS 12-hour day/night periods into the same daily card structure
     * used by the Pushover formatter.
     */
    private function processNwsForecastPeriods(array $periods): array
    {
        $days = [];

        foreach ($periods as $period) {
            if (! is_array($period) || empty($period['startTime'])) {
                continue;
            }

            $date = substr((string) $period['startTime'], 0, 10);
            if ($date === '') {
                continue;
            }

            $isDaytime = (bool) ($period['isDaytime'] ?? false);
            $days[$date] ??= [
                'date' => $date,
                'day_name' => date('l', strtotime($date.' 12:00:00')),
                'day_period' => null,
                'night_period' => null,
            ];

            if ($isDaytime && $days[$date]['day_period'] === null) {
                $days[$date]['day_period'] = $period;
            } elseif (! $isDaytime && $days[$date]['night_period'] === null) {
                $days[$date]['night_period'] = $period;
            }
        }

        ksort($days);

        $daily = [];
        foreach ($days as $date => $day) {
            $dayPeriod = $day['day_period'];
            $nightPeriod = $day['night_period'];
            $sourcePeriod = $dayPeriod ?: $nightPeriod;

            if (! is_array($sourcePeriod)) {
                continue;
            }

            $condition = $this->selectNwsCondition($dayPeriod, $nightPeriod);
            $conditionText = $this->selectedNwsConditionText($dayPeriod, $nightPeriod);
            [$windMin, $windMax] = $this->windRangeFromPeriods($dayPeriod, $nightPeriod);

            $daily[] = [
                'date' => $date,
                'day_name' => $day['day_name'],
                'high' => $this->periodTemperature($dayPeriod ?: $sourcePeriod),
                'low' => $this->periodTemperature($nightPeriod ?: $sourcePeriod),
                'condition' => $condition,
                'icons' => $this->iconsForConditions([['condition' => $conditionText, 'hour' => 12]]),
                'pop' => max($this->periodPop($dayPeriod), $this->periodPop($nightPeriod)),
                'wind_min' => $windMin,
                'wind_max' => $windMax,
                'wind_direction' => $this->periodWindDirection($dayPeriod ?: $nightPeriod ?: $sourcePeriod),
                'rain_in' => null,
                'snow_in' => null,
                'precip_summary' => null,
            ];
        }

        return array_slice($daily, 0, $this->forecastDays());
    }

    private function selectNwsCondition(?array $dayPeriod, ?array $nightPeriod): string
    {
        $dayText = (string) ($dayPeriod['shortForecast'] ?? '');
        $nightText = (string) ($nightPeriod['shortForecast'] ?? '');
        $dayPop = $this->periodPop($dayPeriod);
        $nightPop = $this->periodPop($nightPeriod);

        if ($dayText === '' && $nightText !== '') {
            return $this->compactNwsForecast($nightText);
        }

        if ($nightText !== '' && $nightPop >= 30 && $nightPop > $dayPop && ! $this->isPrecipForecast($dayText)) {
            return $this->pmForecastLabel($nightText);
        }

        return $this->compactNwsForecast($dayText !== '' ? $dayText : $nightText);
    }

    private function selectedNwsConditionText(?array $dayPeriod, ?array $nightPeriod): string
    {
        $condition = $this->selectNwsCondition($dayPeriod, $nightPeriod);

        return match ($condition) {
            'PM Storms', 'T-Storms', 'Chc Storms' => 'thunderstorm',
            'PM Showers', 'Showers', 'Chc Showers' => 'rain showers',
            'PM Rain', 'Rain' => 'rain',
            default => (string) ($dayPeriod['shortForecast'] ?? $nightPeriod['shortForecast'] ?? $condition),
        };
    }

    private function compactNwsForecast(string $forecast): string
    {
        $lower = strtolower($forecast);

        if (str_contains($lower, 'thunder')) {
            return str_contains($lower, 'chance') ? 'Chc Storms' : 'T-Storms';
        }
        if (str_contains($lower, 'shower')) {
            return str_contains($lower, 'chance') ? 'Chc Showers' : 'Showers';
        }
        if (str_contains($lower, 'rain')) {
            return 'Rain';
        }
        if (str_contains($lower, 'snow')) {
            return 'Snow';
        }
        if (str_contains($lower, 'fog')) {
            return 'Fog';
        }
        if (str_contains($lower, 'mostly sunny')) {
            return 'Mostly Sun';
        }
        if (str_contains($lower, 'partly sunny')) {
            return 'Partly Sun';
        }
        if (str_contains($lower, 'sunny')) {
            return 'Sunny';
        }
        if (str_contains($lower, 'partly cloudy')) {
            return 'Partly Cldy';
        }
        if (str_contains($lower, 'mostly cloudy')) {
            return 'Mostly Cldy';
        }
        if (str_contains($lower, 'cloud')) {
            return 'Cloudy';
        }

        return 'Forecast';
    }

    private function pmForecastLabel(string $forecast): string
    {
        $lower = strtolower($forecast);

        if (str_contains($lower, 'thunder')) {
            return 'PM Storms';
        }
        if (str_contains($lower, 'shower')) {
            return 'PM Showers';
        }
        if (str_contains($lower, 'rain')) {
            return 'PM Rain';
        }
        if (str_contains($lower, 'snow')) {
            return 'PM Snow';
        }

        return $this->compactNwsForecast($forecast);
    }

    private function isPrecipForecast(string $forecast): bool
    {
        $lower = strtolower($forecast);

        return str_contains($lower, 'thunder')
            || str_contains($lower, 'shower')
            || str_contains($lower, 'rain')
            || str_contains($lower, 'snow')
            || str_contains($lower, 'sleet')
            || str_contains($lower, 'drizzle');
    }

    private function periodTemperature(?array $period): ?int
    {
        return isset($period['temperature']) ? (int) $period['temperature'] : null;
    }

    private function periodPop(?array $period): int
    {
        $value = $period['probabilityOfPrecipitation']['value'] ?? null;

        return $value === null ? 0 : (int) round((float) $value);
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function windRangeFromPeriods(?array ...$periods): array
    {
        $values = [];
        foreach ($periods as $period) {
            $range = $this->windRangeFromText((string) ($period['windSpeed'] ?? ''));
            foreach ($range as $value) {
                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        return $values === [] ? [null, null] : [min($values), max($values)];
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function windRangeFromText(string $windSpeed): array
    {
        preg_match_all('/\d+/', $windSpeed, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        if ($numbers === []) {
            return [null, null];
        }

        return [min($numbers), max($numbers)];
    }

    private function windMaxFromText(string $windSpeed): ?int
    {
        [, $max] = $this->windRangeFromText($windSpeed);

        return $max;
    }

    private function periodWindDirection(?array $period): ?string
    {
        $direction = strtoupper(trim((string) ($period['windDirection'] ?? '')));

        return $direction !== '' ? $direction : null;
    }

    /**
     * Process 3-hour forecast intervals into TV-style daily summaries.
     * Highs come from the daytime window; lows are paired with the following
     * night/early morning, which better matches local-news 5-day forecast cards.
     */
    private function processDailyForecast(array $forecastList, int $timezoneOffsetSeconds = 0, ?int $referenceTimestamp = null): array
    {
        $dailyData = [];
        $referenceTimestamp ??= time();
        $todayLocal = gmdate('Y-m-d', $referenceTimestamp + $timezoneOffsetSeconds);

        foreach ($forecastList as $item) {
            if (! isset($item['dt'])) {
                continue;
            }

            $localTimestamp = ((int) $item['dt']) + $timezoneOffsetSeconds;
            $date = gmdate('Y-m-d', $localTimestamp);
            $hour = (int) gmdate('G', $localTimestamp);
            $temp = $item['main']['temp'] ?? null;
            $condition = $item['weather'][0]['description'] ?? null;
            $pop = isset($item['pop']) ? (float) $item['pop'] : 0.0;
            $windSpeed = $item['wind']['speed'] ?? null;
            $windDeg = $item['wind']['deg'] ?? null;

            if ($date >= $todayLocal) {
                $this->ensureDailyBucket($dailyData, $date);

                if ($temp !== null) {
                    $dailyData[$date]['temps'][] = (float) $temp;
                    if ($hour >= 6 && $hour < 18) {
                        $dailyData[$date]['daytime_temps'][] = (float) $temp;
                    }
                }

                if ($hour >= 6 && $hour < 18 && $condition) {
                    $dailyData[$date]['daytime_conditions'][] = [
                        'condition' => $condition,
                        'hour' => $hour,
                    ];
                }
            }

            $periodDate = $hour < 6 ? gmdate('Y-m-d', $localTimestamp - 86400) : $date;
            if ($periodDate < $todayLocal) {
                continue;
            }

            $this->ensureDailyBucket($dailyData, $periodDate);

            if ($temp !== null && ($hour >= 18 || $hour < 6)) {
                $dailyData[$periodDate]['night_temps'][] = (float) $temp;
            }

            if ($condition) {
                $dailyData[$periodDate]['conditions'][] = [
                    'condition' => $condition,
                    'hour' => $hour,
                ];
            }

            $dailyData[$periodDate]['pops'][] = $pop;

            if ($windSpeed !== null) {
                $dailyData[$periodDate]['wind_speeds'][] = (float) $windSpeed;
            }

            if ($windDeg !== null) {
                $dailyData[$periodDate]['wind_degrees'][] = (float) $windDeg;
            }

            if (isset($item['rain']['3h'])) {
                $dailyData[$periodDate]['rain_mm'] += (float) $item['rain']['3h'];
            }

            if (isset($item['snow']['3h'])) {
                $dailyData[$periodDate]['snow_mm'] += (float) $item['snow']['3h'];
            }
        }

        foreach ($dailyData as $date => &$day) {
            $highTemps = $day['daytime_temps'] ?: $day['temps'];
            $lowTemps = $day['night_temps'] ?: $day['temps'];
            $conditionRecords = $day['daytime_conditions'] ?: $day['conditions'];

            $day['date'] = $date;
            $day['day_name'] = gmdate('l', strtotime($date.' 12:00:00 UTC'));
            $day['high'] = $highTemps ? round(max($highTemps)) : null;
            $day['low'] = $lowTemps ? round(min($lowTemps)) : null;
            $day['condition'] = $this->summarizeCondition($conditionRecords, $day['high']);
            $day['icons'] = $this->iconsForConditions($conditionRecords);
            $day['pop'] = $day['pops'] ? round(max($day['pops']) * 100) : 0;
            $day['wind_min'] = $day['wind_speeds'] ? round(min($day['wind_speeds'])) : null;
            $day['wind_max'] = $day['wind_speeds'] ? round(max($day['wind_speeds'])) : null;
            $day['wind_direction'] = $this->dominantWindDirection($day['wind_degrees']);
            $day['rain_in'] = round($day['rain_mm'] * 0.0394, 1);
            $day['snow_in'] = round($day['snow_mm'] * 0.0394, 1);

            $precipParts = [];
            if ($day['snow_in'] > 0) {
                $precipParts[] = "snow {$day['snow_in']}\"";
            }
            if ($day['rain_in'] > 0) {
                $precipParts[] = "rain {$day['rain_in']}\"";
            }
            $day['precip_summary'] = implode(', ', $precipParts);

            unset(
                $day['temps'],
                $day['daytime_temps'],
                $day['night_temps'],
                $day['conditions'],
                $day['daytime_conditions'],
                $day['pops'],
                $day['wind_speeds'],
                $day['wind_degrees'],
                $day['rain_mm'],
                $day['snow_mm']
            );
        }

        ksort($dailyData);

        return array_slice(array_values($dailyData), 0, $this->forecastDays());
    }

    private function ensureDailyBucket(array &$dailyData, string $date): void
    {
        if (isset($dailyData[$date])) {
            return;
        }

        $dailyData[$date] = [
            'temps' => [],
            'daytime_temps' => [],
            'night_temps' => [],
            'conditions' => [],
            'daytime_conditions' => [],
            'pops' => [],
            'wind_speeds' => [],
            'wind_degrees' => [],
            'rain_mm' => 0.0,
            'snow_mm' => 0.0,
        ];
    }

    /**
     * @param  array<int, array{condition:string, hour:int}>  $conditionRecords
     */
    private function summarizeCondition(array $conditionRecords, ?int $high): string
    {
        $conditions = array_map(
            static fn (array $record): string => strtolower($record['condition']),
            $conditionRecords
        );

        $hasFog = $this->containsCondition($conditions, ['fog', 'mist', 'haze']);
        $hasSun = $this->containsCondition($conditions, ['clear', 'sunny']);
        $hasStorm = $this->containsCondition($conditions, ['thunderstorm', 'storm']);
        $hasRain = $this->containsCondition($conditions, ['rain', 'shower', 'drizzle']);
        $hasSnow = $this->containsCondition($conditions, ['snow', 'sleet']);
        $hasFewClouds = $this->containsCondition($conditions, ['few clouds', 'scattered clouds', 'partly cloudy']);
        $hasMostlyCloudy = $this->containsCondition($conditions, ['mostly cloudy']);
        $hasClouds = $this->containsCondition($conditions, ['broken clouds', 'overcast clouds', 'cloudy', 'clouds']);

        if ($hasSnow) {
            return 'Snow';
        }

        if ($hasStorm) {
            return ($high ?? 0) >= 95 ? 'Hot/Storms' : 'PM Storms';
        }

        if ($hasRain) {
            return 'Showers';
        }

        if ($hasFog && $hasSun) {
            return 'Fog->Sunny';
        }

        if ($hasFog) {
            return 'Fog';
        }

        if ($hasSun) {
            return 'Sunny';
        }

        if ($hasFewClouds) {
            return 'Partly Cldy';
        }

        if ($hasMostlyCloudy) {
            return 'Mostly Cldy';
        }

        if ($hasClouds) {
            return 'Cloudy';
        }

        return 'Forecast';
    }

    /**
     * @param  array<int, array{condition:string, hour:int}>  $conditionRecords
     */
    private function iconsForConditions(array $conditionRecords): string
    {
        $conditions = array_map(
            static fn (array $record): string => strtolower($record['condition']),
            $conditionRecords
        );

        $hasFog = $this->containsCondition($conditions, ['fog', 'mist', 'haze']);
        $hasSun = $this->containsCondition($conditions, ['clear', 'sunny']);

        if ($hasFog && $hasSun) {
            return '🌫️☀️';
        }

        if ($this->containsCondition($conditions, ['thunderstorm', 'storm'])) {
            return '⛈️';
        }

        if ($this->containsCondition($conditions, ['rain', 'shower', 'drizzle'])) {
            return '🌧️';
        }

        if ($this->containsCondition($conditions, ['snow', 'sleet'])) {
            return '❄️';
        }

        if ($hasFog) {
            return '🌫️';
        }

        if ($hasSun) {
            return '☀️';
        }

        if ($this->containsCondition($conditions, ['few clouds', 'scattered clouds', 'partly cloudy'])) {
            return '🌤️';
        }

        if ($this->containsCondition($conditions, ['mostly cloudy'])) {
            return '⛅';
        }

        if ($this->containsCondition($conditions, ['broken clouds', 'overcast clouds', 'cloudy', 'clouds'])) {
            return '☁️';
        }

        return '☁️';
    }

    /**
     * @param  array<int, string>  $conditions
     * @param  array<int, string>  $needles
     */
    private function containsCondition(array $conditions, array $needles): bool
    {
        foreach ($conditions as $condition) {
            foreach ($needles as $needle) {
                if (str_contains($condition, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, float>  $windDegrees
     */
    private function dominantWindDirection(array $windDegrees): ?string
    {
        if ($windDegrees === []) {
            return null;
        }

        $sumSin = 0.0;
        $sumCos = 0.0;
        foreach ($windDegrees as $degree) {
            $radians = deg2rad($degree);
            $sumSin += sin($radians);
            $sumCos += cos($radians);
        }

        $average = rad2deg(atan2($sumSin, $sumCos));
        if ($average < 0) {
            $average += 360;
        }

        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $index = (int) round($average / 45) % 8;

        return $directions[$index];
    }

    private function forecastDays(): int
    {
        $value = (int) $this->getConfigValue('forecast_days', config('services.weather.forecast_days', 5));

        return max(1, min(7, $value));
    }

    private function connectTimeoutSeconds(): int
    {
        return $this->intConfigOrPolicy('connect_timeout_seconds');
    }

    private function requestTimeoutSeconds(): int
    {
        return $this->intConfigOrPolicy('request_timeout_seconds');
    }

    private function intConfigOrPolicy(string $key): int
    {
        if (array_key_exists($key, $this->config)) {
            return $this->positiveInt($this->config[$key], (int) self::DEFAULT_RUNTIME_POLICY[$key]);
        }

        return $this->runtimePolicyInt($key);
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function buildPreformattedWeather(string $locationName, array $dailyForecast): string
    {
        $locationLabel = strtoupper($locationName !== '' ? $locationName : 'Weather');
        $forecastDays = $this->forecastDays();
        $lines = [
            "{$locationLabel} {$forecastDays}-DAY",
            'DAY  TEMP   ICON    FORECAST     PERCIP  WIND',
        ];

        foreach ($dailyForecast as $day) {
            $dayCode = strtoupper(substr((string) ($day['day_name'] ?? ''), 0, 3));
            $temp = $this->formatTempRange($day['high'] ?? null, $day['low'] ?? null);
            $icon = $this->formatIconColumn((string) ($day['icons'] ?? '☁️'));
            $forecast = $this->formatForecastColumn((string) ($day['condition'] ?? 'Forecast'));
            $percip = ((int) ($day['pop'] ?? 0)).'%';
            $wind = $this->formatWind($day);

            $lines[] = sprintf(
                '%-4s %-6s %s%-12s %-7s %s',
                $dayCode,
                $temp,
                $icon,
                $forecast,
                $percip,
                $wind
            );
        }

        return implode("\n", $lines);
    }

    private function formatTempRange(mixed $high, mixed $low): string
    {
        $highText = $high === null ? '--' : (string) ((int) $high);
        $lowText = $low === null ? '--' : (string) ((int) $low);

        return "{$highText}/{$lowText}";
    }

    private function formatIconColumn(string $icons): string
    {
        return $icons.(str_contains($icons, '🌫️☀️') ? '    ' : '      ');
    }

    private function formatForecastColumn(string $forecast): string
    {
        if (mb_strlen($forecast) > 12) {
            return mb_substr($forecast, 0, 12);
        }

        return $forecast;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function formatWind(array $day): string
    {
        $direction = $day['wind_direction'] ?? null;
        $min = $day['wind_min'] ?? null;
        $max = $day['wind_max'] ?? null;

        if ($min === null && $max === null) {
            return '--';
        }

        if ($min === null) {
            $speed = (string) ((int) $max);
        } elseif ($max === null || (int) $min === (int) $max) {
            $speed = (string) ((int) $min);
        } else {
            $speed = ((int) $min).'-'.((int) $max);
        }

        return $direction ? "{$direction} {$speed}" : $speed;
    }
}
