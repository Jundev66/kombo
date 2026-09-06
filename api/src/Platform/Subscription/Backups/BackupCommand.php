<?php

declare(strict_types=1);

namespace Platform\Subscription\Backups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Platform\Subscription\PlatformAudit;
use Symfony\Component\Process\Process;

/**
 * The daily backup: a database dump and a tar of `storage/app`.
 *
 * Both are needed. Restoring only the database leaves every delivery note
 * pointing at a payment receipt that no longer exists — and the receipt is
 * exactly what gets looked at when a customer says they paid.
 *
 * Kept on the server AND off it, and logged to `platform_audit_log` either way:
 * a backup that fails silently is worse than none, because people trust it.
 */
final class BackupCommand extends Command
{
    protected $signature = 'backups:run
        {--no-cloud : No subir a S3, sólo dejar la copia en el servidor}
        {--keep=14 : Cuántas copias locales se guardan}';

    protected $description = 'Respalda la base de datos y los archivos subidos, en el servidor y fuera de él';

    public function handle(DatabaseDump $dump, PlatformAudit $audit): int
    {
        $folder = (string) config('kombo.backups.path');

        if (! is_dir($folder) && ! mkdir($folder, 0o750, true) && ! is_dir($folder)) {
            return $this->failure($audit, "No se pudo crear la carpeta de respaldos: {$folder}");
        }

        // Sortable as text: that is what makes the rotation below a `sort` rather
        // than a date query.
        $brand = now()->format('Y-m-d_His');

        $base = $folder.'/'.$brand.'-base.dump';
        $files = $folder.'/'.$brand.'-archivos.tar.gz';

        if (($error = $dump->toFile($base)) !== null) {
            return $this->failure($audit, 'Falló el volcado de la base: '.$error);
        }

        if (($error = $this->packFiles($files)) !== null) {
            return $this->failure($audit, 'Falló el empaquetado de archivos: '.$error);
        }

        $this->info('Base:     '.basename($base).'  ('.$this->weight($base).')');
        $this->info('Archivos: '.basename($files).'  ('.$this->weight($files).')');

        $uploaded = $this->uploadToCloud($base, $files);

        if ($uploaded === false) {
            /*
             * A failed upload does NOT delete the local copy — better a copy
             * in the wrong place than nothing. It does exit with an error,
             * because a backup that has not left the server in two weeks has
             * to be visible.
             */
            return $this->failure($audit, 'La copia local está hecha, pero la subida fuera del servidor falló.', [
                'base' => basename($base),
                'archivos' => basename($files),
            ]);
        }

        $deletedRows = $this->rotate($folder, max(1, (int) $this->option('keep')));

        $audit->record('backup.made', null, [
            'base' => basename($base),
            'archivos' => basename($files),
            'bytes' => (int) filesize($base) + (int) filesize($files),
            'fuera_del_servidor' => $uploaded,
            'copias_borradas' => $deletedRows,
        ]);

        $this->info($uploaded ? 'Subido fuera del servidor.' : 'Sólo copia local (no hay S3 configurado).');

        return self::SUCCESS;
    }

    /**
     * All of `storage/app`: `private/` (receipts) and `public/` (photos).
     *
     * With `-C` so paths inside the tar are relative. A tar with absolute paths
     * restores where it likes, not where you tell it.
     */
    private function packFiles(string $destination): ?string
    {
        $root = storage_path('app');

        foreach (['private', 'public'] as $subfolder) {
            if (! is_dir($root.'/'.$subfolder)) {
                mkdir($root.'/'.$subfolder, 0o755, true);
            }
        }

        $process = new Process(
            ['tar', '-czf', $destination, '-C', $root, 'private', 'public'],
            timeout: 900.0,
        );

        $process->run();

        return $process->isSuccessful()
            ? null
            : (trim($process->getErrorOutput()) ?: 'tar terminó con código '.$process->getExitCode());
    }

    /**
     * @return bool|null true uploaded · null no cloud configured · false failed
     */
    private function uploadToCloud(string $base, string $files): ?bool
    {
        if ($this->option('no-cloud') === true || blank(config('filesystems.disks.s3.bucket'))) {
            return null;
        }

        $disk = Storage::disk('s3');

        foreach ([$base, $files] as $path) {
            $pointer = fopen($path, 'r');

            if ($pointer === false) {
                return false;
            }

            // Streamed: a few hundred megabytes read whole into memory takes down the
            // container, on exactly the modest machine where the backup matters most.
            $ok = $disk->writeStream('backups/'.basename($path), $pointer);

            if (is_resource($pointer)) {
                fclose($pointer);
            }

            if ($ok === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keeps the $keep most recent copies and deletes the rest.
     *
     * A full disk is not just a missing backup: PostgreSQL stops accepting
     * writes and the shop cannot take payment. DUMPS are counted, each dragging
     * its tar along, so no broken pairs are left.
     */
    private function rotate(string $folder, int $keep): int
    {
        $dumps = glob($folder.'/*-base.dump') ?: [];
        sort($dumps);

        $extra = array_slice($dumps, 0, max(0, count($dumps) - $keep));
        $deletedRows = 0;

        foreach ($extra as $dump) {
            $pair = str_replace('-base.dump', '-archivos.tar.gz', $dump);

            @unlink($dump);
            @unlink($pair);
            $deletedRows++;
        }

        return $deletedRows;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function failure(PlatformAudit $audit, string $reason, array $details = []): int
    {
        $audit->record('backup.failed', null, ['motivo' => $reason] + $details);

        $this->error($reason);

        return self::FAILURE;
    }

    private function weight(string $path): string
    {
        $bytes = (int) filesize($path);

        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
