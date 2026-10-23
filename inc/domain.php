<?php
/**
 * Regras puras do Tralla. Este arquivo não depende do GLPI.
 */

if (!function_exists('tralla_role_rank')) {
    function tralla_role_rank(string $role): int
    {
        $ranks = [
            'member'     => 1,
            'admin'      => 2,
            'superadmin' => 3,
        ];

        return $ranks[tralla_normalize_board_role($role)] ?? 0;
    }
}

if (!function_exists('tralla_role_at_least')) {
    function tralla_role_at_least(string $role, string $minimum): bool
    {
        return tralla_role_rank($role) >= tralla_role_rank($minimum);
    }
}

if (!function_exists('tralla_normalize_board_role')) {
    function tralla_normalize_board_role(string $role): string
    {
        $role = strtolower(trim($role));

        return in_array($role, ['member', 'admin', 'superadmin'], true) ? $role : '';
    }
}

if (!function_exists('tralla_board_role_labels')) {
    function tralla_board_role_labels(): array
    {
        return [
            'member'     => 'Membro',
            'admin'      => 'Admin',
            'superadmin' => 'Super Admin',
        ];
    }
}

if (!function_exists('tralla_board_role_label')) {
    function tralla_board_role_label(string $role): string
    {
        $labels = tralla_board_role_labels();

        return $labels[tralla_normalize_board_role($role)] ?? '';
    }
}

if (!function_exists('tralla_should_backfill_creator')) {
    function tralla_should_backfill_creator(int $memberCount, int $creatorId): bool
    {
        return $memberCount === 0 && $creatorId > 0;
    }
}

if (!function_exists('tralla_parse_history_card_filter')) {
    function tralla_parse_history_card_filter(string $value): array
    {
        $result = [
            'ok'    => false,
            'type'  => 'all',
            'id'    => 0,
            'stage' => 0,
            'error' => '',
        ];

        $value = trim($value);
        if ($value === '') {
            $result['error'] = 'Informe um card.';

            return $result;
        }

        $idPart = $value;
        $stage = 0;

        if (str_contains($value, '/')) {
            $parts = explode('/', $value);
            if (count($parts) !== 2) {
                $result['error'] = 'Card inválido.';

                return $result;
            }

            $idPart = trim($parts[0]);
            $stagePart = trim($parts[1]);

            if (!ctype_digit($stagePart) || (int) $stagePart <= 0) {
                $result['error'] = 'Posição inválida.';

                return $result;
            }

            $stage = (int) $stagePart;
        }

        if (str_starts_with($idPart, '#')) {
            $idPart = substr($idPart, 1);
        }
        $idPart = trim($idPart);

        if ($idPart === '' || !ctype_digit($idPart)) {
            $result['error'] = 'Card inválido.';

            return $result;
        }

        $id = (int) $idPart;
        if ($id <= 0) {
            $result['error'] = 'Card inválido.';

            return $result;
        }

        $result['ok'] = true;
        $result['id'] = $id;
        $result['stage'] = $stage;
        $result['type'] = $stage > 0 ? 'stage' : 'all';

        return $result;
    }
}

if (!function_exists('tralla_validate_history_dates')) {
    function tralla_validate_history_dates(string $from, string $to): array
    {
        $result = [
            'ok'    => false,
            'from'  => '',
            'to'    => '',
            'error' => '',
        ];

        $from = trim($from);
        $to = trim($to);

        foreach ([$from, $to] as $date) {
            if ($date === '') {
                continue;
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $result['error'] = 'Data inválida.';

                return $result;
            }

            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed === false
                || $parsed->format('Y-m-d') !== $date
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            ) {
                $result['error'] = 'Data inválida.';

                return $result;
            }
        }

        if ($from !== '' && $to !== '' && $from > $to) {
            $result['error'] = 'A data inicial não pode ser posterior à final.';

            return $result;
        }

        $result['ok'] = true;
        $result['from'] = $from !== '' ? $from . ' 00:00:00' : '';
        $result['to'] = $to !== '' ? $to . ' 23:59:59' : '';

        return $result;
    }
}

