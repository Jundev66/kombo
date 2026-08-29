<?php

declare(strict_types=1);

namespace Platform\Subscription\Backups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Platform\Subscription\PlatformAudit;
use Symfony\Component\Process\Process;

/**
 * El respaldo diario.
 *
 * Dos archivos, y hacen falta LOS DOS:
 *
 *   …-base.dump          la base de datos
 *   …-archivos.tar.gz    `storage/app` — comprobantes de pago y fotos
 *
 * Restaurar sólo la base deja todas las notas de entrega apuntando a un
 * comprobante que ya no existe. Y el comprobante es justo lo que se mira cuando
 * un cliente dice que sí pagó.
 *
 * Se guardan en el servidor Y fuera de él. Una copia que vive en la misma
 * máquina que los datos protege del error humano —alguien borró algo— pero no
 * del incendio: el día que se pierde la máquina, se pierde con el respaldo
 * dentro.
 *
 * Y todo queda escrito en `platform_audit_log`, salga bien o salga mal. Un
 * respaldo que falla en silencio es peor que no tener respaldo: con el segundo
 * al menos nadie se confía.
 */
final class BackupCommand extends Command
{
    protected $signature = 'respaldos:hacer
        {--sin-nube : No subir a S3, sólo dejar la copia en el servidor}
        {--conservar=14 : Cuántas copias locales se guardan}';

    protected $description = 'Respalda la base de datos y los archivos subidos, en el servidor y fuera de él';

    public function handle(DatabaseDump $dump, PlatformAudit $audit): int
    {
        $carpeta = (string) config('kombo.backups.path');

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0o750, true) && ! is_dir($carpeta)) {
            return $this->fracaso($audit, "No se pudo crear la carpeta de respaldos: {$carpeta}");
        }

        // Ordenable como texto: es lo que hace que la rotación de más abajo sea
        // un `sort` y no una consulta de fechas.
        $marca = now()->format('Y-m-d_His');

        $base = $carpeta.'/'.$marca.'-base.dump';
        $archivos = $carpeta.'/'.$marca.'-archivos.tar.gz';

        if (($error = $dump->toFile($base)) !== null) {
            return $this->fracaso($audit, 'Falló el volcado de la base: '.$error);
        }

        if (($error = $this->empaquetarArchivos($archivos)) !== null) {
            return $this->fracaso($audit, 'Falló el empaquetado de archivos: '.$error);
        }

        $this->info('Base:     '.basename($base).'  ('.$this->peso($base).')');
        $this->info('Archivos: '.basename($archivos).'  ('.$this->peso($archivos).')');

        $subidos = $this->subirALaNube($base, $archivos);

        if ($subidos === false) {
            /*
             * El fallo de la subida NO borra la copia local: es peor quedarse
             * sin nada que quedarse con una copia en el sitio equivocado. Pero
             * sí sale con error, porque un respaldo que lleva dos semanas sin
             * salir del servidor tiene que verse.
             */
            return $this->fracaso($audit, 'La copia local está hecha, pero la subida fuera del servidor falló.', [
                'base' => basename($base),
                'archivos' => basename($archivos),
            ]);
        }

        $borradas = $this->rotar($carpeta, max(1, (int) $this->option('conservar')));

        $audit->record('backup.made', null, [
            'base' => basename($base),
            'archivos' => basename($archivos),
            'bytes' => (int) filesize($base) + (int) filesize($archivos),
            'fuera_del_servidor' => $subidos,
            'copias_borradas' => $borradas,
        ]);

        $this->info($subidos ? 'Subido fuera del servidor.' : 'Sólo copia local (no hay S3 configurado).');

        return self::SUCCESS;
    }

    /**
     * `storage/app` entero: `private/` (comprobantes) y `public/` (fotos).
     *
     * Con `-C` para que dentro del tar las rutas sean `private/…` y `public/…`
     * y no `/var/www/api/storage/app/private/…`. Un tar con rutas absolutas se
     * restaura donde él quiere, no donde uno le dice.
     */
    private function empaquetarArchivos(string $destino): ?string
    {
        $raiz = storage_path('app');

        foreach (['private', 'public'] as $subcarpeta) {
            if (! is_dir($raiz.'/'.$subcarpeta)) {
                mkdir($raiz.'/'.$subcarpeta, 0o755, true);
            }
        }

        $proceso = new Process(
            ['tar', '-czf', $destino, '-C', $raiz, 'private', 'public'],
            timeout: 900.0,
        );

        $proceso->run();

        return $proceso->isSuccessful()
            ? null
            : (trim($proceso->getErrorOutput()) ?: 'tar terminó con código '.$proceso->getExitCode());
    }

    /**
     * @return bool|null true subido · null no hay nube configurada · false falló
     */
    private function subirALaNube(string $base, string $archivos): ?bool
    {
        if ($this->option('sin-nube') === true || blank(config('filesystems.disks.s3.bucket'))) {
            return null;
        }

        $disco = Storage::disk('s3');

        foreach ([$base, $archivos] as $ruta) {
            $puntero = fopen($ruta, 'r');

            if ($puntero === false) {
                return false;
            }

            // En streaming: un volcado de varios cientos de megas leído entero
            // en memoria tumba el contenedor, y justo en la máquina modesta
            // donde más falta hace que el respaldo salga.
            $ok = $disco->writeStream('respaldos/'.basename($ruta), $puntero);

            if (is_resource($puntero)) {
                fclose($puntero);
            }

            if ($ok === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deja las $conservar copias más recientes y borra las demás.
     *
     * Sin esto el disco se llena, y un disco lleno no es sólo que no haya
     * respaldo: es que PostgreSQL deja de aceptar escrituras y el negocio no
     * puede cobrar.
     *
     * Se cuentan los VOLCADOS, y de cada uno se arrastra su tar. Contar
     * archivos sueltos dejaría parejas rotas — la base de un día con los
     * archivos de otro.
     */
    private function rotar(string $carpeta, int $conservar): int
    {
        $volcados = glob($carpeta.'/*-base.dump') ?: [];
        sort($volcados);

        $sobran = array_slice($volcados, 0, max(0, count($volcados) - $conservar));
        $borradas = 0;

        foreach ($sobran as $volcado) {
            $pareja = str_replace('-base.dump', '-archivos.tar.gz', $volcado);

            @unlink($volcado);
            @unlink($pareja);
            $borradas++;
        }

        return $borradas;
    }

    /**
     * @param  array<string, mixed>  $detalles
     */
    private function fracaso(PlatformAudit $audit, string $motivo, array $detalles = []): int
    {
        $audit->record('backup.failed', null, ['motivo' => $motivo] + $detalles);

        $this->error($motivo);

        return self::FAILURE;
    }

    private function peso(string $ruta): string
    {
        $bytes = (int) filesize($ruta);

        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
