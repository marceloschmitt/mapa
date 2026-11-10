<?php
declare(strict_types=1);

/*
 * Funil do acompanhamento, referência histórica e comparação com margem, num SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/acompanhamento_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\AcompanhamentoRepository;

$dbPath = tempnam(sys_get_temp_dir(), 'mapa_test_');
register_shutdown_function(static function () use ($dbPath): void {
    @unlink($dbPath);
});

// Não lê o .env: o banco fica isolado do ambiente real.
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

echo "== Comparação com margem de 95%\n";
$c = AcompanhamentoRepository::comparar(100, 40, 300, 84);
verificar('proporções e diferença', [40.0, 28.0, 12.0], [$c['p1'], $c['p2'], round($c['diferenca'], 1)]);
// erro = sqrt(0,4·0,6/100 + 0,28·0,72/300) = 0,0554 → margem 10,9 p.p.: 12 ± 10,9 fica acima de zero.
verificar('margem e selo melhor', [10.9, 'melhor'], [round($c['margem'], 1), $c['selo']]);
verificar('dentro da margem é incerto', 'incerto', AcompanhamentoRepository::comparar(100, 30, 300, 84)['selo']);
verificar('abaixo é pior', 'pior', AcompanhamentoRepository::comparar(100, 10, 300, 84)['selo']);
verificar('poucos alunos não compara', ['poucos', null], [
    AcompanhamentoRepository::comparar(10, 8, 300, 84)['selo'],
    AcompanhamentoRepository::comparar(10, 8, 300, 84)['diferenca'],
]);

$pdo = Database::connection();
$pdo->exec("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A'), (2, 'Curso B')");
for ($i = 1; $i <= 8; $i++) {
    $pdo->exec("INSERT INTO alunos (id, login, matricula, nome) VALUES ({$i}, 'a{$i}', '{$i}', 'Aluno {$i}')");
}
$pdo->exec("INSERT INTO acompanhamento_execucoes (id, periodo, data_inicio, data_corte, total_alunos)
            VALUES (1, '2026/2', '2026-08-03', '2026-11-01', 1500)");
$ins = $pdo->prepare(
    'INSERT INTO acompanhamento_alunos
        (execucao_id, aluno_id, curso_id, ingressante, auxilio, risco_tipo, risco_semana, retorno,
         primeiro_contato, dias_ate_contato)
     VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ([
    [1, 1, 1, 0, 'faltas_3semanas', 3, 'voltou', '2026-08-20', 2],
    [2, 1, 0, 1, 'faltas_3semanas', 4, 'parcial', '2026-08-28', 6],
    [3, 1, 0, 0, 'faltas_3semanas', 5, 'nao_voltou', null, null],
    [4, 2, 0, 0, 'faltas_4dias', 2, 'voltou', '2026-08-10', -3],  // contatado antes do risco
    [5, 2, 0, 0, 'percentual_baixo', 6, null, '2026-10-25', 1],    // retorno ainda não medido
    [6, 1, 0, 0, null, null, null, '2026-09-01', null],            // só contatado, sem risco
    [7, 1, 0, 0, 'percentual_baixo', 12, 'voltou', null, null],
] as $linha) {
    $ins->execute($linha);
}

$repo = new AcompanhamentoRepository($pdo);
echo "== Funil\n";
$funil = $repo->funil(1);
verificar('em risco, contatados e mediana dos dias (só do risco em diante)', [6, 4, 2.0], [
    $funil['total']['em_risco'], $funil['total']['contatados'], $funil['total']['dias_mediana'],
]);
verificar('retorno medido dos contatados', ['medidos' => 3, 'voltou' => 2, 'parcial' => 1, 'nao_voltou' => 0],
    $funil['total']['contatados_medidos']);
verificar('retorno medido dos não contatados', ['medidos' => 2, 'voltou' => 1, 'parcial' => 0, 'nao_voltou' => 1],
    $funil['total']['nao_contatados_medidos']);
verificar('por situação (mediana de 2 e 6 dias)', [3, 2, 4.0], [
    $funil['por_tipo']['faltas_3semanas']['em_risco'],
    $funil['por_tipo']['faltas_3semanas']['contatados'],
    $funil['por_tipo']['faltas_3semanas']['dias_mediana'],
]);
$filtrado = $repo->funil(1, ['curso_ids' => [1], 'semana_max' => 9, 'ingressante' => true]);
verificar('filtros de curso, semana e ingressante', [1, 1], [$filtrado['total']['em_risco'], $filtrado['total']['contatados']]);
verificar('filtro de auxílio', 1, $repo->funil(1, ['auxilio' => true])['total']['em_risco']);
verificar('curso sem alunos', 0, $repo->funil(1, ['curso_ids' => []])['total']['em_risco']);

echo "== Referência histórica\n";
$insRef = $pdo->prepare(
    'INSERT INTO referencia_historica_alunos (periodo, com_mapa, curso_id, nome_curso, ingressante, risco_tipo, risco_semana, retorno)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ([
    ['2025/2', 0, 1, 'Curso A', 1, 'faltas_3semanas', 3, 'voltou'],
    ['2025/2', 0, 1, 'Curso A', 0, 'faltas_3semanas', 4, 'parcial'],
    ['2026/1', 0, 2, 'Curso B', 0, 'faltas_3semanas', 5, 'nao_voltou'],
    ['2026/1', 0, 2, 'Curso B', 0, 'faltas_4dias', 2, 'voltou'],
    ['2026/1', 0, 2, 'Curso B', 0, 'faltas_4dias', 14, null],
    ['2026/1', 0, 1, 'Curso A', 0, null, null, null],
    ['2026/2', 1, 1, 'Curso A', 0, 'faltas_3semanas', 3, 'voltou'],  // com MAPA: fora da referência
] as $linha) {
    $insRef->execute($linha);
}
$ref = $repo->referencia();
verificar('períodos sem MAPA', ['2025/2', '2026/1'], $ref['periodos']);
verificar('total em risco e retorno', [5, 4, 2, 1, 1], [
    $ref['total']['em_risco'], $ref['total']['medidos'], $ref['total']['voltou'],
    $ref['total']['parcial'], $ref['total']['nao_voltou'],
]);
verificar('por situação e filtro de curso', [3, 1], [
    $ref['por_tipo']['faltas_3semanas']['em_risco'],
    $repo->referencia(['curso_ids' => [2], 'tipo' => 'faltas_4dias', 'semana_max' => 9])['total']['em_risco'],
]);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
