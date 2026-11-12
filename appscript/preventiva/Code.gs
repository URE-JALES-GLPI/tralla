/**
 * [URE] Tralla — Formulário de Preventiva (Google Apps Script).
 *
 * A escola responde pela página docs/preventiva/index.html (GitHub Pages), que fala com este
 * script. As respostas ficam na planilha "Preventiva - respostas", que a equipe pode abrir.
 *
 * O GLPI fica em rede interna, então o Google nunca chama o GLPI: é o GLPI (Tralla) que chama
 * este script para enviar a lista de escolas (entidades) e buscar as respostas novas.
 * O segredo dessas chamadas fica nas propriedades do script, nunca neste arquivo.
 */
var SHEET_NAME = 'Preventiva - respostas';
var TAB_ANSWERS = 'Respostas';
var TAB_SCHOOLS = 'Escolas';
var TAB_CONFIG = 'Configuração';
var TIMEZONE = 'America/Sao_Paulo';
var ANSWER_HEADERS = [
  'ID', 'Recebido em', 'Nome completo', 'Função', 'ID da escola (GLPI)', 'Escola',
  'Tem problemas de T.I.?', 'Tipos de problema', 'Descrição dos problemas', 'Possíveis melhorias',
  'Enviado ao GLPI em'
];
var COL_SYNCED = ANSWER_HEADERS.length; // última coluna
var MAX_LIST = 50;

/** Opções da página (a página mostra estas mesmas listas). */
var FUNCTIONS = [
  'Diretor(a) de Escola', 'Vice-diretor(a)', 'Coordenador(a) de Gestão Pedagógica (CGP)',
  'Gerente de Organização Escolar (GOE)', 'Agente de Organização Escolar (AOE)',
  'Secretário(a) de Escola', 'Professor(a)', 'Outra'
];
var PROBLEMS = [
  'Internet lenta ou caindo',
  'Wi-Fi sem sinal ou fraco em alguma sala',
  'Computador lento, travando ou que não liga',
  'Impressora com defeito, sem toner ou atolando papel',
  'Projetor, TV ou lousa digital com problema',
  'Cabo de rede, tomada ou rack danificado',
  'Acesso a sistemas (SED, e-mail institucional, Secretaria Escolar Digital)',
  'Notebooks / Chromebooks dos alunos com defeito',
  'Telefone, câmeras ou som',
  'Equipamento faltando ou precisando de troca',
  'Outro'
];

// ---------------------------------------------------------------- instalação

/** Rode uma vez pelo editor: cria a planilha e o segredo e mostra os dois no registro. */
function configurar() {
  var props = PropertiesService.getScriptProperties();
  var book = spreadsheet_();
  if (!props.getProperty('SECRET')) {
    props.setProperty('SECRET', (Utilities.getUuid() + Utilities.getUuid()).replace(/-/g, ''));
  }
  Logger.log('Planilha: ' + book.getUrl());
  Logger.log('Segredo para colar no Tralla: ' + props.getProperty('SECRET'));
}

