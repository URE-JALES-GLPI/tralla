# [URE] Tralla

Plugin de quadros Kanban e manutenção de equipamentos para **GLPI 12**.

O Tralla combina três tipos de quadro:

- **Normal**: listas e cards livres, com drag & drop.
- **Manutenção**: fluxo controlado com técnico responsável, relatório por máquina, aprovação, retirada e conclusão.
- **Atualizações**: cards com descrição ou checklist, responsável, solução e arquivos.

Dentro de um quadro de manutenção, cada card pode ser **Manutenção** ou **Chamado**. O Chamado pede uma descrição da situação em vez de máquinas; o técnico registra o relatório do atendimento antes de finalizar. Depois da aprovação, vai direto para **Concluído**, sem separação nem retirada. Os cards de Chamado têm destaque roxo.
Cards de Chamado aceitam arquivos de até 100 MB cada no detalhe do card.

## Requisitos

- GLPI `>= 12.0.0` e `<= 12.0.99`
- PHP `>= 8.2`
- MySQL/MariaDB compatível com o GLPI
- Direitos de administração para instalar e configurar o plugin

## Instalação

1. Copie a pasta `tralla` para `GLPI_ROOT/plugins/tralla`.
2. Confirme que o servidor web pode ler a pasta.
3. Acesse **Configurar > Plugins**, localize **[URE] Tralla** e clique em **Instalar**, depois **Ativar**.
4. Em **Administração > Perfis**, conceda os direitos:
   - `plugin_tralla`: leitura, criação, atualização e exclusão para operadores.
   - `plugin_tralla_approve`: leitura para membros que aprovam no quadro.
5. Acesse **Ferramentas > Tralla** para criar o primeiro quadro.

Em uma atualização do plugin já instalado, atualize o código e execute o instalador idempotente para criar as novas tabelas e colunas antes de abrir um quadro de Atualizações:

```bash
cd /var/www/glpi/plugins/tralla && git pull --ff-only
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install --force --username=glpi tralla
sudo -u www-data php bin/console plugin:activate tralla
```

> A página de configuração do plugin exige o direito `plugin_tralla` de **atualização**.
> O acesso aos quadros também respeita a entidade ativa do usuário e o vínculo com o quadro.
> O criador do quadro é incluído automaticamente como **SuperAdmin**.

## Primeiros passos

Na página inicial, crie **Grupos** e arraste seus quadros entre eles para organizar os atalhos. Cada usuário tem sua própria organização; mover um quadro nessa página não altera o quadro nem a organização dos demais. Também é possível renomear, excluir e mudar a ordem dos grupos. Ao excluir um grupo, seus quadros voltam para **Sem grupo**.

### 1. Configurar modelos de máquina

Antes de criar cards de manutenção, cadastre ao menos um modelo:

1. Abra a configuração do plugin em **Configurar > Plugins > [URE] Tralla**.
2. Na seção **Modelos de máquina**, informe o nome e clique em **Adicionar modelo**.
3. Use **Arquivar** para retirar um modelo das novas seleções sem apagar o histórico.

Modelos arquivados continuam visíveis em cards e relatórios antigos, mas não podem ser escolhidos em novos cards.

### 2. Criar um quadro normal

1. Em **Ferramentas > Tralla**, clique em **Novo quadro**.
2. Escolha o tipo **Normal**.
3. O plugin cria as listas padrão **A Fazer**, **Em Progresso** e **Concluído**.
4. Crie cards, arraste entre listas e edite o conteúdo pelo modal.

### 3. Criar um quadro de manutenção

1. Crie um quadro do tipo **Manutenção**.
2. O plugin cria as listas:
   - Manutenção Pendente
   - Manutenção Em Andamento
   - Manutenção Aprovação
   - Manutenção Retirada
   - Manutenção Concluído
3. Crie um card informando o nome, o modelo e a quantidade de máquinas. O relatório por máquina pode ser preenchido depois, no detalhe do card.
4. O fluxo é controlado pelo servidor; arrastar cards de manutenção não altera o status.

### 4. Fluxo de manutenção

```text
Pendente --Pegar--> Em Andamento --Finalizar--> Retirada --Confirmar--> Concluído
```

