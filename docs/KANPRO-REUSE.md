# Reaproveitamento do Kanpro

O Tralla usa o Kanpro (`D:\Projetos\kanpro`) como referência de padrões visuais e de integração com o GLPI, não como dependência de runtime.

## O que foi reaproveitado

- Paleta, tipografia, sombras, raios e densidade visual do Kanban.
- Estrutura de página com cabeçalho, ações e área de rolagem horizontal.
- Padrão de cards, listas, pills de status, barras de progresso e modais.
- Convenções de nomenclatura de classes PHP e arquivos `front/*.php`.
- Uso de `Html::header`, `Html::footer`, `Session::checkRight`, `CommonDBTM` e `DB->request`.
- Padrão de cache-buster dos assets via `filemtime`.
- Registro de cron via `CronTask`.
- Endpoint externo autenticado por token.

## O que foi deliberadamente diferente

- Todas as classes CSS usam prefixo `tralla-` e o arquivo é isolado em `public/css/tralla.css`.
- O JavaScript é um único arquivo, sem dependências externas e com early-return fora do Kanban.
- O fluxo de manutenção é controlado no backend; o front não decide transições.
- O schema usa tabelas próprias e nomes `glpi_plugin_tralla_*`.
- O assignat-ure é integrado por endpoint de leitura, sem acoplamento ao código do Kanpro.
- Modelos de máquina têm tela de administração dentro da configuração do plugin.

## Cuidados

- Não copiar regras de negócio específicas do Kanpro (membros, butler, chamados, Telegram).
- Não reutilizar IDs ou classes CSS sem o prefixo `tralla-`.
- Ao alterar tokens visuais, comparar com `D:\Projetos\kanpro\public\css\kanpro.css`.
- O GLPI 12 pode ter mudanças de API em relação ao Kanpro; validar em instância real.
