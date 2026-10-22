<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Configurações globais do [URE] Tralla.
 */
class PluginTrallaConfig
{
    public const TABLE = 'glpi_plugin_tralla_configs';

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'assignature_url'      => '',
            'assignature_token'    => '',
            'approval_group'       => '0',
            'notification_enabled' => '1',
            'notification_emails'  => '',
            'pending_hours'        => '1',
            'withdrawal_hours'     => '24',
        ];
    }

    public static function getAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        global $DB;

        $values = self::defaults();
        if ($DB->tableExists(self::TABLE)) {
            foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
                $values[(string) $row['config_key']] = (string) ($row['config_value'] ?? '');
            }
        }

        self::$cache = $values;

        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $values = self::getAll();

        return array_key_exists($key, $values) ? (string) $values[$key] : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key, (string) $default);

        return $value === '' ? $default : (int) $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');

        return $value === '1' || $value === 'true';
    }

    public static function set(string $key, string $value): void
    {
        global $DB;

        $now = tralla_now();
        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['config_key' => $key],
            'LIMIT'  => 1,
        ])->current();

        if ($exists) {
            $DB->update(self::TABLE, [
                'config_value' => $value,
                'date_mod'     => $now,
            ], ['config_key' => $key]);
        } else {
            $DB->insert(self::TABLE, [
                'config_key'    => $key,
                'config_value'  => $value,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }

        self::$cache = null;
    }

    public static function assignatureUrl(): string
    {
        return trim(self::get('assignature_url'));
    }

    public static function assignatureToken(): string
    {
        return trim(self::get('assignature_token'));
    }

    public static function approvalGroupId(): int
    {
        return max(0, self::getInt('approval_group', 0));
    }

    public static function notificationsEnabled(): bool
    {
        return self::getBool('notification_enabled', true);
    }

    public static function notificationEmails(): array
    {
        $raw = self::get('notification_emails');
        $emails = [];

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    public static function pendingHours(): int
    {
        return max(1, self::getInt('pending_hours', 1));
    }

    public static function withdrawalHours(): int
    {
        return max(1, self::getInt('withdrawal_hours', 24));
    }

    /**
     * URL do card no assignat-ure. Retorna '' quando não configurado.
     */
    public static function assignatureCardUrl(int $cards_id): string
    {
        $base = self::assignatureUrl();
        if ($base === '') {
            return '';
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'card=' . $cards_id;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
