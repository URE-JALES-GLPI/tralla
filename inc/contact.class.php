<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/helpers.php';

/**
 * Contatos de WhatsApp e e-mail das entidades do GLPI, usados pelo Tralla e pelo Assignat-ure.
 */
class PluginTrallaContact
{
    public const TABLE = 'glpi_plugin_tralla_entity_contacts';

    /** @return array<int, array<string, mixed>> Contatos com o nome da entidade, em ordem de entidade. */
    public static function all(): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT'     => ['c.*', 'e.name AS entity_name'],
            'FROM'       => self::TABLE . ' AS c',
            'INNER JOIN' => ['glpi_entities AS e' => ['ON' => ['c' => 'entities_id', 'e' => 'id']]],
            'ORDER'      => ['e.name ASC', 'c.type ASC', 'c.value ASC'],
        ]) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function add(int $entityId, string $type, string $value, string $name, int $userId): void
    {
        global $DB;

        $entity = new Entity();
        if ($entityId < 0 || !$entity->getFromDB($entityId)) {
            throw new RuntimeException('Selecione uma escola válida.');
        }
        if (!isset(tralla_contact_types()[$type])) {
            throw new RuntimeException('Escolha WhatsApp ou E-mail.');
        }
        $normalized = tralla_normalize_contact($type, $value);
        if ($normalized === '') {
            throw new RuntimeException($type === 'email'
                ? 'Informe um e-mail válido.'
                : 'Informe o WhatsApp com DDD, por exemplo (17) 99123-4567.');
        }
        if (countElementsInTable(self::TABLE, ['entities_id' => $entityId, 'type' => $type, 'value' => $normalized]) > 0) {
            throw new RuntimeException('Este contato já está cadastrado nesta escola.');
        }
        $DB->insert(self::TABLE, [
            'entities_id'   => $entityId,
            'type'          => $type,
            'value'         => $normalized,
            'name'          => mb_substr(trim($name), 0, 255),
            'users_id'      => $userId,
            'date_creation' => tralla_now(),
        ]);
    }

    public static function remove(int $id): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['id' => $id]);
    }

    /** @return array<int, array{email: string, name: string}> */
    public static function emailsForEntity(int $entityId): array
    {
        global $DB;

        $emails = [];
        foreach ($DB->request([
            'SELECT' => ['value', 'name'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['entities_id' => $entityId, 'type' => 'email'],
            'ORDER'  => 'value ASC',
        ]) as $row) {
            $emails[] = ['email' => (string) $row['value'], 'name' => (string) $row['name']];
        }

        return $emails;
    }
}
