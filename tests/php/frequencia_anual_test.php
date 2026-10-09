<?php
declare(strict_types=1);

/*
 * Frequência anual dos integrados nas telas (ingressantes e painel), num SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/frequencia_anual_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

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
verificar('rótulo (anual) só no integrado', ['Integrado (anual)', 'Superior'], $porCurso['labels']);
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

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
