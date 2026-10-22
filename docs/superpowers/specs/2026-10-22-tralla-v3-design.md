# Tralla v3 — Design técnico

Data: 2026-10-22
Status: aguardando revisão do usuário
Fonte: solicitação funcional do usuário em 2026-10-22

## 1. Objetivo

Evoluir o Tralla com cinco capacidades integradas:

1. **Membros e papéis por quadro**: `Membro`, `Admin` e `SuperAdmin`.
2. **Aprovação por usuários do GLPI**: substituir o grupo aprovador global por membros do quadro com o direito `plugin_tralla_approve`.
3. **Histórico do quadro**: consulta de todos os eventos dos cards, com filtro por card e datas.
4. **Exclusão com motivo, aprovação e restauração**: lixeira por quadro, com fluxo de aprovação nos quadros de manutenção e exclusão direta nos quadros normais.
5. **Pegar no card minimizado**: ação rápida para cards em `maintenance_pending`.

O fluxo de manutenção existente (máquinas, aprovação `/2`, retirada e conclusão) não muda, exceto pela nova regra de quem aprova e pela possibilidade de solicitar exclusão.

## 2. Decisões de escopo

| Item | Decisão |
|---|---|
| Visibilidade do quadro | Somente usuários cadastrados como membros. O criador entra automaticamente como `SuperAdmin`. |
| Exceção administrativa | Usuários com `plugin_tralla` `PURGE` global têm papel efetivo `SuperAdmin` e veem todos os quadros dentro das entidades às quais têm acesso. |
| Direitos globais | Continuam sendo limite superior. O papel do quadro restringe o que o usuário pode fazer, mas não concede além do direito global. |
| Papéis | Um usuário tem no máximo um papel por quadro: `member`, `admin` ou `superadmin`. |
| Aprovação normal | Qualquer membro do quadro com `plugin_tralla_approve` pode aprovar/reprovar. Não existe marcação separada de aprovador. |
| Grupo aprovador global | Deixa de ser usado. O campo `approval_group` permanece no banco, mas é ignorado; a seção sai da configuração global. |
| Exclusão em manutenção | Exige motivo e passa por aprovação na lista `maintenance_approval`. |
| Exclusão em quadro normal | Exige motivo, mas é soft delete direto; não passa por aprovação. |
| Aba Excluídos | Mostra somente cards com exclusão já aprovada/concluída. Solicitações pendentes ficam em Manutenção Aprovação. |
| Restauração | Volta o card para a lista, status e bloqueio anteriores, preservando técnico, máquinas e histórico. |
| Histórico | Visível para todos os membros do quadro; inclui cards excluídos e eventos de exclusão. |
| `Pegar` minimizado | Disponível quando o card está em `maintenance_pending` e o usuário tem permissão de `take`. |
| Exclusão definitiva | Fora do escopo. A lixeira é sempre reversível. A exclusão do quadro continua removendo os dados em cascata. |
| Exclusão de quadro | Exige papel efetivo `SuperAdmin` e direito global de exclusão. |
| Edição de quadro | Exige papel `Admin` ou `SuperAdmin` e direito global `UPDATE`. |
| Membros | Somente `SuperAdmin` adiciona, remove ou altera papéis. `Admin` pode visualizar a lista em modo somente leitura. |

## 3. Modelo de dados

Todas as tabelas usam o prefixo `glpi_plugin_tralla_`.

### 3.1 Nova tabela `glpi_plugin_tralla_boards_users`

| Campo | Tipo | Regra |
|---|---|---|
| `id` | INT unsigned auto increment | PK |
| `plugin_tralla_boards_id` | INT unsigned | obrigatório |
| `users_id` | INT unsigned | obrigatório |
| `role` | VARCHAR(20) | `member`, `admin`, `superadmin` |
| `date_creation` | DATETIME | preenchido na inclusão |
| `date_mod` | DATETIME | preenchido na alteração |

Índices:

