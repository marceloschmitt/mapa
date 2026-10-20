<?php
declare(strict_types=1);

/*
 * Testes do resumo do efeito dos contatos num SQLite temporário.
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
        'antes' => round((float)$grupo['taxa_antes'], 2),
        'depois' => round((float)$grupo['taxa_depois'], 2),
        'variacao' => round((float)$grupo['variacao'], 2),
        'melhoraram' => $grupo['melhoraram'],
        'iguais' => $grupo['iguais'],
        'pioraram' => $grupo['pioraram'],
        'frequentes' => [$grupo['frequentes_antes'], $grupo['frequentes_depois']],
    ];
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A'), (2, 'Curso B')");
$pdo->exec("INSERT INTO efeito_contatos_execucoes (id, janela_dias, min_aulas) VALUES (1, 14, 2), (2, 14, 2)");

$insSerie = $pdo->prepare(
    "INSERT INTO efeito_contatos_series
        (execucao_id, aluno_id, curso_id, grupo, referencia, data_referencia, total_contatos, canais)
     VALUES (?, ?, ?, ?, 'primeiro', '2026-09-15', ?, ?)"
);
$insSemana = $pdo->prepare('INSERT INTO efeito_contatos_semanas (serie_id, semana, aulas, faltas) VALUES (?, ?, ?, ?)');

/**
 * Série com as aulas/faltas de antes na semana -1 e as de depois na semana 1.
 *
 * @param array<int, array{0: int, 1: int}> $extras semana => [aulas, faltas]
 */
function serie(int $execucao, int $aluno, int $curso, string $grupo, array $antes, array $depois,
               int $contatos = 1, string $canais = '', array $extras = []): void
{
    global $pdo, $insSerie, $insSemana;
    $insSerie->execute([$execucao, $aluno, $curso, $grupo, $contatos, $canais]);
    $id = (int)$pdo->lastInsertId();
    foreach ([-1 => $antes, 1 => $depois] + $extras as $semana => [$aulas, $faltas]) {
        $insSemana->execute([$id, $semana, $aulas, $faltas]);
    }
}

// Execução 1: poucos alunos, para conferir cada conta.
serie(1, 1, 1, 'contatado', [4, 4], [4, 0], 2, 'email_automatico', [3 => [2, 2]]); // 100% -> 0%
serie(1, 2, 1, 'contatado', [4, 2], [4, 1], 1, 'email_automatico,telefone');      // 50% -> 25%
serie(1, 3, 1, 'contatado', [4, 1], [4, 3], 1, 'telefone');                       // 25% -> 75%
serie(1, 4, 1, 'contatado', [4, 2], [4, 2], 1, 'telefone');                       // 50% -> 50%
serie(1, 5, 1, 'contatado', [1, 1], [4, 0], 1, 'telefone');                       // poucas aulas antes
serie(1, 6, 2, 'contatado', [4, 4], [4, 4], 1, 'telefone');                       // outro curso

$repo = new EfeitoContatosRepository($pdo);

echo "== Médias de todos os analisados\n";
$resumo = $repo->resumo(1, 'primeiro', 2, 'janela', 2, 25.0, [1]);
$contatado = $resumo['contatado'];
verificar('alunos do curso', 5, $contatado['alunos']);
verificar('contatos e alunos com dois ou mais', [6, 1], [$resumo['contatos'], $resumo['varios']]);
verificar('todos os analisados, não só quem melhorou', [
    'analisados' => 4,
    'antes' => 56.25,
    'depois' => 37.5,
    'variacao' => -18.75,
    'melhoraram' => 2,
    'iguais' => 1,
    'pioraram' => 1,
    'frequentes' => [1, 2],
], numeros($contatado));
verificar('sem comparação suficiente, sem efeito líquido', null, $contatado['efeito']);
verificar('canal conta quem teve mais de um no dia', [2, 3], [
    $contatado['canais']['email_automatico']['analisados'],
    $contatado['canais']['telefone']['analisados'],
]);

$corte = $repo->resumo(1, 'primeiro', 2, 'corte', 2, 25.0, [1])['contatado'];
// Aluno 1 depois: semanas 1 e 3 = 2 faltas em 6 aulas (33,33%); os demais não têm semana 3.
verificar('até o corte soma as semanas seguintes', 45.83, round((float)$corte['taxa_depois'], 2));

echo "== Efeito líquido com intervalo\n";
// Contatados: metade cai de 100% para 0%, metade fica em 50% (variação média -50).
// Comparação: metade cai de 100% para 50%, metade fica em 50% (variação média -25).
for ($i = 0; $i < 10; $i++) {
    serie(2, 100 + $i, 1, 'contatado', $i < 5 ? [4, 4] : [4, 2], $i < 5 ? [4, 0] : [4, 2], 1, 'email_automatico');
    serie(2, 200 + $i, 1, 'comparacao', $i < 5 ? [4, 4] : [4, 2], [4, 2]);
}
$resumo = $repo->resumo(2, 'primeiro', 2, 'janela', 2, 25.0, null);
$efeito = $resumo['contatado']['efeito'];
verificar('variação de cada grupo', [-50.0, -25.0], [
    round((float)$resumo['contatado']['variacao'], 2),
    round((float)$resumo['comparacao']['variacao'], 2),
]);
// Desvios 52,70 e 26,35; erro 18,63; t(9 gl) = 2,262.
verificar('efeito e intervalo', [-25.0, -67.15, 17.15], [
    round($efeito['valor'], 2),
    round($efeito['min'], 2),
    round($efeito['max'], 2),
]);
verificar('segundo contato não tem comparação', 0, $repo->resumo(2, 'segundo', 2, 'janela', 2, 25.0, null)['comparacao']['alunos']);

echo "== Curva semanal\n";
$serie = $repo->serieSemanal(2, 'primeiro', 2, 2, null);
verificar('contatados', [-1 => ['taxa' => 75.0, 'alunos' => 10], 1 => ['taxa' => 25.0, 'alunos' => 10]], $serie['contatado']);
verificar('comparação', [-1 => ['taxa' => 75.0, 'alunos' => 10], 1 => ['taxa' => 50.0, 'alunos' => 10]], $serie['comparacao']);
$serie = $repo->serieSemanal(1, 'primeiro', 2, 2, [1]);
verificar('só os analisados entram na curva', 4, $serie['contatado'][1]['alunos']);
verificar('semana 3 só de quem tem aula nela', ['taxa' => 100.0, 'alunos' => 1], $serie['contatado'][3]);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
