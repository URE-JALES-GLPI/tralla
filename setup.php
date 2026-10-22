<?php
/**
 * [URE] Tralla — quadros Kanban e fluxo de manutenção para GLPI 12.
 */

require_once __DIR__ . '/inc/compat.php';
require_once __DIR__ . '/inc/helpers.php';

define('PLUGIN_TRALLA_VERSION', '1.0.0');
define('PLUGIN_TRALLA_MIN_GLPI', '12.0.0');
define('PLUGIN_TRALLA_MAX_GLPI', '12.0.99');

/**
 * Inicialização do plugin.
 */
function plugin_init_tralla(): void
{
    global $PLUGIN_HOOKS;

    if (!tralla_is_glpi12_plus()) {
        $PLUGIN_HOOKS['csrf_compliant']['tralla'] = true;
    }

    Plugin::registerClass('PluginTrallaProfile', ['addtabon' => 'Profile']);
    Plugin::registerClass('PluginTrallaBoard');

    if (Session::haveRight('plugin_tralla', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['tralla'] = ['tools' => 'PluginTrallaBoard'];
    }

    $PLUGIN_HOOKS['config_page']['tralla'] = 'front/config.form.php';
    $PLUGIN_HOOKS['change_profile']['tralla'] = ['PluginTrallaProfile', 'changeProfile'];

    $base = __DIR__;
    $versioned = static function (string $relative) use ($base): string {
        $file = $base . '/' . ltrim($relative, '/');
        $mtime = @filemtime($file);

        return $relative . '?v=' . ($mtime ?: PLUGIN_TRALLA_VERSION);
    };

    $PLUGIN_HOOKS['add_css']['tralla'] = [
        $versioned('public/css/tralla.css'),
    ];

    $PLUGIN_HOOKS['add_javascript']['tralla'] = [
        $versioned('public/js/tralla.js'),
    ];
}

/**
 * Metadados exibidos em Configurar > Plugins.
 */
function plugin_version_tralla(): array
{
    return [
        'name'         => '[URE] Tralla',
        'version'      => PLUGIN_TRALLA_VERSION,
        'author'       => 'URE',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TRALLA_MIN_GLPI,
                'max' => PLUGIN_TRALLA_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2.0',
            ],
        ],
    ];
}

/**
 * Pré-requisitos de instalação.
 */
function plugin_tralla_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_TRALLA_MIN_GLPI, 'lt')) {
        echo 'Este plugin requer GLPI >= ' . PLUGIN_TRALLA_MIN_GLPI;
        return false;
    }

    if (version_compare(PHP_VERSION, '8.2.0', 'lt')) {
        echo 'Este plugin requer PHP >= 8.2';
        return false;
    }

    return true;
}

/**
 * Verificação de configuração (sempre válida para este plugin).
 */
function plugin_tralla_check_config(): bool
{
    return true;
}
