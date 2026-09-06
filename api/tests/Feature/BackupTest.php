<?php

declare(strict_types=1);

/*
 * The backups.
 *
 * What is checked is what has logic: that BOTH files come out, that they leave
 * the server, that rotation leaves no broken pairs, and that the result is
 * written down either way.
 *
 * The real `pg_dump` is not exercised — a test calling it would check the
 * installed client version as much as the backup. The real dump is verified by
 * restoring it, which is the only way that counts; see `docs/respaldos.md`.
 */

use App\Models\Platform\PlatformUser;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\Subscription\Backups\DatabaseDump;

beforeEach(function (): void {
    $this->folder = storage_path('framework/testing/backups-'.Str::lower(Str::random(8)));

    config()->set('kombo.backups.path', $this->folder);

    // A fake dump but a real file: rotation and upload work on files, not on
    // the idea of a file.
    $this->app->bind(DatabaseDump::class, fn (): DatabaseDump => new class implements DatabaseDump
    {
        public ?string $error = null;

        public function toFile(string $destination): ?string
        {
            if (BackupFalla::$active) {
                return 'server version 18; pg_dump version 17';
            }

            file_put_contents($destination, 'PGDMP-de-mentira');

            return null;
        }
    });

    BackupFalla::$active = false;
});

afterEach(function (): void {
    foreach (glob($this->folder.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->folder);
});

/** Switch to make the dump fail without changing the double. */
final class BackupFalla
{
    public static bool $active = false;
}

/** @return list<string> the file names in the folder */
function backups(string $folder): array
{
    return array_map('basename', glob($folder.'/*') ?: []);
}

it('leaves both the database and the files', function (): void {
    // An uploaded receipt: what would be lost if the backup were database-only.
    Storage::disk('local')->put('receipts/photo.jpg', 'a photo');

    $this->artisan('backups:run')->assertSuccessful();

    $names = backups($this->folder);

    expect($names)->toHaveCount(2)
        ->and(collect($names)->filter(fn (string $n): bool => str_ends_with($n, '-base.dump')))->toHaveCount(1)
        ->and(collect($names)->filter(fn (string $n): bool => str_ends_with($n, '-archivos.tar.gz')))->toHaveCount(1);

    // And the tar carries the receipt. Without it, restoring would leave every
    // note pointing at a file that no longer exists.
    $tar = collect(glob($this->folder.'/*-archivos.tar.gz'))->first();
    $content = shell_exec('tar -tzf '.escapeshellarg((string) $tar));

    expect($content)->toContain('private/receipts/photo.jpg');
});

it('uploads both copies off the server', function (): void {
    Storage::fake('s3');
    config()->set('filesystems.disks.s3.bucket', 'backups-kombo');

    $this->artisan('backups:run')->assertSuccessful();

    $uploaded = Storage::disk('s3')->files('backups');

    expect($uploaded)->toHaveCount(2)
        ->and(collect($uploaded)->filter(fn (string $n): bool => str_ends_with($n, '-base.dump')))->toHaveCount(1)
        ->and(collect($uploaded)->filter(fn (string $n): bool => str_ends_with($n, '-archivos.tar.gz')))->toHaveCount(1);
});

it('with no S3 configured it makes the local copy and does not complain', function (): void {
    config()->set('filesystems.disks.s3.bucket', null);

    $this->artisan('backups:run')
        ->expectsOutputToContain('Sólo copia local')
        ->assertSuccessful();

    expect(backups($this->folder))->toHaveCount(2);
});

it('rotation deletes whole old pairs', function (): void {
    mkdir($this->folder, 0o750, true);

    // Four earlier backups, oldest to newest.
    foreach (['2026-01-01_030000', '2026-01-02_030000', '2026-01-03_030000', '2026-01-04_030000'] as $brand) {
        file_put_contents($this->folder.'/'.$brand.'-base.dump', 'viejo');
        file_put_contents($this->folder.'/'.$brand.'-archivos.tar.gz', 'viejo');
    }

    $this->artisan('backups:run --keep=2')->assertSuccessful();

    $names = backups($this->folder);

    // Two pairs kept: today's and the most recent of the old ones.
    expect($names)->toHaveCount(4)
        ->and($names)->toContain('2026-01-04_030000-base.dump')
        ->and($names)->toContain('2026-01-04_030000-archivos.tar.gz')
        // And no half pairs: if a dump survives so must its tar, or the restored
        // backup has no receipts.
        ->and($names)->not->toContain('2026-01-01_030000-base.dump')
        ->and($names)->not->toContain('2026-01-01_030000-archivos.tar.gz')
        ->and($names)->not->toContain('2026-01-03_030000-base.dump');
});

it('writes what went well into the audit log', function (): void {
    $this->artisan('backups:run')->assertSuccessful();

    $row = DB::table('platform_audit_log')->where('action', 'backup.made')->first();

    expect($row)->not->toBeNull();

    $details = json_decode((string) $row->details, true);

    expect($details['base'])->toEndWith('-base.dump')
        ->and($details['archivos'])->toEndWith('-archivos.tar.gz')
        ->and($details['bytes'])->toBeGreaterThan(0);
});

it('a backup that fails does not fail silently', function (): void {
    BackupFalla::$active = true;

    $this->artisan('backups:run')->assertFailed();

    $row = DB::table('platform_audit_log')->where('action', 'backup.failed')->first();

    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row->details, true)['motivo'])->toContain('pg_dump version 17');
});

