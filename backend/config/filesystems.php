<?php

return [
    'default' => 'local',
    'disks' => [
        // Private storage: invoices, employee documents, attachments. Never served directly.
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => false, 'throw' => true],
    ],
    'links' => [],
];
