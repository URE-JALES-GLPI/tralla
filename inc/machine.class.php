<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Máquina vinculada a um card de manutenção.
 */
class PluginTrallaMachine extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public static function getTypeName($nb = 0)
    {
        return _n('Máquina', 'Máquinas', $nb, 'tralla');
    }

    public static function getMachine(int $id): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_machines',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    public static function getMachinesForCard(int $cards_id): array
    {
        global $DB;

        $machines = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_machines',
            'WHERE' => ['plugin_tralla_cards_id' => $cards_id],
            'ORDER' => 'rank ASC, id ASC',
        ]);

        foreach ($iterator as $row) {
            $row['model_name'] = '';
            if ((int) $row['plugin_tralla_machine_models_id'] > 0) {
                $model = PluginTrallaMachineModel::getModel((int) $row['plugin_tralla_machine_models_id'], true);
                if ($model !== null) {
                    $row['model_name'] = (string) $model['name'];
                }
            }
            if ((string) $row['status'] === '') {
                $row['status'] = 'in_progress';
            }
            $row['status_label'] = tralla_machine_status_label((string) $row['status']);
            $row['is_filled'] = tralla_machine_is_filled($row);
            $machines[] = $row;
        }

        return $machines;
    }

    public static function summarizeModels(array $machines, array $modelNames = []): string
    {
        $counts = [];
        foreach ($machines as $machine) {
            $modelId = (int) ($machine['plugin_tralla_machine_models_id'] ?? 0);
            $name = trim((string) ($modelNames[$modelId] ?? ($machine['model_name'] ?? '')));
            if ($name === '') {
                $name = 'Modelo não informado';
            }
            if (!isset($counts[$name])) {
                $counts[$name] = 0;
            }
            $counts[$name]++;
        }

        $parts = [];
        foreach ($counts as $name => $count) {
            $parts[] = $count . 'x ' . $name;
        }

        return implode(' · ', $parts);
    }

    public static function insertForCard(int $cards_id, array $data, float $rank = 0): int
    {
        global $DB;

        if ($rank <= 0) {
            $row = $DB->request([
                'SELECT' => ['MAX' => 'rank AS max_rank'],
                'FROM'   => 'glpi_plugin_tralla_machines',
                'WHERE'  => ['plugin_tralla_cards_id' => $cards_id],
            ])->current();
            $rank = (float) ($row['max_rank'] ?? 0) + 1024.0;
        }

        $now = tralla_now();

        $DB->insert('glpi_plugin_tralla_machines', [
            'plugin_tralla_cards_id'          => $cards_id,
            'plugin_tralla_machine_models_id' => (int) ($data['model_id'] ?? 0),
            'label'                           => tralla_normalize_label($data['label'] ?? ''),
            'serial'                          => tralla_normalize_label($data['serial'] ?? ''),
            'report'                          => '',
            'status'                          => array_key_exists((string) ($data['status'] ?? ''), tralla_machine_status_choices())
                ? (string) $data['status'] : 'in_progress',
            'service_done'                    => !empty($data['service_done'])
                && tralla_machine_service_done_allowed((string) ($data['status'] ?? 'in_progress')) ? 1 : 0,
            'needs_inventory'                 => !empty($data['needs_inventory']) ? 1 : 0,
            'inventory_items_id'              => (int) ($data['inventory_items_id'] ?? 0),
            'rank'                            => $rank,
            'report_saved_at'                 => null,
            'date_creation'                   => $now,
            'date_mod'                        => $now,
        ]);

        return (int) $DB->insertId();
    }

    public static function updateMachine(int $id, array $data): bool
    {
        global $DB;

        $allowed = [];
        if (array_key_exists('report', $data)) {
            $allowed['report'] = tralla_normalize_report($data['report']);
            $allowed['report_saved_at'] = tralla_now();
        }
        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            $allowed['status'] = array_key_exists($status, tralla_machine_status_choices()) ? $status : 'in_progress';
        }
        if (array_key_exists('service_done', $data)) {
            $allowed['service_done'] = !empty($data['service_done']) ? 1 : 0;
        }
        if (array_key_exists('needs_inventory', $data)) {
            $allowed['needs_inventory'] = !empty($data['needs_inventory']) ? 1 : 0;
        }
        if (array_key_exists('label', $data)) {
            $allowed['label'] = tralla_normalize_label($data['label']);
        }
        if (array_key_exists('serial', $data)) {
            $allowed['serial'] = tralla_normalize_label($data['serial']);
        }
        if (array_key_exists('model_id', $data)) {
            $allowed['plugin_tralla_machine_models_id'] = (int) $data['model_id'];
        }
        if (array_key_exists('inventory_items_id', $data)) {
            $allowed['inventory_items_id'] = max(0, (int) $data['inventory_items_id']);
        }

        // "Serviço realizado" só vale para Pendente, Concluído ou Inservível.
        if (array_key_exists('status', $allowed) || array_key_exists('service_done', $allowed)) {
            $finalStatus = $allowed['status'] ?? null;
            if ($finalStatus === null) {
                $current = $DB->request([
                    'SELECT' => ['status'],
                    'FROM'   => 'glpi_plugin_tralla_machines',
                    'WHERE'  => ['id' => $id],
                    'LIMIT'  => 1,
                ])->current();
                $finalStatus = (string) ($current['status'] ?? 'in_progress');
            }
            if (!tralla_machine_service_done_allowed($finalStatus)) {
                $allowed['service_done'] = 0;
            }
        }

        if ($allowed === []) {
            return false;
        }

        $allowed['date_mod'] = tralla_now();
        return $DB->update('glpi_plugin_tralla_machines', $allowed, ['id' => $id]) !== false;
    }

    public static function deleteMachine(int $id): bool
    {
        global $DB;

        $DB->delete('glpi_plugin_tralla_machines', ['id' => $id]);

        return true;
    }

    public static function refreshCardTotal(int $cards_id): int
    {
        global $DB;

        $total = countElementsInTable('glpi_plugin_tralla_machines', [
            'plugin_tralla_cards_id' => $cards_id,
        ]);

        $DB->update('glpi_plugin_tralla_cards', [
            'machines_total' => $total,
            'date_mod'       => tralla_now(),
        ], ['id' => $cards_id]);

        return $total;
    }
}