/*
 * ── The platform administrator ──────────────────────────────────────────────
 */

it('creates the administrator without writing the password anywhere', function (): void {
    $email = 'jefe-'.Str::lower(Str::random(6)).'@kombo.test';

    putenv('KOMBO_ADMIN_PASSWORD=una-contrasena-larga');

    $this->artisan('platform:admin '.$email)->assertSuccessful();

    putenv('KOMBO_ADMIN_PASSWORD');

    $user = PlatformUser::where('email', $email)->first();

    expect($user)->not->toBeNull()
        ->and($user->is_active)->toBeTrue()
        // Stored hashed, not raw: the model casts it, and hashing in the command too
        // would hash it twice — an account created without complaint that nobody can
        // sign into.
        ->and($user->password)->not->toBe('una-contrasena-larga')
        ->and(Hash::check('una-contrasena-larga', $user->password))->toBeTrue();
});

it('rejects a short password', function (): void {
    putenv('KOMBO_ADMIN_PASSWORD=corta123');

    $this->artisan('platform:admin nuevo@kombo.test')->assertFailed();

    putenv('KOMBO_ADMIN_PASSWORD');

    expect(PlatformUser::where('email', 'nuevo@kombo.test')->exists())->toBeFalse();
});

it('also recovers access to an existing account', function (): void {
    $email = 'perdido-'.Str::lower(Str::random(6)).'@kombo.test';

    PlatformUser::create([
        'name' => 'Administración',
        'email' => $email,
        'password' => 'la-vieja-que-nadie-recuerda',
        'is_active' => false,
    ]);

    putenv('KOMBO_ADMIN_PASSWORD=la-nueva-de-doce-o-mas');

    $this->artisan('platform:admin '.$email)->assertSuccessful();

    putenv('KOMBO_ADMIN_PASSWORD');

    $user = PlatformUser::where('email', $email)->first();

    expect($user->is_active)->toBeTrue()
        ->and(Hash::check('la-nueva-de-doce-o-mas', $user->password))->toBeTrue();
});

it('with the demo tooling off, the seeder creates no account', function (): void {
    config()->set('kombo.demo_tools', false);

    DB::table('platform_users')->where('email', 'admin@kombo.test')->delete();

    (new PlatformSeeder)->run();

    expect(PlatformUser::where('email', 'admin@kombo.test')->exists())->toBeFalse();
});
