<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/file.class.php';

/**
 * Lista (coluna) de um quadro.
 */
class PluginTrallaList extends CommonDBTM
{
    public static string $rightname = 'plugin_tralla';

    public static function getTypeName($nb = 0)
    {
        return _n('Lista', 'Listas', $nb, 'tralla');
    }

    public function prepareInputForAdd($input)
    {
        $boardId = (int) ($input['plugin_tralla_boards_id'] ?? 0);
        if ($boardId <= 0 || !PluginTrallaBoard::canViewBoard($boardId)) {
            Session::addMessageAfterRedirect('Quadro inválido.', false, ERROR);
            return false;
        }

        $board = new PluginTrallaBoard();
        $board->getFromDB($boardId);
        $boardType = (string) ($board->fields['type'] ?? 'normal');

        $input['name'] = tralla_normalize_label($input['name'] ?? '');
        if ($input['name'] === '') {
            Session::addMessageAfterRedirect('Informe o nome da lista.', false, ERROR);
            return false;
        }

        $category = (string) ($input['category'] ?? '');
        if ($boardType === 'maintenance') {
            if ($category !== '' && !tralla_is_maintenance_category($category)) {
                $category = '';
            }
        } elseif ($boardType === 'updates') {
            if (!in_array($category, tralla_update_categories(), true)) {
                throw new RuntimeException('Categoria de atualização inválida.');
            }
        } else {
            $category = '';
        }
        $input['category'] = $category;
        $input['color'] = array_key_exists('color', $input)
            ? tralla_valid_color((string) $input['color'], tralla_category_color($category))
            : tralla_category_color($category);
        $input['plugin_tralla_boards_id'] = $boardId;
        $input['is_archived'] = !empty($input['is_archived']) ? 1 : 0;

        if (!isset($input['rank']) || (float) $input['rank'] <= 0) {
            $input['rank'] = self::nextRank($boardId);
        }

        $input['users_id'] = (int) Session::getLoginUserID();
        $input['date_creation'] = tralla_now();
        $input['date_mod'] = $input['date_creation'];

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        $listId = (int) ($input['id'] ?? 0);
        $current = self::getList($listId);
        if ($current === null) {
            return false;
        }

        $board = new PluginTrallaBoard();
        $board->getFromDB((int) $current['plugin_tralla_boards_id']);
        $boardType = (string) ($board->fields['type'] ?? 'normal');

        if (array_key_exists('name', $input)) {
            $input['name'] = tralla_normalize_label($input['name']);
            if ($input['name'] === '') {
                unset($input['name']);
            } elseif ($input['name'] !== (string) $current['name']
                && PluginTrallaBoardUser::getRole(
                    (int) $current['plugin_tralla_boards_id'],
                    (int) Session::getLoginUserID()
                ) !== 'superadmin') {
                throw new RuntimeException('Somente SuperAdmin deste quadro pode alterar o título da lista.');
            }
        }

        if (array_key_exists('category', $input)) {
            $currentCategory = (string) ($current['category'] ?? '');
            $category = (string) $input['category'];

            // Categorias de fluxo são imutáveis depois de atribuídas.
            if ($currentCategory !== '') {
                $category = $currentCategory;
            }

            if ($boardType === 'updates') {
                if (!in_array($category, tralla_update_categories(), true)) {
                    unset($input['category']);
                }
            } elseif ($boardType !== 'maintenance') {
                $category = '';
            } elseif ($category !== '' && !tralla_is_maintenance_category($category)) {
                unset($input['category']);
            }
            if (array_key_exists('category', $input)) {
                $input['category'] = $category;
            }
        }

        if (array_key_exists('color', $input)) {
            $input['color'] = tralla_valid_color((string) $input['color']);
        }
        if (array_key_exists('status_archived', $input)) {
            $input['is_archived'] = !empty($input['status_archived']) ? 1 : 0;
            unset($input['status_archived']);
        }
        if (array_key_exists('is_archived', $input)) {
            $input['is_archived'] = !empty($input['is_archived']) ? 1 : 0;
        }

        $input['date_mod'] = tralla_now();

        return $input;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        $listId = (int) $this->getID();
        if ($listId <= 0) {
            return;
        }

        $cardIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_tralla_cards',
            'WHERE'  => ['plugin_tralla_lists_id' => $listId],
        ]) as $row) {
            $cardIds[] = (int) $row['id'];
        }

        if ($cardIds !== []) {
            PluginTrallaFile::purgeForCards($cardIds);
            $DB->delete('glpi_plugin_tralla_machines', ['plugin_tralla_cards_id' => $cardIds]);
            $DB->delete('glpi_plugin_tralla_history', ['plugin_tralla_cards_id' => $cardIds]);
            $DB->delete('glpi_plugin_tralla_notifications', ['plugin_tralla_cards_id' => $cardIds]);
            $DB->delete('glpi_plugin_tralla_cards', ['id' => $cardIds]);
        }
    }

    public static function getList(int $lists_id): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_tralla_lists',
            'WHERE' => ['id' => $lists_id],
            'LIMIT' => 1,
        ])->current();

        return is_array($row) ? $row : null;
    }

    public static function getListsForBoard(int $boards_id, bool $includeArchived = false): array
    {
        global $DB;

        $where = ['plugin_tralla_boards_id' => $boards_id];
        if (!$includeArchived) {
            $where['is_archived'] = 0;
        }

        $lists = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_tralla_lists',
            'WHERE' => $where,
            'ORDER' => 'rank ASC, id ASC',
        ]) as $row) {
            $lists[] = $row;
        }

        return $lists;
    }

    public static function nextRank(int $boards_id): float
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'rank AS max_rank'],
            'FROM'   => 'glpi_plugin_tralla_lists',
            'WHERE'  => ['plugin_tralla_boards_id' => $boards_id],
        ])->current();

        return (float) ($row['max_rank'] ?? 0) + 1000.0;
    }

    public static function categoryExists(int $boards_id, string $category, int $ignoreId = 0): bool
    {
        global $DB;

        if ($category === '') {
            return false;
        }

        $where = [
            'plugin_tralla_boards_id' => $boards_id,
            'category'                => $category,
            'is_archived'             => 0,
        ];
        if ($ignoreId > 0) {
            $where['NOT'] = ['id' => $ignoreId];
        }

        return countElementsInTable('glpi_plugin_tralla_lists', $where) > 0;
    }

    public static function canViewList(int $lists_id, int $boards_id): bool
    {
        $list = self::getList($lists_id);

        return $list !== null
            && (int) $list['plugin_tralla_boards_id'] === $boards_id
            && PluginTrallaBoard::canViewBoard($boards_id);
    }
}
