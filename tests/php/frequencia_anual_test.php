<?php
declare(strict_types=1);

/*
 * Frequência anual dos integrados nas telas (ingressantes e painel), num SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/frequencia_anual_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Core\Auth;
use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\AnalyticsRepository;

$dbPath = tempnam(sys_get_temp_dir(), 'mapa_test_');
register_shutdown_function(static function () use ($dbPath): void {
    @unlink($dbPath);
});

// Não lê o .env: o banco e o envio de e-mail ficam isolados do ambiente real.
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

$pdo->exec("INSERT INTO cursos (id, nome_curso, curso_nivel) VALUES (1, 'Integrado', 'N'), (2, 'Superior', 'G')");
$pdo->exec("INSERT INTO coletas (id) VALUES (1)");

// Todos abaixo de 75%, exceto o aluno 5.
$alunos = [
    // id, curso, ingresso, percentual, frequencia_desde
    [1, 1, '2026/0', 60.0, '2026-02-15'],
    [2, 1, '2025/0', 60.0, '2026-02-15'],
    [3, 2, '2026/2', 50.0, null],
    [4, 2, '2026/1', 50.0, null],
    [5, 1, '2026/0', 90.0, '2026-02-15'],
];
$insAluno = $pdo->prepare("INSERT INTO alunos (id, login, matricula, nome) VALUES (?, ?, ?, ?)");
$insIngresso = $pdo->prepare('INSERT INTO aluno_cursos (aluno_id, curso_id, ano_semestre_ingresso) VALUES (?, ?, ?)');
$insCurso = $pdo->prepare(
    'INSERT INTO frequencia_curso (coleta_id, aluno_id, curso_id, percentual_frequencia, frequencia_desde)
     VALUES (1, ?, ?, ?, ?)'
);
$insDisc = $pdo->prepare(
    "INSERT INTO frequencia_disciplina (coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina, percentual_frequencia)
     VALUES (1, ?, ?, 'POA-X01', 'Disciplina X', ?)"
);
foreach ($alunos as [$id, $curso, $ingresso, $percentual, $desde]) {
    $insAluno->execute([$id, "a{$id}", "m{$id}", "Aluno {$id}"]);
    $insIngresso->execute([$id, $curso, $ingresso]);
    $insCurso->execute([$id, $curso, $percentual, $desde]);
    $insDisc->execute([$id, $curso, $percentual]);
}

$repo = new AnalyticsRepository();

echo "== Período de ingresso dos integrados\n";
verificar('2026/2 -> 2026/0', '2026/0', AnalyticsRepository::periodoIngressoAnual('2026/2'));
verificar('2026/1 -> 2026/0', '2026/0', AnalyticsRepository::periodoIngressoAnual('2026/1'));
verificar('período inválido', null, AnalyticsRepository::periodoIngressoAnual('2026'));

echo "== Ingressantes\n";
$linhas = $repo->ingressantesComProblema(1, '2026/2');
$porAluno = [];
foreach ($linhas as $linha) {
    $porAluno[(int)$linha['aluno_id']] = $linha['frequencia_desde'];
}
ksort($porAluno);
verificar(
    'ingressantes do semestre e integrados do ano, abaixo do limite',
    [1 => '2026-02-15', 3 => null],
    $porAluno
);

echo "== Painel\n";
verificar('início do ano letivo na coleta', '2026-02-15', $repo->frequenciaAnualDesde(1));
$porCurso = $repo->frequenciaPorCurso(1);
sort($porCurso['labels']);
verificar('asterisco só no integrado', ['* Integrado', 'Superior'], $porCurso['labels']);
$criticas = [];
foreach ($repo->disciplinasCriticas(1, null) as $row) {
    $criticas[(string)$row['nome_curso']] = $row['frequencia_desde'];
}
ksort($criticas);
verificar(
    'disciplinas críticas indicam o curso anual',
    ['Integrado' => '2026-02-15', 'Superior' => null],
    $criticas
);

echo "== Frequência corrente\n";
// Aluno 6 ainda sem aulas registradas: só disciplina, sem frequencia_curso.
$insAluno->execute([6, 'a6', 'm6', 'Aluno 6']);
$insDisc->execute([6, 2, null]);
$corrente = [];
foreach ($repo->linhasFrequenciaCorrente(1) as $linha) {
    $corrente[(int)$linha['aluno_id']] = [$linha['frequencia'], $linha['frequencia_desde']];
}
ksort($corrente);
verificar('lê a coleta: integrados com o ano, sem aulas com frequência nula', [
    1 => [60.0, '2026-02-15'],
    2 => [60.0, '2026-02-15'],
    3 => [50.0, null],
    4 => [50.0, null],
    5 => [90.0, '2026-02-15'],
    6 => [null, null],
], $corrente);
verificar('filtro de curso', [3, 4, 6], array_map(
    static fn(array $l): int => (int)$l['aluno_id'],
    $repo->linhasFrequenciaCorrente(1, [2])
));
$disciplinas = $repo->disciplinasFrequenciaCorrente(1, [1, 6]);
verificar('disciplinas por aluno e curso', ['1-1', '6-2'], array_keys($disciplinas));
verificar('percentual da disciplina', 60.0, $disciplinas['1-1'][0]['frequencia']);

echo "== Auxílio estudantil\n";
// Mesmo login em dois cursos: a marcação é por pessoa.
$insAluno->execute([7, 'a1', 'm7-outro', 'Aluno 1 outro curso']);
$insIngresso->execute([7, 2, '2026/2']);
$insCurso->execute([7, 2, 55.0, null]);
$insDisc->execute([7, 2, 55.0]);

verificar('sem marcação no início', false, $repo->temAuxilio('a1'));
verificar('alternar marca', true, $repo->alternarAuxilio('a1', 1));
verificar('tem auxílio após marcar', true, $repo->temAuxilio('a1'));
$mapa = $repo->loginsComAuxilio();
verificar('mapa contém o login', true, isset($mapa['a1']));

$filtrados = $repo->linhasFrequenciaCorrente(1, null, '', true);
$loginsFiltrados = [];
foreach ($filtrados as $linha) {
    $loginsFiltrados[] = (string)$linha['login'];
}
sort($loginsFiltrados);
verificar(
    'filtro só auxílio: duas linhas do mesmo login',
    ['a1', 'a1'],
    $loginsFiltrados
);
$idsFiltrados = array_map(static fn(array $l): int => (int)$l['aluno_id'], $filtrados);
sort($idsFiltrados);
verificar('filtro só auxílio: aluno_ids 1 e 7', [1, 7], $idsFiltrados);

verificar('alternar desmarca', false, $repo->alternarAuxilio('a1', 1));
verificar('filtro vazio após desmarcar', [], $repo->linhasFrequenciaCorrente(1, null, '', true));

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
verificar('admin pode marcar', true, (static function (): bool {
    Auth::login(['id' => 1, 'username' => 'admin', 'perfil' => 'administrador', 'nome' => 'Admin']);
    return Auth::canMarcarAuxilioEstudantil();
})());
verificar('geral pode marcar', true, (static function (): bool {
    Auth::login(['id' => 2, 'username' => 'geral', 'perfil' => 'geral', 'nome' => 'Geral']);
    return Auth::canMarcarAuxilioEstudantil();
})());
verificar('professor não marca', false, (static function (): bool {
    Auth::login(['id' => 3, 'username' => 'prof', 'perfil' => 'professor', 'nome' => 'Prof']);
    return Auth::canMarcarAuxilioEstudantil();
})());

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