- único `(plugin_tralla_boards_id, users_id)`;
- índice por `users_id`;
- índice por `role`.

### 3.2 Alterações em `glpi_plugin_tralla_cards`

Novos campos:

| Campo | Tipo | Uso |
|---|---|---|
| `is_deleted` | TINYINT(1) NOT NULL DEFAULT 0 | marca o card na lixeira |
| `approval_kind` | VARCHAR(20) NOT NULL DEFAULT 'maintenance' | `maintenance` ou `deletion` em cards `/2` |
| `deletion_reason` | TEXT NULL | motivo informado na solicitação |
| `deletion_requested_at` | DATETIME NULL | data da solicitação |
| `deletion_requested_by` | INT unsigned NOT NULL DEFAULT 0 | solicitante |
| `deletion_decided_at` | DATETIME NULL | data da exclusão ou recusa |
| `deletion_decided_by` | INT unsigned NOT NULL DEFAULT 0 | aprovador ou autor da exclusão direta |
| `deletion_previous_lists_id` | INT unsigned NOT NULL DEFAULT 0 | lista antes da exclusão |
| `deletion_previous_status` | VARCHAR(30) NOT NULL DEFAULT '' | status antes da exclusão |
| `deletion_previous_is_locked` | TINYINT(1) NOT NULL DEFAULT 0 | bloqueio antes da exclusão |

Novos valores de `status`:

- `deletion_pending`: card de manutenção aguardando decisão de exclusão;
- `deleted`: card na lixeira.

`is_archived` continua sendo o arquivamento dos cards `/2`; não é reutilizado para a lixeira.

### 3.3 `glpi_plugin_tralla_history`

O schema existente é mantido. Nenhum campo novo é necessário. As novas ações são:

- `card_deletion_requested`
- `card_deletion_approved`
- `card_deletion_rejected`
- `card_deletion_restored`

### 3.4 `glpi_plugin_tralla_configs`

- `approval_group` permanece na tabela para compatibilidade, mas não é mais consultado.
- A chave e a seção “Grupo aprovador” saem de `front/config.form.php`.
- `PluginTrallaConfig::approvalGroupId()` é removido do código.

## 4. Papéis e permissões

### 4.1 Hierarquia

```
member < admin < superadmin
```

`PURGE` global produz um papel efetivo `superadmin`, sem criar linha em `boards_users`.

### 4.2 Matriz de capacidades

| Capacidade | Membro | Admin | SuperAdmin | Direito global mínimo |
|---|---:|---:|---:|---|
| Ver quadro e histórico | sim | sim | sim | `READ` |
| Criar card | sim | sim | sim | `CREATE` |
| Editar card / mover / pegar | sim | sim | sim | `UPDATE` |
| Editar máquinas | conforme técnico/estado atual | conforme técnico/estado atual | conforme técnico/estado atual | `UPDATE` |
| Solicitar exclusão | sim | sim | sim | `DELETE` ou `PURGE` |
| Aprovar/reprovar | sim, se `plugin_tralla_approve` | sim, se `plugin_tralla_approve` | sim, se `plugin_tralla_approve` | `plugin_tralla_approve` |
| Ver Excluídos | não | sim | sim | `READ` |
| Restaurar excluído | não | sim | sim | `UPDATE` |
| Configurar quadro/listas | não | sim | sim | `UPDATE` |
| Gerenciar membros | não | não | sim | `UPDATE` |
| Arquivar quadro | não | não | sim | `UPDATE` |
| Excluir quadro | não | não | sim | `DELETE`/`PURGE` |

Regras adicionais:

- O criador do quadro é inserido como `superadmin`.
- O último `superadmin` não pode ser removido nem rebaixado.
- Um usuário sem linha em `boards_users` não vê o quadro, exceto `PURGE` global.
- O acesso à entidade do quadro continua obrigatório para todos, inclusive `PURGE` global.
- Aprovar/reprovar exige que o usuário seja membro do quadro (ou `PURGE` global) e tenha `plugin_tralla_approve`.
- O direito global de `DELETE` é necessário para solicitar exclusão; o papel `Admin`/`SuperAdmin` não substitui esse direito.
- O direito global `UPDATE` é necessário para configurar quadro, listas, membros e restaurar cards.

