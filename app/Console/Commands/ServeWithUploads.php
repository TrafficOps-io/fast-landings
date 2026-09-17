<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand;

class ServeWithUploads extends ServeCommand
{
    protected $name = 'fast-landings:serve';

    protected $description = 'Serve Fast Landings locally with its configured archive and image upload limits';

    protected function serverCommand(): array
    {
        $command = parent::serverCommand();
        $uploadMb = (int) ceil(max(
            config('fast-landings.max_upload_kb'),
            config('fast-landings.templates.max_image_kb'),
        ) / 1024);

        // PHP starts a child server process; flags on the Artisan parent do not
        // propagate. Leave room for multipart metadata above the file limit.
        array_splice($command, 1, 0, [
            '-d', 'upload_max_filesize='.$uploadMb.'M',
            '-d', 'post_max_size='.($uploadMb + 10).'M',
        ]);

        return $command;
    }
}
