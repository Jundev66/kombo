<?php

declare(strict_types=1);

return [

    /*
     * El dominio raíz del que cuelgan los negocios.
     *
     * `elsazon.localhost` en desarrollo, `elsazon.kombo.app` en producción. El
     * resolutor de negocios extrae el subdominio contra este valor: si el host
     * no termina en él, no hay negocio que resolver y la petición sigue sin
     * contexto (que es lo que necesita la super administración).
     */
    'root_domain' => env('KOMBO_ROOT_DOMAIN', 'localhost'),

    /*
     * El dominio de la super administración. No es un negocio y nunca lo será:
     * `admin` está en la lista de slugs reservados del resolutor.
     */
    'admin_host' => env('KOMBO_ADMIN_HOST', 'admin.localhost'),

    /*
     * Herramientas de demostración (cambiar de usuario sin contraseña, sembrar
     * datos). Es una bandera propia y no una comprobación directa de APP_ENV
     * para poder PROBARLA sin falsear el entorno: falsear APP_ENV arrastra la
     * exención de CSRF del entorno de pruebas y convierte el test en una pelea
     * con el framework.
     */
    'demo_tools' => env('KOMBO_DEMO_TOOLS', env('APP_ENV') === 'local'),

    /*
     * Cuánto se cachea la resolución de subdominio → negocio.
     *
     * Es una consulta por petición si no se cachea. Con caché, el precio a
     * pagar es acordarse de invalidarla: cualquier operación que cambie el
     * identificador o el estado de un negocio tiene que llamar a
     * TenantResolver::forget(). Si no, el síntoma engaña — /me responde bien
     * (viene de caché) y TODAS las consultas devuelven cero filas, porque RLS
     * filtra por un identificador que ya no existe.
     */
    'tenant_cache_ttl' => (int) env('KOMBO_TENANT_CACHE_TTL', 3600),

];
