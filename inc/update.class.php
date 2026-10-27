<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/** Fluxo controlado dos cards do quadro Atualizações. */
class PluginTrallaUpdate
{
    private const ITEMS = 'glpi_plugin_tralla_update_items';
    private const CARDS = 'glpi_plugin_tralla_cards';

    private static function card(int $id, int $userId, bool $write = true): array
    {
        $card = PluginTrallaCard::getCard($id);
        if ($card === null || (string) ($card['workflow_type'] ?? '') !== 'update'
            || PluginTrallaCard::getBoardType((int) $card['plugin_tralla_boards_id']) !== 'updates'
            || !empty($card['is_deleted']) || !empty($card['is_archived'])
            || !PluginTrallaBoard::canViewBoard((int) $card['plugin_tralla_boards_id'])) {
            throw new RuntimeException('Card de atualização não encontrado.');
        }
        if ($write && (!Session::haveRight('plugin_tralla', UPDATE)
            || PluginTrallaBoard::roleFor((int) $card['plugin_tralla_boards_id'], $userId) === '')) {
            throw new RuntimeException('Sem permissão para alterar este card.');
        }
        return $card;
    }

    private static function isManager(array $card, int $userId): bool
    {
        return (int) $card['users_id'] === $userId
            || tralla_role_at_least(
                PluginTrallaBoard::roleFor((int) $card['plugin_tralla_boards_id'], $userId), 'admin'
            );
    }

    private static function listId(array $card, string $category): int
    {
        $list = PluginTrallaBoard::findListByCategory((int) $card['plugin_tralla_boards_id'], $category);
        if ($list === null) {
            throw new RuntimeException('A lista do fluxo não foi encontrada.');
        }
        return (int) $list['id'];
    }

