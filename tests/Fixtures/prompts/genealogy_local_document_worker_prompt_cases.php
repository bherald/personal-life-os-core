<?php

return [
    'short_text' => [
        'text' => 'John Doe, born in Springfield, Illinois, worked as a farmer.',
        'context' => [
            'media_type' => 'census transcript',
            'title' => '1920 Doe household census',
        ],
    ],
    'long_text' => [
        'text' => str_repeat('Mary Smith residence entry with spouse and child context. ', 30),
        'context' => [
            'media_type' => 'compiled genealogy notes',
            'title' => 'Smith family research packet',
        ],
    ],
    'noisy_ocr_text' => [
        'text' => "Samu3l Sm1th b. 1834\nm. Mary Ann Doe 2 Jun 1858\nres. Sample Co.",
        'context' => [
            'media_type' => 'ocr transcript',
            'title' => 'Noisy OCR Bible leaf',
        ],
    ],
];
