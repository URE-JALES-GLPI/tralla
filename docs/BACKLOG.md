# Backlog — [URE] Tralla

Itens conscientemente fora da primeira versão ou que dependem de definição funcional.

## Produto

- **Separar card (split)**: o fluxograma original não descreve regras de rateio de máquinas, histórico e aprovação. Definir antes de implementar.
- **CRM entre finalização e retirada**: etapa sem contrato funcional; não bloquear o fluxo atual.
- **Push automático para o assignat-ure**: hoje existe endpoint de leitura e link manual. Definir autenticação, payload e política de reenvio.
- **Vínculo automático com inventário**: a flag `needs_inventory` é registrada, mas não cria Computer/Asset. Definir mapeamento de modelo, entidade e campos.
- **Escalonamento de aprovação**: grupo aprovador já configurável; falta definir prazo, delegação e escalonamento.
- **Reordenação visual de listas**: a ação `list_reorder` existe no backend, mas ainda não há interação de UI dedicada.
- **Notificações in-app**: hoje o foco é e-mail via fila do GLPI; avaliar notificações nativas.

## Técnico

- Adicionar testes de integração em uma instância GLPI 12 com banco real.
- Cobrir transições de status com testes automatizados de domínio.
- Validar o comportamento do cron em ambientes sem `MODE_EXTERNAL`.
- Revisar compatibilidade com temas GLPI 12 e modo escuro.
- Avaliar limite de tamanho e sanitização do relatório técnico para anexos futuros.