### 4.3 Regras de card

`PluginTrallaCard::permissionsFor()` passa a retornar, entre outras:

- `request_deletion`: substitui o antigo `delete`;
- `approve` / `reject`: papel de membro + `plugin_tralla_approve`;
- `take`, `finalize`, `edit_machines`, `withdraw`: mantêm as regras de estado/técnico atuais, com o papel de membro como condição adicional.

`request_deletion` é verdadeiro somente quando:

- o card é `stage = 1`;
- `is_deleted = 0`;
- `status != deletion_pending`;
- `is_locked = 0`;
- o usuário tem `PURGE` global, ou é membro do quadro e tem `DELETE` global.

Cards `/2` nunca podem ser excluídos manualmente; são arquivados pelo fluxo de decisão.

## 5. Fluxos de exclusão

### 5.1 Quadro normal

```
Card ativo --Excluir + motivo--> Card deleted (is_deleted=1) --Restaurar--> estado anterior
```

- O motivo é obrigatório, com 1 a 2000 caracteres após sanitização.
- O card recebe:
  - `is_deleted = 1`;
  - `status = deleted`;
  - `is_locked = 1`;
  - `deletion_reason`, `deletion_requested_at/by`;
  - `deletion_decided_at/by` iguais ao momento/usuário da exclusão;
  - `deletion_previous_*` com lista, status e bloqueio anteriores.
- O card sai do Kanban e passa a aparecer em **Excluídos**.
- Não há card `/2`.

### 5.2 Quadro de manutenção

```
Card /1 ativo --Excluir + motivo--> deletion_pending
                                      |
                                      +--> card /2 approval_kind=deletion em Manutenção Aprovação
                                      |
                     Aprovar ---------+--> /1 deleted (is_deleted=1)
                     Recusar ---------+--> /1 volta ao estado anterior
```

Na solicitação:

- o card `/1` recebe `status = deletion_pending`, `is_locked = 1`, `approval_cards_id` apontando para o `/2` e os campos `deletion_*`/`deletion_previous_*`;
- o card `/1` permanece na lista anterior, mas fica oculto do Kanban;
- é criado um card `/2` na lista `maintenance_approval`, com:
  - `stage = 2`;
  - `parent_cards_id = /1`;
  - `approval_kind = deletion`;
  - `status = waiting_approval`;
  - `name` e `description` copiados do card original;
  - `users_id` = solicitante;
  - `technician_id` = técnico do card original;
  - `machines_total` = total do card original;
  - `date_creation`/`date_mod` = agora.
- O histórico registra `card_deletion_requested` no `/1` e no `/2`.

Na aprovação:

- o `/1` recebe `status = deleted`, `is_deleted = 1`, `is_locked = 1`, `deletion_decided_at/by`;
- o `/2` recebe `status = approved`, `is_archived = 1`, `users_id_approver` e `date_mod`;
- o `/2` permanece vinculado ao `/1` por `parent_cards_id`;
- o histórico registra `card_deletion_approved` nos dois cards.

Na recusa:

- o `/1` volta para `deletion_previous_status`, `deletion_previous_lists_id` e `deletion_previous_is_locked`;
- os campos `deletion_*` e `deletion_previous_*` do `/1` são limpos;
- `approval_cards_id` do `/1` volta para `0`;
- o `/2` recebe `status = rejected`, `is_archived = 1`, `users_id_approver`, `rejection_reason` opcional e `date_mod`;
- o histórico registra `card_deletion_rejected` nos dois cards, incluindo o motivo da recusa quando informado.

### 5.3 Restauração

