<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$missingIds = DB::table('operations')
    ->leftJoin('users', 'operations.user_id', '=', 'users.id')
    ->whereNull('users.id')
    ->select('operations.user_id')
    ->distinct()
    ->orderBy('operations.user_id')
    ->pluck('operations.user_id');

if ($missingIds->isEmpty()) {
    echo "No missing users found.\n";
    exit(0);
}

$now = now();
$rows = [];

foreach ($missingIds as $userId) {
    $label = (string) $userId;

    $rows[] = [
        'id' => $userId,
        'name' => 'User ' . $label,
        'email' => 'user-' . $label . '@example.com',
        'password' => Hash::make(Str::random(32)),
        'confirmation_token' => Str::random(32),
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

DB::table('users')->insert($rows);

echo 'Inserted ' . count($rows) . " placeholder users.\n";
