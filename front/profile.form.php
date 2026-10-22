<?php
include('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/tralla/inc/compat.php';
require_once GLPI_ROOT . '/plugins/tralla/inc/helpers.php';

global $CFG_GLPI;

Session::checkRight('profile', UPDATE);
tralla_check_csrf($_POST);

$profiles_id = tralla_post_int('profiles_id');
$profile = new Profile();

if ($profiles_id <= 0 || !$profile->getFromDB($profiles_id)) {
    Session::addMessageAfterRedirect('Perfil inválido.', false, ERROR);
    Html::redirect((string) $CFG_GLPI['root_doc'] . '/front/profile.php');
}

if (isset($_POST['update'])) {
    $allowed = READ | CREATE | UPDATE | DELETE | PURGE;
    $rights = tralla_post_int('rights') & $allowed;
    $approve = !empty($_POST['approve_rights']) ? READ : 0;

    PluginTrallaProfile::setProfileRight($profiles_id, PluginTrallaProfile::RIGHT_TRALLA, $rights);
    PluginTrallaProfile::setProfileRight($profiles_id, PluginTrallaProfile::RIGHT_APPROVE, $approve);

    if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profiles_id) {
        $_SESSION['glpiactiveprofile'][PluginTrallaProfile::RIGHT_TRALLA] = $rights;
        $_SESSION['glpiactiveprofile'][PluginTrallaProfile::RIGHT_APPROVE] = $approve;
    }

    Session::addMessageAfterRedirect('Permissões do Tralla atualizadas.', true);
}

Html::redirect((string) $CFG_GLPI['root_doc'] . '/front/profile.form.php?id=' . $profiles_id);
