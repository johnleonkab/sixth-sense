<?php

namespace App\Console\Commands;

use App\Voice\OpenUi\Data\Datasets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('openui:datasets')]
#[Description('Write resources/openui/datasets.json, the datasets composed views may query, for npm run openui:prompt')]
class ExportOpenUiDatasets extends Command
{
    public const PATH = 'openui/datasets.json';

    /**
     * Execute the console command.
     */
    public function handle(Datasets $datasets): int
    {
        file_put_contents(resource_path(self::PATH), json_encode($datasets->specs(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->components->info('resources/'.self::PATH);

        return self::SUCCESS;
    }
}
