<?php
/** Confirmação de assinatura enviada pelo Assignat-ure. */
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tralla_json(['ok' => false, 'error' => 'Método não permitido.'], 405);
}
$expected = PluginTrallaConfig::assignatureToken();
$provided = (string) ($_SERVER['HTTP_X_TRALLA_TOKEN'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    tralla_json(['ok' => false, 'error' => 'Token inválido.'], 401);
}
$body = file_get_contents('php://input', false, null, 0, 16_385);
$data = json_decode((string) $body, true);
if (!is_array($data) || (string) ($data['action'] ?? '') !== 'signature_completed') {
    tralla_json(['ok' => false, 'error' => 'Dados inválidos.'], 400);
}
try {
    PluginTrallaCard::completeSignedWithdrawal(
        PluginTrallaConfig::assignatureInternalId((int) ($data['id'] ?? 0)),
        (string) ($data['signer_name'] ?? ''),
        (string) ($data['signature_sha256'] ?? '')
    );
    header('Cache-Control: no-store');
    tralla_json(['ok' => true, 'data' => ['completed' => true]]);
} catch (Throwable $e) {
    tralla_json(['ok' => false, 'error' => $e->getMessage()], 409);
}
