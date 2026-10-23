# [URE] Tralla

Plugin de quadros Kanban e manutenção de equipamentos para **GLPI 12**.

O Tralla combina dois tipos de quadro:

- **Normal**: listas e cards livres, com drag & drop.
- **Manutenção**: fluxo controlado com técnico responsável, relatório por máquina, aprovação, retirada e conclusão.

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
5. Acesse **Ferramentas > Quadros Tralla** para criar o primeiro quadro.

> A página de configuração do plugin exige o direito `plugin_tralla` de **atualização**.
> O acesso aos quadros também respeita a entidade ativa do usuário e o vínculo com o quadro.
> O criador do quadro é incluído automaticamente como **SuperAdmin**.

## Primeiros passos

### 1. Configurar modelos de máquina

Antes de criar cards de manutenção, cadastre ao menos um modelo:

1. Abra a configuração do plugin em **Configurar > Plugins > [URE] Tralla**.
2. Na seção **Modelos de máquina**, informe o nome e clique em **Adicionar modelo**.
3. Use **Arquivar** para retirar um modelo das novas seleções sem apagar o histórico.

Modelos arquivados continuam visíveis em cards e relatórios antigos, mas não podem ser escolhidos em novos cards.

### 2. Criar um quadro normal

1. Em **Ferramentas > Quadros Tralla**, clique em **Novo quadro**.
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
Pendente --Pegar--> Em Andamento --Finalizar--> Aprovação (/2)
                                                        |
                                              Aprovar --+--> Retirada --Confirmar--> Concluído
                                                        |
                                              Reprovar -+--> Em Andamento
```

- **Pegar**: grava o técnico responsável e move o card para Em Andamento. No card minimizado em Manutenção Pendente, o botão compacto **Pegar** executa a ação sem abrir o modal.
- **Editar máquinas**: somente o técnico responsável, enquanto o card está em andamento e desbloqueado.
- **Finalizar**: exige relatório, **Serviço realizado** e status `done`, `not_repairable` (Inservível) ou `pending` em todas as máquinas. É necessário ao menos uma máquina concluída ou inservível. As máquinas pendentes são reunidas em um novo card na lista **Manutenção Pendente**; o card atual segue para aprovação com as demais máquinas.
- **Aprovar**: move o `/1` para Retirada, desbloqueia e arquiva o `/2` como aprovado.
- **Reprovar**: devolve o `/1` para Em Andamento com o motivo registrado e arquiva o `/2`.
- **Confirmar retirada**: move o `/1` para Concluído e grava a data de retirada. Em cards com família, confirmar em um membro confirma a retirada de toda a família.
- **Separar card**: seleciona máquinas de um card em andamento e cria um card irmão `/N` com o mesmo responsável; bloqueado quando algum membro da família está em aprovação ou retirada.
- **Solicitar edição**: técnico ou admin com atualização solicita alteração de nome e máquinas; cria card `/2` de aprovação de edição. Aprovar aplica as alterações; reprovar descarta.
- **Cards decididos**: após aprovação ou reprovação, o card de aprovação permanece no quadro por 10 segundos com selo `Aprovado` ou `Reprovado` antes de sair.
- **Concluído**: somente leitura.

## Membros e papéis

Cada quadro tem sua própria lista de membros. A visibilidade é definida pelo vínculo com o quadro; os direitos globais do perfil funcionam como limite superior e não concedem acesso a quadros dos quais o usuário não participa. Usuários com `plugin_tralla` **PURGE** global têm papel efetivo **SuperAdmin** e veem os quadros permitidos pela entidade ativa.

| Papel | Capacidades principais |
|---|---|
| **Membro** (`member`) | Ver quadro e histórico, criar/editar cards, mover cards, pegar cards e solicitar exclusão, conforme os direitos globais. |
| **Admin** (`admin`) | Tudo de Membro, mais configurar quadro e listas, ver **Excluídos** e restaurar cards. Não gerencia membros. |
| **SuperAdmin** (`superadmin`) | Tudo de Admin, mais gerenciar membros, arquivar e excluir o quadro. |

- Somente **SuperAdmin** adiciona, altera ou remove membros.
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

- Filtre por ID do card (`123`, `#123`, `123/1` ou `123/2`), data inicial e data final.
- A tabela mostra data/hora, card, ação, usuário e detalhes.
- Os resultados são paginados, com 50 eventos por página.
- **Limpar** remove os filtros e volta à primeira página.

## Notificações e cron

A tarefa automática é registrada na instalação:

- Itemtype: `PluginTrallaCron`
- Nome: `notify`
- Frequência: horária

Ela:

- avisa uma vez sobre cards pendentes há mais de `pending_hours`;
- reenvia lembrete de retirada a cada `withdrawal_hours` até a confirmação;
- enfileira e-mails pelo sistema de notificações do GLPI quando habilitado.

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
