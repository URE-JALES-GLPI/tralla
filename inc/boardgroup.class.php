<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/config.class.php';

/** Organização pessoal dos quadros visíveis na página inicial do Tralla. */
class PluginTrallaBoardGroup
{
    private static function key(int $userId): string
    {
        return 'board_groups_user_' . $userId;
    }

    /**
     * @param int[] $accessibleIds Quadros que o usuário ainda pode acessar, inclusive arquivados.
     * @return array{groups: array<int, array{id:string,name:string,board_ids:array<int,int>}>, ungrouped:array<int,int>}
     */
    private static function load(int $userId, array $accessibleIds): array
    {
        $decoded = json_decode(PluginTrallaConfig::get(self::key($userId)), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $seenBoards = [];
        $seenGroups = [];
        $groups = [];

        foreach ((array) ($decoded['groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $id = (string) ($group['id'] ?? '');
            $name = trim((string) ($group['name'] ?? ''));
            if (!preg_match('/^[a-f0-9]{16}$/D', $id) || $name === '' || isset($seenGroups[$id])) {
                continue;
            }
            $seenGroups[$id] = true;
            $boards = [];
            foreach ((array) ($group['board_ids'] ?? []) as $boardId) {
                $boardId = (int) $boardId;
                if ($boardId > 0 && !isset($seenBoards[$boardId])) {
                    $boards[] = $boardId;
                    $seenBoards[$boardId] = true;
                }
            }
            $groups[] = ['id' => $id, 'name' => mb_substr($name, 0, 80), 'board_ids' => $boards];
        }

        $ungrouped = [];
        foreach ((array) ($decoded['ungrouped'] ?? []) as $boardId) {
            $boardId = (int) $boardId;
            if ($boardId > 0 && !isset($seenBoards[$boardId])) {
                $ungrouped[] = $boardId;
                $seenBoards[$boardId] = true;
            }
        }
        foreach ($accessibleIds as $boardId) {
            $boardId = (int) $boardId;
            if ($boardId > 0 && !isset($seenBoards[$boardId])) {
                $ungrouped[] = $boardId;
                $seenBoards[$boardId] = true;
            }
        }

        return ['groups' => $groups, 'ungrouped' => $ungrouped];
    }

    /** @param int[] $accessibleIds @param int[] $displayedIds */
    public static function display(int $userId, array $accessibleIds, array $displayedIds): array
    {
        $layout = self::load($userId, $accessibleIds);
        $displayed = array_fill_keys(array_map('intval', $displayedIds), true);
        $layout['ungrouped'] = array_values(array_filter($layout['ungrouped'], static fn(int $id): bool => isset($displayed[$id])));
        foreach ($layout['groups'] as &$group) {
            $group['board_ids'] = array_values(array_filter($group['board_ids'], static fn(int $id): bool => isset($displayed[$id])));
        }
        unset($group);

        return $layout;
    }

    private static function save(int $userId, array $layout): void
    {
        PluginTrallaConfig::set(self::key($userId), json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param int[] $accessibleIds */
    public static function create(int $userId, array $accessibleIds, string $name): void
    {
        $name = trim(strip_tags($name));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException('Informe um nome de grupo com até 80 caracteres.');
        }
        $layout = self::load($userId, $accessibleIds);
        if (count($layout['groups']) >= 30) {
            throw new RuntimeException('Limite de 30 grupos por usuário.');
        }
        $layout['groups'][] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'board_ids' => []];
        self::save($userId, $layout);
    }

    /** @param int[] $accessibleIds */
    public static function rename(int $userId, array $accessibleIds, string $groupId, string $name): void
    {
        $name = trim(strip_tags($name));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException('Informe um nome de grupo com até 80 caracteres.');
        }
        $layout = self::load($userId, $accessibleIds);
        foreach ($layout['groups'] as $index => $group) {
            if ($group['id'] === $groupId) {
                $layout['groups'][$index]['name'] = $name;
                self::save($userId, $layout);
                return;
            }
        }
        throw new RuntimeException('Grupo não encontrado.');
    }

    /** @param int[] $accessibleIds */
    public static function delete(int $userId, array $accessibleIds, string $groupId): void
    {
        $layout = self::load($userId, $accessibleIds);
        foreach ($layout['groups'] as $index => $group) {
            if ($group['id'] === $groupId) {
                $layout['ungrouped'] = array_merge($layout['ungrouped'], $group['board_ids']);
                array_splice($layout['groups'], $index, 1);
                self::save($userId, $layout);
                return;
            }
        }
        throw new RuntimeException('Grupo não encontrado.');
    }

    /** @param int[] $accessibleIds */
    public static function shift(int $userId, array $accessibleIds, string $groupId, int $direction): void
    {
        if (!in_array($direction, [-1, 1], true)) {
            throw new RuntimeException('Movimento inválido.');
        }
        $layout = self::load($userId, $accessibleIds);
        foreach ($layout['groups'] as $index => $group) {
            if ($group['id'] === $groupId) {
                $next = $index + $direction;
                if (isset($layout['groups'][$next])) {
                    [$layout['groups'][$index], $layout['groups'][$next]] = [$layout['groups'][$next], $layout['groups'][$index]];
                    self::save($userId, $layout);
                }
                return;
            }
        }
        throw new RuntimeException('Grupo não encontrado.');
    }

    /** @param int[] $accessibleIds */
    public static function move(int $userId, array $accessibleIds, int $boardId, string $groupId, int $beforeBoardId): void
    {
        if ($boardId <= 0 || !in_array($boardId, $accessibleIds, true)) {
            throw new RuntimeException('Quadro sem acesso.');
        }
        $layout = self::load($userId, $accessibleIds);
        $target = null;
        if ($groupId !== '') {
            foreach ($layout['groups'] as $index => $group) {
                if ($group['id'] === $groupId) {
                    $target = $index;
                    break;
                }
            }
            if ($target === null) {
                throw new RuntimeException('Grupo não encontrado.');
            }
        }
        if ($beforeBoardId === $boardId) {
            return;
        }
        $layout['ungrouped'] = array_values(array_diff($layout['ungrouped'], [$boardId]));
        foreach ($layout['groups'] as &$group) {
            $group['board_ids'] = array_values(array_diff($group['board_ids'], [$boardId]));
        }
        unset($group);

        if ($target === null) {
            $items =& $layout['ungrouped'];
        } else {
            $items =& $layout['groups'][$target]['board_ids'];
        }
        $position = $beforeBoardId > 0 ? array_search($beforeBoardId, $items, true) : false;
        array_splice($items, $position === false ? count($items) : $position, 0, [$boardId]);
        self::save($userId, $layout);
    }
}