- **Pegar**: grava o técnico responsável e move o card para Em Andamento. No card minimizado em Manutenção Pendente, o botão compacto **Pegar** executa a ação sem abrir o modal.
- **Editar máquinas**: somente o técnico responsável, enquanto o card está em andamento e desbloqueado.
- **Finalizar**: exige relatório, **Serviço realizado** e status `done`, `not_repairable` (Inservível) ou `pending` em todas as máquinas. É necessário ao menos uma máquina concluída ou inservível. As máquinas pendentes são reunidas em um novo card na lista **Manutenção Pendente**; o card atual é finalizado com as demais máquinas, sem passar por aprovação. Quando todos os cards de uma separação estiverem finalizados, reúne as máquinas no card de origem, arquiva os cards separados e libera somente a origem para Retirada. O Chamado continua passando por aprovação antes de ir para Concluído.
- **Numeração**: cada card principal recebe um número sequencial próprio no quadro (`#1`, `#2`, `#3`...), independente do id interno, que também é usado pelos cards de aprovação. Separações e aprovações continuam esse número (`#3/2`).
- **Histórico da separação**: no card reunido, o cabeçalho mostra os cards da separação (ex.: `30/1`, `30/2`). Ao clicar em um deles, o card é exibido como estava durante a separação, somente leitura, com técnico, máquinas e histórico próprios, e um botão para voltar ao card completo. Cada máquina mostra o técnico e o card em que foi feita, e o histórico do card reunido junta os eventos de todos os cards, identificando a origem de cada um.
- **Confirmar retirada**: move o card de origem para Concluído e grava a data de retirada.
- **Separar card**: seleciona máquinas e cria uma aprovação com o próximo número visual. Se aprovada, cria um card irmão com o número seguinte; se recusada, nenhuma máquina é movida. A separação fica bloqueada quando algum membro da família está em aprovação ou retirada.
- **Solicitar edição**: técnico ou admin com atualização solicita alteração de nome e máquinas; cria um card de aprovação com o próximo número visual. Aprovar aplica as alterações; reprovar descarta.
- **Cards decididos**: após aprovação ou reprovação, o card de aprovação permanece no quadro por 10 segundos com selo `Aprovado` ou `Reprovado` antes de sair.
- **Concluído**: somente leitura.

### Quadro de Atualizações

Ao criar o quadro, o Tralla cria quatro listas fixas: **Atualização Futura**, **Atualização Pendente**, **Atualização Em Andamento** e **Atualização Concluído**. É possível criar cards nas duas primeiras. Em Futura, o criador ou um administrador do quadro move o card manualmente para Pendente. Em Pendente, **Fazer** atribui o card a quem clicou e o leva para Em Andamento.

O card pode ser de **Descrição** (texto, anexos e solução) ou **Checklist** (itens marcáveis, até 10 arquivos de 100 MB por item e observação final opcional). Na criação da checklist, cada item já aceita a seleção de arquivos; o envio começa após o card ser criado e mostra o progresso. Se algum envio falhar, o card permanece criado e os arquivos podem ser reenviados nele. Os textos são salvos automaticamente enquanto se digita. O responsável finaliza após registrar uma solução no card de Descrição ou marcar todos os itens no card de Checklist. O card segue diretamente para Concluído.

### Chamado aberto pelo WhatsApp

O plugin WhatsAppSimples abre o mesmo modal **Novo card**, só com Chamado, na tela do atendimento, no quadro configurado (padrão: quadro 1), e cria o card direto em **Manutenção Em Andamento**: o técnico do atendimento vira o responsável, escolhe a prioridade (P1 a P4) e o SLA começa a contar na criação, sem passar por Pendente nem por aprovação. O card mostra a origem **WhatsApp** e a seção **Conversa do WhatsApp**, remontada a partir do atendimento sempre que o card é aberto. Pela mesma tela, o técnico vê os cards abertos do contato, edita descrição e relatório e finaliza o card, que vai direto para Concluído. Ao ser criado, o chamado ganha um card de acompanhamento em Aprovação, sem aprovar/reprovar, que é arquivado na conclusão. O card pode ser transferido para a conversa de outro contato; as conversas anteriores continuam no card. Detalhes em `whatsappsimples/docs/CARD_TRALLA.md`.

## Membros e papéis

Cada quadro tem sua própria lista de membros. A visibilidade é definida pelo vínculo com o quadro; os direitos globais do perfil funcionam como limite superior e não concedem acesso a quadros dos quais o usuário não participa. Usuários com `plugin_tralla` **PURGE** global têm papel efetivo **SuperAdmin** e veem os quadros permitidos pela entidade ativa.

