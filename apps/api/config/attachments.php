<?php

return [
    'disk' => env('ATTACHMENTS_DISK', 'local'),
    'max_source_bytes' => 5 * 1024 * 1024,
    'max_stored_bytes' => 5 * 1024 * 1024,
    'max_dimension' => 2560,
    'max_source_pixels' => 40_000_000,
    'max_per_parent' => 10,
    'max_bytes_per_user' => 100 * 1024 * 1024,
    'cleanup_batch_size' => 100,
    'jpeg_quality' => 85,
    'png_compression' => 6,
    'webp_quality' => 85,
];
