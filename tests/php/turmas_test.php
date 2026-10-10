<?php
declare(strict_types=1);

/*
 * Testes do escopo por turma (fase 4) num SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/turmas_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\UserRepository;
use Mapa\Services\ChamadaEmailService;

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

/** @param list<array<string, mixed>> $rows */
function idsOrdenados(array $rows, string $coluna = 'id'): array
{
    $ids = array_map(static fn (array $row): int => (int)$row[$coluna], $rows);
    sort($ids);
    return $ids;
}

$pdo = Database::connection();

$pdo->exec("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A')");
$pdo->exec("INSERT INTO coletas (id) VALUES (1)");
foreach ([1, 2, 3, 4] as $id) {
    $pdo->exec("INSERT INTO alunos (id, login, matricula, nome) VALUES ({$id}, 'a{$id}', 'm{$id}', 'Aluno {$id}')");
}
$pdo->exec("INSERT INTO professores (id, cpf, nome) VALUES (1, '11111111111', 'Ana'), (2, '22222222222', 'Bruno')");
$pdo->exec("INSERT INTO disciplina_professores (codigo_disciplina, disciplina, professor_id)
            VALUES ('POA-X01', 'Disciplina X', 1), ('POA-X01', 'Disciplina X', 2)");
$pdo->exec("INSERT INTO turmas (id_turma, codigo_disciplina, disciplina, nome_turma)
            VALUES (1, 'POA-X01', 'Disciplina X', '01'), (2, 'POA-X01', 'Disciplina X', '02')");
$pdo->exec("INSERT INTO turma_professores (id_turma, professor_id) VALUES (1, 1), (2, 2)");
$pdo->exec("INSERT INTO usuarios (username, nome, cpf, perfil) VALUES ('ana', 'Ana', '11111111111', 'professor')");
$usuarioAna = (int)$pdo->lastInsertId();

// Aluno 3 está na disciplina sem turma identificada.
$frequencias = [
    [1, 'POA-X01', 'Disciplina X', 50.0, 1],
    [2, 'POA-X01', 'Disciplina X', 40.0, 2],
    [3, 'POA-X01', 'Disciplina X', 60.0, null],
    [4, 'POA-X01', 'Disciplina X', 90.0, 1],
    [1, 'POA-Y01', 'Disciplina Y', 50.0, null],
    [2, 'POA-Y01', 'Disciplina Y', 50.0, null],
];
$insFreq = $pdo->prepare(
    'INSERT INTO frequencia_disciplina
        (coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina, percentual_frequencia, id_turma)
     VALUES (1, ?, 1, ?, ?, ?, ?)'
);
foreach ($frequencias as $f) {
    $insFreq->execute($f);
}

$insAlarme = $pdo->prepare(
    "INSERT INTO alarmes (coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina, tipo, mensagem, id_turma)
     VALUES (1, ?, 1, ?, ?, 'percentual_baixo', 'teste', ?)"
);
$alarmeIds = [];
foreach ([
    'a1x' => [1, 'POA-X01', 'Disciplina X', 1],
    'a2x' => [2, 'POA-X01', 'Disciplina X', 2],
    'a3x' => [3, 'POA-X01', 'Disciplina X', null],
    'a1y' => [1, 'POA-Y01', 'Disciplina Y', null],
    'a2y' => [2, 'POA-Y01', 'Disciplina Y', null],
] as $chave => $valores) {
    $insAlarme->execute($valores);
    $alarmeIds[$chave] = (int)$pdo->lastInsertId();
}

echo "== Vínculo do usuário professor\n";
$users = new UserRepository();
verificar('turmas da Ana', [1], $users->turmaIdsDoUsuario($usuarioAna));
verificar('disciplinas da Ana', ['POA-X01'], $users->disciplinaCodigosDoUsuario($usuarioAna));

echo "== Alarmes no escopo do professor\n";
$codigos = ['POA-X01'];
$repo = new AnalyticsRepository();
verificar(
    'sem restrição de turma vê todas as turmas',
    idsOrdenados([['id' => $alarmeIds['a1x']], ['id' => $alarmeIds['a2x']], ['id' => $alarmeIds['a3x']]]),
    idsOrdenados($repo->alarmes(1, false, null, null, null, $codigos))
);
verificar('matriculados sem restrição', 4, $repo->contarAlunosMatriculados(1, null, $codigos));

$repo->restringirTurmas([1]);
verificar(
    'só a turma dele e os sem turma',
    idsOrdenados([['id' => $alarmeIds['a1x']], ['id' => $alarmeIds['a3x']]]),
    idsOrdenados($repo->alarmes(1, false, null, null, null, $codigos))
);
verificar(
    'outras disciplinas só dos alunos das turmas dele',
    idsOrdenados([['id' => $alarmeIds['a1x']], ['id' => $alarmeIds['a3x']], ['id' => $alarmeIds['a1y']]]),
    idsOrdenados($repo->alarmes(1, false, null, null, null, $codigos, true))
);
verificar('matriculados nas turmas dele', 3, $repo->contarAlunosMatriculados(1, null, $codigos));
verificar(
    'não marca alarme de outra turma',
    false,
    $repo->marcarAlarmeVisualizado($alarmeIds['a2x'], $usuarioAna, 'email', null, $codigos)
);
verificar(
    'marca alarme da turma dele',
    true,
    $repo->marcarAlarmeVisualizado($alarmeIds['a1x'], $usuarioAna, 'email', null, $codigos)
);

$repo->restringirTurmas([]);
verificar('lista vazia de turmas não restringe', 4, $repo->contarAlunosMatriculados(1, null, $codigos));

echo "== Disciplinas críticas por turma\n";
$criticas = [];
foreach ((new AnalyticsRepository())->disciplinasCriticas(1, null) as $row) {
    if ($row['codigo_disciplina'] === 'POA-X01') {
        $criticas[$row['id_turma'] === null ? 'sem turma' : 'turma ' . $row['id_turma']] = [
            (string)$row['nome_turma'],
            (int)$row['alunos'],
            (int)$row['abaixo_limite'],
            (string)$row['professores'],
        ];
    }
}
ksort($criticas);
verificar('uma linha por turma, com os professores da turma', [
    'sem turma' => ['', 1, 1, 'Ana, Bruno'],
    'turma 1' => ['01', 2, 1, 'Ana'],
    'turma 2' => ['02', 1, 1, 'Bruno'],
], $criticas);

echo "== Chave de envio do e-mail de chamada\n";
$linhaTurma = ['codigo_disciplina' => 'POA-X01', 'curso_id' => 1, 'dia_esperado' => '2026-09-21', 'id_turma' => 2];
$linhaDisciplina = ['codigo_disciplina' => 'POA-X01', 'curso_id' => 1, 'dia_esperado' => '2026-09-21'];
verificar('chave da turma', 'T2|2026-09-21', ChamadaEmailService::chaveEnvio($linhaTurma));
verificar('chave da disciplina', 'POA-X01|1|2026-09-21', ChamadaEmailService::chaveEnvio($linhaDisciplina));

$servico = new ChamadaEmailService($pdo);
$envioAntigo = ['enviado_em' => '2026-09-22 08:00:00', 'destinatarios' => 'ana@x'];
verificar(
    'turma reconhece e-mail antigo da disciplina',
    $envioAntigo,
    $servico->envioRegistrado(['POA-X01|1|2026-09-21' => $envioAntigo], $linhaTurma)
);
verificar(
    'e-mail de outra turma não conta',
    null,
    $servico->envioRegistrado(['T1|2026-09-21' => $envioAntigo], $linhaTurma)
);

echo "== Destinatários do e-mail de chamada\n";
$pdo->exec("UPDATE professores SET email = CASE id WHEN 1 THEN 'ana@x.br' ELSE 'bruno@x.br' END");
$emailsTurma = new ReflectionMethod(ChamadaEmailService::class, 'emailsProfessoresTurma');
$emailsTurma->setAccessible(true);
verificar('só o professor da turma', ['bruno@x.br'], $emailsTurma->invoke($servico, 2));
$emailsDisciplina = new ReflectionMethod(ChamadaEmailService::class, 'emailsProfessores');
$emailsDisciplina->setAccessible(true);
$todos = $emailsDisciplina->invoke($servico, 'POA-X01');
sort($todos);
verificar('todos os professores da disciplina', ['ana@x.br', 'bruno@x.br'], $todos);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