| Papel | Capacidades principais |
|---|---|
| **Membro** (`member`) | Ver quadro e histórico, criar/editar cards, mover cards, pegar cards e solicitar exclusão, conforme os direitos globais. |
| **Admin** (`admin`) | Tudo de Membro, mais configurar quadro e cores das listas, ver **Excluídos** e restaurar cards. Não gerencia membros. |
| **SuperAdmin** (`superadmin`) | Tudo de Admin, mais renomear listas, gerenciar membros, arquivar e excluir o quadro. |
| **Tablet** (`tablet`) | Só em quadros de manutenção. Visualiza o quadro e abre os cards; o único botão é **Editar**, que altera nome e máquinas do card na hora, sem aprovação, e registra cada alteração no histórico. Não pode ser aprovador. |

- Somente **SuperAdmin** adiciona, altera ou remove membros.
- Somente o **SuperAdmin cadastrado no próprio quadro** pode alterar os títulos das listas. O perfil Super-Admin do GLPI sozinho não concede essa ação.
- **Admin** visualiza a aba **Membros** em modo somente leitura.
- O último **SuperAdmin** do quadro não pode ser removido nem rebaixado.
- Aprovar ou reprovar exige vínculo com o quadro e o direito `plugin_tralla_approve`.
- O direito global `UPDATE` é necessário para configurar o quadro, gerenciar membros e restaurar cards.
- O direito global `DELETE` (ou `PURGE`) é necessário para solicitar exclusão.

## Configuração

A página de configuração permite:

- URL do **assignat-ure** e token de leitura.
- Membros do quadro com `plugin_tralla_approve` para aprovar e reprovar cards.
- Ativação de notificações por e-mail.
- Destinatários adicionais.
- Prazo de aviso de card pendente (horas).
- Intervalo de lembrete de retirada (horas).
- Cadastro, arquivamento e restauração de modelos de máquina.

> Não existe mais grupo aprovador global. A aprovação é feita por membros do quadro que possuam `plugin_tralla_approve`.
> A chave `approval_group` permanece na tabela de configuração apenas por compatibilidade e não é usada.

### assignat-ure

O endpoint de leitura fica em:

```text
/plugins/tralla/front/assignature.php?token=SEU_TOKEN&card=ID
```

Também é possível usar o header `X-Tralla-Token`.

Listagem de cards aguardando retirada:

```text
/plugins/tralla/front/assignature.php?token=SEU_TOKEN&list=withdrawal
```

O token é comparado com `hash_equals`. Gere um novo token na configuração sempre que necessário.

Para usar a assinatura dentro do Assignat-ure, instale o plugin em `plugins/assignature`, cadastre o Tralla como fonte com o endpoint acima e o token gerado, e configure no Tralla a URL `https://SEU-GLPI/plugins/assignature/front/request.php` (use `http://` quando o GLPI estiver servido por HTTP). Cada card em retirada abre seu próprio pedido nessa URL. Depois que a assinatura é salva, o Assignat-ure envia um `POST` autenticado para `/plugins/tralla/front/assignature_callback.php`; o Tralla conclui apenas o card assinado. Se o retorno falhar, o Assignat-ure mantém o comprovante e tenta reenviar pela tarefa automática.

## Exclusão e restauração

A exclusão de cards exige motivo, com 1 a 2000 caracteres, e é sempre reversível. Cards excluídos saem do Kanban e ficam disponíveis em **Excluídos** para **Admin** e **SuperAdmin**.

### Quadro normal

1. Abra o card e clique em **Excluir card**.
2. Informe o motivo no diálogo (obrigatório).
3. O card vai para **Excluídos**; não há aprovação nem card `/2`.

**Admin** e **SuperAdmin** com `UPDATE` global restauram o card pelo botão **Restaurar**, após confirmação. A restauração devolve lista, status e bloqueio anteriores, preservando técnico, máquinas e histórico.

### Quadro de manutenção

1. Abra o card `/1` e clique em **Excluir card**.
2. Informe o motivo (obrigatório). A solicitação cria um card `/2` em **Manutenção Aprovação** com o selo **Solicitação de exclusão**.
3. **Aprovar exclusão** move o `/1` para **Excluídos** e arquiva o `/2`.
4. **Recusar exclusão** restaura o `/1` ao estado anterior e arquiva o `/2` como recusado.

A aba **Excluídos** mostra somente exclusões concluídas. Solicitações pendentes continuam em **Manutenção Aprovação**.

