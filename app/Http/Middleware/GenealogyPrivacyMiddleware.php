<?php

namespace App\Http\Middleware;

use App\Services\Genealogy\PrivacyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to apply privacy filters to genealogy API responses
 *
 * Automatically redacts living person data based on tree privacy settings
 * and user permissions. Implements Priority 3.1 from genealogy-module-review.md.
 *
 * @see /docs/genealogy-module-review.md Priority 3.1
 */
class GenealogyPrivacyMiddleware
{
    protected PrivacyService $privacyService;

    public function __construct(PrivacyService $privacyService)
    {
        $this->privacyService = $privacyService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only process JSON responses
        if (!$response instanceof JsonResponse) {
            return $response;
        }

        // Get response data
        $data = $response->getData(true);

        // Skip if no data or not successful
        if (!isset($data['success']) || $data['success'] !== true) {
            return $response;
        }

        // Get the current user ID (null for guests)
        $userId = Auth::id();

        // Determine if this is a public request (no auth)
        $isPublicRequest = $userId === null;

        // Apply privacy filtering to the response data
        $filteredData = $this->applyPrivacyFilters($data, $userId, $isPublicRequest);

        // Return modified response
        return $response->setData($filteredData);
    }

    /**
     * Apply privacy filters to response data
     *
     * @param array $data
     * @param int|null $userId
     * @param bool $isPublicRequest
     * @return array
     */
    protected function applyPrivacyFilters(array $data, ?int $userId, bool $isPublicRequest): array
    {
        // Handle single person in data
        if (isset($data['data']) && $this->isPersonData($data['data'])) {
            $data['data'] = $this->filterPersonData($data['data'], $userId);
            return $data;
        }

        // Handle array of persons in data
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = $this->filterArrayData($data['data'], $userId);
            return $data;
        }

        // Handle pagination structure
        if (isset($data['data']['persons']) && is_array($data['data']['persons'])) {
            $data['data']['persons'] = array_map(
                fn($person) => $this->filterPersonData($person, $userId),
                $data['data']['persons']
            );
        }

        // Handle tree data with persons
        if (isset($data['data']['data']) && is_array($data['data']['data'])) {
            $data['data']['data'] = $this->filterArrayData($data['data']['data'], $userId);
        }

        // Handle family data with spouse/children arrays
        if (isset($data['data']['families']) && is_array($data['data']['families'])) {
            $data['data']['families'] = array_map(
                fn($family) => $this->filterFamilyData($family, $userId),
                $data['data']['families']
            );
        }

        return $data;
    }

    /**
     * Check if data represents a person record
     *
     * @param mixed $data
     * @return bool
     */
    protected function isPersonData(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        // Check for common person fields
        $personFields = ['given_name', 'surname', 'birth_date', 'death_date', 'living'];
        $matches = 0;
        foreach ($personFields as $field) {
            if (array_key_exists($field, $data)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    /**
     * Filter person data based on privacy settings
     *
     * @param array $person
     * @param int|null $userId
     * @return array
     */
    protected function filterPersonData(array $person, ?int $userId): array
    {
        // Skip if no tree_id (can't apply privacy)
        if (!isset($person['tree_id'])) {
            return $person;
        }

        return $this->privacyService->applyPrivacyFilter(
            $person,
            (int)$person['tree_id'],
            $userId
        );
    }

    /**
     * Filter an array of data items
     *
     * @param array $items
     * @param int|null $userId
     * @return array
     */
    protected function filterArrayData(array $items, ?int $userId): array
    {
        return array_map(function ($item) use ($userId) {
            if (is_array($item) && $this->isPersonData($item)) {
                return $this->filterPersonData($item, $userId);
            }
            return $item;
        }, $items);
    }

    /**
     * Filter family data including nested person data
     *
     * @param array $family
     * @param int|null $userId
     * @return array
     */
    protected function filterFamilyData(array $family, ?int $userId): array
    {
        // Filter husband if present
        if (isset($family['husband']) && is_array($family['husband'])) {
            $family['husband'] = $this->filterPersonData($family['husband'], $userId);
        }

        // Filter wife if present
        if (isset($family['wife']) && is_array($family['wife'])) {
            $family['wife'] = $this->filterPersonData($family['wife'], $userId);
        }

        // Filter children if present
        if (isset($family['children']) && is_array($family['children'])) {
            $family['children'] = array_map(
                fn($child) => is_array($child) ? $this->filterPersonData($child, $userId) : $child,
                $family['children']
            );
        }

        return $family;
    }
}
