<?php
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/card.class.php';

Session::checkRight('plugin_tralla', READ);

try {
    $fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$fileId || $fileId <= 0) {
        throw new RuntimeException('Arquivo não encontrado.');
    }
    $file = PluginTrallaFile::download($fileId);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Arquivo indisponível.');
}

$downloadName = (string) $file['name'];
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="arquivo"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (int) $file['size']);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile((string) $file['path']);
exit;
