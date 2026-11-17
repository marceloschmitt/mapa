<?php
declare(strict_types=1);

/*
 * Comparação de duas proporções com margem de 95% (o "voltou" da tela Eficácia dos alarmes).
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

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
