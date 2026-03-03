<?php

namespace App\SiteTypes;

use App\Actions\Database\CreateDatabase;
use App\Actions\Site\UpdateEnv;
use App\Exceptions\SSHError;
use App\Models\Site;
use App\Services\Database\Postgresql;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Laravel extends PHPSite
{
    public static function id(): string
    {
        return 'laravel';
    }

    public static function make(): self
    {
        return new self(new Site(['type' => self::id()]));
    }

    public function createRules(array $input): array
    {
        $rules = parent::createRules($input);

        $hasDatabaseName = ! empty($input['database_name']);

        $rules['database_name'] = [
            'nullable',
            'alpha_dash',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value && ! $this->site->server->database()) {
                    $fail(__('Database service is not installed on this server.'));
                }
            },
            $hasDatabaseName ? Rule::unique('databases', 'name')->where('server_id', $this->site->server_id)->whereNull('deleted_at') : '',
        ];
        $rules['database_user_name'] = [
            $hasDatabaseName ? 'required' : 'nullable',
            'alpha_dash',
            $hasDatabaseName ? Rule::unique('database_users', 'username')->where('server_id', $this->site->server_id)->whereNull('deleted_at') : '',
        ];

        return $rules;
    }

    public function data(array $input): array
    {
        $data = parent::data($input);

        if (! empty($input['database_name'])) {
            $data['database_name'] = $input['database_name'];
            $data['database_user_name'] = $input['database_user_name'];
            $data['database_user_password'] = Str::password(16);
        }

        return $data;
    }

    /**
     * @throws SSHError
     */
    public function install(): void
    {
        parent::install();

        if (! empty($this->site->type_data['database_name'])) {
            $this->createDatabase();
            $this->progress(85);
            $this->setupEnv();
            $this->progress(95);
        }
    }

    public function baseCommands(): array
    {
        return array_merge(parent::baseCommands(), [
            [
                'name' => 'cache:clear',
                'command' => 'php artisan cache:clear',
            ],
            [
                'name' => 'down',
                'command' => 'php artisan down --retry=5 --refresh=6 --quiet',
            ],
            [
                'name' => 'up',
                'command' => 'php artisan up',
            ],
        ]);
    }

    /**
     * @throws SSHError
     */
    private function createDatabase(): void
    {
        $server = $this->site->server;
        $databaseHandler = $server->database()->handler();

        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        if ($databaseHandler instanceof Postgresql) {
            $charset = 'UTF8';
            $collation = 'en_US.UTF-8';
        }

        app(CreateDatabase::class)->create($server, [
            'name' => $this->site->type_data['database_name'],
            'charset' => $charset,
            'collation' => $collation,
            'username' => $this->site->type_data['database_user_name'],
            'password' => $this->site->type_data['database_user_password'],
        ]);
    }

    /**
     * @throws SSHError
     */
    private function setupEnv(): void
    {
        // Copy .env.example to .env and generate key if needed
        $this->site->server->ssh($this->site->user)->exec(
            view('ssh.laravel.setup-env', ['path' => $this->site->path]),
            'setup-laravel-env',
            $this->site->id
        );

        // Read the .env, update DB credentials, and write back
        $env = $this->site->getEnv();
        if ($env) {
            $databaseHandler = $this->site->server->database()->handler();

            $connection = 'mysql';
            $port = '3306';
            if ($databaseHandler instanceof Postgresql) {
                $connection = 'pgsql';
                $port = '5432';
            }

            $env = $this->setEnvValue($env, 'DB_CONNECTION', $connection);
            $env = $this->setEnvValue($env, 'DB_HOST', '127.0.0.1');
            $env = $this->setEnvValue($env, 'DB_PORT', $port);
            $env = $this->setEnvValue($env, 'DB_DATABASE', $this->site->type_data['database_name']);
            $env = $this->setEnvValue($env, 'DB_USERNAME', $this->site->type_data['database_user_name']);
            $env = $this->setEnvValue($env, 'DB_PASSWORD', $this->site->type_data['database_user_password']);

            app(UpdateEnv::class)->update($this->site, ['env' => $env]);
        }
    }

    private function setEnvValue(string $env, string $key, string $value): string
    {
        $escaped = preg_quote($key, '/');
        $pattern = "/^{$escaped}=.*/m";

        if (preg_match($pattern, $env)) {
            return preg_replace($pattern, "{$key}={$value}", $env);
        }

        return $env."\n{$key}={$value}";
    }
}