function spreadsheet_() {
  var props = PropertiesService.getScriptProperties();
  var id = props.getProperty('SHEET_ID');
  var book = null;
  if (id) {
    try { book = SpreadsheetApp.openById(id); } catch (e) { book = null; }
  }
  if (!book) {
    book = SpreadsheetApp.create(SHEET_NAME);
    props.setProperty('SHEET_ID', book.getId());
  }
  var answers = book.getSheetByName(TAB_ANSWERS);
  if (!answers) {
    answers = book.getSheets()[0];
    answers.setName(TAB_ANSWERS);
  }
  if (answers.getLastRow() === 0) {
    answers.appendRow(ANSWER_HEADERS);
    answers.setFrozenRows(1);
    answers.getRange(1, 1, 1, ANSWER_HEADERS.length).setFontWeight('bold');
  }
  var schools = book.getSheetByName(TAB_SCHOOLS);
  if (!schools) {
    schools = book.insertSheet(TAB_SCHOOLS);
    schools.appendRow(['ID (GLPI)', 'Nome']);
    schools.setFrozenRows(1);
    schools.getRange(1, 1, 1, 2).setFontWeight('bold');
  }
  var config = book.getSheetByName(TAB_CONFIG);
  if (!config) {
    config = book.insertSheet(TAB_CONFIG);
    config.getRange(1, 1, 3, 2).setValues([
      ['Aceitar respostas até (dia, inclusive)', ''],
      ['Mensagem depois do prazo', 'O prazo para responder este formulário terminou. Obrigado!'],
      ['Deixe a data em branco para aceitar respostas sem prazo.', '']
    ]);
    config.getRange('A1:A2').setFontWeight('bold');
    config.getRange('B1').setNumberFormat('dd/mm/yyyy');
    config.setColumnWidth(1, 300);
    config.setColumnWidth(2, 420);
  }
  return book;
}

/** Prazo da aba Configuração: { aberto, ate: 'dd/mm/aaaa' | '', mensagem }. Vale até 23:59 do dia. */
function deadline_() {
  var cache = CacheService.getScriptCache();
  var cached = cache.get('deadline');
  if (cached) {
    return JSON.parse(cached);
  }
  var sheet = spreadsheet_().getSheetByName(TAB_CONFIG);
  var value = sheet.getRange('B1').getValue();
  var message = String(sheet.getRange('B2').getValue() || '').trim()
    || 'O prazo para responder este formulário terminou. Obrigado!';
  var info = { aberto: true, ate: '', mensagem: message };
  if (value instanceof Date && !isNaN(value.getTime())) {
    var day = Utilities.formatDate(value, TIMEZONE, 'yyyy-MM-dd');
    var today = Utilities.formatDate(new Date(), TIMEZONE, 'yyyy-MM-dd');
    info.ate = Utilities.formatDate(value, TIMEZONE, 'dd/MM/yyyy');
    info.aberto = today <= day;
  }
  cache.put('deadline', JSON.stringify(info), 60);
  return info;
}