## Histórico

O botão **Histórico** fica disponível para todos os membros do quadro e abre um modal com os eventos de todos os cards, inclusive excluídos.

- Filtre pelo código do card (`12`, `#12`, `12/1` ou `12/2`), usuário do quadro, texto da máquina (nome, número de série ou ID), modelo, tipo de atualização, data inicial e data final. Os filtros podem ser combinados; máquina e modelo incluem o histórico do card de aprovação vinculado.
- A tabela mostra data/hora, card, ação, usuário e detalhes.
- Os resultados são paginados, com 50 eventos por página.
- **Limpar** remove os filtros e volta à primeira página.

## Notificações e cron

A tarefa automática é registrada na instalação:

- Itemtype: `PluginTrallaCron`
- Nome: `notify`
- Frequência: a cada minuto

Ela:

- avisa uma vez sobre cards pendentes há mais de `pending_hours`;
- reenvia lembrete de retirada a cada `withdrawal_hours` até a confirmação;
- enfileira e-mails pelo sistema de notificações do GLPI quando habilitado;
- nos quadros de manutenção com **Notificar por Email** e/ou **Notificar por WhatsApp** marcados (aba Geral do quadro):
  - **Pendente**: lembra os membros do quadro (sem o papel Tablet) enquanto o card liberado não for pego, a cada 5 min (P1), 10 min (P2), 30 min (P3) ou 1 h (P4), contando da criação ou da liberação;
  - **SLA**: avisa o técnico do card em andamento e os aprovadores do quadro 4, 3, 2 e 1 hora antes do prazo das respostas paliativa e definitiva; cada aviso é enviado uma vez e fica no histórico do card.

O e-mail usa o endereço padrão do usuário no GLPI. O WhatsApp usa o **Celular** do cadastro do usuário (ou o **Telefone**, se o celular estiver vazio) e a EvolutionAPI configurada no plugin WhatsAppSimples. Cada envio fica registrado em `glpi_plugin_tralla_notifications`.

O cron do GLPI precisa rodar a cada minuto (ex.: `* * * * * www-data php /var/www/glpi/front/cron.php`) para os lembretes de 5 minutos.

Ajuste a execução em **Configurar > Ações automáticas** se o ambiente não executa o cron externo do GLPI.

## Estrutura

```text
tralla/
├── front/                 # páginas e endpoint JSON
├── inc/                   # classes de domínio e helpers
├── public/css/            # design system do plugin
├── public/js/             # Kanban, modais e autosave
├── docs/                  # design, plano, backlog e checklist
├── hook.php               # instalação, schema, seeds, direitos e cron
├── setup.php              # registro do plugin, menus e assets
└── README.md
```

## Direitos e segurança

- Toda ação AJAX revalida direito global, papel no quadro, vínculo com o card e CSRF no servidor.
- O acesso a quadros e cards exige entidade ativa e vínculo com o quadro; `PURGE` global é a exceção administrativa.
- Os direitos globais são um limite superior: o papel do quadro restringe, mas não amplia o que o perfil permite.
- Membros veem apenas os quadros dos quais participam; aprovação exige `plugin_tralla_approve` e vínculo com o quadro.
- Somente **SuperAdmin** gerencia membros; o último **SuperAdmin** é protegido no servidor.
- A exclusão exige motivo, é reversível e respeita o fluxo de aprovação do tipo de quadro.
- Restaurar cards exige papel **Admin**/**SuperAdmin** e `UPDATE` global.
- Cards de manutenção não podem ser movidos por drag & drop.
- O histórico e a aba **Excluídos** revalidam o papel do quadro no servidor.
- O endpoint do assignat-ure é somente leitura e exige token.

## Limitações desta versão

- A etapa de CRM entre finalização e retirada não está implementada.
- A exclusão definitiva de cards e a exclusão em lote não estão implementadas; a lixeira é sempre reversível.
- A flag **Necessita inventariar** é registrada, mas não cria automaticamente Computer/Asset.
- O envio/push para o assignat-ure é manual; o plugin disponibiliza o link e o endpoint de consulta.
- A verificação visual e o teste de instalação precisam ser feitos em uma instância GLPI 12 real.

Consulte `docs/BACKLOG.md`, `docs/KANPRO-REUSE.md` e `docs/TESTING.md`.

## Licença

GPLv3 ou superior. Consulte `LICENSE`.
