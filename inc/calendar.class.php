<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Calendário de envios do Tralla: além de fim de semana e feriados automáticos
 * (tralla_holiday_map), dias tirados do envio ('skip') ou forçados ('force').
 */
class PluginTrallaCalendar
{
    public const TABLE = 'glpi_plugin_tralla_calendar_days';

    /** Exceção cadastrada para o dia: '' (automático), 'skip' ou 'force'. */
    public static function dayMode(string $ymd): string
    {
        global $DB;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || !$DB->tableExists(self::TABLE)) {
            return '';
        }
        $row = $DB->request(['SELECT' => ['mode'], 'FROM' => self::TABLE, 'WHERE' => ['day' => $ymd], 'LIMIT' => 1])->current();
        $mode = is_array($row) ? (string) $row['mode'] : '';

        return in_array($mode, ['skip', 'force'], true) ? $mode : '';
    }

    /** Hoje é dia de enviar lembretes e avisos automáticos? */
    public static function isNotificationDay(int $timestamp): bool
    {
        $mode = self::dayMode(date('Y-m-d', $timestamp));
        if ($mode !== '') {
            return $mode === 'force';
        }

        return tralla_is_business_day($timestamp);
    }

    /** Exceções a partir de uma data, em ordem. */
    public static function upcoming(string $fromYmd): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['day' => ['>=', $fromYmd]],
            'ORDER' => 'day ASC',
        ]) as $row) {
            $row['user_name'] = tralla_user_name((int) $row['users_id']);
            $rows[] = $row;
        }

        return $rows;
    }

    public static function save(string $ymd, string $mode, string $reason, int $userId): void
    {
        global $DB;

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($date === false || $date->format('Y-m-d') !== $ymd) {
            throw new RuntimeException('Informe uma data válida.');
        }
        if (!in_array($mode, ['skip', 'force'], true)) {
            throw new RuntimeException('Escolha se o dia fica sem envio ou com envio forçado.');
        }
        $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
        $now = tralla_now();
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['day' => $ymd], 'LIMIT' => 1])->current();
        if (is_array($existing)) {
            $DB->update(self::TABLE, ['mode' => $mode, 'reason' => $reason, 'users_id' => $userId, 'date_mod' => $now],
                ['id' => (int) $existing['id']]);
            return;
        }
        $DB->insert(self::TABLE, [
            'day' => $ymd, 'mode' => $mode, 'reason' => $reason, 'users_id' => $userId,
            'date_creation' => $now, 'date_mod' => $now,
        ]);
    }

    public static function remove(int $id): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['id' => $id]);
    }
}