/** Pelo editor ou pelo GLPI: definirPrazo('2026-11-30') ou definirPrazo('') para sem prazo. */
function definirPrazo(isoDate) {
  var sheet = spreadsheet_().getSheetByName(TAB_CONFIG);
  var text = String(isoDate || '').trim();
  if (text === '') {
    sheet.getRange('B1').clearContent();
  } else {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
    if (!m) {
      throw new Error('Data inválida. Use AAAA-MM-DD.');
    }
    sheet.getRange('B1').setValue(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  }
  CacheService.getScriptCache().remove('deadline');
  return deadline_();
}

// ---------------------------------------------------------------- web app

/** GET ?action=escolas → lista para a página. Sem segredo: só nomes das escolas. */
function doGet(e) {
  var action = String(e && e.parameter && e.parameter.action || '');
  if (action === 'escolas') {
    var prazo = deadline_();
    return json_({ ok: true, prazo: prazo, escolas: prazo.aberto ? schools_() : [], funcoes: FUNCTIONS, problemas: PROBLEMS });
  }
  return json_({ ok: true, servico: 'Preventiva URE Jales' });
}

function doPost(e) {
  try {
    var body = JSON.parse(e.postData.contents);
    // Envio do formulário pela página: público, sem segredo.
    if (body.action === 'enviar') {
      return json_({ ok: true, id: submit_(body.form || {}) });
    }
    if (!secretOk_(body.secret)) {
      return json_({ ok: false, error: 'Segredo inválido.' });
    }
    switch (body.action) {
      case 'ping':
        spreadsheet_();
        return json_({ ok: true });
      case 'prazo': // GLPI define o prazo: 'AAAA-MM-DD' ou '' (sem prazo)
        return json_({ ok: true, prazo: definirPrazo(body.ate) });
      case 'escolas': // GLPI envia a lista completa de entidades: [{id, nome}]
        return json_({ ok: true, total: replaceSchools_(body.escolas) });
      case 'respostas': // GLPI busca as respostas ainda não enviadas
        return json_({ ok: true, respostas: pending_(Number(body.limit) || MAX_LIST) });
      case 'ack': // GLPI confirma que gravou estas respostas
        return json_({ ok: true, marcadas: ack_(body.ids) });
      default:
        return json_({ ok: false, error: 'Ação desconhecida.' });
    }
  } catch (error) {
    return json_({ ok: false, error: String(error && error.message || error) });
  }
}

// ---------------------------------------------------------------- formulário

function submit_(form) {
  // Campo escondido: robôs preenchem, pessoas não.
  if (String(form.site || '') !== '') {
    throw new Error('Envio recusado.');
  }
  var prazo = deadline_();
  if (!prazo.aberto) {
    throw new Error(prazo.mensagem);
  }
  // O formulário pede só a escola (nome e função saíram); as colunas continuam na planilha.
  var name = clean_(form.nome, 150).replace(/\s+/g, ' ');
  var role = clean_(form.funcao, 100);
  var schoolId = String(form.escola_id || '').replace(/\D/g, '');
  var school = schools_().filter(function (s) { return String(s.id) === schoolId; })[0];
  if (!school) {
    throw new Error('Escolha a escola na lista.');
  }
  var hasProblems = form.tem_problemas === 'sim' ? 'Sim' : (form.tem_problemas === 'nao' ? 'Não' : '');
  if (!hasProblems) {
    throw new Error('Responda se existem problemas de T.I. a resolver.');
  }
  var kinds = [];
  var description = '';
  if (hasProblems === 'Sim') {
    kinds = (Array.isArray(form.tipos) ? form.tipos : []).map(String)
      .filter(function (k) { return PROBLEMS.indexOf(k) !== -1; });
    description = clean_(form.descricao, 4000);
    if (description.length < 10) {
      throw new Error('Descreva os problemas (pelo menos algumas palavras).');
    }
  }
  var improvements = clean_(form.melhorias, 4000);

  // Evita envio duplicado (duplo clique / reenvio) da mesma pessoa na mesma escola por 2 minutos.
  var cache = CacheService.getScriptCache();
  var dupKey = 'dup_' + Utilities.base64EncodeWebSafe(Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256, schoolId + '|' + name.toLowerCase() + '|' + description + '|' + improvements));
  if (cache.get(dupKey)) {
    throw new Error('Esta resposta já foi enviada. Obrigado!');
  }

  var lock = LockService.getScriptLock();
  lock.waitLock(15000);
  try {
    var id = Utilities.getUuid();
    spreadsheet_().getSheetByName(TAB_ANSWERS).appendRow([
      id, new Date(), cell_(name), cell_(role), Number(schoolId), cell_(school.nome), hasProblems,
      cell_(kinds.join('; ')), cell_(description), cell_(improvements), ''
    ]);
    cache.put(dupKey, '1', 120);
    return id;
  } finally {
    lock.releaseLock();
  }
}

// ---------------------------------------------------------------- escolas

function schools_() {
  var cache = CacheService.getScriptCache();
  var cached = cache.get('schools');
  if (cached) {
    return JSON.parse(cached);
  }
  var sheet = spreadsheet_().getSheetByName(TAB_SCHOOLS);
  var last = sheet.getLastRow();
  var list = [];
  if (last > 1) {
    sheet.getRange(2, 1, last - 1, 2).getValues().forEach(function (row) {
      var id = Number(row[0]);
      var nome = String(row[1] || '').trim();
      if (id > 0 && nome) {
        list.push({ id: id, nome: nome });
      }
    });
  }
  list.sort(function (a, b) { return a.nome.localeCompare(b.nome, 'pt-BR'); });
  try { cache.put('schools', JSON.stringify(list), 600); } catch (e) { /* lista grande: sem cache */ }
  return list;
}

