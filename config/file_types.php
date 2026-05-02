<?php

/**
 * Centralized file type extension lists
 *
 * Master reference for file type classification. Services should reference
 * these instead of maintaining their own duplicate constants.
 *
 * Usage: config('file_types.image'), config('file_types.video'), etc.
 * For image+raw: array_merge(config('file_types.image'), config('file_types.image_raw'))
 */

return [
    // Common raster image formats (web/display-safe)
    'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif', 'heic', 'heif'],

    // RAW camera formats (require specialized processing)
    'image_raw' => ['raw', 'cr2', 'nef', 'arw', 'dng', 'orf', 'rw2'],

    // Extensions that support EXIF/XMP metadata
    'exif' => ['jpg', 'jpeg', 'tiff', 'tif', 'heic', 'heif', 'png', 'webp', 'dng', 'cr2', 'nef', 'arw'],

    // Video formats
    'video' => ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp'],

    // Audio formats
    'audio' => ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'wma', 'aiff'],

    // Document/office formats
    'document' => [
        'pdf', 'doc', 'docx', 'rtf', 'odt', 'txt',
        'xls', 'xlsx', 'csv', 'ods',
        'ppt', 'pptx', 'odp',
        'html', 'htm', 'md', 'epub',
    ],

    // Office-only subset (no text/markup formats)
    'office' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'],

    // Archive formats
    'archive' => ['zip', '7z', 'tar', 'gz', 'tgz', 'bz2'],

    // Code/script extensions (extractable as plain text)
    'code' => [
        'php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'svelte', 'mjs', 'cjs',
        'py', 'rb', 'go', 'rs', 'swift', 'kt', 'scala', 'lua', 'r',
        'java', 'cpp', 'c', 'h', 'cs', 'sql', 'sh', 'bash', 'zsh',
        'toml', 'ini', 'conf', 'env', 'yaml', 'yml', 'svg', 'css',
    ],

    // Plain text formats
    'text' => ['txt', 'md', 'html', 'htm', 'rtf', 'csv', 'json', 'xml'],

    // PDF only
    'pdf' => ['pdf'],

    // Extensions eligible for RAG indexing (semantic content worth searching).
    // Code, config, archives, and binary-markup files are excluded — they produce
    // short, low-value content that pollutes rag_documents without aiding search.
    // Images are handled separately (require ai_description IS NOT NULL).
    'rag_indexable' => [
        'pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'md', 'csv',
        'html', 'htm', 'xls', 'xlsx', 'ppt', 'pptx', 'epub',
    ],
];