- Somente `Admin` ou `SuperAdmin` com `UPDATE` global.
- O card precisa ter `is_deleted = 1`.
- A lista anterior é usada se existir, estiver ativa e pertencer ao quadro.
- Fallback:
  - quadro de manutenção: `maintenance_pending`;
  - quadro normal: primeira lista ativa do quadro.
- Se não houver lista válida, a operação falha sem alterar o card.
- Ao restaurar:
  - `is_deleted = 0`;
  - `status`, `plugin_tralla_lists_id` e `is_locked` voltam ao estado anterior (ou fallback);
  - `approval_cards_id = 0`;
  - os campos `deletion_*` e `deletion_previous_*` são limpos;
  - o histórico registra `card_deletion_restored`.
- O card restaurado volta a aparecer no Kanban e desaparece de **Excluídos**.

## 6. Histórico do quadro

### 6.1 Consulta

`PluginTrallaHistory::getForBoard(int $boardId, array $filters): array`

Filtros:

- `card_id` (string, opcional):
  - `123` ou `#123`: casa o card `/1` e todos os cards `/2` vinculados;
  - `123/1`: somente o card original;
  - `123/2`: todos os cards `/2` vinculados ao card `123`;
  - valor inválido retorna erro de validação.
- `date_from` e `date_to` (`Y-m-d`, opcionais):
  - inclusivos;
  - `date_to` é convertido para `23:59:59`;
  - `date_from > date_to` retorna erro.
- `page` (inteiro, mínimo 1; padrão 1).

Regras:

- 50 registros por página;
- ordenação `date_creation DESC, id DESC`;
- join com `glpi_plugin_tralla_cards` para garantir que o evento pertence ao quadro;
- join com `glpi_users` para o nome do usuário;
- inclui cards com `is_deleted = 1`;
- retorna `rows`, `total`, `page`, `pages`;
- cada linha contém `card_id`, `card_code`, `card_name`, `action`, `action_label`, `details`, `date_creation`, `date_formatted`, `user_name`, `is_deleted`.

### 6.2 Acesso

- Todos os membros do quadro com `plugin_tralla` `READ`.
- O histórico do card no modal de detalhe continua usando `getForCard()`.

## 7. Interface

### 7.1 Cabeçalho do quadro

- **Histórico**: botão visível para todos os membros; abre modal.
- **Excluídos**: botão ao lado do Histórico, visível para `Admin`/`SuperAdmin`; abre modal.
- **Editar quadro**: visível para `Admin`/`SuperAdmin`.
- **Lista**: visível para `Admin`/`SuperAdmin`.
- **Adicionar card**: visível para `Membro` ou superior com `CREATE`.
- **Pegar** no card minimizado: visível quando `permissions.take`.

### 7.2 Configurações do quadro

A página `front/board.form.php` passa a ter abas:

- **Geral**: campos atuais de nome, tema, cor, descrição e arquivamento.
- **Membros**: lista de membros com nome, login, papel e data de inclusão.

Comportamento:

- `Admin`/`SuperAdmin` acessam a página;
- somente `SuperAdmin` edita membros;
- `Admin` vê a aba Membros em modo somente leitura;
- adicionar usuário: `User::dropdown` + seletor de papel + botão Adicionar;
- alterar papel: seletor inline com botão Salvar;
- remover: botão com confirmação;
- tentativa de remover/rebaixar o último `SuperAdmin` é bloqueada no servidor e explicada na UI;
- o criador aparece como `SuperAdmin`;
- não aninhar formulários: a aba Membros usa formulários separados (ou o atributo HTML `form`) para adicionar, alterar e remover, sem envolver o formulário Geral.

### 7.3 Exclusão

- O diálogo de motivo usa `promptDialog` com `multiline`, `maxlength=2000` e validação obrigatória.
- Em manutenção, o texto avisa que a exclusão será enviada para aprovação.
- Em quadro normal, o texto avisa que o card irá para **Excluídos** e poderá ser restaurado.
- O backend rejeita motivo vazio, mesmo que o cliente seja contornado.
- O card `/2` de exclusão mostra:
  - selo **Solicitação de exclusão**;
  - motivo informado;
  - link **Ver origem**;
  - botões **Aprovar exclusão** e **Recusar exclusão**.