function replaceSchools_(rows) {
  if (!Array.isArray(rows) || rows.length === 0) {
    throw new Error('Lista de escolas vazia.');
  }
  var values = [];
  var seen = {};
  rows.forEach(function (row) {
    var id = Number(row && row.id);
    var nome = clean_(row && row.nome, 255);
    if (id > 0 && nome && !seen[id]) {
      seen[id] = true;
      values.push([id, cell_(nome)]);
    }
  });
  if (values.length === 0) {
    throw new Error('Nenhuma escola válida na lista.');
  }
  var lock = LockService.getScriptLock();
  lock.waitLock(15000);
  try {
    var sheet = spreadsheet_().getSheetByName(TAB_SCHOOLS);
    if (sheet.getLastRow() > 1) {
      sheet.getRange(2, 1, sheet.getLastRow() - 1, 2).clearContent();
    }
    sheet.getRange(2, 1, values.length, 2).setValues(values);
    CacheService.getScriptCache().remove('schools');
  } finally {
    lock.releaseLock();
  }
  return values.length;
}

// ---------------------------------------------------------------- sincronização com o GLPI

function pending_(limit) {
  var sheet = spreadsheet_().getSheetByName(TAB_ANSWERS);
  var last = sheet.getLastRow();
  if (last < 2) {
    return [];
  }
  var out = [];
  var values = sheet.getRange(2, 1, last - 1, ANSWER_HEADERS.length).getValues();
  for (var i = 0; i < values.length && out.length < Math.min(limit, MAX_LIST); i++) {
    var r = values[i];
    if (r[COL_SYNCED - 1] !== '' || !r[0]) {
      continue;
    }
    out.push({
      id: String(r[0]),
      recebido_em: r[1] instanceof Date ? Utilities.formatDate(r[1], 'America/Sao_Paulo', "yyyy-MM-dd'T'HH:mm:ss") : String(r[1]),
      nome: String(r[2]), funcao: String(r[3]), escola_id: Number(r[4]), escola: String(r[5]),
      tem_problemas: String(r[6]) === 'Sim', tipos: String(r[7]) ? String(r[7]).split('; ') : [],
      descricao: String(r[8]), melhorias: String(r[9])
    });
  }
  return out;
}

function ack_(ids) {
  if (!Array.isArray(ids) || ids.length === 0) {
    return 0;
  }
  var wanted = {};
  ids.forEach(function (id) { wanted[String(id)] = true; });
  var lock = LockService.getScriptLock();
  lock.waitLock(15000);
  try {
    var sheet = spreadsheet_().getSheetByName(TAB_ANSWERS);
    var last = sheet.getLastRow();
    if (last < 2) {
      return 0;
    }
    var idCol = sheet.getRange(2, 1, last - 1, 1).getValues();
    var now = new Date();
    var count = 0;
    idCol.forEach(function (row, i) {
      if (wanted[String(row[0])]) {
        sheet.getRange(i + 2, COL_SYNCED).setValue(now);
        count++;
      }
    });
    return count;
  } finally {
    lock.releaseLock();
  }
}

// ---------------------------------------------------------------- utilidades

function secretOk_(secret) {
  var expected = PropertiesService.getScriptProperties().getProperty('SECRET') || '';
  var given = String(secret || '');
  if (!expected || given.length !== expected.length) {
    return false;
  }
  var diff = 0;
  for (var i = 0; i < expected.length; i++) {
    diff |= expected.charCodeAt(i) ^ given.charCodeAt(i);
  }
  return diff === 0;
}

function clean_(value, max) {
  return String(value == null ? '' : value).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '').trim().slice(0, max);
}

/** Texto que começa com = + - @ viraria fórmula na planilha: grava como texto. */
function cell_(text) {
  return /^[=+\-@]/.test(text) ? "'" + text : text;
}

function json_(data) {
  return ContentService.createTextOutput(JSON.stringify(data)).setMimeType(ContentService.MimeType.JSON);
}
