<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Histórico de ações dos cards.
 */
class PluginTrallaHistory extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public static function getTypeName($nb = 0)
    {
        return _n('Histórico', 'Históricos', $nb, 'tralla');
    }

    public static function log(int $cards_id, int $users_id, string $action, string $details = ''): void
    {
        global $DB;

        if ($cards_id <= 0) {
            return;
        }

        $DB->insert('glpi_plugin_tralla_history', [
            'plugin_tralla_cards_id' => $cards_id,
            'users_id'               => $users_id,
            'action'                 => substr($action, 0, 60),
            'details'                => $details,
            'date_creation'          => tralla_now(),
        ]);
    }

    public static function getForCard(int $cards_id): array
    {
        global $DB;

        $history = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_history',
            'WHERE' => ['plugin_tralla_cards_id' => $cards_id],
            'ORDER' => 'date_creation DESC, id DESC',
        ]);

        foreach ($iterator as $row) {
            $row['user_name'] = tralla_user_name((int) $row['users_id']);
            $row['action_label'] = self::getActionLabel((string) $row['action']);
            $row['date_formatted'] = tralla_date($row['date_creation']);
            $history[] = $row;
        }

        return $history;
    }

    public static function getActionLabel(string $action): string
    {
        $labels = [
            'board_created'         => 'Quadro criado',
            'card_created'          => 'Card criado',
            'card_updated'          => 'Card atualizado',
            'card_deleted'          => 'Card excluído',
            'card_moved'            => 'Card movido',
            'card_taken'            => 'Card pego pelo técnico',
            'machine_added'         => 'Máquina adicionada',
            'machine_updated'       => 'Máquina atualizada',
            'machine_deleted'       => 'Máquina removida',
            'card_finalized'        => 'Manutenção finalizada',
            'approval_created'      => 'Card de aprovação criado',
            'approval_approved'     => 'Manutenção aprovada',
            'approval_rejected'     => 'Manutenção reprovada',
            'withdrawal_confirmed'  => 'Retirada confirmada',
            'withdrawal_reminded'   => 'Lembrete de retirada enviado',
            'pending_notified'      => 'Notificação de pendente enviada',
        ];

        return $labels[$action] ?? $action;
    }
}
