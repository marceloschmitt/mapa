<?php
declare(strict_types=1);

/*
 * Tela "Eficácia dos alarmes": lista de nova abordagem, escopo de professor, CSV
 * e a página renderizada por perfil (avaliação com contatos fictícios), num
 * SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/acompanhamento_tela_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Controllers\AcompanhamentoController;
use Mapa\Core\Auth;
use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\AcompanhamentoRepository;
use Mapa\Models\ContatoRepository;

$dbPath = tempnam(sys_get_temp_dir(), 'mapa_test_');
register_shutdown_function(static function () use ($dbPath): void {
    @unlink($dbPath);
});

$cache = new ReflectionProperty(Env::class, 'cache');
$cache->setAccessible(true);
$cache->setValue(null, ['DB_PATH' => $dbPath, 'EMAIL_SEND' => 'false']);

$falhas = 0;
$total = 0;

function verificar(string $nome, $esperado, $obtido): void
{
    global $falhas, $total;
    $total++;
    if ($esperado === $obtido) {
        echo "ok   {$nome}\n";
        return;
    }
    $falhas++;
    echo "FALHA {$nome}\n      esperado: " . var_export($esperado, true)
        . "\n      obtido:   " . var_export($obtido, true) . "\n";
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO cursos (id, nome_curso, curso_nivel) VALUES
    (1, 'Curso A', 'S'), (2, 'Curso B', 'S'), (3, 'Integrado C', 'N')");
for ($i = 1; $i <= 10; $i++) {
    $pdo->exec("INSERT INTO alunos (id, login, matricula, nome) VALUES ({$i}, 'a{$i}', 'M{$i}', 'Aluno {$i}')");
}
$pdo->exec("UPDATE alunos SET nome_social = 'Social 7' WHERE id = 7");
$pdo->exec("INSERT OR IGNORE INTO usuarios (id, username, nome, perfil) VALUES (1, 'admin', 'Admin', 'administrador')");
$pdo->exec("INSERT INTO coletas (id, status) VALUES (1, 'completa')");
$pdo->exec("INSERT INTO acompanhamento_execucoes (id, coleta_id, periodo, data_inicio, data_corte, total_alunos)
            VALUES (1, 1, '2026/2', '2026-08-03', '2026-11-01', 1500)");

$ins = $pdo->prepare(
    'INSERT INTO acompanhamento_alunos
        (execucao_id, aluno_id, curso_id, nivel, ingressante, auxilio, saiu, risco_tipo, risco_data, risco_semana,
         retorno, total_contatos, primeiro_contato, dias_ate_contato, ultimo_contato, ultimo_canal, ultimo_falou,
         ultimo_motivo, desde_contato_aulas, desde_contato_faltas)
     VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ([
    // aluno, curso, nível, ingr, aux, saiu, risco, data, semana, retorno, contatos, 1º, dias, último, canal, falou, motivo, aulas, faltas
    [1, 1, 'S', 1, 0, 0, 'faltas_4dias', '2026-09-01', 5, 'nao_voltou', 1, '2026-09-02', 1, '2026-09-02', 'email_automatico', null, null, 20, 20],
    [2, 1, 'S', 0, 1, 0, 'faltas_3semanas', '2026-09-10', 6, 'voltou', 2, '2026-09-11', 1, '2026-10-01', 'telefone', 1, 'trabalho', 20, 8],
    [3, 2, 'S', 0, 0, 0, 'faltas_3semanas', '2026-09-25', 8, 'parcial', 1, '2026-10-25', 30, '2026-10-25', 'email', null, null, 3, 3],
    [4, 2, 'S', 0, 0, 0, 'percentual_baixo', '2026-09-10', 6, 'voltou', 1, '2026-09-20', 10, '2026-09-20', 'email', null, null, 10, 1],
    [5, 2, 'S', 0, 0, 0, 'faltas_4dias', '2026-09-14', 7, 'nao_voltou', 1, '2026-09-15', 1, '2026-09-15', 'email', null, null, 2, 2],
    [6, 1, 'S', 0, 0, 1, null, null, null, null, 1, '2026-09-15', null, '2026-09-15', 'email', null, null, 10, 10],
    [7, 1, 'S', 0, 0, 0, null, null, null, null, 1, '2026-09-05', null, '2026-09-05', 'whatsapp', 0, null, 10, 6],
    [8, 2, 'S', 0, 0, 0, 'faltas_3semanas', '2026-08-25', 4, 'voltou', 1, '2026-09-01', 7, '2026-09-01', 'email', null, null, 10, 9],
    [9, 3, 'N', 0, 0, 0, 'faltas_3semanas', '2026-09-01', 5, 'voltou', 0, null, null, null, null, null, null, 0, 0],
    [10, 1, 'S', 0, 0, 0, 'percentual_baixo', '2026-10-25', 12, null, 0, null, null, null, null, null, null, 0, 0],
] as $linha) {
    $ins->execute($linha);
}

$contatos = new ContatoRepository($pdo);
foreach ([
    [1, 1, '2026-09-02', 'email_automatico'],
    [2, 1, '2026-09-11', 'email_automatico'],
    [2, 1, '2026-09-15', 'telefone'],
    [3, 2, '2026-10-25', 'email'],
    [4, 2, '2026-09-20', 'email'],
    [5, 2, '2026-09-15', 'email'],
    [7, 1, '2026-09-05', 'whatsapp'],
    [8, 2, '2026-09-01', 'email'],
    [8, 2, '2026-11-05', 'telefone'],  // depois da análise: sai da lista na hora
] as [$aluno, $curso, $data, $canal]) {
    $contatos->registrar([
        'aluno_id' => $aluno, 'curso_id' => $curso, 'data_contato' => $data, 'canal' => $canal,
        'origem' => $canal === 'email_automatico' ? 'automatico' : 'manual',
    ]);
}

$pdo->exec("INSERT INTO frequencia_disciplina (coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina, id_turma) VALUES
    (1, 1, 1, 'MAT1', 'Matemática', 10),
    (1, 2, 1, 'MAT1', 'Matemática', 11),
    (1, 7, 1, 'FIS1', 'Física', 10)");

// Séries da avaliação (contatos fictícios): 12 contatados que sobem de 25% para 100% de presença
// e 12 da comparação que ficam em 25%. Contatados 1-6 no curso A, por e-mail automático; 7-12 no
// curso B, por ligação.
$pdo->exec("INSERT INTO efeito_contatos_execucoes (id, coleta_id, janela_dias, min_aulas) VALUES (1, 1, 14, 3)");
$insSerie = $pdo->prepare(
    "INSERT INTO efeito_contatos_series
        (execucao_id, aluno_id, curso_id, grupo, referencia, data_referencia, total_contatos, canais,
         alarme_tipo, dias_ate_contato, ingressante, auxilio)
     VALUES (1, ?, ?, ?, 'primeiro', '2026-09-15', ?, ?, 'faltas_3semanas', ?, 0, 0)"
);
$insSemana = $pdo->prepare('INSERT INTO efeito_contatos_semanas (serie_id, semana, aulas, faltas) VALUES (?, ?, ?, ?)');
for ($i = 1; $i <= 24; $i++) {
    $contatado = $i <= 12;
    $insSerie->execute([
        100 + $i, $i <= 6 || ($i > 12 && $i <= 18) ? 1 : 2, $contatado ? 'contatado' : 'comparacao',
        $contatado ? 1 : 0, $contatado ? ($i <= 6 ? 'email_automatico' : 'telefone') : '', $contatado ? 2 : null,
    ]);
    $serieId = (int)$pdo->lastInsertId();
    foreach ([-2, -1] as $semana) {
        $insSemana->execute([$serieId, $semana, 4, 3]);                  // 25% de presença
    }
    foreach ([1, 2] as $semana) {
        $insSemana->execute([$serieId, $semana, 4, $contatado ? 0 : 3]); // 100% ou 25%
    }
}

$repo = new AcompanhamentoRepository($pdo);

echo "== Nova abordagem\n";
$ids = static fn(array $r): array => array_map(static fn(array $l): int => (int)$l['aluno_id'], $r['linhas']);
$lista = $repo->novaAbordagem(1, '2026-11-01');
verificar('quem entra, na ordem (prioridade alta, ingressante primeiro)', [1, 7, 2], $ids($lista));
verificar('contadores', [3, ['alta' => 2, 'media' => 1]], [$lista['total'], $lista['por_prioridade']]);
verificar('por situação', ['percentual_baixo' => 0, 'faltas_3semanas' => 1, 'faltas_4dias' => 1, 'sem_risco' => 1],
    $lista['por_situacao']);
verificar('presença desde o contato e nome social', [0.0, 'Social 7'], [
    (float)$lista['linhas'][0]['presenca_desde'], $lista['linhas'][1]['nome_social'],
]);
verificar('filtro de prioridade média', [2], $ids($repo->novaAbordagem(1, '2026-11-01', ['prioridade' => 'media'])));
verificar('filtro de 2 contatos', [2], $ids($repo->novaAbordagem(1, '2026-11-01', ['contatos' => 2])));
$semRisco = $repo->novaAbordagem(1, '2026-11-01', ['situacao' => 'sem_risco']);
verificar('situação sem risco; contadores sem o filtro de situação', [[7], 3], [
    $ids($semRisco), array_sum($semRisco['por_prioridade']),
]);
$pagina2 = $repo->novaAbordagem(1, '2026-11-01', [], 2, 2);
verificar('paginação', [[2], 3], [$ids($pagina2), $pagina2['total']]);
verificar('curso B fica vazio (recente, voltou, poucas aulas, contato novo)', 0,
    $repo->novaAbordagem(1, '2026-11-01', ['curso_ids' => [2]])['total']);
$contatos->registrar(['aluno_id' => 2, 'curso_id' => 1, 'data_contato' => '2026-11-10', 'canal' => 'presencial']);
verificar('novo contato tira o aluno da lista', [1, 7], $ids($repo->novaAbordagem(1, '2026-11-01')));

echo "== Escopo do professor\n";
$prof = ['professor' => ['coleta_id' => 1, 'codigos' => ['MAT1'], 'turmas' => [10]]];
verificar('disciplina e turma dele', [1], $ids($repo->novaAbordagem(1, '2026-11-01', $prof)));
verificar('sem turmas: a disciplina toda; sem disciplinas: ninguém', [true, false], [
    $repo->alunoNoEscopo(1, 2, 1, ['professor' => ['coleta_id' => 1, 'codigos' => ['MAT1'], 'turmas' => []]]),
    $repo->alunoNoEscopo(1, 1, 1, ['professor' => ['coleta_id' => 1, 'codigos' => []]]),
]);
verificar('aluno no escopo', [true, false, false], [
    $repo->alunoNoEscopo(1, 1, 1, $prof),
    $repo->alunoNoEscopo(1, 2, 1, $prof),
    $repo->alunoNoEscopo(1, 1, 1, ['curso_ids' => [2]]),
]);

echo "== Cobertura (topo)\n";
// Com alarme crítico: 1, 2, 3, 4, 5, 8, 9, 10. E-mail automático: 1 e 2. Equipe: 2, 3, 4, 5, 8.
$cobertura = $repo->cobertura(1);
verificar('cobertura (equipe 2 ou mais: alunos 2 e 8)', [8, 2, 5, 2, 2], [
    $cobertura['com_alarme'], $cobertura['email_automatico'], $cobertura['equipe'],
    $cobertura['sem_contato'], $cobertura['equipe_dois'],
]);
// Dias do alarme ao 1º contato da equipe: 2 -> 5, 3 -> 30, 4 -> 10, 5 -> 1, 8 -> 7.
verificar('mediana do alarme ao contato da equipe', 7.0, $cobertura['dias_mediana']);
verificar('cobertura com filtro de curso', 4, $repo->cobertura(1, ['curso_ids' => [2]])['equipe']);

echo "== CSV\n";
$csv = AcompanhamentoController::linhasCsv($repo->novaAbordagem(1, '2026-11-01', [], 1, null)['linhas']);
verificar('cabeçalho e primeira linha', [13, ['Curso A', 'Aluno 1', 'M1', 'Dias seguidos de falta', 'alta', 'sim', 'não', '1', '02/09/2026', 'E-mail automático', '', '', '0']], [
    count($csv[0]), $csv[1],
]);

echo "== Página por perfil\n";
set_error_handler(static function (int $nivel, string $mensagem, string $arquivo, int $linha): bool {
    throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
});
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$pagina = static function (array $usuario, array $get = [], array $cursos = [], array $disciplinas = [], array $turmas = []): string {
    Auth::login($usuario, $cursos, $disciplinas, $turmas);
    $_GET = $get;
    $_SERVER['REQUEST_URI'] = '/acompanhamento';
    ob_start();
    try {
        (new AcompanhamentoController())->index();
    } finally {
        $html = (string)ob_get_clean();
    }

    return $html;
};
$admin = $pagina(['id' => 1, 'username' => 'admin', 'nome' => 'Admin', 'perfil' => 'administrador']);
verificar('admin: as duas análises, curva, recortes, lista e exportação', [true, true, true, true, true, true, true], [
    str_contains($admin, '1. Contato da equipe: presença antes e depois'),
    str_contains($admin, '2. Contato da equipe: voltaram a frequentar'),
    str_contains($admin, 'chartPresenca'),
    str_contains($admin, 'Por recorte'),
    str_contains($admin, 'Social 7'),
    str_contains($admin, '/acompanhamento/exportar'),
    str_contains($admin, 'Gerar análise'),
]);
verificar('admin: topo pelos contatos registrados e efeito da equipe (+75 p.p.)', [true, true, true, true], [
    (bool)preg_match('/Alunos com alarme crítico<\/div>\s*<div class="fs-3 fw-semibold">8</', $admin),
    str_contains($admin, '2 sem nenhum contato'),
    (bool)preg_match('/Receberam contato da equipe<\/div>\s*<div class="fs-3 fw-semibold">5</', $admin),
    str_contains($admin, 'Efeito do contato da equipe: +75,0 p.p. de presença'),
]);
verificar('sem "antes do MAPA"', false, str_contains($admin, 'antes do MAPA'));
verificar('seção do 2º contato, ainda sem alunos', [true, true], [
    str_contains($admin, '3. Vale insistir? O 2º contato da equipe'),
    str_contains($admin, 'Nenhum aluno com 2º contato da equipe neste recorte.'),
]);
$coord = $pagina(['id' => 1, 'username' => 'coord', 'nome' => 'Coord', 'perfil' => 'coordenador_curso'], [], [2]);
verificar('coordenador do curso B: lista vazia, sem exportar', [true, false, false], [
    str_contains($coord, 'Nenhum aluno nesta lista'),
    str_contains($coord, 'Aluno 1'),
    str_contains($coord, '/acompanhamento/exportar'),
]);
$professor = $pagina(
    ['id' => 1, 'username' => 'prof', 'nome' => 'Prof', 'perfil' => 'professor'],
    [],
    [],
    ['MAT1'],
    [10]
);
verificar('professor: só os alunos dele, também na avaliação', [true, false, true], [
    str_contains($professor, 'Aluno 1'),
    str_contains($professor, 'Social 7'),
    str_contains($professor, 'Nenhum contato da equipe registrado com alunos em alarme crítico'),
]);
$filtrada = $pagina(
    ['id' => 1, 'username' => 'admin', 'nome' => 'Admin', 'perfil' => 'administrador'],
    ['situacao' => 'sem_risco', 'por_pagina' => '50', 'auxilio' => '0', 'ingressante' => '0']
);
verificar('filtros na URL', [true, false], [
    str_contains($filtrada, 'Social 7'),
    str_contains($filtrada, 'Aluno 1<'),
]);
$cursoB = $pagina(['id' => 1, 'username' => 'admin', 'nome' => 'Admin', 'perfil' => 'administrador'], ['curso' => '2']);
verificar('curso B: só a ligação no recorte de contato', [true, false], [
    str_contains($cursoB, 'Ligação telefônica'),
    (bool)preg_match('/<th scope="row" class="fw-normal">E-mail automático/', $cursoB),
]);
$pdo->exec('DELETE FROM acompanhamento_execucoes');
$vazia = $pagina(['id' => 1, 'username' => 'admin', 'nome' => 'Admin', 'perfil' => 'administrador']);
verificar('sem análise', true, str_contains($vazia, 'Nenhuma análise gerada ainda'));
restore_error_handler();

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