- Aprovar exclusão fecha o modal, atualiza o quadro e mostra toast.
- Recusar exclusão reabre o card original no estado restaurado.

### 7.4 Excluídos

- Modal com tabela: código, nome, motivo, solicitado em, excluído em, solicitante, aprovador, lista anterior e botão **Restaurar**.
- Restaurar pede confirmação.
- Após restaurar, a linha sai da lista e o estado do quadro é atualizado.
- Estado vazio: “Nenhum card excluído neste quadro.”

### 7.5 Histórico

- Modal largo com filtros de ID, data inicial e data final.
- Botões **Filtrar** e **Limpar**.
- Tabela com data/hora, card, ação, usuário e detalhes.
- Paginação com página anterior/próxima.
- O filtro de ID aceita `123`, `#123`, `123/1` e `123/2`.

### 7.6 Pegar minimizado

- O card renderizado no Kanban mostra um botão compacto **Pegar** quando `permissions.take`.
- O clique usa `stopPropagation` e não abre o modal.
- A ação chama `take_card`, aplica o novo estado e mostra “Card atribuído a você.”
- O botão desaparece quando o card deixa de estar em `maintenance_pending`.

## 8. Backend e API

### 8.1 Classes

**`PluginTrallaBoardUser`** (`inc/boarduser.class.php`):

- `getMembers(int $boardId): array`
- `getRole(int $boardId, int $userId): string`
- `setRole(int $boardId, int $userId, string $role): void`
- `remove(int $boardId, int $userId): void`
- `countSuperadmins(int $boardId): int`
- `addCreator(int $boardId, int $userId): void`

**`PluginTrallaBoard`**:

- `roleFor(int $boardId, int $userId = 0): string`
- `canViewBoard(int $boardId): bool`
- `canOperateBoard(int $boardId, int $userId = 0): bool`
- `canAdminBoard(int $boardId, int $userId = 0): bool`
- `canGovernBoard(int $boardId, int $userId = 0): bool`
- `getVisibleBoards(bool $includeArchived = false): array`: membresia ou `PURGE` global, dentro da entidade
- `post_addItem()`: cria o membro criador como `superadmin`
- `canUpdateItem()`: `Admin`/`SuperAdmin` + `UPDATE` global + acesso à entidade
- `canDeleteItem()`: `SuperAdmin` + `DELETE` global + acesso à entidade
- `canPurgeItem()`: `SuperAdmin` + `PURGE` global + acesso à entidade
- `cleanDBonPurge()`: remove `boards_users`
- `getStats()`, `countCards()` e `countMaintenanceByStatus()`: ignoram `is_deleted = 1` e `status = deletion_pending`

**`PluginTrallaCard`**:

- `requestDeletion(int $id, string $reason, int $userId = 0, bool $isAdmin = false): array`
- `decideDeletion(int $approvalId, bool $approve, string $reason, int $userId, bool $isAdmin): array`
- `restoreDeleted(int $id, int $userId = 0, bool $isAdmin = false): array`
- `getDeletedForBoard(int $boardId): array`
- `getCardsForBoard()`: exclui `is_deleted=1` e `status=deletion_pending`
- `permissionsFor()`: inclui `request_deletion`
- `userIsApprover(int $userId, int $boardId): bool`
- `finalize()`: grava `approval_kind='maintenance'`

**`PluginTrallaHistory`**:

- `getForBoard(int $boardId, array $filters): array`
- `getActionLabel()`: inclui as quatro ações de exclusão

### 8.2 Endpoints AJAX

Todos usam `tralla_check_csrf()`, `Session::checkRight('plugin_tralla', READ)`, `PluginTrallaBoard::canViewBoard()` e retornam `tralla_json`.

