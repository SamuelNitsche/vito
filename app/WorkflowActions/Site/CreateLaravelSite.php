<?php

namespace App\WorkflowActions\Site;

class CreateLaravelSite extends CreateSite
{
    public function inputs(): array
    {
        return array_merge(parent::inputs(), [
            'type' => 'laravel',
            'php_version' => 'PHP version, example: 8.1, 8.2',
            'source_control' => 'Source control ID',
            'repository' => 'organization/repository',
            'branch' => 'Branch to deploy, example: main',
            'web_directory' => 'public',
            'composer' => 'whether to run composer install (true/false, optional)',
            'database_name' => 'Database name to create (optional)',
            'database_user_name' => 'Database username to create (required if database_name is provided, password is auto-generated)',
        ]);
    }
}
