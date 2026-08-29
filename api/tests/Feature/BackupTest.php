<?php

declare(strict_types=1);

/*
 * Los respaldos.
 *
 * Lo que se comprueba aquí es lo que tiene lógica: que salen los DOS archivos,
 * que se van fuera del servidor, que la rotación no deja parejas rotas y que
 * el resultado queda escrito pase lo que pase.
 *
 * El `pg_dump` de verdad no se ejercita: detrás de la interfaz va un doble que
 * escribe un archivo. Una prueba que llamara al programa real comprobaría a la
 * vez el respaldo y la versión del cliente instalado en la imagen, y fallaría
 * por la segunda razón dando la impresión de la primera. El volcado real se
 * verifica restaurándolo, que es la única forma que vale, y está en
 * `docs/respaldos.md`.
 */

use App\Models\Platform\PlatformUser;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\Subscription\Backups\DatabaseDump;

beforeEach(function (): void {
    $this->carpeta = storage_path('framework/testing/respaldos-'.Str::lower(Str::random(8)));

    config()->set('kombo.backups.path', $this->carpeta);

    // Un volcado de mentira, pero un archivo de verdad: la rotación y la
    // subida trabajan sobre archivos, no sobre la idea de un archivo.
    $this->app->bind(DatabaseDump::class, fn (): DatabaseDump => new class implements DatabaseDump
    {
        public ?string $error = null;

        public function toFile(string $destino): ?string
        {
            if (BackupFalla::$activo) {
                return 'server version 18; pg_dump version 17';
            }

            file_put_contents($destino, 'PGDMP-de-mentira');

            return null;
        }
    });

    BackupFalla::$activo = false;
});

afterEach(function (): void {
    foreach (glob($this->carpeta.'/*') ?: [] as $archivo) {
        @unlink($archivo);
    }

    @rmdir($this->carpeta);
});

/** Interruptor para hacer fallar el volcado sin cambiar el doble. */
final class BackupFalla
{
    public static bool $activo = false;
}

/** @return list<string> los nombres de archivo que hay en la carpeta */
function respaldos(string $carpeta): array
{
    return array_map('basename', glob($carpeta.'/*') ?: []);
}

it('deja la base y los archivos, los dos', function (): void {
    // Un comprobante subido: lo que se perdería si el respaldo fuera sólo de
    // la base de datos.
    Storage::disk('local')->put('comprobantes/pago.jpg', 'una foto');

    $this->artisan('respaldos:hacer')->assertSuccessful();

    $nombres = respaldos($this->carpeta);

    expect($nombres)->toHaveCount(2)
        ->and(collect($nombres)->filter(fn (string $n): bool => str_ends_with($n, '-base.dump')))->toHaveCount(1)
        ->and(collect($nombres)->filter(fn (string $n): bool => str_ends_with($n, '-archivos.tar.gz')))->toHaveCount(1);

    // Y el tar lleva el comprobante dentro. Sin esto, restaurar dejaría todas
    // las notas apuntando a un archivo que ya no existe.
    $tar = collect(glob($this->carpeta.'/*-archivos.tar.gz'))->first();
    $contenido = shell_exec('tar -tzf '.escapeshellarg((string) $tar));

    expect($contenido)->toContain('private/comprobantes/pago.jpg');
});

it('sube las dos copias fuera del servidor', function (): void {
    Storage::fake('s3');
    config()->set('filesystems.disks.s3.bucket', 'respaldos-kombo');

    $this->artisan('respaldos:hacer')->assertSuccessful();

    $subidos = Storage::disk('s3')->files('respaldos');

    expect($subidos)->toHaveCount(2)
        ->and(collect($subidos)->filter(fn (string $n): bool => str_ends_with($n, '-base.dump')))->toHaveCount(1)
        ->and(collect($subidos)->filter(fn (string $n): bool => str_ends_with($n, '-archivos.tar.gz')))->toHaveCount(1);
});

it('sin S3 configurado hace la copia local y no se queja', function (): void {
    config()->set('filesystems.disks.s3.bucket', null);

    $this->artisan('respaldos:hacer')
        ->expectsOutputToContain('Sólo copia local')
        ->assertSuccessful();

    expect(respaldos($this->carpeta))->toHaveCount(2);
});

