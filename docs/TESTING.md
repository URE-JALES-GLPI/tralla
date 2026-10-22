# Checklist de teste manual — [URE] Tralla

> O ambiente de desenvolvimento não possui PHP/GLPI/MySQL executáveis. A validação abaixo deve ser feita em uma instância GLPI 12 limpa ou de homologação.

## Verificações estáticas já executadas

```powershell
node --check .\public\js\tralla.js
node "C:\Users\LEONAR~1.FAC\AppData\Local\Temp\opencode\tralla-lint\lint.js" "D:\Projetos\tralla"
```

Resultado esperado:

- JavaScript sem erro de sintaxe.
- 20 arquivos PHP com 0 erros de parse.

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
- [ ] Criar, editar e excluir card.
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

## Permissões de aprovação

- [ ] Usuário com `plugin_tralla_approve` e sem grupo configurado consegue aprovar.
- [ ] Com grupo aprovador configurado, somente membros do grupo aprovam.
- [ ] Usuário sem `plugin_tralla_approve` não aprova nem reprova.
- [ ] Usuário sem `plugin_tralla` READ não acessa o endpoint AJAX.

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
