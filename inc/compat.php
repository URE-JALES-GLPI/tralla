<?php
/**
 * Compatibilidade entre GLPI 11 e 12.
 *
 * O plugin tem como alvo o GLPI 12, mas mantém helpers guardados para não
 * quebrar caso seja testado em uma versão 11.x.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

if (!function_exists('tralla_glpi_version')) {
    function tralla_glpi_version(): string
    {
        return defined('GLPI_VERSION') ? (string) GLPI_VERSION : '';
    }
}

if (!function_exists('tralla_glpi_major')) {
    function tralla_glpi_major(): int
    {
        $version = tralla_glpi_version();
        if ($version === '') {
            return 0;
        }

        return (int) explode('.', $version)[0];
    }
}

if (!function_exists('tralla_is_glpi12_plus')) {
    function tralla_is_glpi12_plus(): bool
    {
        return tralla_glpi_major() >= 12;
    }
}

if (!function_exists('tralla_web_dir')) {
    /**
     * URL base do plugin (ex.: /glpi/plugins/tralla).
     */
    function tralla_web_dir(string $plugin = 'tralla'): string
    {
        if (method_exists('Plugin', 'getWebDir')) {
            return (string) Plugin::getWebDir($plugin);
        }

        global $CFG_GLPI;
        $root = (is_array($CFG_GLPI) && isset($CFG_GLPI['root_doc']))
            ? (string) $CFG_GLPI['root_doc']
            : '';

        return $root . '/plugins/' . $plugin;
    }
}

if (!function_exists('tralla_csrf_token')) {
    /**
     * No GLPI 12 não existem tokens CSRF; a proteção é feita pelo listener do core.
     */
    function tralla_csrf_token(): string
    {
        if (tralla_is_glpi12_plus()) {
            return '';
        }

        return (string) Session::getNewCSRFToken();
    }
}

if (!function_exists('tralla_check_csrf')) {
    function tralla_check_csrf(array $data): void
    {
        if (tralla_is_glpi12_plus()) {
            return;
        }

        Session::checkCSRF($data, true);
    }
}
