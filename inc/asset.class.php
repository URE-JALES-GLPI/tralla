<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Integração com os ativos do GLPI (plugin Inventário de Ativos / assetmgrstatus).
 * Cada máquina de um card de manutenção pode ser vinculada a um ativo (de qualquer entidade) para
 * que o histórico de manutenção do ativo seja alimentado pelo Tralla.
 */
class PluginTrallaAsset
{
    private const LEGACY_SUFFIX = ['Desktop', 'Notebook', 'Celular', 'Tablet'];

    /** Os "Computadores" nativos do GLPI (glpi_computers) entram junto com a categoria Desktop. */
    public const CORE_COMPUTER = 'Computer';

    /** O GLPI tem ativos personalizados (glpi_assets_assets) neste ambiente? */
    public static function isAvailable(): bool
    {
        global $DB;
        return $DB->tableExists('glpi_assets_assets') && $DB->tableExists('glpi_assets_assetdefinitions');
    }

    /** O vínculo precisa da coluna asset_itemtype (criada em "Atualizar" do plugin). */
    public static function canLink(): bool
    {
        global $DB;
        return self::isAvailable() && $DB->fieldExists('glpi_plugin_tralla_machines', 'asset_itemtype');
    }

    /** system_name => rótulo, só dos tipos que existem no GLPI. */
    public static function types(): array
    {
        global $DB;
        if (!self::isAvailable()) {
            return [];
        }
        $wanted = [
            'Notebook' => 'Notebook', 'Desktop' => 'Desktop', 'Celular' => 'Celular', 'Tablet' => 'Tablet',
            'Switch' => 'Switch', 'Televisao' => 'Televisão', 'Firewall' => 'Firewall', 'RackdeRede' => 'Rack de Rede',
            'PlataformadeRecarga' => 'Plataforma de Recarga', 'AccessPoint' => 'Access Point',
        ];
        $class = '\\GlpiPlugin\\Assetmgrstatus\\MaintenanceRecord';
        if (class_exists($class) && method_exists($class, 'getAssetTypes')) {
            $wanted = [];
            foreach ($class::getAssetTypes() as $system => $def) {
                $wanted[$system] = (string) ($def['label'] ?? $system);
            }
        }
        $existing = [];
        foreach ($DB->request(['SELECT' => ['system_name'], 'FROM' => 'glpi_assets_assetdefinitions']) as $row) {
            $existing[(string) $row['system_name']] = true;
        }
        return array_intersect_key($wanted, $existing);
    }

    public static function itemtypeFor(string $system): string
    {
        if ($system === self::CORE_COMPUTER) {
            return 'Computer';
        }
        return 'Glpi\\CustomAsset\\' . $system . (in_array($system, self::LEGACY_SUFFIX, true) ? 'Asset' : '');
    }

    /** 'Glpi\CustomAsset\NotebookAsset' => 'Notebook' ('' se não for um ativo conhecido). */
    public static function systemFor(string $itemtype): string
    {
        global $DB;
        if ($itemtype === 'Computer') {
            return $DB->tableExists('glpi_computers') ? self::CORE_COMPUTER : '';
        }
        foreach (array_keys(self::types()) as $system) {
            if (self::itemtypeFor($system) === $itemtype) {
                return $system;
            }
        }
        return '';
    }

