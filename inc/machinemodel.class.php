<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Modelo de máquina usado no cadastro de manutenção.
 */
class PluginTrallaMachineModel extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_tralla_machine_models';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Modelo de máquina', 'Modelos de máquina', $nb, 'tralla');
    }

    public function prepareInputForAdd($input)
    {
        $input['name'] = tralla_normalize_label($input['name'] ?? '');
        if ($input['name'] === '') {
            Session::addMessageAfterRedirect('Informe o nome do modelo.', false, ERROR);
            return false;
        }

        $entitiesId = (int) ($input['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        if (!Session::haveAccessToEntity($entitiesId)) {
            $entitiesId = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        }
        $input['entities_id'] = $entitiesId;
        $input['is_recursive'] = !empty($input['is_recursive']) ? 1 : 0;
        $input['comment'] = trim(strip_tags((string) ($input['comment'] ?? '')));
        $input['is_archived'] = 0;
        $input['users_id'] = (int) Session::getLoginUserID();
        $input['date_creation'] = tralla_now();
        $input['date_mod'] = $input['date_creation'];

        return $input;
    }

    public static function getModel(int $id, bool $includeArchived = false): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_machine_models',
            'WHERE' => $includeArchived ? ['id' => $id] : ['id' => $id, 'is_archived' => 0],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    public static function canUseModel(int $id): bool
    {
        $model = self::getModel($id);
        if ($model === null) {
            return false;
        }

        return Session::haveAccessToEntity(
            (int) $model['entities_id'],
            (bool) $model['is_recursive']
        );
    }

    public static function getModels(bool $includeArchived = false): array
    {
        global $DB;

        $models = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_machine_models',
            'WHERE' => $includeArchived ? [] : ['is_archived' => 0],
            'ORDER' => 'name ASC',
        ]) as $row) {
            if (Session::haveAccessToEntity((int) $row['entities_id'], (bool) $row['is_recursive'])) {
                $models[] = $row;
            }
        }

        return $models;
    }

    /**
     * Nomes de modelos por ID, incluindo arquivados (histórico de cards).
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    public static function getModelNames(array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_plugin_tralla_machine_models',
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }
}
