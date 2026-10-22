# Tralla v2 — Design técnico

Data: 2026-10-22
Status: aprovado pelo usuário ("funcionando de ponta a ponta")
Fonte funcional: `Tralla v2 - Especificação Funcional.docx` (rascunho inicial)

## 1. Identidade

- Nome exibido: **[URE] Tralla**
- Chave/diretório do plugin: `tralla`
- Compatibilidade: GLPI **12.x**, PHP **8.2+**
- Licença: GPLv3+
- Vanilla PHP/CSS/JS, sem dependências externas.

## 2. Objetivo

Plugin de quadros Kanban integrado ao GLPI, com dois tipos de quadro:

1. **Normal** — listas e cards livres, equivalente ao Trello.
2. **Manutenção** — fluxo controlado de manutenção de equipamentos com categorias de lista,
   ciclo de vida, técnico responsável, relatório por máquina, aprovação e retirada.

O design visual e os tokens são derivados do Kanpro (`D:\Projetos\kanpro`), com prefixo
`tr-` para evitar colisão.

## 3. Decisões de escopo (itens em aberto da especificação)

| Item | Decisão desta versão |
|---|---|
| Reprovação do card `/2` | O card `/1` volta para **Em Andamento**, é desbloqueado e o `/2` é arquivado como `rejected`. Motivo opcional fica registrado. |
| Quem aprova | Perfil GLPI com direito **`plugin_tralla_approve`** ou membro do **grupo aprovador** configurado. Se nenhum grupo for configurado, vale o direito. |
| Retirada parcial | A retirada é do **lote inteiro** (um card = um termo). |
| Vínculo com inventário | A flag "Necessita inventariar" é registrada e destacada; **não cria** Computer/Asset automaticamente. Campo `inventory_items_id` fica reservado para vínculo futuro. |
| Notificação de pendente | Cron horário; card em Pendente há mais de `pending_hours` (padrão 1h) gera registro e e-mail opcional. Dispara uma vez por card. |
| Notificação de retirada | Cron horário; card em Retirada reenvia lembrete a cada `withdrawal_hours` (padrão 24h) até a confirmação. |
| Separar card | **Fora do escopo desta versão.** O fluxograma não descreve as regras; fica registrado em `docs/BACKLOG.md`. |
| CRM | **Fora do escopo desta versão.** Etapa não descrita; fica em `docs/BACKLOG.md`. |
| Origem do card | Campo `source` (`notebook_desktop`, `tablet_mobile`, `manual`). Nesta versão afeta apenas o rótulo/ícone do card; nenhuma regra automática. |
| assignat-ure | Integração por **endpoint de leitura autenticado por token** (`front/assignature.php`) + link configurável. O envio/push fica para a etapa de detalhamento. |

## 4. Modelo de dados

Todas as tabelas usam prefixo `glpi_plugin_tralla_`.

### boards
`id, name, type (normal|maintenance), color, background, comment, entities_id,
is_recursive, is_archived, users_id, date_creation, date_mod`

### lists
`id, plugin_tralla_boards_id, name, rank, category, color, is_archived, users_id,
date_creation, date_mod`

Categorias válidas:
`maintenance_pending`, `maintenance_in_progress`, `maintenance_approval`,
`maintenance_withdrawal`, `maintenance_done`; vazio = lista normal.

Em quadro de manutenção cada categoria é **única** (garante transição determinística).
Ao criar o quadro, as cinco listas são criadas automaticamente. Quadros normais recebem
"A Fazer", "Em Progresso" e "Concluído".

### cards
`id, plugin_tralla_boards_id, plugin_tralla_lists_id, name, description, rank, users_id,
technician_id, stage (1|2), parent_cards_id, approval_cards_id, status, is_locked,
is_archived, source, machines_total, date_taken, date_finalized, date_approved,
date_withdrawn, pending_notified, withdrawal_reminded_at, assignature_sent,
rejection_reason, users_id_approver, date_creation, date_mod`

Status: `normal`, `pending`, `in_progress`, `waiting_approval`, `withdrawal`, `done`,
`approved`, `rejected`.

### machine_models
`id, name, entities_id, is_recursive, comment, is_archived, users_id, date_creation, date_mod`

### machines
`id, plugin_tralla_cards_id, plugin_tralla_machine_models_id, label, serial, report,
status, service_done, needs_inventory, inventory_items_id, rank, report_saved_at,
date_creation, date_mod`

Status da máquina: vazio, `pending`, `in_progress`, `done`, `not_repairable`.

### history
`id, plugin_tralla_cards_id, users_id, action, details, date_creation`

### configs
`id, config_key (unique), config_value`

Chaves: `assignature_url`, `assignature_token`, `approval_group`, `notification_enabled`,
`notification_emails`, `pending_hours`, `withdrawal_hours`.

### notifications
`id, plugin_tralla_cards_id, type, channel, recipient, status, message, date_creation`

## 5. Ciclo de vida

```
Pendente --Pegar--> Em Andamento --Finalizar--> [Aprovação /2]
                                                    |
                                          Aprovar --+--> Retirada --Confirmar--> Concluído
                                                    |
                                          Reprovar -+--> Em Andamento
```

Regras:

- **Criar**: sempre na lista `maintenance_pending` (stage 1, status `pending`), com uma ou
  mais máquinas (modelo + identificação). Código exibido: `{id}/1`.
