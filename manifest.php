<?php
/**
 * manifest.php — Manifest PWA dinámico por rol de usuario.
 * Devuelve los shortcuts filtrados según el rol de la sesión activa.
 */
require_once __DIR__ . '/core/helpers.php';

session_init();

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store');

$user = auth_user();
$rol  = $user['rol'] ?? '';

// Catálogo completo de shortcuts con sus roles permitidos
$catalogo = [
    [
        'name'        => 'Nueva propuesta',
        'short_name'  => 'Propuesta',
        'description' => 'Crear una nueva propuesta comercial',
        'url'         => '/modules/cotizaciones/crear.php?source=pwa-shortcut',
        'roles'       => ['gerente_general', 'gerente_operaciones', 'ejecutivo_ventas'],
    ],
    [
        'name'        => 'Empresas',
        'short_name'  => 'Empresas',
        'description' => 'Gestión de empresas clientes',
        'url'         => '/modules/clientes/index.php?source=pwa-shortcut',
        'roles'       => ['gerente_general', 'gerente_operaciones', 'ejecutivo_ventas'],
    ],
    [
        'name'        => 'Certificados',
        'short_name'  => 'Certificados',
        'description' => 'Gestión de certificados ISO',
        'url'         => '/modules/certificados/index.php?source=pwa-shortcut',
        'roles'       => ['gerente_general', 'gerente_operaciones', 'ejecutivo_ventas', 'gestor_logistico'],
    ],
    [
        'name'        => 'Envíos',
        'short_name'  => 'Envíos',
        'description' => 'Seguimiento de envíos',
        'url'         => '/modules/envios/index.php?source=pwa-shortcut',
        'roles'       => ['gerente_general', 'gerente_operaciones', 'gestor_logistico', 'delivery'],
    ],
];

$icon = [['src' => '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];

$shortcuts = [];
foreach ($catalogo as $s) {
    if ($rol && in_array($rol, $s['roles'])) {
        $shortcuts[] = [
            'name'        => $s['name'],
            'short_name'  => $s['short_name'],
            'description' => $s['description'],
            'url'         => $s['url'],
            'icons'       => $icon,
        ];
    }
}

$manifest = [
    'name'             => 'Group Global ISO',
    'short_name'       => 'GGI Sistema',
    'description'      => 'Sistema Integral de Gestión — Group Global ISO S.A.C.',
    'start_url'        => '/modules/dashboard/index.php?source=pwa',
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'theme_color'      => '#1A3A8F',
    'background_color' => '#ffffff',
    'lang'             => 'es-PE',
    'dir'              => 'ltr',
    'id'               => '/modules/dashboard/index.php',
    'icons'            => [
        ['src' => '/assets/icons/icon-72.png',           'sizes' => '72x72',     'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-96.png',           'sizes' => '96x96',     'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-128.png',          'sizes' => '128x128',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-144.png',          'sizes' => '144x144',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-152.png',          'sizes' => '152x152',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-180.png',          'sizes' => '180x180',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-192.png',          'sizes' => '192x192',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-384.png',          'sizes' => '384x384',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-512.png',          'sizes' => '512x512',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-maskable-192.png', 'sizes' => '192x192',   'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => '/assets/icons/icon-maskable-512.png', 'sizes' => '512x512',   'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'screenshots' => [
        [
            'src'         => '/assets/splash/splash-1170x2532.png',
            'sizes'       => '1170x2532',
            'type'        => 'image/png',
            'form_factor' => 'narrow',
            'label'       => 'Group Global ISO — Sistema Integral de Gestión',
        ],
    ],
    'shortcuts'                  => $shortcuts,
    'categories'                 => ['business', 'productivity'],
    'prefer_related_applications' => false,
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
