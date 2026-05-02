<?php

namespace App\Http\Traits;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Response Trait - Standardized API response formatting
 *
 * Provides consistent JSON response structure across all API controllers.
 * Extracted as part of Priority 2.4 for standardized error handling.
 *
 * @see /docs/genealogy-module-review.md Priority 2.4
 */
trait ApiResponseTrait
{
    /**
     * Return a successful response
     *
     * @param mixed $data Response data
     * @param string|null $message Success message
     * @param int $code HTTP status code
     * @return JsonResponse
     */
    protected function successResponse(
        mixed $data = null,
        ?string $message = null,
        int $code = Response::HTTP_OK
    ): JsonResponse {
        $response = [
            'success' => true,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response, $code);
    }

    /**
     * Return a created response (201)
     *
     * @param mixed $data Response data
     * @param string|null $message Success message
     * @return JsonResponse
     */
    protected function createdResponse(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->successResponse($data, $message ?? 'Resource created successfully', Response::HTTP_CREATED);
    }

    /**
     * Return an error response
     *
     * @param string $message Error message
     * @param Exception|null $exception Exception for logging
     * @param int $code HTTP status code
     * @param array|null $errors Additional error details
     * @param bool $logError Whether to log the error
     * @return JsonResponse
     */
    protected function errorResponse(
        string $message,
        ?Exception $exception = null,
        int $code = Response::HTTP_INTERNAL_SERVER_ERROR,
        ?array $errors = null,
        bool $logError = true
    ): JsonResponse {
        // Auto-detect appropriate status code based on exception type
        if ($exception !== null && $code === Response::HTTP_INTERNAL_SERVER_ERROR) {
            $code = $this->getStatusCodeForException($exception);
        }

        $response = [
            'success' => false,
            'error' => [
                'message' => $exception !== null ? "{$message}: {$exception->getMessage()}" : $message,
            ],
        ];

        if ($errors !== null) {
            $response['error']['errors'] = $errors;
        }

        // Add exception code if available (useful for debugging)
        if ($exception !== null && $exception->getCode() !== 0) {
            $response['error']['code'] = $exception->getCode();
        }

        // Log the error if enabled
        if ($logError && $exception !== null) {
            $this->logError($message, $exception, $code);
        }

        return response()->json($response, $code);
    }

    /**
     * Return a not found response (404)
     *
     * @param string $resource Resource name
     * @param int|string|null $id Resource ID
     * @return JsonResponse
     */
    protected function notFoundResponse(string $resource, int|string|null $id = null): JsonResponse
    {
        $message = $id !== null
            ? "{$resource} with ID {$id} not found"
            : "{$resource} not found";

        return $this->errorResponse($message, null, Response::HTTP_NOT_FOUND, null, false);
    }

    /**
     * Return a validation error response (422)
     *
     * @param array $errors Validation errors
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function validationErrorResponse(
        array $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => $message,
                'errors' => $errors,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Return an unauthorized response (401)
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->errorResponse($message, null, Response::HTTP_UNAUTHORIZED, null, false);
    }

    /**
     * Return a forbidden response (403)
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->errorResponse($message, null, Response::HTTP_FORBIDDEN, null, false);
    }

    /**
     * Return a bad request response (400)
     *
     * @param string $message Error message
     * @param Exception|null $exception Optional exception
     * @return JsonResponse
     */
    protected function badRequestResponse(string $message, ?Exception $exception = null): JsonResponse
    {
        return $this->errorResponse($message, $exception, Response::HTTP_BAD_REQUEST);
    }

    /**
     * Return a conflict response (409)
     *
     * @param string $message Error message
     * @param Exception|null $exception Optional exception
     * @return JsonResponse
     */
    protected function conflictResponse(string $message, ?Exception $exception = null): JsonResponse
    {
        return $this->errorResponse($message, $exception, Response::HTTP_CONFLICT);
    }

    /**
     * Return a service unavailable response (503)
     *
     * @param string $message Error message
     * @param Exception|null $exception Optional exception
     * @return JsonResponse
     */
    protected function serviceUnavailableResponse(
        string $message = 'Service temporarily unavailable',
        ?Exception $exception = null
    ): JsonResponse {
        return $this->errorResponse($message, $exception, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * Get appropriate HTTP status code for exception type
     *
     * @param Exception $exception
     * @return int
     */
    protected function getStatusCodeForException(Exception $exception): int
    {
        return match (true) {
            $exception instanceof ValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception instanceof InvalidArgumentException => Response::HTTP_BAD_REQUEST,
            str_contains(strtolower($exception->getMessage()), 'not found') => Response::HTTP_NOT_FOUND,
            str_contains(strtolower($exception->getMessage()), 'unauthorized') => Response::HTTP_UNAUTHORIZED,
            str_contains(strtolower($exception->getMessage()), 'forbidden') => Response::HTTP_FORBIDDEN,
            str_contains(strtolower($exception->getMessage()), 'duplicate') => Response::HTTP_CONFLICT,
            str_contains(strtolower($exception->getMessage()), 'already exists') => Response::HTTP_CONFLICT,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * Log an error with context
     *
     * @param string $message
     * @param Exception $exception
     * @param int $code
     * @return void
     */
    protected function logError(string $message, Exception $exception, int $code): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'http_code' => $code,
        ];

        // Log as error for 5xx, warning for 4xx
        if ($code >= 500) {
            Log::error($message, $context);
        } else {
            Log::warning($message, $context);
        }
    }

    /**
     * Handle an exception and return appropriate response
     *
     * Convenience method that determines the best response type based on exception
     *
     * @param string $context Context message (e.g., "Failed to create person")
     * @param Exception $exception The exception
     * @return JsonResponse
     */
    protected function handleException(string $context, Exception $exception): JsonResponse
    {
        // Handle validation exceptions specially
        if ($exception instanceof ValidationException) {
            return $this->validationErrorResponse(
                $exception->errors(),
                $context
            );
        }

        // Handle invalid argument exceptions as bad requests
        if ($exception instanceof InvalidArgumentException) {
            return $this->badRequestResponse($context, $exception);
        }

        // Default error handling
        return $this->errorResponse($context, $exception);
    }

    /**
     * Return a paginated response
     *
     * @param array $data Items for the current page
     * @param int $total Total number of items
     * @param int $page Current page number
     * @param int $perPage Items per page
     * @param string|null $message Optional message
     * @return JsonResponse
     */
    protected function paginatedResponse(
        array $data,
        int $total,
        int $page,
        int $perPage,
        ?string $message = null
    ): JsonResponse {
        $lastPage = (int) ceil($total / $perPage);

        $response = [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
                'to' => min($page * $perPage, $total),
            ],
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response);
    }
}
