# Formulário de Preventiva (Google Apps Script)

Formulário que as escolas respondem para a **Preventiva de T.I.** O link é um só para todo mundo,
cada pessoa pode responder quantas vezes quiser e o formulário fecha sozinho depois do prazo.

- **Página:** `docs/preventiva/index.html`, publicada pelo GitHub Pages.
- **Script:** `Code.gs` guarda as respostas na planilha **Preventiva - respostas** (Google Drive).
- O GLPI fica em rede interna: é o GLPI (Tralla) que chama o script para mandar a lista de escolas e
  buscar as respostas. O Google nunca acessa o GLPI.

## O que o formulário pergunta

1. Escola (entidades do GLPI, com busca pelo nome)
2. Existem problemas de T.I. a resolver? (Sim/Não). Se **Sim**: descrição (obrigatória)
3. Como a equipe SEINTEC pode atender melhor a escola? (sempre visível, opcional)

Colunas da aba **Respostas**, nesta ordem: ID · Data · ID da escola (GLPI) · Escola · Tem problemas de T.I.? · Descrição dos problemas · Possíveis melhorias · Enviado ao GLPI em. Os títulos podem ser renomeados; a ordem não (o script usa a posição).

## Instalação

1. Com a conta Google institucional, abra <https://script.google.com> e crie um **Novo projeto**
   chamado "Preventiva URE".
2. Em **Configurações do projeto**, deixe o fuso horário em **(GMT-03:00) São Paulo**.
3. Cole o conteúdo de `Code.gs` no arquivo `Code.gs` do projeto.
4. Selecione a função `configurar` e clique em **Executar**. Autorize o acesso às planilhas.
5. O registro de execução mostra o **link da planilha** e o **segredo** (guarde o segredo para o
   Tralla).
6. **Implantar → Nova implantação → App da Web**:
   - **Executar como:** eu
   - **Quem pode acessar:** qualquer pessoa
7. Copie a URL que termina em `/exec` e coloque em `SCRIPT_URL` no `docs/preventiva/index.html`.
8. No GitHub, ative o Pages do repositório (**Settings → Pages → Branch `main`, pasta `/docs`**).
   O formulário fica em `https://<organização>.github.io/<repositório>/preventiva/`.

Ao alterar o `Code.gs`, publique de novo em **Implantar → Gerenciar implantações → Editar → Nova
versão**, mantendo a mesma URL.

## Prazo

Na planilha, aba **Configuração**:

- **B1 — Aceitar respostas até:** a data do último dia (vale até 23:59). Em branco = sem prazo.
- **B2 — Mensagem depois do prazo:** o texto que aparece quando o formulário fecha.

A mudança vale em até 1 minuto. Também dá para mudar pelo editor com `definirPrazo('2026-11-30')`
ou, depois, pelo próprio Tralla.

## Escolas

Enquanto o Tralla ainda não manda a lista, preencha a aba **Escolas** com o **ID da entidade no
GLPI** (coluna A) e o **nome** (coluna B). Quando o Tralla sincronizar, ele substitui a lista
inteira.

## Chamadas do GLPI (com o segredo)

`POST` na URL `/exec`, corpo JSON como texto:

| `action`    | Envia                                   | Responde                                   |
|-------------|-----------------------------------------|--------------------------------------------|
| `ping`      | `secret`                                | `{ ok }`                                   |
| `escolas`   | `secret`, `escolas: [{id, nome}]`       | `{ ok, total }` (substitui a lista)        |
| `prazo`     | `secret`, `ate: 'AAAA-MM-DD'` ou `''`   | `{ ok, prazo }`                            |
| `respostas` | `secret`, `limit` (até 50)              | `{ ok, respostas: [...] }` ainda não lidas |
| `ack`       | `secret`, `ids: [...]`                  | `{ ok, marcadas }`                         |

A página usa sem segredo apenas `GET ?action=escolas` (prazo, escolas e listas) e
`POST action=enviar`.

## Proteções

- Campo escondido contra robôs e bloqueio de envio repetido idêntico por 2 minutos.
- Textos que começam com `=`, `+`, `-` ou `@` são gravados como texto (não viram fórmula).
- O segredo fica nas propriedades do script, nunca no código.
