<?php

/**
 * Public setup health-check manifest.
 *
 * Drives `php artisan setup:doctor`. Read-only checks only — never invoke
 * installers, never write outside report output, never call non-localhost
 * services.
 *
 * Profiles map check groups to a concrete subset of requirements. `core` is
 * the minimal Laravel + DB + Redis surface needed to boot. `media` adds
 * extraction binaries, Python tier deps, pgvector, browser runtimes, and
 * face/media assets. `gpu` adds GPU-runtime hints. `full` is the union of
 * public profiles. `personal` adds operator-local services and credentials
 * that should stay out of public CI.
 */

return [
    'profiles' => ['core', 'media', 'gpu', 'full', 'personal'],

    'groups' => ['env', 'php', 'binaries', 'python', 'services', 'passport', 'database', 'browser', 'assets', 'docker'],

    /*
    |---------------------------------------------------------------------
    | Required env keys per profile
    |---------------------------------------------------------------------
    |
    | `required` keys must be present and non-empty (after stripping the
    | sample placeholder shown in `placeholders`). `recommended` keys warn
    | when missing/blank but never fail.
    */
    'env' => [
        'core' => [
            'required' => [
                'APP_KEY',
                'APP_URL',
                'DB_CONNECTION',
                'DB_HOST',
                'DB_DATABASE',
                'DB_USERNAME',
                'REDIS_HOST',
                'WEB_UI_MASTER_PASSWORD',
            ],
            'recommended' => [
                'APP_NAME',
                'APP_ENV',
                'CACHE_STORE',
                'QUEUE_CONNECTION',
                'SESSION_DRIVER',
            ],
        ],
        'media' => [
            'required' => [
                'RAG_DB_HOST',
                'RAG_DB_DATABASE',
                'RAG_DB_USERNAME',
            ],
            'recommended' => [
                'TIKA_URL',
                'NEXTCLOUD_URL',
                'NEXTCLOUD_DATA_PATH',
                'SEARXNG_URL',
            ],
        ],
        'gpu' => [
            'required' => [],
            'recommended' => [
                'OLLAMA_API_URL',
                'OLLAMA_MODEL',
                'OLLAMA_EMBEDDING_MODEL',
                'OLLAMA_VISION_MODEL',
            ],
        ],
        'full' => [
            'required' => [],
            'recommended' => [
                'WHISPER_PATH',
            ],
        ],
        'personal' => [
            'required' => [],
            'recommended' => [
                'PUSHOVER_API_TOKEN',
                'PUSHOVER_USER_KEY',
                'NEXTCLOUD_JOPLIN_PATH',
                'JOPLIN_WATCH_LATER_FOLDER_ID',
                'THUNDERBIRD_MCP_URL',
            ],
        ],
        'placeholders' => ['change-me', 'change-root-me', 'your-api-key'],
    ],

    /*
    |---------------------------------------------------------------------
    | PHP runtime requirements
    |---------------------------------------------------------------------
    */
    'php' => [
        'min_version' => '8.2.0',
        'recommended_version' => '8.3.0',
        'extensions' => [
            'core' => [
                'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'json',
                'mbstring', 'openssl', 'pcntl', 'pdo', 'pdo_mysql', 'redis',
                'simplexml', 'sockets', 'tokenizer', 'xml', 'xmlreader',
                'xmlwriter', 'zip',
            ],
            'media' => [
                'exif', 'gd', 'pdo_pgsql', 'xsl',
            ],
            'gpu' => [],
            'full' => ['imagick'],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | OS binaries on PATH
    |---------------------------------------------------------------------
    |
    | `required` binaries fail the group; `recommended` only warn.
    */
    'binaries' => [
        'core' => [
            'required' => ['php', 'composer'],
            'recommended' => ['node', 'npm', 'git', 'curl', 'unzip'],
        ],
        'media' => [
            'required' => [
                [
                    'name' => 'exiftool',
                    'min_version' => '12.30',
                    'version_args' => ['-ver'],
                    'version_regex' => '/(\d+(?:\.\d+){0,3})/',
                ],
                [
                    'name' => 'ffmpeg',
                    'min_version' => '4.4',
                    'version_args' => ['-version'],
                    'version_regex' => '/ffmpeg version\s+(\d+(?:\.\d+){0,3})/i',
                ],
                [
                    'name' => 'ffprobe',
                    'min_version' => '4.4',
                    'version_args' => ['-version'],
                    'version_regex' => '/ffprobe version\s+(\d+(?:\.\d+){0,3})/i',
                ],
                'pdftoppm',
                'pdftotext',
                [
                    'name' => 'tesseract',
                    'min_version' => '5.0',
                    'version_args' => ['--version'],
                    'version_regex' => '/tesseract\s+(\d+(?:\.\d+){0,3})/i',
                ],
            ],
            'recommended' => ['libreoffice', 'soffice', 'docx2txt', 'antiword', '7z', 'yt-dlp'],
        ],
        'gpu' => [
            'required' => [],
            'recommended' => ['nvidia-smi'],
        ],
        'full' => [
            'required' => [],
            'recommended' => [
                [
                    'name' => 'java',
                    'min_version' => '11',
                    'version_args' => ['-version'],
                    'version_regex' => '/version\s+"?(\d+(?:\.\d+){0,3})/i',
                ],
                'whisper',
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Python tiers
    |---------------------------------------------------------------------
    |
    | A tier is satisfied when the requirement file exists, Python 3.10+
    | is on PATH, and declared modules import cleanly. spaCy model checks
    | stay read-only and report the download command when the model is
    | missing.
    */
    'python' => [
        'binary' => env('PYTHON_BINARY'),
        'min_version' => '3.10',
        'tiers' => [
            'core' => [
                'requirements_file' => 'requirements-core.txt',
                'modules' => ['numpy', 'PIL', 'psycopg2', 'tqdm'],
            ],
            'media' => [
                'requirements_file' => 'requirements-media.txt',
                'modules' => ['face_recognition', 'dlib', 'spacy', 'hdbscan', 'igraph', 'leidenalg', 'sklearn', 'scipy'],
                'spacy_models' => [
                    [
                        'name' => 'en_core_web_sm',
                        'required' => false,
                    ],
                ],
            ],
            'gpu' => [
                'requirements_file' => 'requirements-gpu.txt',
                'modules' => ['torch', 'torchvision', 'transformers', 'sentence_transformers', 'whisper'],
            ],
            'full' => [
                'requirements_file' => 'requirements-gpu.txt',
                'modules' => [],
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Localhost services
    |---------------------------------------------------------------------
    |
    | Only host:port reachability is probed. URLs are resolved from env
    | with a default; remote/LAN URLs are flagged as warn and not probed.
    */
    'services' => [
        'core' => [
            [
                'name' => 'mysql',
                'env' => 'DB_HOST',
                'host_default' => '127.0.0.1',
                'port_env' => 'DB_PORT',
                'port_default' => 3306,
                'required' => true,
            ],
            [
                'name' => 'redis',
                'env' => 'REDIS_HOST',
                'host_default' => '127.0.0.1',
                'port_env' => 'REDIS_PORT',
                'port_default' => 6379,
                'required' => true,
            ],
        ],
        'media' => [
            [
                'name' => 'postgres',
                'env' => 'RAG_DB_HOST',
                'host_default' => '127.0.0.1',
                'port_env' => 'RAG_DB_PORT',
                'port_default' => 5432,
                'required' => true,
            ],
            [
                'name' => 'tika',
                'url_env' => 'TIKA_URL',
                'url_default' => 'http://127.0.0.1:9998',
                'required' => false,
                'version_path' => '/version',
                'min_version' => '2.9',
                'version_regex' => '/Apache Tika\s+(\d+\.\d+(?:\.\d+){0,2})/',
            ],
            [
                'name' => 'searxng',
                'url_env' => 'SEARXNG_URL',
                'url_default' => 'http://127.0.0.1:8888',
                'required' => false,
            ],
        ],
        'gpu' => [
            [
                'name' => 'ollama',
                'url_env' => 'OLLAMA_API_URL',
                'url_default' => 'http://127.0.0.1:11434',
                'required' => false,
                'model_tags_path' => '/api/tags',
                'model_envs' => [
                    ['env' => 'OLLAMA_MODEL', 'default' => 'llama3.1:8b'],
                    ['env' => 'OLLAMA_EMBEDDING_MODEL', 'default' => 'nomic-embed-text'],
                    ['env' => 'OLLAMA_VISION_MODEL', 'default' => 'llava:7b'],
                ],
            ],
        ],
        'full' => [
            [
                'name' => 'nextcloud',
                'url_env' => 'NEXTCLOUD_URL',
                'url_default' => 'http://127.0.0.1:8080',
                'required' => false,
            ],
        ],
        'personal' => [
            [
                'name' => 'thunderbird',
                'url_env' => 'THUNDERBIRD_MCP_URL',
                'url_default' => 'http://127.0.0.1:8766',
                'required' => false,
            ],
        ],
        'connect_timeout_seconds' => 2,
    ],

    /*
    |---------------------------------------------------------------------
    | Database feature probes
    |---------------------------------------------------------------------
    |
    | These checks connect through Laravel database connections and run
    | read-only metadata queries. They are skipped by `--skip-services`.
    */
    'database' => [
        'core' => [],
        // GPU embedding jobs still write into the media/RAG PostgreSQL schema,
        // so DatabaseChecker includes these media checks for the gpu profile.
        'media' => [
            'postgres_extensions' => [
                [
                    'connection' => 'pgsql_rag',
                    'extension' => 'vector',
                    'required' => true,
                ],
                [
                    'connection' => 'pgsql_rag',
                    'extension' => 'fuzzystrmatch',
                    'required' => true,
                ],
                [
                    'connection' => 'pgsql_rag',
                    'extension' => 'pg_trgm',
                    'required' => false,
                ],
            ],
        ],
        'gpu' => [],
        'full' => [],
        'personal' => [],
    ],

    /*
    |---------------------------------------------------------------------
    | Browser automation runtime probes
    |---------------------------------------------------------------------
    */
    'browser' => [
        'core' => [],
        'media' => [
            [
                'name' => 'playwright.chromium',
                'engine' => 'playwright',
                'required' => false,
                'install_hint' => 'npx playwright install --with-deps chromium',
            ],
            [
                'name' => 'puppeteer.chrome',
                'engine' => 'puppeteer',
                'required' => false,
                'env_keys' => ['PUPPETEER_EXECUTABLE_PATH', 'PLOS_PUPPETEER_CHROME'],
                'config_path' => 'mcp.servers.puppeteer.env.PUPPETEER_EXECUTABLE_PATH',
                'cache_globs' => ['$HOME/.cache/puppeteer/chrome/*/chrome-linux64/chrome'],
                'fallback_paths' => ['/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/usr/bin/chromium'],
                'fallback_bins' => ['google-chrome', 'chromium-browser', 'chromium', 'chrome'],
                'install_hint' => 'set PLOS_PUPPETEER_CHROME/PUPPETEER_EXECUTABLE_PATH or install Chrome/Chromium',
            ],
        ],
        'gpu' => [],
        'full' => [],
        'personal' => [],
    ],

    /*
    |---------------------------------------------------------------------
    | Runtime assets that are intentionally not installed by the doctor
    |---------------------------------------------------------------------
    */
    'assets' => [
        'core' => [
            'required_writable_dirs' => [
                'storage',
                'storage/app',
                'bootstrap/cache',
            ],
        ],
        'media' => [
            'recommended_writable_dirs' => [
                'storage/app/temp',
                'storage/app/thumbnails',
                'storage/app/face_batch',
                'storage/app/face_crops',
            ],
            'required_files' => [
                'scripts/browser-server/puppeteer-server.cjs',
                'scripts/browser-server/playwright-server.cjs',
            ],
            'recommended_files' => [
                'scripts/shape_predictor_68_face_landmarks.dat',
                'scripts/dlib_face_recognition_resnet_model_v1.dat',
            ],
            'required_dirs' => [],
            'recommended_dirs' => [],
            'env_dirs' => [
                [
                    'name' => 'nextcloud.data_path',
                    'env' => 'NEXTCLOUD_DATA_PATH',
                    'readable' => true,
                    'fail_when_set' => true,
                ],
            ],
        ],
        'gpu' => [],
        'full' => [],
        'personal' => [],
    ],

    /*
    |---------------------------------------------------------------------
    | Docker assets shipped with the public scaffold
    |---------------------------------------------------------------------
    */
    'docker' => [
        'core' => [
            'engine' => [
                'required' => false,
                'compose' => true,
                'daemon' => true,
            ],
            'compose_files' => ['docker-compose.yml'],
            'required_files' => ['docker/README.md'],
            'required_dirs' => ['docker/php', 'docker/nginx'],
        ],
        'media' => [
            'compose_files' => [],
            'required_files' => [],
            'required_dirs' => ['docker/postgres'],
        ],
        'gpu' => [
            'compose_files' => [],
            'required_files' => [],
            'required_dirs' => [],
        ],
        'full' => [
            'compose_files' => [],
            'required_files' => [],
            'required_dirs' => [],
        ],
        'personal' => [
            'compose_files' => [],
            'required_files' => [],
            'required_dirs' => [],
        ],
    ],
];
