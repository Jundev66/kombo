<?php

declare(strict_types=1);

/*
 * Rutas de PLATAFORMA solamente.
 *
 * Las rutas de un módulo NO se declaran aquí: las declara su manifiesto
 * (`routes()`) y las carga PlatformServiceProvider bajo el middleware
 * `module:{codigo}`. Agregar un módulo no toca este fichero, y eso es
 * deliberado — es lo que hace que encender fiado, o comandas, o la caja, sea
 * una fila en `tenant_modules` y no un despliegue.
 *
 * Fase 1 traerá aquí: /auth/login, /auth/device, /auth/pin, /auth/staff,
 * /auth/logout y /me.
 */
