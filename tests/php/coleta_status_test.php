<?php
declare(strict_types=1);

/*
 * Coleta completa x em andamento/falhou e o aviso do portal, num SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/coleta_status_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ColetaRepository;

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

$pdo = Database::connection();
$repo = new AnalyticsRepository($pdo);
$coletas = new ColetaRepository($pdo);

echo "== Última coleta mostrada no portal\n";
$pdo->exec("INSERT INTO coletas (id, data_referencia, status) VALUES (1, '2026-10-18', NULL), (2, '2026-11-01', 'completa')");
verificar('completa é a última', 2, (int)$repo->ultimaColeta()['id']);
$pdo->exec("INSERT INTO coletas (id, data_referencia, status) VALUES (3, '2026-11-02', 'em_andamento')");
verificar('em andamento não aparece', 2, (int)$repo->ultimaColeta()['id']);
$pdo->exec("UPDATE coletas SET status = 'falhou' WHERE id = 3");
verificar('falhou não aparece', 2, (int)$repo->ultimaColeta()['id']);

echo "== Aviso da última rodada\n";
verificar('sem rodada registrada, sem aviso', null, $coletas->avisoUltimaExecucao());

$pdo->exec("INSERT INTO coleta_execucoes (iniciada_em, terminada_em, status, coleta_id)
            VALUES ('2026-11-01 08:00:00', '2026-11-01 08:10:00', 'concluida', 2)");
verificar('rodada concluída, sem aviso', null, $coletas->avisoUltimaExecucao());

$pdo->exec("INSERT INTO coleta_execucoes (iniciada_em, terminada_em, status, passo_falhou, coleta_id)
            VALUES ('2026-11-02 09:00:00', '2026-11-02 09:05:00', 'falhou', 'consulta_alunos_massa.py', NULL)");
verificar(
    'rodada que falhou',
    'A coleta de 02/11 09:00 falhou em consulta_alunos_massa.py. O portal continua mostrando os dados de 01/11/2026.',
    $coletas->avisoUltimaExecucao()
);

$pdo->exec("INSERT INTO coleta_execucoes (iniciada_em, status) VALUES ('2026-11-03 10:00:00', 'em_andamento')");
verificar(
    'em andamento há pouco tempo, sem aviso',
    null,
    $coletas->avisoUltimaExecucao(strtotime('2026-11-03 11:00:00'))
);
verificar(
    'em andamento há mais de 3 horas',
    'A coleta iniciada em 03/11 10:00 não terminou. O portal continua mostrando os dados de 01/11/2026.',
    $coletas->avisoUltimaExecucao(strtotime('2026-11-03 14:00:00'))
);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
