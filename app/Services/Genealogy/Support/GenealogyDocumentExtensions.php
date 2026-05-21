<?php

namespace App\Services\Genealogy\Support;

/**
 * Unified extension classifier for genealogy ingest and intake staging.
 *
 * Source of truth is `config/file_types.php`:
 *   - `document` covers doc/office/text/markup formats
 *   - `image`    covers scan-of-certificate evidence (jpg, png, tif, etc.)
 *
 * Genealogy ingest operates at the evidence layer and accepts BOTH —
 * the union is computed once here so `GenealogyDocumentIngestionService`,
 * `GenealogyIntakeStagingService`, and the health-gate drift guard all
 * read the same list. Replaces the parallel private `DOCUMENT_EXTENSIONS`
 * constants that shipped in both services pre-2026-04-18.
 */
final class GenealogyDocumentExtensions
{
    /**
     * Extensions that ingest will classify as an evidence document.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $document = (array) config('file_types.document', []);
        $image = (array) config('file_types.image', []);

        $merged = array_map(
            static fn (string $ext): string => strtolower($ext),
            array_merge($document, $image)
        );

        return array_values(array_unique($merged));
    }

    /**
     * True if the given extension is in the unified allowlist.
     */
    public static function isAllowed(string $extension): bool
    {
        return in_array(strtolower(ltrim($extension, '.')), self::allowed(), true);
    }

    /**
     * True if the extension is in `config('file_types.image')` — useful for
     * callers that need to treat image-class evidence differently from
     * document-class evidence at classification time (e.g., AI vision
     * routing vs Tika routing) without re-hardcoding the image list.
     */
    public static function isImage(string $extension): bool
    {
        $ext = strtolower(ltrim($extension, '.'));

        return in_array($ext, (array) config('file_types.image', []), true);
    }

    /**
     * Resolve a best-effort MIME type from an extension covered by the
     * unified allowlist. Returns `application/octet-stream` for unknown
     * extensions so callers can treat that as "unclassified".
     */
    public static function mimeFromExtension(string $extension): string
    {
        return match (strtolower(ltrim($extension, '.'))) {
            // Document class
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'html', 'htm' => 'text/html',
            'md' => 'text/markdown',
            'epub' => 'application/epub+zip',
            // Image class
            'jpg', 'jpeg', 'jfif' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'jp2', 'j2k', 'jpf', 'jpx' => 'image/jp2',
            default => 'application/octet-stream',
        };
    }
}