it('la rotación borra las parejas viejas enteras', function (): void {
    mkdir($this->carpeta, 0o750, true);

    // Cuatro respaldos anteriores, del más viejo al más nuevo.
    foreach (['2026-01-01_030000', '2026-01-02_030000', '2026-01-03_030000', '2026-01-04_030000'] as $marca) {
        file_put_contents($this->carpeta.'/'.$marca.'-base.dump', 'viejo');
        file_put_contents($this->carpeta.'/'.$marca.'-archivos.tar.gz', 'viejo');
    }

    $this->artisan('respaldos:hacer --conservar=2')->assertSuccessful();

    $nombres = respaldos($this->carpeta);

    // Dos parejas conservadas: la de hoy y la más reciente de las viejas.
    expect($nombres)->toHaveCount(4)
        ->and($nombres)->toContain('2026-01-04_030000-base.dump')
        ->and($nombres)->toContain('2026-01-04_030000-archivos.tar.gz')
        // Y ninguna pareja a medias: si sobrevive el volcado tiene que
        // sobrevivir su tar, o el respaldo restaurado queda sin comprobantes.
        ->and($nombres)->not->toContain('2026-01-01_030000-base.dump')
        ->and($nombres)->not->toContain('2026-01-01_030000-archivos.tar.gz')
        ->and($nombres)->not->toContain('2026-01-03_030000-base.dump');
});

it('escribe en la bitácora lo que salió bien', function (): void {
    $this->artisan('respaldos:hacer')->assertSuccessful();

    $fila = DB::table('platform_audit_log')->where('action', 'backup.made')->first();

    expect($fila)->not->toBeNull();

    $detalles = json_decode((string) $fila->details, true);

    expect($detalles['base'])->toEndWith('-base.dump')
        ->and($detalles['archivos'])->toEndWith('-archivos.tar.gz')
        ->and($detalles['bytes'])->toBeGreaterThan(0);
});

it('un respaldo que falla no falla en silencio', function (): void {
    BackupFalla::$activo = true;

    $this->artisan('respaldos:hacer')->assertFailed();

    $fila = DB::table('platform_audit_log')->where('action', 'backup.failed')->first();

    expect($fila)->not->toBeNull()
        ->and(json_decode((string) $fila->details, true)['motivo'])->toContain('pg_dump version 17');
});

/*
 * ── El administrador de plataforma ──────────────────────────────────────────
 */

it('crea el administrador sin escribir la contraseña en ningún sitio', function (): void {
    $correo = 'jefe-'.Str::lower(Str::random(6)).'@kombo.test';

    putenv('KOMBO_ADMIN_PASSWORD=una-contrasena-larga');

    $this->artisan('plataforma:admin '.$correo)->assertSuccessful();

    putenv('KOMBO_ADMIN_PASSWORD');

    $usuario = PlatformUser::where('email', $correo)->first();

    expect($usuario)->not->toBeNull()
        ->and($usuario->is_active)->toBeTrue()
        // Guardada cifrada, no en crudo: el modelo la castea a `hashed`, y
        // cifrarla también en el comando la dejaría cifrada dos veces —una
        // cuenta que se crea sin queja y con la que no se puede entrar.
        ->and($usuario->password)->not->toBe('una-contrasena-larga')
        ->and(Hash::check('una-contrasena-larga', $usuario->password))->toBeTrue();
});

it('rechaza una contraseña corta', function (): void {
    putenv('KOMBO_ADMIN_PASSWORD=corta123');

    $this->artisan('plataforma:admin nuevo@kombo.test')->assertFailed();

    putenv('KOMBO_ADMIN_PASSWORD');

    expect(PlatformUser::where('email', 'nuevo@kombo.test')->exists())->toBeFalse();
});

it('sirve para recuperar el acceso de una cuenta existente', function (): void {
    $correo = 'perdido-'.Str::lower(Str::random(6)).'@kombo.test';

    PlatformUser::create([
        'name' => 'Administración',
        'email' => $correo,
        'password' => 'la-vieja-que-nadie-recuerda',
        'is_active' => false,
    ]);

    putenv('KOMBO_ADMIN_PASSWORD=la-nueva-de-doce-o-mas');

    $this->artisan('plataforma:admin '.$correo)->assertSuccessful();

    putenv('KOMBO_ADMIN_PASSWORD');

    $usuario = PlatformUser::where('email', $correo)->first();

    expect($usuario->is_active)->toBeTrue()
        ->and(Hash::check('la-nueva-de-doce-o-mas', $usuario->password))->toBeTrue();
});

it('con las herramientas de demostración apagadas, el sembrador no crea ninguna cuenta', function (): void {
    config()->set('kombo.demo_tools', false);

    DB::table('platform_users')->where('email', 'admin@kombo.test')->delete();

    (new PlatformSeeder)->run();

    expect(PlatformUser::where('email', 'admin@kombo.test')->exists())->toBeFalse();
});