    public static function items(int $cardId): array
    {
        global $DB;
        $items = [];
        foreach ($DB->request([
            'FROM' => self::ITEMS,
            'WHERE' => ['plugin_tralla_cards_id' => $cardId],
            'ORDER' => 'rank ASC, id ASC',
        ]) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'is_done' => (bool) $row['is_done'],
            ];
        }
        return $items;
    }

    public static function create(int $boardId, int $listId, string $title, string $mode,
        string $description, array $items, int $userId): array
    {
        global $DB;
        if (!Session::haveRight('plugin_tralla', CREATE)
            || !PluginTrallaBoard::canOperateBoard($boardId)
            || PluginTrallaCard::getBoardType($boardId) !== 'updates') {
            throw new RuntimeException('Sem permissão para criar neste quadro.');
        }
        $list = PluginTrallaList::getList($listId);
        $category = (string) ($list['category'] ?? '');
        if ((int) ($list['plugin_tralla_boards_id'] ?? 0) !== $boardId
            || !in_array($category, ['update_future', 'update_pending'], true)) {
            throw new RuntimeException('Crie o card em Atualização Futura ou Pendente.');
        }
        $title = tralla_normalize_label($title);
        if ($title === '' || mb_strlen($title) > 255 || !in_array($mode, ['description', 'checklist'], true)) {
            throw new RuntimeException('Informe um título e selecione Descrição ou Checklist.');
        }
        $description = trim($description);
        if (mb_strlen($description) > 200000 || count($items) > 200) {
            throw new RuntimeException('Descrição ou checklist excede o limite permitido.');
        }
        $titles = [];
        if ($mode === 'checklist') {
            foreach ($items as $item) {
                if (!is_scalar($item)) {
                    throw new RuntimeException('Item da checklist inválido.');
                }
                $itemTitle = tralla_normalize_label((string) $item);
                if ($itemTitle === '') {
                    continue;
                }
                if (mb_strlen($itemTitle) > 500) {
                    throw new RuntimeException('Um item da checklist excede 500 caracteres.');
                }
                $titles[] = $itemTitle;
            }
        }
        $now = tralla_now();
        $DB->beginTransaction();
        try {
            if (!$DB->insert(self::CARDS, [
                'plugin_tralla_boards_id' => $boardId,
                'plugin_tralla_lists_id' => $listId,
                'name' => $title,
                'description' => $mode === 'description' ? $description : '',
                'update_mode' => $mode,
                'rank' => PluginTrallaCard::nextRankForList($listId),
                'users_id' => $userId,
                'technician_id' => 0,
                'stage' => 1,
                'status' => $category,
                'workflow_type' => 'update',
                'is_locked' => 0,
                'is_archived' => 0,
                'source' => 'manual',
                'machines_total' => 0,
                'date_creation' => $now,
                'date_mod' => $now,
            ])) {
                throw new RuntimeException('Não foi possível criar o card.');
            }
            $id = (int) $DB->insertId();
            $rank = 1024;
            foreach ($titles as $itemTitle) {
                if (!$DB->insert(self::ITEMS, [
                    'plugin_tralla_cards_id' => $id,
                    'title' => $itemTitle,
                    'is_done' => 0,
                    'rank' => $rank,
                    'date_creation' => $now,
                    'date_mod' => $now,
                ])) {
                    throw new RuntimeException('Não foi possível criar um item da checklist.');
                }
                $rank += 1024;
            }
            PluginTrallaHistory::log($id, $userId, 'card_created', 'Atualização criada.');
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        return PluginTrallaCard::getCardDetail($id, $userId);
    }

    public static function transition(int $id, string $action, int $userId): array
    {
        global $DB;
        $card = self::card($id, $userId);
        $DB->beginTransaction();
        try {
            $DB->doQuery('SELECT `id` FROM `' . self::CARDS . '` WHERE `id` = ' . $id . ' FOR UPDATE');
            $card = self::card($id, $userId);
            $status = (string) $card['status'];
            $fields = ['date_mod' => tralla_now()];
            if ($action === 'schedule' && $status === 'update_future' && self::isManager($card, $userId)) {
                $fields['status'] = 'update_pending';
                $fields['plugin_tralla_lists_id'] = self::listId($card, 'update_pending');
                $fields['rank'] = PluginTrallaCard::nextRankForList($fields['plugin_tralla_lists_id']);
            } elseif ($action === 'take' && $status === 'update_pending') {
                $fields['status'] = 'update_in_progress';
                $fields['plugin_tralla_lists_id'] = self::listId($card, 'update_in_progress');
                $fields['rank'] = PluginTrallaCard::nextRankForList($fields['plugin_tralla_lists_id']);
                $fields['technician_id'] = $userId;
                $fields['date_taken'] = tralla_now();
            } elseif ($action === 'finish' && $status === 'update_in_progress'
                && (int) $card['technician_id'] === $userId) {
                if ((string) $card['update_mode'] === 'description'
                    && trim((string) ($card['update_solution'] ?? '')) === '') {
                    throw new RuntimeException('Registre a solução antes de finalizar.');
                }
                if ((string) $card['update_mode'] === 'checklist') {
                    $items = self::items($id);
                    if ($items === [] || count(array_filter($items, static fn($item): bool => !$item['is_done'])) > 0) {
                        throw new RuntimeException('Marque todos os itens da checklist antes de finalizar.');
                    }
                }
                $fields['status'] = 'update_done';
                $fields['plugin_tralla_lists_id'] = self::listId($card, 'update_done');
                $fields['rank'] = PluginTrallaCard::nextRankForList($fields['plugin_tralla_lists_id']);
                $fields['date_finalized'] = tralla_now();
            } else {
                throw new RuntimeException('Esta ação não está disponível para o card.');
            }
            if (!$DB->update(self::CARDS, $fields, ['id' => $id])) {
                throw new RuntimeException('Não foi possível atualizar o card.');
            }
            PluginTrallaHistory::log($id, $userId, 'update_' . $action,
                ['schedule' => 'Movido para Pendente.', 'take' => 'Atualização assumida.',
                    'finish' => 'Atualização concluída.'][$action]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        return PluginTrallaCard::getCardDetail($id, $userId);
    }

    public static function saveText(int $id, string $field, string $value, int $userId): array
    {
        global $DB;
        $DB->beginTransaction();
        try {
        $DB->doQuery('SELECT `id` FROM `' . self::CARDS . '` WHERE `id` = ' . $id . ' FOR UPDATE');
        $card = self::card($id, $userId);
        $allowed = $field === 'description' && (string) $card['update_mode'] === 'description'
            && in_array((string) $card['status'], ['update_future', 'update_pending'], true)
            && self::isManager($card, $userId);
        $allowed = $allowed || in_array($field, ['update_solution', 'update_observation'], true)
            && (string) $card['status'] === 'update_in_progress'
            && (int) $card['technician_id'] === $userId
            && ($field !== 'update_solution' || (string) $card['update_mode'] === 'description')
            && ($field !== 'update_observation' || (string) $card['update_mode'] === 'checklist');
        if (!$allowed || mb_strlen($value) > 200000) {
            throw new RuntimeException('Este campo não pode ser alterado agora.');
        }
        if (!$DB->update(self::CARDS, [$field => trim($value), 'date_mod' => tralla_now()], ['id' => $id])) {
            throw new RuntimeException('Não foi possível salvar o texto.');
        }
        $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        return PluginTrallaCard::getCardDetail($id, $userId);
    }

    public static function item(int $id, int $itemId, string $action, string $title, int $userId): array
    {
        global $DB;
        $DB->beginTransaction();
        try {
        $DB->doQuery('SELECT `id` FROM `' . self::CARDS . '` WHERE `id` = ' . $id . ' FOR UPDATE');
        $card = self::card($id, $userId);
        if ((string) $card['update_mode'] !== 'checklist'
            || (string) $card['status'] === 'update_done') {
            throw new RuntimeException('Esta checklist não pode ser alterada.');
        }
        $owner = (string) $card['status'] === 'update_in_progress'
            && (int) $card['technician_id'] === $userId;
        $manager = in_array((string) $card['status'], ['update_future', 'update_pending'], true)
            && self::isManager($card, $userId);
        if (!$owner && !$manager) {
            throw new RuntimeException('Sem permissão para alterar esta checklist.');
        }
        $existing = null;
        if ($itemId > 0) {
            $existing = $DB->request([
                'FROM' => self::ITEMS,
                'WHERE' => ['id' => $itemId, 'plugin_tralla_cards_id' => $id],
                'LIMIT' => 1,
            ])->current();
            if (!is_array($existing)) {
                throw new RuntimeException('Item não encontrado neste card.');
            }
        }
        if ($action === 'add') {
            $title = tralla_normalize_label($title);
            $itemCount = count(self::items($id));
            if ($title === '' || mb_strlen($title) > 500 || $itemCount >= 200) {
                throw new RuntimeException('Informe um item válido (até 500 caracteres).');
            }
            if (!$DB->insert(self::ITEMS, [
                'plugin_tralla_cards_id' => $id,
                'title' => $title,
                'is_done' => 0,
                'rank' => ($itemCount + 1) * 1024,
                'date_creation' => tralla_now(),
                'date_mod' => tralla_now(),
            ])) {
                throw new RuntimeException('Não foi possível adicionar o item.');
            }
        } elseif ($action === 'toggle' && $existing !== null && $owner) {
            if (!$DB->update(self::ITEMS, ['is_done' => empty($existing['is_done']) ? 1 : 0,
                'date_mod' => tralla_now()], ['id' => $itemId])) {
                throw new RuntimeException('Não foi possível atualizar o item.');
            }
        } elseif ($action === 'rename' && $existing !== null) {
            $title = tralla_normalize_label($title);
            if ($title === '' || mb_strlen($title) > 500) {
                throw new RuntimeException('Informe um item válido.');
            }
            if (!$DB->update(self::ITEMS, ['title' => $title, 'date_mod' => tralla_now()], ['id' => $itemId])) {
                throw new RuntimeException('Não foi possível renomear o item.');
            }
        } elseif ($action === 'delete' && $existing !== null) {
            if (countElementsInTable(PluginTrallaFile::TABLE,
                ['plugin_tralla_update_items_id' => $itemId]) > 0) {
                throw new RuntimeException('Remova os arquivos deste item antes de excluí-lo.');
            }
            if (!$DB->delete(self::ITEMS, ['id' => $itemId])) {
                throw new RuntimeException('Não foi possível excluir o item.');
            }
        } else {
            throw new RuntimeException('Ação da checklist inválida.');
        }
        if (!$DB->update(self::CARDS, ['date_mod' => tralla_now()], ['id' => $id])) {
            throw new RuntimeException('Não foi possível atualizar o card.');
        }
        PluginTrallaHistory::log($id, $userId, 'update_item_' . $action,
            $action === 'delete' ? 'Item excluído.' : ($title !== '' ? $title : 'Item atualizado.'));
        $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        return PluginTrallaCard::getCardDetail($id, $userId);
    }
}
