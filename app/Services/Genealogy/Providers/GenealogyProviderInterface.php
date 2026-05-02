<?php

namespace App\Services\Genealogy\Providers;

/**
 * Interface for all external genealogy data providers
 *
 * Supports multiple authentication types:
 * - none: No authentication required (LOC, some NARA endpoints)
 * - api_key: Simple API key authentication
 * - oauth2: Full OAuth2 flow for future providers that explicitly support it
 * - session: Cookie-based session authentication
 *
 * All providers must implement RAW SQL for any database operations
 */
interface GenealogyProviderInterface
{
    /**
     * Get the provider's unique identifier
     */
    public function getProviderId(): string;

    /**
     * Get human-readable provider name
     */
    public function getProviderName(): string;

    /**
     * Get authentication type: none, api_key, oauth2, session
     */
    public function getAuthType(): string;

    /**
     * Check if provider is properly configured and ready to use
     */
    public function isConfigured(): bool;

    /**
     * Check if provider is currently authenticated (for oauth2/session)
     */
    public function isAuthenticated(): bool;

    /**
     * Get OAuth2 authorization URL (for oauth2 providers)
     */
    public function getAuthorizationUrl(?string $state = null): ?string;

    /**
     * Handle OAuth2 callback and store tokens
     */
    public function handleOAuthCallback(string $code, ?string $state = null): bool;

    /**
     * Refresh OAuth2 access token if expired
     */
    public function refreshAccessToken(): bool;

    /**
     * Search for persons matching criteria
     */
    public function searchPersons(array $criteria, array $options = []): array;

    /**
     * Search for records (births, deaths, marriages, etc.)
     */
    public function searchRecords(array $criteria, array $options = []): array;

    /**
     * Get a specific record by ID
     */
    public function getRecord(string $recordId): ?array;

    /**
     * Get person details from external source
     */
    public function getPerson(string $personId): ?array;

    /**
     * Get person's family relationships
     */
    public function getPersonFamily(string $personId): ?array;

    /**
     * Get available record collections/databases
     */
    public function getCollections(): array;

    /**
     * Get provider capabilities
     */
    public function getCapabilities(): array;

    /**
     * Get rate limit status
     */
    public function getRateLimitStatus(): array;

    /**
     * Get last error message
     */
    public function getLastError(): ?string;
}
