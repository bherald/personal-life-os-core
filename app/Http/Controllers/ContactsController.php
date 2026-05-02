<?php

namespace App\Http\Controllers;

use App\Services\NextcloudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

/**
 * Contacts Controller
 *
 * Provides API endpoints for contacts integration with Nextcloud CardDAV.
 * Supports fetching address books and contacts.
 *
 * Features:
 * - Intelligent caching for fast response times
 * - Force refresh capability via ?force=true
 * - Cache metadata in responses (lastUpdated, fromCache)
 */
class ContactsController extends Controller
{
    private NextcloudService $nextcloud;

    public function __construct(NextcloudService $nextcloud)
    {
        $this->nextcloud = $nextcloud;
    }

    /**
     * Get list of available address books
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAddressBooks(Request $request): JsonResponse
    {
        try {
            $forceRefresh = $request->boolean('force', false);
            $addressBooks = $this->nextcloud->getAddressBooks($forceRefresh);

            return response()->json([
                'success' => true,
                'data' => [
                    'addressBooks' => $addressBooks,
                ],
                'cache' => $this->nextcloud->getCacheStatus()['addressbooks'] ?? null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch address books: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get contacts from a specific address book
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getContacts(Request $request): JsonResponse
    {
        try {
            $addressBookId = $request->query('addressBook');
            $forceRefresh = $request->boolean('force', false);
            $contacts = $this->nextcloud->getContacts($addressBookId, $forceRefresh);

            return response()->json([
                'success' => true,
                'data' => [
                    'contacts' => $contacts,
                    'count' => count($contacts),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch contacts: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get all contacts from all address books (cached)
     *
     * Uses intelligent caching for fast response times.
     * Pass ?force=true to bypass cache and fetch fresh data.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllContacts(Request $request): JsonResponse
    {
        try {
            $forceRefresh = $request->boolean('force', false);

            // Use the cached method from NextcloudService
            $result = $this->nextcloud->getAllContactsCached($forceRefresh);

            return response()->json([
                'success' => true,
                'data' => [
                    'contacts' => $result['contacts'],
                    'addressBooks' => $result['addressBooks'],
                    'count' => $result['count'],
                ],
                'cache' => $result['cache'] ?? null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch contacts: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Clear contacts caches and return fresh data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            // Clear caches
            $this->nextcloud->clearAllCaches();

            // Fetch fresh data
            $result = $this->nextcloud->getAllContactsCached(true);

            return response()->json([
                'success' => true,
                'message' => 'Cache refreshed successfully',
                'data' => [
                    'contacts' => $result['contacts'],
                    'addressBooks' => $result['addressBooks'],
                    'count' => $result['count'],
                ],
                'cache' => $result['cache'] ?? null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to refresh contacts data: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get cache status for contacts
     *
     * @return JsonResponse
     */
    public function cacheStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->nextcloud->getCacheStatus(),
        ]);
    }
}
