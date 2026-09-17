<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        // Each import and image field validates its own format after staging.
        'rules' => ['required', 'file', 'max:'.config('fast-landings.max_upload_kb')],
        'directory' => 'livewire-tmp',
        'middleware' => ['throttle:30,1', 'panel-host', 'auth', 'active'],
        'preview_mimes' => [],
        'max_upload_time' => 10,
        'cleanup' => true,
    ],
];