- **Pegar**: qualquer usuário com UPDATE; grava `technician_id`, move para
  `maintenance_in_progress`, status `in_progress`.
- **Edição de máquina**: somente o técnico vinculado (ou PURGE). Relatório com autosave
  (debounce no cliente, gravação incremental no servidor).
- **Finalizar**: exige ≥1 máquina e todas "preenchidas" = relatório não vazio **e** status
  `done`/`not_repairable`. Cria o card `/2` em `maintenance_approval`, bloqueia o `/1`
  (`is_locked=1`, status `waiting_approval`).
- **Aprovar**: move o `/1` para `maintenance_withdrawal`, desbloqueia, status `withdrawal`,
  arquiva o `/2` como `approved`, marca disponibilidade no assignat-ure.
- **Reprovar**: `/1` volta para `maintenance_in_progress`, desbloqueia, status `in_progress`;
  `/2` arquivado como `rejected`; motivo em `rejection_reason`.
- **Confirmar retirada**: `/1` vai para `maintenance_done`, status `done`, data de retirada.
- **Concluído** é somente leitura.

Cards de quadro **Normal** não usam status de manutenção, não têm máquinas e podem ser
arrastados livremente entre listas.

## 6. Identificadores

- Manutenção, card base: `{cards.id}/1`.
- Manutenção, aprovação: `{parent_cards_id}/2`.
- Normal: `#{cards.id}`.
- O número base é o ID auto-incremental do GLPI, único e sequencial. Não é reciclado.

## 7. Permissões

Direitos de perfil:

- `plugin_tralla`: READ / CREATE / UPDATE / DELETE / PURGE.
- `plugin_tralla_approve`: aprovar/reprovar.

Regras:

- Ver quadro/cards: READ + entidade acessível.
- Criar quadro/lista/card: CREATE (UPDATE para mover/editar).
- Pegar card e editar máquinas: UPDATE + vínculo de técnico (máquinas).
- Aprovar/reprovar: `plugin_tralla_approve` e, se houver grupo configurado, pertencer ao grupo.
- Confirmar retirada: técnico do card ou aprovador.
- Excluir: DELETE (card manutenção só antes de pegar ou por PURGE).
- Toda ação é revalidada no servidor; o front é apenas cosmético.

## 8. Notificações

Cron `PluginTrallaCron::cronNotify` (horário):

1. Cards `pending` com `date_creation` além de `pending_hours` e `pending_notified=0`:
   registra em `notifications`, marca `pending_notified=1`, envia e-mail se habilitado.
2. Cards `withdrawal` com `date_approved` além de `withdrawal_hours` desde o último
   lembrete: registra e reenvia até a confirmação.
3. E-mails usam `QueuedNotification` quando disponível; falha de e-mail nunca quebra o cron.

## 9. Integração assignat-ure

- `front/assignature.php?token=...&cards_id=...` devolve JSON somente leitura com card,
  máquinas, técnico, quadro e entidade.
- `front/assignature.php?token=...&list=withdrawal` lista os cards aguardando retirada.
- Link "Abrir no assignat-ure" é montado com `assignature_url` + `?card={id}`.
- Ao aprovar, o card é marcado `assignature_sent=1` e fica disponível imediatamente.
- Token compartilhado configurável em `front/config.form.php`; comparação com `hash_equals`.

## 10. Design (origem Kanpro)

Reaproveitado de `D:\Projetos\kanpro`:

- Paleta e tokens: azul `#0079bf`, fundo `#f4f5f7`, texto `#172b4d`, subtexto `#5e6c84`,
  borda `#dfe1e6`, raio 8/12px, sombras `0 1px 1px rgba(9,30,66,.25)`.
- Estrutura visual: quadro horizontal, colunas de 272px, cards brancos, badges, avatar,
  modal central, composer de card, estado vazio.
- Interações: drag & drop HTML5, modal, autosave, feedback por toast.
- Não há cópia de código do Kanpro: apenas tokens e padrões visuais, com prefixo `tr-`.
- Origem registrada em `docs/KANPRO-REUSE.md`.

## 11. Segurança

- Saída sempre escapada (`htmlspecialchars`/`escapeHtml`).
- SQL somente via query builder do GLPI; IDs convertidos para inteiro.
- CSRF tratado pelo GLPI 12; helper de compatibilidade para versões anteriores.
- Endpoint assignat-ure exige token ou sessão com READ.
- Sem upload de arquivo nesta versão.
- Transições de estado validadas no servidor, nunca apenas no cliente.

## 12. Não-objetivos / anti-objetivos

- Não duplicar o Kanpro nem compartilhar tabelas com ele.
- Não permitir burlar o fluxo por drag & drop em quadro de manutenção.
- Não gerar termo/retirada duplicado.
- Não apagar histórico ao arquivar.
- Não introduzir dependências JS/PHP externas.

## 13. Pendências registradas

- Separar card (split) — aguardando descrição funcional.
- CRM entre finalização e retirada — aguardando descrição.
- Push automático para assignat-ure — aguardando contrato.
- Vínculo automático de "necessita inventariar" com Computers/Assets.
- Aprovação por grupo: UI de configuração incluída; regras de escalonamento não descritas.
