<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/** Espelha o histórico dos cards de manutenção em chamados nativos do GLPI. */
class PluginTrallaTicketSync
{
    private static bool $shutdownRegistered = false;

    public static function schedule(): void
    {
        if (self::$shutdownRegistered || !PluginTrallaConfig::getBool('ticket_sync_enabled', true)) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            try {
                self::syncPending(100);
            } catch (Throwable $e) {
                error_log('[Tralla] Falha na sincronização de chamados: ' . $e->getMessage());
            }
        });
    }

    public static function syncPending(int $limit = 100): int
    {
        global $DB;
        if (!PluginTrallaConfig::getBool('ticket_sync_enabled', true)
            || !$DB->fieldExists('glpi_plugin_tralla_history', 'ticket_followups_id')
            || !$DB->fieldExists('glpi_plugin_tralla_cards', 'tickets_id')) {
            return 0;
        }
        $start = PluginTrallaConfig::getInt('ticket_sync_start_history_id', 0);
        $rows = $DB->request([
            'SELECT' => ['h.id AS id'],
            'FROM' => 'glpi_plugin_tralla_history AS h',
            'INNER JOIN' => [
                'glpi_plugin_tralla_cards AS c' => ['ON' => ['h' => 'plugin_tralla_cards_id', 'c' => 'id']],
                'glpi_plugin_tralla_boards AS b' => ['ON' => ['c' => 'plugin_tralla_boards_id', 'b' => 'id']],
            ],
            'WHERE' => ['h.id' => ['>', $start], 'h.ticket_followups_id' => 0, 'b.type' => 'maintenance'],
            'ORDER' => 'h.id ASC',
            'LIMIT' => max(1, min(500, $limit)),
        ]);
        $synced = 0;
        foreach ($rows as $row) {
            try {
                if (self::syncEvent((int) $row['id'])) {
                    $synced++;
                }
            } catch (Throwable $e) {
                error_log('[Tralla] Evento #' . (int) $row['id'] . ': ' . $e->getMessage());
            }
        }
        return $synced;
    }

    /** Reaplica status, prioridade e SLAs aos chamados já vinculados. */
    public static function reconcileLinked(): int
    {
        global $DB;
        $updated = 0;
        foreach ($DB->request([
            'SELECT' => ['c.id AS id'],
            'FROM' => 'glpi_plugin_tralla_cards AS c',
            'INNER JOIN' => [
                'glpi_plugin_tralla_boards AS b' => ['ON' => ['c' => 'plugin_tralla_boards_id', 'b' => 'id']],
            ],
            'WHERE' => ['c.stage' => 1, 'c.tickets_id' => ['>', 0], 'b.type' => 'maintenance', 'c.is_deleted' => 0],
        ]) as $row) {
            try {
                $card = PluginTrallaCard::getCard((int) $row['id']);
                if ($card === null) { continue; }
                $ticket = new Ticket();
                $ticketId = (int) $card['tickets_id'];
                if (!$ticket->getFromDB($ticketId)) { continue; }
                if (self::updateTicket($ticket, $card, $ticketId, self::entityFor($card))) {
                    $updated++;
                }
            } catch (Throwable $e) {
                error_log('[Tralla] Falha ao reconciliar chamado do card #' . (int) $row['id'] . ': ' . $e->getMessage());
            }
        }
        return $updated;
    }

    private static function syncEvent(int $historyId): bool
    {
        global $DB, $CFG_GLPI;
        $event = $DB->request([
            'FROM' => 'glpi_plugin_tralla_history',
            'WHERE' => ['id' => $historyId], 'LIMIT' => 1,
        ])->current();
        if (!is_array($event) || (int) $event['ticket_followups_id'] !== 0) {
            return false;
        }
        $card = PluginTrallaCard::getCard((int) $event['plugin_tralla_cards_id']);
        if ($card === null) {
            return false;
        }
        $boardType = PluginTrallaCard::getBoardType((int) $card['plugin_tralla_boards_id']);
        if ($boardType !== 'maintenance') {
            // Quadros normais e de Atualizações não geram chamados.
            return false;
        }
        $primary = (int) $card['stage'] === 2
            ? PluginTrallaCard::getCard((int) $card['parent_cards_id']) : $card;
        if ($primary === null) {
            return false;
        }
        $ticketId = (int) ($primary['tickets_id'] ?? 0);
        if ((string) $primary['status'] === 'deleted' && $ticketId <= 0) {
            $DB->update('glpi_plugin_tralla_history', ['ticket_followups_id' => 2147483647], ['id' => $historyId]);
            return true;
        }
        $entityId = self::entityFor($primary);
        $ticket = new Ticket();
        if ($ticketId > 0 && !$ticket->getFromDB($ticketId)) {
            throw new RuntimeException('O chamado GLPI vinculado #' . $ticketId . ' não existe mais.');
        }
        if ($ticketId > 0 && (int) ($ticket->fields['is_deleted'] ?? 0) === 1) {
            if ((string) $primary['status'] === 'deleted') {
                $DB->update('glpi_plugin_tralla_history', ['ticket_followups_id' => 2147483647], ['id' => $historyId]);
                return true;
            }
            if (!$ticket->restore(['id' => $ticketId])) {
                throw new RuntimeException('Não foi possível restaurar o chamado #' . $ticketId . '.');
            }
            $ticket->getFromDB($ticketId);
        }
        if ($ticketId <= 0) {
            $code = tralla_display_code($primary, 'maintenance');
            $description = trim((string) ($primary['description'] ?? ''));
            $category = trim((string) ($primary['call_category'] ?? ''));
            $subcategory = trim((string) ($primary['call_subcategory'] ?? ''));
            $lines = [
                'Card Tralla: ' . $code,
                'Quadro: ' . self::boardName((int) $primary['plugin_tralla_boards_id']),
                'Tipo: ' . ((string) ($primary['workflow_type'] ?? '') === 'call' ? 'Chamado' : 'Manutenção'),
            ];
            if ($category !== '') { $lines[] = 'Categoria: ' . $category; }
            if ($subcategory !== '') { $lines[] = 'Subcategoria: ' . $subcategory; }
            if ($description !== '') { $lines[] = 'Descrição: ' . $description; }
            $lines[] = 'Abrir card: ' . rtrim((string) ($CFG_GLPI['url_base'] ?? $CFG_GLPI['root_doc'] ?? ''), '/')
                . '/plugins/tralla/front/kanban.php?boards_id=' . (int) $primary['plugin_tralla_boards_id']
                . '&card_id=' . (int) $primary['id'];
            $ticketId = (int) $ticket->add([
                'name' => '[Tralla ' . $code . '] ' . (string) $primary['name'],
                'content' => implode("\n", $lines),
                'entities_id' => $entityId,
                'status' => self::ticketStatus($primary),
                'type' => (string) ($primary['workflow_type'] ?? '') === 'call'
                    ? Ticket::INCIDENT_TYPE : Ticket::DEMAND_TYPE,
                'users_id_recipient' => (int) ($primary['users_id'] ?? 0),
            ]);
            if ($ticketId <= 0) {
                throw new RuntimeException('Não foi possível criar o chamado GLPI do card #' . (int) $primary['id'] . '.');
            }
            $DB->update('glpi_plugin_tralla_cards', ['tickets_id' => $ticketId], ['id' => (int) $primary['id']]);
        }

        self::linkUser($ticketId, (int) ($primary['users_id'] ?? 0), CommonITILActor::REQUESTER);
        self::linkUser($ticketId, (int) ($primary['technician_id'] ?? 0), CommonITILActor::ASSIGN);

        $code = tralla_display_code($card, 'maintenance');
        $label = PluginTrallaHistory::getActionLabel((string) $event['action']);
        $details = trim((string) ($event['details'] ?? ''));
        $text = '[Tralla ' . $code . '] ' . $label . ($details !== '' ? "\n" . $details : '');
        $snapshot = self::eventSnapshot((string) $event['action'], $primary);
        if ($snapshot !== '') {
            $text .= "\n\n" . $snapshot;
        }
        $followupId = self::existingFollowup($ticketId, $text, $historyId);
        if ($followupId <= 0) {
            $followup = new ITILFollowup();
            $followupId = (int) $followup->add([
                'itemtype' => 'Ticket',
                'items_id' => $ticketId,
                'content' => $text . "\n[Tralla evento #" . $historyId . ']',
                'users_id' => (int) ($event['users_id'] ?? 0),
                'is_private' => 0,
            ]);
        }
        if ($followupId <= 0) {
            throw new RuntimeException('Não foi possível registrar acompanhamento no chamado #' . $ticketId . '.');
        }
        $ticket->getFromDB($ticketId);
        self::updateTicket($ticket, $primary, $ticketId, $entityId);
        if ((string) $primary['status'] === 'deleted' && !$ticket->delete(['id' => $ticketId])) {
            throw new RuntimeException('Não foi possível enviar o chamado #' . $ticketId . ' para a lixeira.');
        }
        $DB->update('glpi_plugin_tralla_history', ['ticket_followups_id' => $followupId], ['id' => $historyId]);
        return true;
    }

    private static function updateTicket(Ticket $ticket, array $primary, int $ticketId, int $entityId): bool
    {
        $changes = [];
        $expectedName = '[Tralla ' . tralla_display_code($primary, 'maintenance') . '] ' . (string) $primary['name'];
        if ((string) $ticket->fields['name'] !== $expectedName) {
            $changes['name'] = $expectedName;
        }
        $status = self::ticketStatus($primary);
        if ((int) $ticket->fields['status'] !== $status) {
            $changes['status'] = $status;
        }
        $priority = (string) ($primary['priority'] ?? '');
        if (preg_match('/^P([1-5])$/', $priority, $matches)) {
            $slaStart = trim((string) ($primary['date_sla_start'] ?? ''));
            if ($slaStart !== '' && strtotime($slaStart) !== false
                && (string) ($ticket->fields['date'] ?? '') !== $slaStart) {
                $changes['date'] = $slaStart;
            }
            $urgency = 6 - (int) $matches[1];
            if ((int) $ticket->fields['urgency'] !== $urgency) {
                $changes['urgency'] = $urgency;
            }
            foreach (['tto' => SLM::TTO, 'ttr' => SLM::TTR] as $kind => $type) {
                $slaId = PluginTrallaConfig::getInt('ticket_sla_' . $kind . '_' . $priority, 0);
                if ($slaId > 0 && self::validSla($slaId, $type, $entityId)) {
                    if ((int) ($ticket->fields['slas_id_' . $kind] ?? 0) !== $slaId) {
                        $changes['slas_id_' . $kind] = $slaId;
                    }
                    if ($slaStart !== '' && strtotime($slaStart) !== false) {
                        $sla = new SLA();
                        $sla->getFromDB($slaId);
                        $sla->setTicketCalendar(Entity::getUsedConfig(
                            'calendars_strategy', $entityId, 'calendars_id', 0
                        ));
                        $due = $sla->computeDate($slaStart, 0);
                        $field = $kind === 'tto' ? 'time_to_own' : 'time_to_resolve';
                        if ($due !== null && (string) ($ticket->fields[$field] ?? '') !== $due) {
                            $changes[$field] = $due;
                        }
                    }
                }
            }
            if ((int) ($ticket->fields['sla_waiting_duration'] ?? 0) !== 0) {
                $changes['sla_waiting_duration'] = 0;
            }
            $takenAt = trim((string) ($primary['date_taken'] ?? ''));
            if ($slaStart !== '' && strtotime($slaStart) !== false
                && $takenAt !== '' && strtotime($takenAt) !== false) {
                if ((string) ($ticket->fields['takeintoaccountdate'] ?? '') !== $takenAt) {
                    $changes['takeintoaccountdate'] = $takenAt;
                }
                $elapsed = max(1, strtotime($takenAt) - strtotime($slaStart));
                $ttoId = PluginTrallaConfig::getInt('ticket_sla_tto_' . $priority, 0);
                $tto = new SLA();
                if ($ttoId > 0 && $tto->getFromDB($ttoId) && (int) $tto->fields['calendars_id'] > 0) {
                    $calendar = new Calendar();
                    if ($calendar->getFromDB((int) $tto->fields['calendars_id'])) {
                        $elapsed = max(1, $calendar->getActiveTimeBetween($slaStart, $takenAt));
                    }
                }
                if ((int) ($ticket->fields['takeintoaccount_delay_stat'] ?? 0) !== $elapsed) {
                    $changes['takeintoaccount_delay_stat'] = $elapsed;
                }
            }
            if (in_array((string) ($primary['status'] ?? ''), ['approved', 'withdrawal', 'done'], true)) {
                $solvedAt = trim((string) ($primary['date_approved'] ?? ''));
                if ($solvedAt !== '' && strtotime($solvedAt) !== false
                    && (string) ($ticket->fields['solvedate'] ?? '') !== $solvedAt) {
                    $changes['solvedate'] = $solvedAt;
                }
            }
        }
        if ($changes !== [] && (string) $primary['status'] !== 'deleted') {
            if (array_intersect(['slas_id_tto', 'slas_id_ttr', 'time_to_own', 'time_to_resolve'], array_keys($changes))
                && !Session::haveRight(SLM::$rightname, SLM::RIGHT_ASSIGN)) {
                throw new RuntimeException('Sem permissão para atribuir SLA ao chamado #' . $ticketId . '.');
            }
            $changes['id'] = $ticketId;
            if (!$ticket->update($changes)) {
                throw new RuntimeException('Não foi possível atualizar status/SLA do chamado #' . $ticketId . '.');
            }
            return true;
        }
        return false;
    }

    private static function entityFor(array $card): int
    {
        global $DB;
        $entityId = (int) ($card['entities_id'] ?? 0);
        if ($entityId > 0) { return $entityId; }
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities',
            'WHERE' => ['name' => (string) $card['name']], 'LIMIT' => 1])->current();
        if (is_array($row)) { return (int) $row['id']; }
        $board = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_plugin_tralla_boards',
            'WHERE' => ['id' => (int) $card['plugin_tralla_boards_id']], 'LIMIT' => 1])->current();
        return (int) ($board['entities_id'] ?? 0);
    }

    private static function boardName(int $boardId): string
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_plugin_tralla_boards',
            'WHERE' => ['id' => $boardId], 'LIMIT' => 1])->current();
        return (string) ($row['name'] ?? '');
    }

    private static function eventSnapshot(string $action, array $card): string
    {
        global $DB;
        if (!in_array($action, ['card_created', 'card_finalized', 'machine_added', 'machine_deleted',
            'machine_updated', 'edit_approved', 'tablet_edit', 'approval_approved', 'call_report_updated',
            'priority_set', 'call_released', 'withdrawal_confirmed'], true)) {
            return '';
        }
        $lines = ['Estado do card: ' . tralla_status_label((string) $card['status'])];
        if ((string) ($card['priority'] ?? '') !== '') {
            $lines[] = 'Prioridade Tralla: ' . (string) $card['priority'];
        }
        $report = trim((string) ($card['service_report'] ?? ''));
        if ($report !== '') {
            $lines[] = 'Relatório do atendimento: ' . $report;
        }
        $machines = iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_tralla_machines',
            'WHERE' => ['plugin_tralla_cards_id' => (int) $card['id']],
            'ORDER' => 'rank ASC, id ASC',
        ]));
        if ($machines !== []) {
            $modelIds = array_values(array_unique(array_map(
                static fn(array $machine): int => (int) ($machine['plugin_tralla_machine_models_id'] ?? 0), $machines
            )));
            $models = PluginTrallaMachineModel::getModelNames($modelIds);
            foreach ($machines as $index => $machine) {
                $model = (string) ($models[(int) ($machine['plugin_tralla_machine_models_id'] ?? 0)] ?? 'Máquina');
                $lines[] = ($index + 1) . '. ' . $model . ' — ' . tralla_machine_status_label((string) ($machine['status'] ?? ''));
                $machineReport = trim((string) ($machine['report'] ?? ''));
                if ($machineReport !== '') { $lines[] = '   Relatório: ' . $machineReport; }
                if (!empty($machine['service_done'])) { $lines[] = '   Serviço realizado: sim'; }
                if (!empty($machine['needs_inventory'])) { $lines[] = '   Necessita inventariar: sim'; }
            }
        }
        return mb_substr(implode("\n", $lines), 0, 20000);
    }

    private static function ticketStatus(array $card): int
    {
        $status = (string) ($card['status'] ?? 'pending');
        if ($status === 'deletion_pending') {
            $status = (string) (($card['deletion_previous_status'] ?? '') ?: 'in_progress');
        }
        return match ($status) {
            'in_progress' => Ticket::ASSIGNED,
            'waiting_approval' => Ticket::ASSIGNED,
            'approved', 'withdrawal', 'done' => Ticket::SOLVED,
            'deleted' => Ticket::CLOSED,
            default => Ticket::INCOMING,
        };
    }

    private static function linkUser(int $ticketId, int $userId, int $type): void
    {
        global $DB;
        if ($userId <= 0) { return; }
        $existing = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticketId, 'users_id' => $userId, 'type' => $type],
            'LIMIT' => 1])->current();
        if (is_array($existing)) { return; }
        $actor = new Ticket_User();
        if (!$actor->add(['tickets_id' => $ticketId, 'users_id' => $userId, 'type' => $type])) {
            error_log('[Tralla] Não foi possível vincular usuário #' . $userId . ' ao chamado #' . $ticketId . '.');
        }
    }

    private static function existingFollowup(int $ticketId, string $text, int $historyId): int
    {
        global $DB;
        $marked = $text . "\n[Tralla evento #" . $historyId . ']';
        foreach ($DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_itilfollowups',
            'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $ticketId,
                'content' => [$marked, $text]],
            'ORDER' => 'id ASC',
        ]) as $row) {
            $claimed = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_tralla_history',
                'WHERE' => ['ticket_followups_id' => (int) $row['id']], 'LIMIT' => 1])->current();
            if (!is_array($claimed)) { return (int) $row['id']; }
        }
        return 0;
    }

    private static function validSla(int $slaId, int $type, int $entityId): bool
    {
        $sla = new SLA();
        if (!$sla->getFromDB($slaId) || (int) $sla->fields['type'] !== $type) {
            return false;
        }
        $slaEntity = (int) $sla->fields['entities_id'];
        return $slaEntity === $entityId || ($slaEntity === 0 && (int) $sla->fields['is_recursive'] === 1);
    }
}
