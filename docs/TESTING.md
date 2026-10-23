# Checklist de teste manual — [URE] Tralla

> O ambiente de desenvolvimento não possui PHP/GLPI/MySQL executáveis. A validação abaixo deve ser feita em uma instância GLPI 12 limpa ou de homologação.

## Verificações estáticas

```powershell
node --check .\public\js\tralla.js
node C:\Users\Leonardo\AppData\Local\Temp\opencode\check-css.js
```

Resultado esperado:

- JavaScript sem erro de sintaxe.
- CSS sem erros no `check-css.js`.
- `php -l` sem erros nos arquivos PHP alterados.

## Instalação

- [ ] Copiar `tralla` para `GLPI_ROOT/plugins/tralla`.
- [ ] Instalar e ativar em **Configurar > Plugins**.
- [ ] Confirmar criação das 8 tabelas `glpi_plugin_tralla_*`.
- [ ] Confirmar tarefa `PluginTrallaCron` / `notify`.
- [ ] Confirmar direitos `plugin_tralla` e `plugin_tralla_approve` em **Administração > Perfis**.
- [ ] Acessar **Ferramentas > Quadros Tralla** sem erro.

## Quadro normal

- [ ] Criar quadro normal.
- [ ] Confirmar listas A Fazer, Em Progresso e Concluído.
- [ ] Criar, editar e excluir card com motivo.
- [ ] Arrastar card entre listas e recarregar a página.
- [ ] Criar, renomear, recolorir e excluir lista vazia.
- [ ] Confirmar que lista com cards não pode ser excluída.
- [ ] Testar usuário sem direito de leitura (não deve acessar).

## Modelos e quadro de manutenção

- [ ] Cadastrar modelo em **Configurações > Modelos de máquina**.
- [ ] Arquivar modelo e confirmar que ele não aparece em novos cards.
- [ ] Restaurar modelo arquivado.
- [ ] Criar quadro de manutenção e confirmar as 5 listas.
- [ ] Criar card com uma máquina.
- [ ] Criar card com duas máquinas.
- [ ] Confirmar código do card base `{id}/1`.

## Fluxo de manutenção

- [ ] **Pegar** como técnico A; confirmar técnico e lista Em Andamento.
- [ ] Técnico B não deve editar máquinas do card do técnico A.
- [ ] Preencher relatório e status de todas as máquinas.
- [ ] Finalizar; confirmar card `/2` em Aprovação e `/1` bloqueado.
- [ ] Aprovar; confirmar `/1` em Retirada e `/2` arquivado.
- [ ] Confirmar retirada; confirmar `/1` em Concluído.
- [ ] Repetir fluxo e reprovar; confirmar `/1` em Em Andamento e motivo no histórico.
- [ ] Verificar que drag & drop não move card de manutenção.
- [ ] Verificar que card Concluído é somente leitura.

## v3 — membros, exclusão, histórico e Pegar

### Membros e visibilidade

- [ ] O criador do quadro aparece como **SuperAdmin** na aba **Membros**.
- [ ] Membro vê apenas os quadros dos quais participa.
- [ ] Usuário sem vínculo, mas com direitos globais, não vê nem abre o quadro.
- [ ] Usuário com `plugin_tralla` **PURGE** global vê os quadros permitidos pela entidade ativa.
- [ ] Direito global é limite superior: um Membro com `plugin_tralla` READ não cria cards.
- [ ] Admin vê a aba **Membros** em modo somente leitura.
- [ ] SuperAdmin adiciona, altera o papel e remove membros.
- [ ] Tentativa de remover ou rebaixar o último SuperAdmin é bloqueada na UI e no servidor.
- [ ] Adicionar usuário inválido, duplicado ou com papel inválido é recusado.
- [ ] Abas **Geral** e **Membros** navegam sem aninhar formulários.

### Exclusão em quadro normal

- [ ] **Excluir card** com motivo vazio bloqueia o diálogo e exibe “Informe o motivo da exclusão.”.
- [ ] Motivo válido move o card para **Excluídos** e mostra “Card movido para Excluídos.”.
- [ ] O card sai do Kanban e não há card `/2`.
- [ ] **Excluídos** lista código, nome, motivo, solicitado em, excluído em, solicitante, aprovador e lista anterior.
- [ ] **Restaurar** pede confirmação; o card volta à lista/status anterior e some de **Excluídos**.
- [ ] Membro não vê o botão **Excluídos** nem restaura cards.

### Exclusão em quadro de manutenção

