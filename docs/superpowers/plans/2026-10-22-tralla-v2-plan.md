# Tralla v2 — Plano de implementação

Base: `2026-10-22-tralla-v2-design.md`

## 1. Fundação

- [x] Criar estrutura de plugin GLPI 12 (`setup.php`, `hook.php`, `inc/`, `front/`, `public/`).
- [x] Registrar metadados `[URE] Tralla`, versão, requisitos e hooks.
- [x] Criar schema completo em `plugin_tralla_install()` com migrações idempotentes.
- [x] Registrar direitos de perfil e tarefa cron.
- [x] Configuração padrão (`assignature_token`, horas de notificação).

## 2. Domínio

- [x] Helpers puros: códigos `n/1` e `n/2`, rótulos, whitelist de categorias/status.
- [x] `PluginTrallaBoard` + criação automática de listas por tipo.
- [x] `PluginTrallaList` + validação de categoria única.
- [x] `PluginTrallaCard` + máquina de estados completa.
- [x] `PluginTrallaMachine` + autosave e regra de "preenchida".
- [x] `PluginTrallaMachineModel` + cadastro inline.
- [x] `PluginTrallaHistory` + trilha de auditoria.
- [x] `PluginTrallaConfig`.
- [x] `PluginTrallaCron` + notificações de pendente/retirada.

## 3. HTTP

- [x] `front/board.php` — listagem/criação de quadros.
- [x] `front/board.form.php` — CRUD de quadro.
- [x] `front/kanban.php` — bootstrap do quadro.
- [x] `front/ajax.php` — AJAX com permissões e transições.
- [x] `front/config.form.php` — configurações.
- [x] `front/profile.form.php` — direitos.
- [x] `front/assignature.php` — integração read-only.

## 4. Interface

- [x] `public/css/tralla.css` — tokens Kanpro + layout responsivo.
- [x] `public/js/tralla.js` — render, DnD, modal, autosave, aprovação, retirada.
- [x] Estados vazios, toasts, badges, progresso e acessibilidade básica.

## 5. Qualidade

- [x] Testes puros em `tests/run.php` (códigos, transições, preenchimento, permissões).
- [x] `node --check` no JS.
- [x] Parse PHP de todos os arquivos (php-parser).
- [x] Documentação: README, KANPRO-REUSE, TESTING, BACKLOG.

## 6. Verificação manual (GLPI 12)

- [ ] Instalar plugin em GLPI 12 e conceder direitos.
- [ ] Criar quadro Normal, listas, cards, arrastar e editar.
- [ ] Criar quadro Manutenção com as cinco listas.
- [ ] Criar card, cadastrar modelo inline, pegar, autosave, finalizar.
- [ ] Aprovar e confirmar retirada; verificar códigos `n/1` e `n/2`.
- [ ] Reprovar e verificar retorno para Em Andamento.
- [ ] Rodar cron manualmente e conferir notificações.
- [ ] Abrir endpoint assignat-ure com token.
