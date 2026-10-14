<?php

/*
 * Ajustes de esta instalación de SISPAM.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Módulos encendidos (SISPAM_MODULOS)
    |--------------------------------------------------------------------------
    |
    | Lista blanca con las claves de `Rol::MODULOS`, separadas por coma:
    |
    |   SISPAM_MODULOS=consultar_paciente,usuarios,sedes,auditoria
    |
    | Lo que no esté en la lista no existe en esta instalación, **ni para el
    | administrador**: sale del menú y responde 403 en pantallas, acciones y
    | archivos. Se revisa en un solo sitio, `User::puede()`.
    |
    | Vacía, todo queda encendido: es lo de desarrollo. Es lista blanca a
    | propósito: un módulo nuevo nace apagado en producción hasta que alguien
    | lo nombre aquí.
    |
    */

    'modulos' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SISPAM_MODULOS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Proxies de confianza (TRUSTED_PROXIES)
    |--------------------------------------------------------------------------
    |
    | Si delante del servidor hay un proxy (Cloudflare, un balanceador), sus IP
    | separadas por coma, o `*` si no son fijas. Así Laravel cree el
    | `X-Forwarded-Proto: https` que manda el proxy. Vacío: ninguno.
    |
    | Las URLs igual salen con https si APP_URL lo trae
    | (`App\Providers\HttpsServiceProvider`).
    |
    */

    'proxies_de_confianza' => env('TRUSTED_PROXIES'),

];