    private static function definitionId(string $system): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_assets_assetdefinitions', 'WHERE' => ['system_name' => $system], 'LIMIT' => 1,
        ])->current();
        return (int) ($row['id'] ?? 0);
    }

    /** Modelos em uso na categoria (id => nome), para o filtro "Modelo" da busca. */
    public static function models(string $system, int $entityId = 0): array
    {
        global $DB;
        if (!isset(self::types()[$system])) {
            return [];
        }
        $out = [];
        if ($system === 'Desktop' && $DB->tableExists('glpi_computers')) {
            // Modelos dos Computadores nativos: id negativo para não confundir com os dos ativos personalizados.
            foreach ($DB->request([
                'SELECT'     => ['glpi_computermodels.id', 'glpi_computermodels.name'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_computers',
                'INNER JOIN' => ['glpi_computermodels' => ['ON' => ['glpi_computers' => 'computermodels_id', 'glpi_computermodels' => 'id']]],
                'WHERE'      => ['glpi_computers.is_deleted' => 0, 'glpi_computers.is_template' => 0]
                    + ($entityId > 0 ? ['glpi_computers.entities_id' => $entityId] : []),
                'ORDER'      => 'glpi_computermodels.name ASC',
            ]) as $row) {
                $out[] = ['id' => -(int) $row['id'], 'name' => (string) $row['name']];
            }
        }
        $definitionId = self::definitionId($system);
        if ($definitionId <= 0) {
            return $out;
        }
        foreach ($DB->request([
            'SELECT'    => ['glpi_assets_assetmodels.id', 'glpi_assets_assetmodels.name'],
            'DISTINCT'  => true,
            'FROM'      => 'glpi_assets_assets',
            'INNER JOIN' => ['glpi_assets_assetmodels' => ['ON' => ['glpi_assets_assets' => 'assets_assetmodels_id', 'glpi_assets_assetmodels' => 'id']]],
            'WHERE'     => ['glpi_assets_assets.assets_assetdefinitions_id' => $definitionId, 'glpi_assets_assets.is_deleted' => 0]
                + ($entityId > 0 ? ['glpi_assets_assets.entities_id' => $entityId] : []),
            'ORDER'     => 'glpi_assets_assetmodels.name ASC',
        ]) as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        return $out;
    }

    /**
     * Busca em todas as entidades por nome, modelo, serial, patrimônio ou IMEI (campos personalizados).
     * Só lê dados de identificação; quem chama já checou o direito no Tralla.
     */
    public static function search(string $system, string $query, int $limit = 30, int $modelId = 0, int $entityId = 0): array
    {
        global $DB;
        $types = self::types();
        $query = trim($query);
        if ($system === '*') {
            // "Todos": junta o resultado de todas as categorias (só por texto, sem filtro de modelo).
            if (mb_strlen($query) < 2) {
                return [];
            }
            $all = [];
            foreach (array_keys($types) as $each) {
                $all = array_merge($all, self::search($each, $query, $limit, 0, $entityId));
            }
            usort($all, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            return array_slice($all, 0, 50);
        }
        if (!isset($types[$system]) || (mb_strlen($query) < 2 && $modelId === 0)) {
            return [];
        }
        $computers = [];
        if ($system === 'Desktop' && $modelId <= 0 && $DB->tableExists('glpi_computers')) {
            // Desktop = ativos personalizados + Computadores nativos (modelo negativo = só computadores).
            $computers = self::searchComputers($query, $limit, 0, $entityId);
        }
        if ($modelId < 0) {
            return self::searchComputers($query, $limit, -$modelId, $entityId);
        }
        $definitionId = self::definitionId($system);
        if ($definitionId <= 0) {
            return $computers;
        }
        $like = ['LIKE', '%' . $query . '%'];
        $or = [
            ['glpi_assets_assets.name' => $like],
            ['glpi_assets_assets.serial' => $like],
            ['glpi_assets_assets.otherserial' => $like],
        ];
        if ($DB->fieldExists('glpi_assets_assets', 'custom_fields')) {
            // IMEI e demais campos personalizados: só os VALORES (não os nomes dos campos nem o JSON cru).
            $expression = class_exists('\\Glpi\\DBAL\\QueryExpression') ? '\\Glpi\\DBAL\\QueryExpression' : '\\QueryExpression';
            $or[] = new $expression(
                "JSON_SEARCH(`glpi_assets_assets`.`custom_fields`, 'one', " . $DB->quote('%' . $query . '%') . ") IS NOT NULL"
            );
        }
        $select = ['glpi_assets_assets.id', 'glpi_assets_assets.name', 'glpi_assets_assets.serial',
            'glpi_assets_assets.otherserial', 'glpi_entities.completename AS entity_name',
            'glpi_assets_assetmodels.name AS model_name'];
        $hasCustom = $DB->fieldExists('glpi_assets_assets', 'custom_fields');
        if ($hasCustom) {
            $select[] = 'glpi_assets_assets.custom_fields';
        }
        $out = [];
        foreach ($DB->request([
            'SELECT'    => $select,
            'FROM'      => 'glpi_assets_assets',
            'LEFT JOIN' => [
                'glpi_entities'          => ['ON' => ['glpi_assets_assets' => 'entities_id', 'glpi_entities' => 'id']],
                'glpi_assets_assetmodels' => ['ON' => ['glpi_assets_assets' => 'assets_assetmodels_id', 'glpi_assets_assetmodels' => 'id']],
            ],
            'WHERE'     => [
                'glpi_assets_assets.assets_assetdefinitions_id' => $definitionId,
                'glpi_assets_assets.is_deleted'  => 0,
                'glpi_assets_assets.is_template' => 0,
            ] + ($modelId > 0 ? ['glpi_assets_assets.assets_assetmodels_id' => $modelId] : [])
              + ($entityId > 0 ? ['glpi_assets_assets.entities_id' => $entityId] : [])
              + ($query !== '' ? [['OR' => $or]] : []),
            'ORDER'     => 'glpi_assets_assets.name ASC',
            'LIMIT'     => max(1, min(50, $limit)),
        ]) as $row) {
            // Onde o termo foi achado (inclui campos que não aparecem no resumo, como IMEI).
            $matches = [];
            $needle = mb_strtolower($query);
            if ($needle !== '') {
                $custom = $hasCustom && !empty($row['custom_fields']) ? json_decode((string) $row['custom_fields'], true) : null;
                if (is_array($custom)) {
                    foreach ($custom as $key => $value) {
                        if (is_scalar($value) && mb_stripos((string) $value, $needle) !== false) {
                            $matches[] = ['label' => mb_strtoupper(str_replace('_', ' ', (string) $key)), 'value' => (string) $value];
                        }
                    }
                }
            }
            $out[] = [
                'matches'     => $matches,
                'id'          => (int) $row['id'],
                'itemtype'    => self::itemtypeFor($system),
                'name'        => (string) $row['name'],
                'serial'      => (string) $row['serial'],
                'otherserial' => (string) $row['otherserial'],
                'model'       => (string) ($row['model_name'] ?? ''),
                'entity'      => (string) ($row['entity_name'] ?? ''),
            ];
        }
        return array_slice(array_merge($out, $computers), 0, 50);
    }

    /** Busca nos Computadores nativos do GLPI por nome, serial ou patrimônio. */
    private static function searchComputers(string $query, int $limit, int $modelId, int $entityId): array
    {
        global $DB;
        $like = ['LIKE', '%' . $query . '%'];
        $out = [];
        foreach ($DB->request([
            'SELECT'    => ['glpi_computers.id', 'glpi_computers.name', 'glpi_computers.serial', 'glpi_computers.otherserial',
                'glpi_entities.completename AS entity_name', 'glpi_computermodels.name AS model_name'],
            'FROM'      => 'glpi_computers',
            'LEFT JOIN' => [
                'glpi_entities'       => ['ON' => ['glpi_computers' => 'entities_id', 'glpi_entities' => 'id']],
                'glpi_computermodels' => ['ON' => ['glpi_computers' => 'computermodels_id', 'glpi_computermodels' => 'id']],
            ],
            'WHERE'     => ['glpi_computers.is_deleted' => 0, 'glpi_computers.is_template' => 0]
                + ($modelId > 0 ? ['glpi_computers.computermodels_id' => $modelId] : [])
                + ($entityId > 0 ? ['glpi_computers.entities_id' => $entityId] : [])
                + ($query !== '' ? [['OR' => [
                    ['glpi_computers.name' => $like],
                    ['glpi_computers.serial' => $like],
                    ['glpi_computers.otherserial' => $like],
                ]]] : []),
            'ORDER'     => 'glpi_computers.name ASC',
            'LIMIT'     => max(1, min(50, $limit)),
        ]) as $row) {
            $out[] = [
                'matches'     => [],
                'id'          => (int) $row['id'],
                'itemtype'    => 'Computer',
                'name'        => (string) $row['name'],
                'serial'      => (string) $row['serial'],
                'otherserial' => (string) $row['otherserial'],
                'model'       => (string) ($row['model_name'] ?? ''),
                'entity'      => (string) ($row['entity_name'] ?? ''),
            ];
        }
        return $out;
    }

    /** Dados de exibição de um ativo vinculado (ou null se não existe mais). */
    public static function describe(string $itemtype, int $itemsId): ?array
    {
        global $DB, $CFG_GLPI;
        if ($itemsId <= 0 || $itemtype === '') {
            return null;
        }
        if ($itemtype === 'Computer') {
            $row = $DB->request([
                'SELECT'    => ['glpi_computers.id', 'glpi_computers.name', 'glpi_computers.serial', 'glpi_computers.otherserial',
                    'glpi_entities.completename AS entity_name', 'glpi_computermodels.name AS model_name'],
                'FROM'      => 'glpi_computers',
                'LEFT JOIN' => [
                    'glpi_entities'       => ['ON' => ['glpi_computers' => 'entities_id', 'glpi_entities' => 'id']],
                    'glpi_computermodels' => ['ON' => ['glpi_computers' => 'computermodels_id', 'glpi_computermodels' => 'id']],
                ],
                'WHERE'     => ['glpi_computers.id' => $itemsId],
                'LIMIT'     => 1,
            ])->current();
            if (!is_array($row)) {
                return null;
            }
            return [
                'id'          => $itemsId,
                'itemtype'    => 'Computer',
                'type_label'  => 'Computador (GLPI)',
                'name'        => (string) $row['name'],
                'serial'      => (string) $row['serial'],
                'otherserial' => (string) $row['otherserial'],
                'model'       => (string) ($row['model_name'] ?? ''),
                'entity'      => (string) ($row['entity_name'] ?? ''),
                'url'         => (string) $CFG_GLPI['root_doc'] . '/front/computer.form.php?id=' . $itemsId,
            ];
        }
        $row = $DB->request([
            'SELECT'    => ['glpi_assets_assets.id', 'glpi_assets_assets.name', 'glpi_assets_assets.serial',
                'glpi_assets_assets.otherserial', 'glpi_entities.completename AS entity_name',
                'glpi_assets_assetmodels.name AS model_name'],
            'FROM'      => 'glpi_assets_assets',
            'LEFT JOIN' => [
                'glpi_entities'           => ['ON' => ['glpi_assets_assets' => 'entities_id', 'glpi_entities' => 'id']],
                'glpi_assets_assetmodels' => ['ON' => ['glpi_assets_assets' => 'assets_assetmodels_id', 'glpi_assets_assetmodels' => 'id']],
            ],
            'WHERE'     => ['glpi_assets_assets.id' => $itemsId],
            'LIMIT'     => 1,
        ])->current();
        if (!is_array($row)) {
            return null;
        }
        $system = self::systemFor($itemtype);
        return [
            'id'          => $itemsId,
            'itemtype'    => $itemtype,
            'type_label'  => (self::types()[$system] ?? $system),
            'name'        => (string) $row['name'],
            'serial'      => (string) $row['serial'],
            'otherserial' => (string) $row['otherserial'],
            'model'       => (string) ($row['model_name'] ?? ''),
            'entity'      => (string) ($row['entity_name'] ?? ''),
            'url'         => (string) $CFG_GLPI['root_doc'] . '/front/asset/asset.form.php?class=' . rawurlencode($system) . '&id=' . $itemsId,
        ];
    }

    /**
     * Outros cards do Tralla em que este ativo já esteve vinculado (um por manutenção:
     * cards da mesma família, criados por separação, contam uma vez só).
     */
    public static function previousCards(string $itemtype, int $itemsId, int $machineId, int $currentCardId): array
    {
        global $DB;
        if ($itemsId <= 0 || $itemtype === '') {
            return [];
        }
        $current = PluginTrallaCard::getCard($currentCardId);
        $family = (int) ($current['code_root_cards_id'] ?? 0) ?: $currentCardId;
        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['c.id', 'c.name', 'c.date_creation', 'c.code_root_cards_id', 'c.plugin_tralla_boards_id'],
            'FROM'       => 'glpi_plugin_tralla_machines AS m',
            'INNER JOIN' => ['glpi_plugin_tralla_cards AS c' => ['ON' => ['m' => 'plugin_tralla_cards_id', 'c' => 'id']]],
            'WHERE'      => [
                'm.asset_itemtype' => $itemtype, 'm.inventory_items_id' => $itemsId,
                ['m.id' => ['<>', $machineId]], 'c.is_deleted' => 0,
            ],
            'ORDER'      => 'c.date_creation DESC',
        ]) as $row) {
            $root = (int) ($row['code_root_cards_id'] ?? 0) ?: (int) $row['id'];
            if ($root === $family || isset($out[$root])) {
                continue;
            }
            $card = PluginTrallaCard::getCard((int) $row['id']);
            $out[$root] = [
                'id'   => (int) $row['id'],
                'code' => $card !== null ? tralla_display_code($card, PluginTrallaCard::getBoardType((int) $row['plugin_tralla_boards_id'])) : '#' . (int) $row['id'],
                'name' => (string) $row['name'],
                'date' => date('d/m/Y', strtotime((string) $row['date_creation'])),
            ];
        }
        return array_values($out);
    }

    private static function cardLabel(int $cardId): string
    {
        $card = PluginTrallaCard::getCard($cardId);
        if ($card === null) {
            return 'card #' . $cardId;
        }
        return 'card ' . tralla_display_code($card, PluginTrallaCard::getBoardType((int) $card['plugin_tralla_boards_id']))
            . ' (' . (string) $card['name'] . ')';
    }

    /** Vincula (ou, com $itemsId = 0, desvincula) a máquina a um ativo e registra o histórico. */
    public static function link(int $machineId, string $itemtype, int $itemsId, int $userId): array
    {
        global $DB;
        if (!self::canLink()) {
            throw new RuntimeException('O vínculo com ativos ainda não foi habilitado: clique em Atualizar no plugin Tralla.');
        }
        $machine = PluginTrallaMachine::getMachine($machineId);
        if ($machine === null) {
            throw new RuntimeException('Máquina não encontrada.');
        }
        $cardId = (int) $machine['plugin_tralla_cards_id'];

        $oldType = (string) ($machine['asset_itemtype'] ?? '');
        $oldId = (int) ($machine['inventory_items_id'] ?? 0);

        if ($itemsId > 0) {
            if (self::systemFor($itemtype) === '') {
                throw new RuntimeException('Tipo de ativo inválido.');
            }
            $asset = self::describe($itemtype, $itemsId);
            if ($asset === null) {
                throw new RuntimeException('Ativo não encontrado.');
            }
            // Um ativo não fica em duas máquinas do mesmo card.
            $dup = $DB->request([
                'COUNT' => 'n', 'FROM' => 'glpi_plugin_tralla_machines',
                'WHERE' => ['plugin_tralla_cards_id' => $cardId, 'asset_itemtype' => $itemtype,
                    'inventory_items_id' => $itemsId, ['id' => ['<>', $machineId]]],
            ])->current();
            if ((int) ($dup['n'] ?? 0) > 0) {
                throw new RuntimeException('Esse ativo já está vinculado a outra máquina deste card.');
            }
        }

        $DB->update('glpi_plugin_tralla_machines', [
            'asset_itemtype'     => $itemsId > 0 ? $itemtype : '',
            'inventory_items_id' => max(0, $itemsId),
            'date_mod'           => tralla_now(),
        ], ['id' => $machineId]);

        $cardLabel = self::cardLabel($cardId);
        if ($oldId > 0 && $oldType !== '' && ($oldId !== $itemsId || $oldType !== $itemtype)) {
            self::record($oldType, $oldId, 'Desvinculado da máquina do ' . $cardLabel . ' no Tralla.', false, $cardId, true);
            $old = self::describe($oldType, $oldId);
            PluginTrallaHistory::log($cardId, $userId, 'asset_unlinked',
                'Ativo "' . ($old['name'] ?? ('#' . $oldId)) . '" desvinculado de uma máquina.');
        }
        if ($itemsId > 0 && ($oldId !== $itemsId || $oldType !== $itemtype)) {
            self::announce($machineId, $cardId, $itemtype, $itemsId, $userId);
        }
        $DB->update('glpi_plugin_tralla_cards', ['date_mod' => tralla_now()], ['id' => $cardId]);

        return PluginTrallaMachine::getMachine($machineId) ?? [];
    }

    /** Registra o vínculo no histórico do card e do ativo (também usado quando o vínculo nasce na criação do card). */
    public static function announce(int $machineId, int $cardId, string $itemtype, int $itemsId, int $userId): void
    {
        $asset = self::describe($itemtype, $itemsId);
        self::record($itemtype, $itemsId, 'Vinculado a uma máquina do ' . self::cardLabel($cardId) . ' no Tralla.', false, $cardId);
        PluginTrallaHistory::log($cardId, $userId, 'asset_linked',
            'Máquina vinculada ao ativo "' . ($asset['name'] ?? ('#' . $itemsId)) . '"'
            . (($asset['otherserial'] ?? '') !== '' ? ' (patrimônio ' . $asset['otherserial'] . ')' : '') . '.');
    }

    /** Status da máquina no Tralla => nome do status do ativo no GLPI (Pendente e Em andamento não alteram). */
    private const STATE_NAMES = [
        'done'           => 'Concluído',
        'not_repairable' => 'Inservível',
        'warranty'       => 'Garantia',
        'damaged'        => 'Danificado',
    ];

    /** Id do status do GLPI com esse nome; cria se ainda não existir. */
    private static function stateId(string $name): int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_states']) as $row) {
            if (mb_strtolower(trim((string) $row['name'])) === mb_strtolower($name)) {
                return (int) $row['id'];
            }
        }
        $state = new State();
        $id = (int) $state->add(['name' => $name, 'entities_id' => 0, 'is_recursive' => 1]);
        if ($id > 0) {
            return $id;
        }
        $DB->insert('glpi_states', ['name' => $name, 'completename' => $name, 'entities_id' => 0, 'is_recursive' => 1,
            'states_id' => 0, 'level' => 1, 'date_creation' => tralla_now(), 'date_mod' => tralla_now()]);
        return (int) $DB->insertId();
    }

    /** Pelo resultado da máquina no card, atualiza o status do ativo vinculado no GLPI. */
    private static function applyMachineState(string $itemtype, int $itemsId, string $machineStatus): void
    {
        global $DB;
        $name = self::STATE_NAMES[$machineStatus] ?? '';
        if ($name === '' || $itemsId <= 0 || $itemtype === '') {
            return;
        }
        try {
            $stateId = self::stateId($name);
            if ($stateId <= 0) {
                return;
            }
            $item = function_exists('getItemForItemtype') ? getItemForItemtype($itemtype) : null;
            if ($item instanceof CommonDBTM && $item->getFromDB($itemsId)) {
                if ((int) ($item->fields['states_id'] ?? 0) === $stateId) {
                    return;
                }
                if ($item->update(['id' => $itemsId, 'states_id' => $stateId])) {
                    return;
                }
            }
            $table = $itemtype === 'Computer' ? 'glpi_computers' : 'glpi_assets_assets';
            $DB->update($table, ['states_id' => $stateId], ['id' => $itemsId]);
        } catch (Throwable $e) {
            error_log('[Tralla] status do ativo no GLPI: ' . $e->getMessage());
        }
    }

    /** Ao finalizar o card, cada máquina vinculada e pronta vira "Manutenção realizada" no histórico do ativo. */
    public static function logFinalization(int $cardId, array $machineIds): void
    {
        if (!self::canLink()) {
            return;
        }
        $statusLabels = tralla_machine_status_labels();
        foreach ($machineIds as $machineId) {
            try {
                $machine = PluginTrallaMachine::getMachine((int) $machineId);
                if ($machine === null || (int) ($machine['inventory_items_id'] ?? 0) <= 0 || (string) ($machine['asset_itemtype'] ?? '') === '') {
                    continue;
                }
                self::applyMachineState((string) $machine['asset_itemtype'], (int) $machine['inventory_items_id'], (string) $machine['status']);
                $report = trim((string) ($machine['report'] ?? ''));
                $text = 'Manutenção no ' . self::cardLabel($cardId) . ' — resultado: '
                    . ($statusLabels[(string) $machine['status']] ?? (string) $machine['status'])
                    . ($report !== '' ? '. Relatório técnico: ' . $report : '.');
                self::record((string) $machine['asset_itemtype'], (int) $machine['inventory_items_id'], $text, true, $cardId);
            } catch (Throwable $e) {
                error_log('[Tralla] histórico do ativo: ' . $e->getMessage());
            }
        }
    }

    /**
     * Escreve nos dois históricos: o do próprio GLPI (aba Histórico do ativo) e o
     * "Histórico Manutenção" do plugin Inventário de Ativos, quando instalado.
     */
    private static function record(string $itemtype, int $itemsId, string $text, bool $isMaintenance, int $cardId = 0, bool $unlink = false): void
    {
        try {
            if (class_exists('Log') && defined('Log::HISTORY_LOG_SIMPLE_MESSAGE')) {
                Log::history($itemsId, $itemtype, [0, '', '[Tralla] ' . $text], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
            }
        } catch (Throwable $e) {
            error_log('[Tralla] histórico GLPI do ativo: ' . $e->getMessage());
        }
        $class = '\\GlpiPlugin\\Assetmgrstatus\\MaintenanceRecord';
        try {
            if (class_exists($class)) {
                if ($isMaintenance && method_exists($class, 'logTrallaMaintenance')) {
                    $class::logTrallaMaintenance($itemtype, $itemsId, $text, $cardId);
                } elseif ($unlink && method_exists($class, 'logTrallaUnlink')) {
                    $class::logTrallaUnlink($itemtype, $itemsId, $text, $cardId);
                } elseif (!$isMaintenance && method_exists($class, 'logTrallaLink')) {
                    $class::logTrallaLink($itemtype, $itemsId, $text, $cardId);
                }
            }
        } catch (Throwable $e) {
            error_log('[Tralla] histórico do Inventário de Ativos: ' . $e->getMessage());
        }
    }
}
