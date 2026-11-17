<?php
declare(strict_types=1);

/*
 * Avaliação dos contatos (tela Eficácia dos alarmes) num SQLite temporário, com
 * contatos fictícios: presença antes × depois, "voltou a 75%", comparação,
 * recortes, filtros e curva semanal.
 * Rodar na raiz do projeto: php tests/php/efeito_contatos_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\EfeitoContatosRepository;

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

/** @param array<string, mixed> $grupo */
function numeros(array $grupo): array
{
    return [
        'analisados' => $grupo['analisados'],
        'antes' => round((float)$grupo['presenca_antes'], 2),
        'depois' => round((float)$grupo['presenca_depois'], 2),
        'variacao' => round((float)$grupo['variacao'], 2),
        'melhoraram' => $grupo['melhoraram'],
        'iguais' => $grupo['iguais'],
        'pioraram' => $grupo['pioraram'],
        'voltou' => [$grupo['voltaram'], $grupo['abaixo_antes']],
    ];
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A'), (2, 'Curso B')");
$pdo->exec("INSERT INTO coletas (id, status) VALUES (1, 'completa')");
$pdo->exec("INSERT INTO efeito_contatos_execucoes (id, janela_dias, min_aulas) VALUES (1, 14, 2), (2, 14, 2)");

$insSerie = $pdo->prepare(
    "INSERT INTO efeito_contatos_series
        (execucao_id, aluno_id, curso_id, grupo, referencia, data_referencia, total_contatos, canais,
         alarme_tipo, dias_ate_contato, ingressante, auxilio)
     VALUES (?, ?, ?, ?, 'primeiro', '2026-09-15', ?, ?, ?, ?, ?, ?)"
);
$insSemana = $pdo->prepare('INSERT INTO efeito_contatos_semanas (serie_id, semana, aulas, faltas) VALUES (?, ?, ?, ?)');

/**
 * Série com as aulas/faltas de antes na semana -1 e as de depois na semana 1.
 *
 * @param array{tipo?: string, dias?: int|null, ingressante?: int, auxilio?: int, contatos?: int, canais?: string} $extra
 * @param array<int, array{0: int, 1: int}> $semanas semana => [aulas, faltas]
 */
function serie(int $execucao, int $aluno, int $curso, string $grupo, array $antes, array $depois,
               array $extra = [], array $semanas = []): void
{
    global $pdo, $insSerie, $insSemana;
    $insSerie->execute([
        $execucao, $aluno, $curso, $grupo,
        $extra['contatos'] ?? ($grupo === 'contatado' ? 1 : 0),
        $extra['canais'] ?? '',
        $extra['tipo'] ?? 'percentual_baixo',
        $grupo === 'contatado' ? ($extra['dias'] ?? 1) : null,
        $extra['ingressante'] ?? 0,
        $extra['auxilio'] ?? 0,
    ]);
    $id = (int)$pdo->lastInsertId();
    foreach ([-1 => $antes, 1 => $depois] + $semanas as $semana => [$aulas, $faltas]) {
        $insSemana->execute([$id, $semana, $aulas, $faltas]);
    }
}

// Execução 1: poucos alunos, para conferir cada conta. Presença = 100 − faltas ÷ aulas.
serie(1, 1, 1, 'contatado', [4, 4], [4, 0], ['contatos' => 2, 'canais' => 'email_automatico', 'tipo' => 'faltas_4dias', 'dias' => 2, 'ingressante' => 1], [3 => [2, 2]]); // 0% -> 100%
serie(1, 2, 1, 'contatado', [4, 2], [4, 1], ['canais' => 'email_automatico,telefone', 'tipo' => 'faltas_4dias', 'dias' => 0, 'auxilio' => 1]); // 50% -> 75%
serie(1, 3, 1, 'contatado', [4, 1], [4, 3], ['canais' => 'telefone', 'dias' => 5]);   // 75% -> 25%
serie(1, 4, 1, 'contatado', [4, 2], [4, 2], ['canais' => 'telefone', 'dias' => -3]);  // 50% -> 50% (contato antes do alarme)
serie(1, 5, 1, 'contatado', [1, 1], [4, 0], ['canais' => 'telefone', 'dias' => 10]);  // poucas aulas antes
serie(1, 6, 2, 'contatado', [4, 4], [4, 4], ['canais' => 'telefone']);                // outro curso

$repo = new EfeitoContatosRepository($pdo);

echo "== Análise 1: presença antes × depois\n";
$av = $repo->avaliar(1, ['curso_ids' => [1]], 2, 2);
verificar('alunos do curso', 5, $av['contatado']['alunos']);
verificar('todos os analisados, não só quem melhorou', [
    'analisados' => 4,
    'antes' => 43.75,
    'depois' => 62.5,
    'variacao' => 18.75,
    'melhoraram' => 2,
    'iguais' => 1,
    'pioraram' => 1,
    // Análise 2: abaixo de 75% antes = alunos 1, 2 e 4; chegaram a 75% = 1 e 2.
    'voltou' => [2, 3],
], numeros($av['contatado']));
verificar('sem comparação suficiente, sem efeito e sem diferença', [null, 'poucos'], [$av['efeito'], $av['voltou']['selo']]);

echo "== Resumo do topo\n";
verificar('com alarme, contatados, contatos, dois ou mais', [5, 5, 6, 1], [
    $av['resumo']['com_alarme'], $av['resumo']['contatados'], $av['resumo']['contatos'], $av['resumo']['varios'],
]);
verificar('mediana dos dias (sem o contato antes do alarme): 0, 2, 5, 10', 3.5, $av['resumo']['dias_mediana']);

echo "== Recortes\n";
verificar('canal conta quem teve mais de um no dia', [2, 3], [
    $av['recortes']['canal']['email_automatico']['contatado']['analisados'],
    $av['recortes']['canal']['telefone']['contatado']['analisados'],
]);
verificar('rapidez', ['ate3' => 2, 'ate7' => 1, 'antes' => 1, 'mais7' => 1], array_map(
    static fn(array $g): int => $g['contatado']['alunos'],
    $av['recortes']['rapidez']
));
verificar('tipo, ingresso e auxílio', [2, 3, 1, 1], [
    $av['recortes']['tipo']['faltas_4dias']['contatado']['alunos'],
    $av['recortes']['tipo']['percentual_baixo']['contatado']['alunos'],
    $av['recortes']['ingressante']['sim']['contatado']['alunos'],
    $av['recortes']['auxilio']['sim']['contatado']['alunos'],
]);
verificar('curso pelo nome', ['Curso A'], array_keys($av['recortes']['curso']));
verificar('faixas de rapidez', ['', 'antes', 'ate3', 'ate3', 'ate7', 'mais7'], array_map(
    [EfeitoContatosRepository::class, 'faixaRapidez'],
    [null, -1, 0, 3, 7, 8]
));

echo "== Filtros\n";
verificar('tipo de alarme e ingressante', [2, 1], [
    $repo->avaliar(1, ['curso_ids' => [1], 'tipo' => 'faltas_4dias'], 2, 2)['contatado']['alunos'],
    $repo->avaliar(1, ['ingressante' => true], 2, 2)['contatado']['alunos'],
]);
$pdo->exec("INSERT INTO alunos (id, login, matricula, nome) VALUES (1, 'a1', '1', 'Aluno 1')");
$pdo->exec("INSERT INTO frequencia_disciplina (coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina, id_turma)
            VALUES (1, 1, 1, 'MAT1', 'Matemática', 10)");
verificar('professor: só os alunos das disciplinas dele', [1, 0], [
    $repo->avaliar(1, ['professor' => ['coleta_id' => 1, 'codigos' => ['MAT1'], 'turmas' => [10]]], 2, 2)['contatado']['alunos'],
    $repo->avaliar(1, ['professor' => ['coleta_id' => 1, 'codigos' => []]], 2, 2)['contatado']['alunos'],
]);
verificar('curso sem alunos', 0, $repo->avaliar(1, ['curso_ids' => []], 2, 2)['resumo']['com_alarme']);

echo "== Efeito líquido e voltou contra a comparação\n";
// Contatados: metade vai de 0% para 100%, metade fica em 50% (variação média +50).
// Comparação: metade vai de 0% para 50%, metade fica em 50% (variação média +25).
for ($i = 0; $i < 10; $i++) {
    serie(2, 100 + $i, 1, 'contatado', $i < 5 ? [4, 4] : [4, 2], $i < 5 ? [4, 0] : [4, 2], ['canais' => 'email_automatico', 'tipo' => 'faltas_3semanas']);
    serie(2, 200 + $i, 1, 'comparacao', $i < 5 ? [4, 4] : [4, 2], [4, 2], ['tipo' => 'faltas_3semanas']);
}
$av = $repo->avaliar(2, [], 2, 2);
verificar('variação de cada grupo', [50.0, 25.0], [
    round((float)$av['contatado']['variacao'], 2),
    round((float)$av['comparacao']['variacao'], 2),
]);
// Desvios 52,70 e 26,35; erro 18,63; t(9 gl) = 2,262.
verificar('efeito e intervalo', [25.0, -17.15, 67.15], [
    round($av['efeito']['valor'], 2),
    round($av['efeito']['min'], 2),
    round($av['efeito']['max'], 2),
]);
verificar('voltou: 5 de 10 contra 0 de 10 (menos de 20: sem comparar)', [[5, 10], [0, 10], 'poucos'], [
    [$av['contatado']['voltaram'], $av['contatado']['abaixo_antes']],
    [$av['comparacao']['voltaram'], $av['comparacao']['abaixo_antes']],
    $av['voltou']['selo'],
]);
verificar('tipo de alarme compara com a comparação do mesmo tipo', 25.0,
    round($av['recortes']['tipo']['faltas_3semanas']['efeito']['valor'], 2));
verificar('canal compara com a comparação inteira', 10, $av['recortes']['canal']['email_automatico']['comparacao']['analisados']);
verificar('com alarme conta os dois grupos', [20, 10], [$av['resumo']['com_alarme'], $av['resumo']['contatados']]);

echo "== Efeito do 2º contato\n";
// Execução 3: 12 alunos com 2º contato vão de 25% para 75%; 2 já estavam em 100% antes do 2º
// (voltaram depois do 1º: ficam de fora). Comparação (um contato só): 12 ficam em 25%.
$pdo->exec("INSERT INTO efeito_contatos_execucoes (id, janela_dias, min_aulas, intervalo_segundo) VALUES (3, 14, 2, 9)");
$insSegundo = $pdo->prepare(
    "INSERT INTO efeito_contatos_series
        (execucao_id, aluno_id, curso_id, grupo, referencia, data_referencia, total_contatos, canais, alarme_tipo)
     VALUES (3, ?, 1, ?, 'segundo', '2026-09-20', 2, '', 'percentual_baixo')"
);
foreach (range(1, 26) as $i) {
    $grupo = $i <= 14 ? 'contatado' : 'comparacao';
    $insSegundo->execute([300 + $i, $grupo]);
    $id = (int)$pdo->lastInsertId();
    $jaVoltou = $i >= 13 && $i <= 14;
    $insSemana->execute([$id, -1, 4, $jaVoltou ? 0 : 3]);
    $insSemana->execute([$id, 1, 4, $grupo === 'contatado' ? 1 : 3]);
}
$s2 = $repo->avaliar(3, [], 2, 2, 'segundo', true);
verificar('quem já tinha voltado fica de fora', [12, 12], [$s2['contatado']['alunos'], $s2['comparacao']['alunos']]);
verificar('presença e efeito do 2º contato', [25.0, 75.0, 0.0, 50.0], [
    round((float)$s2['contatado']['presenca_antes'], 2),
    round((float)$s2['contatado']['presenca_depois'], 2),
    round((float)$s2['comparacao']['variacao'], 2),
    round($s2['efeito']['valor'], 2),
]);
verificar('sem filtrar, os 14 entram', 14, $repo->avaliar(3, [], 2, 2, 'segundo')['contatado']['alunos']);
verificar('1º contato não mistura com o 2º', 0, $repo->avaliar(3, [], 2, 2)['contatado']['alunos']);
verificar('intervalo gravado na execução', 9, (int)$pdo->query('SELECT intervalo_segundo FROM efeito_contatos_execucoes WHERE id = 3')->fetchColumn());

echo "== Conseguiu falar e só quem estava abaixo de 75%\n";
$pdo->exec("UPDATE efeito_contatos_series SET falou = 1 WHERE execucao_id = 1 AND aluno_id IN (1, 2)");
$pdo->exec("UPDATE efeito_contatos_series SET falou = 0 WHERE execucao_id = 1 AND aluno_id = 3");
$av = $repo->avaliar(1, ['curso_ids' => [1]], 2, 2);
verificar('recorte conseguiu falar', ['sim' => 2, 'nao' => 1, 'sem_resposta' => 2], array_map(
    static fn(array $g): int => $g['contatado']['alunos'],
    $av['recortes']['falou']
));
// Só abaixo de 75% antes: o aluno 3 (75%) sai; o 5 (poucas aulas antes) fica, sem entrar na conta.
$abaixo = $repo->avaliar(1, ['curso_ids' => [1]], 2, 2, 'primeiro', true);
verificar('só quem não tinha voltado', [4, 3], [$abaixo['contatado']['alunos'], $abaixo['contatado']['analisados']]);
verificar('curva só com quem estava abaixo de 75%', 3,
    $repo->serieSemanal(1, ['curso_ids' => [1]], 2, 2, true)['contatado'][1]['alunos']);

echo "== Curva semanal (presença)\n";
$curva = $repo->serieSemanal(2, [], 2, 2);
verificar('contatados', [-1 => ['presenca' => 25.0, 'alunos' => 10], 1 => ['presenca' => 75.0, 'alunos' => 10]], $curva['contatado']);
verificar('comparação', [-1 => ['presenca' => 25.0, 'alunos' => 10], 1 => ['presenca' => 50.0, 'alunos' => 10]], $curva['comparacao']);
$curva = $repo->serieSemanal(1, ['curso_ids' => [1]], 2, 2);
verificar('só os analisados entram na curva', 4, $curva['contatado'][1]['alunos']);
verificar('semana 3 só de quem tem aula nela', ['presenca' => 0.0, 'alunos' => 1], $curva['contatado'][3]);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