| Ação | Parâmetros | Permissão | Retorno |
|---|---|---|---|
| `card_request_deletion` | `id`, `reason` | `request_deletion` | `state`, `card`, `approval` |
| `approve_card` | `id` | membro + `plugin_tralla_approve` | `state`, `card` |
| `reject_card` | `id`, `reason` | membro + `plugin_tralla_approve` | `state`, `card` |
| `deleted_cards` | `boards_id` | `Admin`/`SuperAdmin` | `cards` |
| `restore_card` | `id` | `Admin`/`SuperAdmin` + `UPDATE` | `state`, `card` |
| `board_history` | `boards_id`, `card_id`, `date_from`, `date_to`, `page` | membro | `rows`, `total`, `page`, `pages` |
| `take_card` | `id` | membro + `UPDATE` + regra de estado | `state`, `card` |

`card_delete` é removido do AJAX e do JS. O antigo comportamento de exclusão definitiva não é mais exposto.

### 8.3 Board form

Ações POST adicionais em `front/board.form.php`:

- `member_add`: `users_id`, `role`;
- `member_update`: `users_id`, `role`;
- `member_remove`: `users_id`.

Validações:

- quadro válido e acessível;
- ator `SuperAdmin`;
- papel válido;
- usuário GLPI existente e não excluído;
- não duplicar membro;
- não remover/rebaixar o último `SuperAdmin`;
- CSRF.

### 8.4 Bootstrap do Kanban

`front/kanban.php` passa a enviar:

```php
'board' => [
    'id' => (int) $boards_id,
    'role' => $role,
    'role_label' => $roleLabel,
    // campos atuais...
],
'can' => [
    'create_card' => ...,
    'move_card' => ...,
    'manage_lists' => ...,
    'manage_board' => ...,
    'manage_members' => ...,
    'view_deleted' => ...,
    'restore' => ...,
    'approve' => ...,
],
```

O JS deixa de derivar todas as capacidades apenas de `rights` e passa a usar `can` para as ações de quadro. `rights` continua sendo enviado para compatibilidade e para ações globais.

## 9. Migração

`plugin_tralla_update()` chama `plugin_tralla_install()`.

Na instalação/atualização:

1. Criar `glpi_plugin_tralla_boards_users` se não existir.
2. Adicionar as colunas novas em `glpi_plugin_tralla_cards` com checagem idempotente.
3. Preencher `is_deleted = 0` e `approval_kind = 'maintenance'` nos cards existentes.
4. Para cada quadro sem membros:
   - se `users_id > 0` e o usuário existir, inserir como `superadmin`;
   - se o criador não existir mais, não inserir; o quadro fica acessível por `PURGE` global até a configuração.
5. Inserir `glpi_plugin_tralla_boards_users` na lista de tabelas removidas por `plugin_tralla_uninstall()`.
6. Incluir a remoção de membros em `PluginTrallaBoard::cleanDBonPurge()`.

A migração é idempotente e não altera o criador nem papéis já existentes.

## 10. Segurança e consistência

- Toda ação revalida direito global, papel do quadro e vínculo com o card no servidor.
- Motivo de exclusão: `trim`, remoção de tags e limite de 2000 caracteres.
- Nome de membro e papel são validados contra listas fechadas.
- Consultas de histórico usam o query builder do GLPI; nenhum filtro é concatenado em SQL.
- Solicitação de exclusão, decisão e restauração usam transação.
- Conflitos de decisão concorrente: a segunda decisão falha porque o card não está mais em `waiting_approval`.
- A linha única `(boards_id, users_id)` impede membros duplicados.
- O último `SuperAdmin` é protegido por contagem dentro da transação.
- Cards excluídos não entram em contagens, estatísticas ou no Kanban.
- A lixeira não apaga máquinas, histórico ou vínculo com técnico.
- A exclusão de quadro continua removendo cards, máquinas, histórico, notificações, listas e membros.

