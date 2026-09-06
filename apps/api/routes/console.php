<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:validate-environment', function (): int {
    $required = [
        'APP_KEY' => config('app.key'),
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.pgsql.host'),
        'DB_DATABASE' => config('database.connections.pgsql.database'),
        'DB_USERNAME' => config('database.connections.pgsql.username'),
        'REDIS_HOST' => config('database.redis.default.host'),
        'AWS_ACCESS_KEY_ID' => config('filesystems.disks.s3.key'),
        'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.s3.secret'),
        'AWS_BUCKET' => config('filesystems.disks.s3.bucket'),
        'AWS_ENDPOINT' => config('filesystems.disks.s3.endpoint'),
        'MAIL_HOST' => config('mail.mailers.smtp.host'),
    ];
    $expected = [
        'DB_CONNECTION' => [config('database.default'), 'pgsql'],
        'CACHE_STORE' => [config('cache.default'), 'redis'],
        'QUEUE_CONNECTION' => [config('queue.default'), 'redis'],
        'FILESYSTEM_DISK' => [config('filesystems.default'), 's3'],
        'MAIL_MAILER' => [config('mail.default'), 'smtp'],
    ];

    $missing = array_keys(array_filter(
        $required,
        static fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
    ));

    if ($missing !== []) {
        $this->error('Missing required environment values: '.implode(', ', $missing));

        return Command::FAILURE;
    }

    $invalid = array_keys(array_filter(
        $expected,
        static fn (array $values): bool => $values[0] !== $values[1],
    ));

    if ($invalid !== []) {
        $this->error('Invalid local service drivers: '.implode(', ', $invalid));

        return Command::FAILURE;
    }

    $this->info('Environment configuration is valid.');

    return Command::SUCCESS;
})->purpose('Validate required API runtime configuration');
