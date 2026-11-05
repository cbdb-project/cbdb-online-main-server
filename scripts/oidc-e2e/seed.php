<?php

/*
 * OIDC 端到端驗證的環境準備（由 run.sh 呼叫，不要手動對開發資料庫執行）。
 *
 * 用法：
 *   php scripts/oidc-e2e/seed.php <workdir>             建表、建使用者與 client、生成金鑰，寫出 <workdir>/e2e.json
 *   php scripts/oidc-e2e/seed.php <workdir> deactivate  把測試使用者 alice 停用（驗證「兌換前被停用」用）
 *
 * 安全護欄：只在 DB 是 SQLite、而且資料庫檔案位於 <workdir> 之內時才動作，否則直接中止。
 * 這個腳本會 drop／建立 users 表，指到開發或正式資料庫就是災難。
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use phpseclib4\Crypt\RSA;

require __DIR__ . '/../../vendor/autoload.php';

$workdir = $argv[1] ?? null;
$action = $argv[2] ?? 'seed';

if ($workdir === null || !is_dir($workdir)) {
    fwrite(STDERR, "用法: php scripts/oidc-e2e/seed.php <workdir> [deactivate]\n");
    exit(2);
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = (string) config('database.default');
$database = (string) config('database.connections.' . $connection . '.database');
$resolvedWork = realpath($workdir);
$resolvedDb = is_file($database) ? realpath($database) : false;

if ($connection !== 'sqlite' || $resolvedWork === false || $resolvedDb === false || !str_starts_with($resolvedDb, rtrim($resolvedWork, '/\\') . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "中止：資料庫必須是位於 workdir 內的 SQLite 檔案（目前 connection={$connection}, database={$database}）。\n");
    exit(3);
}

if ($action === 'deactivate') {
    $user = User::query()->where('email', 'alice@e2e.test')->firstOrFail();
    $user->is_active = User::STATUS_INACTIVE;
    $user->save();
    echo "deactivated {$user->email}\n";
    exit(0);
}

Schema::dropIfExists('users');
Schema::create('users', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->string('institution')->nullable();
    $table->json('settings')->nullable();
    $table->string('avatar')->nullable();
    $table->string('confirmation_token')->nullable();
    $table->smallInteger('is_active')->default(0);
    $table->smallInteger('is_admin')->default(0);
    $table->rememberToken();
    $table->timestamps();
});

$paths = array_map(
    static fn (string $file) => 'database/migrations/' . basename($file),
    glob(database_path('migrations/*_create_oauth_*_table.php')) ?: []
);
if (Artisan::call('migrate', ['--path' => $paths, '--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(4);
}

$password = 'e2e-password-' . bin2hex(random_bytes(4));
$alice = new User();
$alice->forceFill(['name' => 'Alice 愛麗絲', 'email' => 'alice@e2e.test', 'password' => Hash::make($password), 'is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_SUPER_ADMIN])->save();
$bob = new User();
$bob->forceFill(['name' => 'Bob (inactive)', 'email' => 'bob@e2e.test', 'password' => Hash::make($password), 'is_active' => User::STATUS_INACTIVE])->save();

// 簽章金鑰：與 passport:keys 相同的做法（phpseclib），寫進 workdir，不碰 storage/ 下既有的金鑰。
$key = RSA::createKey(2048);
file_put_contents($workdir . '/oauth-public.key', (string) $key->getPublicKey());
file_put_contents($workdir . '/oauth-private.key', (string) $key);

$redirect = 'http://127.0.0.1:9/callback'; // 沒有人在聽：腳本只讀 authorize 回應的 Location
$client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('e2e RP', [$redirect], true);

file_put_contents($workdir . '/e2e.json', json_encode([
    'issuer' => (string) config('openid.issuedBy'),
    'client_id' => $client->getKey(),
    'client_secret' => $client->plainSecret,
    'redirect_uri' => $redirect,
    'password' => $password,
    'users' => ['active' => ['id' => $alice->id, 'email' => $alice->email, 'name' => $alice->name], 'inactive' => ['email' => $bob->email]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "seeded\n";