## 11. Testes

### 11.1 Testes puros

Criar `tests/run.php` com um harness sem GLPI/banco para:

- hierarquia de papéis e matriz de capacidades;
- `request_deletion` por tipo de quadro, estágio, bloqueio e `is_deleted`;
- parser de ID do histórico (`123`, `#123`, `123/1`, `123/2`, inválidos);
- validação de intervalo de datas e motivo;
- proteção do último `SuperAdmin`;
- transições de exclusão/restauração;
- normalização de código de card.

### 11.2 Testes CDP

Estender os harnesses existentes em `C:\Users\Leonardo\AppData\Local\Temp\opencode\`:

- `cdp-normal-card-test.js`: exclusão direta com motivo, Excluídos e restauração;
- `cdp-create-card-test.js`: **Pegar** no card minimizado e bloqueio de criação nas listas de fluxo;
- novo teste de membros: abas, inclusão, alteração de papel e bloqueio do último SuperAdmin;
- novo teste de aprovação de exclusão: card `/2`, aprovar, recusar e conferir estados;
- novo teste de histórico: filtros por ID/data e paginação.

### 11.3 Verificações estáticas

- `php -l` em todos os arquivos PHP alterados;
- `node --check public/js/tralla.js`;
- `node check-css.js`;
- inspeção de `lsp_diagnostics` nos arquivos alterados.

### 11.4 Homologação manual

Atualizar `docs/TESTING.md` com:

- visibilidade por membro e `PURGE` global;
- criação automática do criador como SuperAdmin;
- matriz de permissões dos três papéis;
- aprovação normal e aprovação de exclusão;
- exclusão direta em quadro normal;
- restauração e fallback de lista;
- filtros e paginação do histórico;
- **Pegar** no card minimizado;
- regressão do fluxo de manutenção v2.

Sem PHP/MySQL/GLPI local, a validação de schema e integração real fica para a instância GLPI 12 de homologação.

## 12. Não-objetivos

- Exclusão definitiva de cards pela interface.
- Exclusão em lote.
- Aprovação de exclusão em quadros normais.
- Comentários, menções ou notificações específicas de exclusão.
- Integração com grupos dinâmicos ou perfis GLPI para representar papéis.
- Alteração do fluxo de máquinas, assignat-ure, cron ou notificações existentes.
- Auditoria de alterações de membros no histórico de cards.

## 13. Arquivos afetados

### Backend

- `hook.php`
- `inc/board.class.php`
- `inc/boarduser.class.php` (novo)
- `inc/card.class.php`
- `inc/history.class.php`
- `inc/helpers.php`
- `inc/config.class.php`
- `front/ajax.php`
- `front/board.form.php`
- `front/board.php`
- `front/kanban.php`
- `front/config.form.php`

### Frontend

- `public/js/tralla.js`
- `public/css/tralla.css`
- `DESIGN.md`

### Documentação e testes

- `README.md`
- `docs/TESTING.md`
- `tests/run.php` (novo)

## 14. Critérios de aceite

1. Um quadro só é visível para membros e para `PURGE` global dentro da entidade.
2. O criador é SuperAdmin automaticamente.
3. Membro, Admin e SuperAdmin aplicam a matriz da seção 4.
4. O último SuperAdmin não pode ser removido nem rebaixado.
5. Aprovar/reprovar exige `plugin_tralla_approve` e vínculo com o quadro.
6. Excluir em quadro normal exige motivo e move o card para Excluídos.
7. Excluir em manutenção exige motivo, cria o card `/2` e passa por aprovação.
8. Excluídos mostra somente exclusões concluídas e permite restaurar.
9. Restaurar devolve o card ao estado anterior.
10. Histórico filtra por ID e datas e inclui cards excluídos.
11. O card minimizado em `maintenance_pending` mostra **Pegar** e executa a ação sem abrir o modal.
12. O fluxo de manutenção v2 continua passando nos testes de regressão.