- [ ] **Excluir card** com motivo vazio bloqueia o diálogo.
- [ ] Motivo válido cria card `/2` em **Manutenção Aprovação** e oculta o `/1` do Kanban.
- [ ] O `/2` mostra **Solicitação de exclusão**, o motivo, **Ver origem** e os botões **Aprovar exclusão**/**Recusar exclusão**.
- [ ] **Recusar exclusão** restaura o `/1` ao estado anterior e arquiva o `/2`.
- [ ] **Aprovar exclusão** move o `/1` para **Excluídos** e arquiva o `/2`.
- [ ] **Excluídos** mostra somente exclusões concluídas; solicitações pendentes ficam em **Manutenção Aprovação**.

### Histórico

- [ ] Membro com `plugin_tralla` READ abre **Histórico**.
- [ ] Filtro `123` e `#123` retorna o card `/1` e os `/2` vinculados.
- [ ] Filtros `123/1` e `123/2` retornam apenas a etapa correspondente.
- [ ] Filtro inválido exibe erro de validação.
- [ ] Datas inicial/final são inclusivas e intervalo invertido é recusado.
- [ ] Paginação anterior/próxima respeita 50 eventos por página.
- [ ] Eventos de exclusão e cards excluídos aparecem no histórico.
- [ ] **Limpar** remove os filtros e volta à primeira página.
- [ ] Quadro sem eventos mostra “Nenhum evento encontrado.”.

### Pegar minimizado e cabeçalho por papel

- [ ] Card em `maintenance_pending` mostra o botão compacto **Pegar**.
- [ ] Clicar em **Pegar** não abre o modal, atribui o técnico, move para Em Andamento e mostra “Card atribuído a você.”.
- [ ] O botão **Pegar** desaparece quando o card sai de `maintenance_pending`.
- [ ] Membro sem permissão de `take` não vê o botão.
- [ ] Membro vê **Histórico**; Admin vê também **Excluídos** e **Editar quadro**; SuperAdmin vê também **Lista**.
- [ ] Membro com `CREATE` vê **Adicionar card**.

### Regressão do fluxo v2

- [ ] **Pegar** → **Finalizar** → **Aprovar**/**Reprovar** → **Retirada** → **Concluído** continua funcionando.
- [ ] Finalizar cria o `/2` de manutenção com `approval_kind=maintenance`.
- [ ] Reprovar devolve o `/1` para Em Andamento com o motivo.
- [ ] Drag & drop não move cards de manutenção.
- [ ] Card Concluído permanece somente leitura.

## Permissões de aprovação

- [ ] Membro do quadro com `plugin_tralla_approve` aprova e reprova.
- [ ] Membro do quadro sem `plugin_tralla_approve` não aprova nem reprova.
- [ ] Não membro com direito global de aprovação não aprova outro quadro.
- [ ] SuperAdmin do quadro aprova somente se também tiver `plugin_tralla_approve`.
- [ ] Usuário com **PURGE** global aprova apenas como **SuperAdmin** efetivo e com `plugin_tralla_approve`.
- [ ] Usuário sem `plugin_tralla` READ não acessa o endpoint AJAX.
- [ ] Remover o vínculo do quadro revoga a aprovação nas próximas ações.

## assignat-ure

- [ ] Configurar URL e token.
- [ ] Gerar novo token e confirmar que o antigo deixa de funcionar.
- [ ] Abrir `/plugins/tralla/front/assignature.php?token=...&card=ID`.
- [ ] Abrir `...&list=withdrawal` e conferir cards em retirada.
- [ ] Testar token inválido (HTTP 401) e card inexistente (HTTP 404).
- [ ] Confirmar `Cache-Control: no-store`.

## Notificações e cron

- [ ] Habilitar notificações e configurar destinatários.
- [ ] Enviar e-mail de teste para o usuário logado.
- [ ] Executar a ação automática `PluginTrallaCron` / `notify`.
- [ ] Confirmar aviso de card pendente apenas uma vez.
- [ ] Confirmar lembrete de retirada respeitando `withdrawal_hours`.
- [ ] Confirmar que desabilitar notificações interrompe novos envios.

## Regressão visual

- [ ] Testar largura desktop, tablet e celular.
- [ ] Confirmar que o CSS do Tralla não afeta outras páginas do GLPI.
- [ ] Confirmar que o JS não inicializa fora das páginas do plugin.
- [ ] Testar modal de quadro, card, lista e confirmações.
- [ ] Testar com tema claro e escuro do GLPI.
