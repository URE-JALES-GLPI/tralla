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
   - `plugin_tralla_approve`: leitura para aprovadores.
5. Acesse **Ferramentas > Quadros Tralla** para criar o primeiro quadro.

> A página de configuração do plugin exige o direito `plugin_tralla` de **atualização**.
> O acesso aos quadros também respeita a entidade ativa do usuário.

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
3. Crie um card informando uma ou mais máquinas (modelo + etiqueta/série).
4. O fluxo é controlado pelo servidor; arrastar cards de manutenção não altera o status.

### 4. Fluxo de manutenção

```text
Pendente --Pegar--> Em Andamento --Finalizar--> Aprovação (/2)
                                                        |
                                              Aprovar --+--> Retirada --Confirmar--> Concluído
                                                        |
                                              Reprovar -+--> Em Andamento
```

- **Pegar**: grava o técnico responsável e move o card para Em Andamento.
- **Editar máquinas**: somente o técnico responsável, enquanto o card está em andamento e desbloqueado.
- **Finalizar**: exige todas as máquinas preenchidas (relatório não vazio + status `done` ou `not_repairable`). Cria o card de aprovação `/2` e bloqueia o card `/1`.
- **Aprovar**: move o `/1` para Retirada, desbloqueia e arquiva o `/2` como aprovado.
- **Reprovar**: devolve o `/1` para Em Andamento com o motivo registrado e arquiva o `/2`.
- **Confirmar retirada**: move o `/1` para Concluído e grava a data de retirada.
- **Concluído**: somente leitura.

## Configuração

A página de configuração permite:

- URL do **assignat-ure** e token de leitura.
- Grupo aprovador opcional.
- Ativação de notificações por e-mail.
- Destinatários adicionais.
- Prazo de aviso de card pendente (horas).
- Intervalo de lembrete de retirada (horas).
- Cadastro, arquivamento e restauração de modelos de máquina.

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

- Toda ação AJAX revalida direitos e CSRF no servidor.
- Acesso a quadros e cards é limitado pela entidade ativa.
- Cards de manutenção não podem ser movidos por drag & drop.
- A exclusão de cards de manutenção é limitada ao estado Pendente ou a quem possui PURGE.
- O endpoint do assignat-ure é somente leitura e exige token.

## Limitações desta versão

- Separar card (split) e etapa de CRM não estão implementados.
- A flag **Necessita inventariar** é registrada, mas não cria automaticamente Computer/Asset.
- O envio/push para o assignat-ure é manual; o plugin disponibiliza o link e o endpoint de consulta.
- A verificação visual e o teste de instalação precisam ser feitos em uma instância GLPI 12 real.

Consulte `docs/BACKLOG.md`, `docs/KANPRO-REUSE.md` e `docs/TESTING.md`.

## Licença

GPLv3 ou superior. Consulte `LICENSE`.
