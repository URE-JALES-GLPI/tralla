<?php
/**
 * Regras puras do Tralla. Este arquivo não depende do GLPI.
 */

if (!function_exists('tralla_finalization_partition')) {
    function tralla_finalization_partition(array $machines): array
    {
        $ready = [];
        $pending = [];
        foreach ($machines as $machine) {
            $id = (int) ($machine['id'] ?? 0);
            $status = (string) ($machine['status'] ?? '');
            if ($status === 'pending') {
                $pending[] = $id;
            } elseif (in_array($status, ['done', 'not_repairable', 'warranty'], true)) {
                $ready[] = $id;
            }
        }

        return ['ready' => $ready, 'pending' => $pending];
    }
}

if (!function_exists('tralla_normalize_call_categories')) {
    /**
     * Valida as categorias vindas da tela de configuração.
     *
     * @param array $rows lista de ['name' => string, 'subs' => string[]]
     * @return array{categories: array<string, string[]>, error: string}
     */
    function tralla_normalize_call_categories(array $rows): array
    {
        $clean = static fn ($value): string => is_scalar($value)
            ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value))) : '';
        $fail = static fn (string $error): array => ['categories' => [], 'error' => $error];
        $categories = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = $clean(is_array($row) ? ($row['name'] ?? '') : '');
            $subs = [];
            $seenSubs = [];
            foreach ((array) (is_array($row) ? ($row['subs'] ?? []) : []) as $sub) {
                $sub = $clean($sub);
                $subKey = mb_strtolower($sub);
                if ($sub === '' || isset($seenSubs[$subKey])) {
                    continue;
                }
                if (mb_strlen($sub) > 150) {
                    return $fail('A subcategoria "' . mb_substr($sub, 0, 40) . '..." passa de 150 caracteres.');
                }
                $seenSubs[$subKey] = true;
                $subs[] = $sub;
            }
            if ($name === '' && $subs === []) {
                continue;
            }
            if ($name === '') {
                return $fail('Informe o nome de todas as categorias.');
            }
            if (mb_strlen($name) > 100) {
                return $fail('A categoria "' . mb_substr($name, 0, 40) . '..." passa de 100 caracteres.');
            }
            if (isset($seen[mb_strtolower($name)])) {
                return $fail('A categoria "' . $name . '" está repetida.');
            }
            if ($subs === []) {
                return $fail('A categoria "' . $name . '" precisa de ao menos uma subcategoria.');
            }
            $seen[mb_strtolower($name)] = true;
            $categories[$name] = $subs;
        }
        if ($categories === []) {
            return $fail('Mantenha ao menos uma categoria.');
        }

        return ['categories' => $categories, 'error' => ''];
    }
}

if (!function_exists('tralla_person_name')) {
    /** Nome de exibição: primeiro nome e depois sobrenome; sem nenhum dos dois, o login. */
    function tralla_person_name(string $firstname, string $realname, string $login = ''): string
    {
        $name = trim(trim($firstname) . ' ' . trim($realname));

        return $name !== '' ? $name : trim($login);
    }
}

if (!function_exists('tralla_pending_reminder_minutes')) {
    /** Intervalo dos lembretes de card no Pendente, em minutos, por prioridade. */
    function tralla_pending_reminder_minutes(string $priority): int
    {
        return ['P1' => 5, 'P2' => 10, 'P3' => 30, 'P4' => 60][$priority] ?? 0;
    }
}

if (!function_exists('tralla_pending_reminder_allowed_at')) {
    /** Lembretes de card no Pendente só saem das 8h até as 17h. */
    function tralla_pending_reminder_allowed_at(int $timestamp): bool
    {
        $hour = (int) date('G', $timestamp);

        return $hour >= 8 && $hour < 17;
    }
}

if (!function_exists('tralla_whatsapp_number')) {
    /** Número no formato da EvolutionAPI (55 + DDD + número) ou '' se inválido. */
    function tralla_whatsapp_number(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return strlen($digits) >= 12 && strlen($digits) <= 13 ? $digits : '';
    }
}

if (!function_exists('tralla_duration_label')) {
    /** Duração legível: 45min, 3h10, 2d 4h. */
    function tralla_duration_label(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        if ($minutes < 60) {
            return $minutes . 'min';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            $rest = $minutes % 60;
            return $hours . 'h' . ($rest > 0 ? sprintf('%02d', $rest) : '');
        }

        return intdiv($hours, 24) . 'd ' . ($hours % 24) . 'h';
    }
}

if (!function_exists('tralla_holiday_map')) {
    /** Feriados automáticos (nacionais, SP e municipais de Jales) do ano. */
    function tralla_holiday_map(int $year): array
    {
        $map = [];
        foreach ([
            '01-01' => 'Confraternização Universal', '04-15' => 'Fundação de Jales (municipal)',
            '04-21' => 'Tiradentes', '05-01' => 'Dia do Trabalho', '07-09' => 'Revolução Constitucionalista (SP)',
            '08-15' => 'Assunção de N. Sra. (municipal Jales)', '09-07' => 'Independência',
            '10-12' => 'N. Sra. Aparecida', '11-02' => 'Finados', '11-15' => 'Proclamação da República',
            '11-20' => 'Consciência Negra', '12-25' => 'Natal',
        ] as $day => $name) {
            $map[sprintf('%04d-', $year) . $day] = $name;
        }
        // Páscoa (algoritmo de Meeus/Jones/Butcher) e as datas móveis que dependem dela.
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        foreach (['-48 days' => 'Carnaval (seg)', '-47 days' => 'Carnaval (ter)', '-2 days' => 'Sexta-feira Santa',
            '+0 days' => 'Páscoa', '+60 days' => 'Corpus Christi'] as $offset => $name) {
            $map[$easter->modify($offset)->format('Y-m-d')] = $name;
        }

        return $map;
    }
}

if (!function_exists('tralla_is_business_day')) {
    /** Dia útil: de segunda a sexta e fora dos feriados de tralla_holiday_map(). */
    function tralla_is_business_day(int $timestamp): bool
    {
        if ((int) date('N', $timestamp) >= 6) {
            return false;
        }

        return !isset(tralla_holiday_map((int) date('Y', $timestamp))[date('Y-m-d', $timestamp)]);
    }
}

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

        return in_array($role, ['tablet', 'member', 'admin', 'superadmin'], true) ? $role : '';
    }
}

if (!function_exists('tralla_board_role_labels')) {
    /** O papel Tablet só existe em quadros de manutenção; sem tipo, lista todos os papéis. */
    function tralla_board_role_labels(string $boardType = ''): array
    {
        $labels = [
            'member'     => 'Membro',
            'admin'      => 'Admin',
            'superadmin' => 'Super Admin',
        ];
        if ($boardType === '' || $boardType === 'maintenance') {
            $labels['tablet'] = 'Tablet';
        }

        return $labels;
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
    function tralla_role_can_view(bool $isMember, bool $hasEntityAccess, bool $hasRead): bool
    {
        return $hasRead && $hasEntityAccess && $isMember;
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
