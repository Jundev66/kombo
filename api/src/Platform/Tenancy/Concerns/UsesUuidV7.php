<?php

declare(strict_types=1);

namespace Platform\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Identificadores UUID **v7**, no v4.
 *
 * v7 lleva la marca de tiempo en los bits altos. Dos consecuencias que
 * importan:
 *
 * - **Conserva el orden de creación.** Se puede ordenar por id y significa
 *   algo, sin depender de `created_at`.
 * - **No fragmenta el índice.** Insertar identificadores aleatorios hace que
 *   PostgreSQL escriba en páginas dispersas del árbol; con v7 las inserciones
 *   van al final, como un autoincremento. En una tabla de pedidos que crece
 *   todos los días, sobre una máquina modesta, la diferencia se nota.
 *
 * Y sigue sin ser adivinable desde fuera, que es lo que descarta el
 * autoincremento: los identificadores viajan en URLs.
 *
 * Va aparte de BelongsToTenant porque son dos decisiones distintas —una es
 * «pertenece a un negocio», la otra «así se numera»— y porque tenerlas juntas
 * chocaba con `HasUuids` de Laravel.
 */
trait UsesUuidV7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