if (!function_exists('tralla_normalize_deletion_reason')) {
    function tralla_normalize_deletion_reason(string $reason): string
    {
        $reason = trim(strip_tags($reason));

        if (function_exists('mb_substr')) {
            return mb_substr($reason, 0, 2000);
        }

        return substr($reason, 0, 2000);
    }
}

if (!function_exists('tralla_is_valid_deletion_reason')) {
    function tralla_is_valid_deletion_reason(string $reason): bool
    {
        $reason = tralla_normalize_deletion_reason($reason);
        if ($reason === '') {
            return false;
        }

        return function_exists('mb_strlen')
            ? mb_strlen($reason) <= 2000
            : strlen($reason) <= 2000;
    }
}

if (!function_exists('tralla_can_request_deletion')) {
    function tralla_can_request_deletion(array $card, bool $isMember, bool $hasDelete, bool $isAdmin): bool
    {
        $stage = (int) ($card['stage'] ?? 1);
        $isDeleted = !empty($card['is_deleted']);
        $status = (string) ($card['status'] ?? '');
        $isLocked = !empty($card['is_locked']);

        if ($stage !== 1 || $isDeleted || $isLocked) {
            return false;
        }

        if (in_array($status, ['deletion_pending', 'deleted'], true)) {
            return false;
        }

        return $isAdmin || ($isMember && $hasDelete);
    }
}

if (!function_exists('tralla_restore_list_id')) {
    function tralla_restore_list_id(array $card, array $lists, string $boardType): int
    {
        $previousId = (int) ($card['deletion_previous_lists_id'] ?? 0);

        if ($previousId > 0) {
            foreach ($lists as $list) {
                if ((int) ($list['id'] ?? 0) !== $previousId || !empty($list['is_archived'])) {
                    continue;
                }

                return $previousId;
            }
        }

        if ($boardType === 'maintenance') {
            foreach ($lists as $list) {
                if (empty($list['is_archived'])
                    && (string) ($list['category'] ?? '') === 'maintenance_pending'
                ) {
                    return (int) ($list['id'] ?? 0);
                }
            }

            return 0;
        }

        foreach ($lists as $list) {
            if (empty($list['is_archived'])) {
                return (int) ($list['id'] ?? 0);
            }
        }

        return 0;
    }
}

if (!function_exists('tralla_restore_status')) {
    function tralla_restore_status(array $card, string $boardType): string
    {
        $previous = (string) ($card['deletion_previous_status'] ?? '');

        if ($previous !== '' && !in_array($previous, ['deleted', 'deletion_pending'], true)) {
            return $previous;
        }

        return $boardType === 'maintenance' ? 'maintenance_pending' : 'normal';
    }
}

if (!function_exists('tralla_restore_is_locked')) {
    function tralla_restore_is_locked(array $card): int
    {
        $previous = (string) ($card['deletion_previous_status'] ?? '');

        if ($previous === '' || in_array($previous, ['deleted', 'deletion_pending'], true)) {
            return 0;
        }

        return !empty($card['deletion_previous_is_locked']) ? 1 : 0;
    }
}

if (!function_exists('tralla_role_can_view')) {
    function tralla_role_can_view(bool $isMember, bool $isSuperAdmin, bool $hasEntityAccess, bool $hasRead): bool
    {
        return $hasRead && $hasEntityAccess && ($isMember || $isSuperAdmin);
    }
}

if (!function_exists('tralla_can_manage_members')) {
    function tralla_can_manage_members(string $role, bool $hasUpdate): bool
    {
        return tralla_normalize_board_role($role) === 'superadmin' && $hasUpdate;
    }
}

if (!function_exists('tralla_can_restore')) {
    function tralla_can_restore(string $role, bool $hasUpdate): bool
    {
        $role = tralla_normalize_board_role($role);

        return in_array($role, ['admin', 'superadmin'], true) && $hasUpdate;
    }
}
