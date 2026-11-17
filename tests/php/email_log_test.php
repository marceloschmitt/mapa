<?php
declare(strict_types=1);

/*
 * Log unificado de e-mails (alunos e professores/usuários).
 * Rodar na raiz: php tests/php/email_log_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\EmailLogRepository;

$dbPath = tempnam(sys_get_temp_dir(), 'mapa_email_log_');
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
$pdo->exec("INSERT INTO usuarios (username, nome, email, perfil)
            VALUES ('prof1', 'Ana Professora', 'ana@poa.ifrs.edu.br', 'professor')");
$pdo->exec("INSERT INTO professores (cpf, nome, email)
            VALUES ('11111111111', 'Bruno Docente', 'bruno@x.br')");
$pdo->exec("INSERT INTO alunos (login, matricula, nome, email)
            VALUES ('a1', 'm1', 'Carlos Aluno', 'carlos@aluno.br')");

$repo = new EmailLogRepository($pdo);
$repo->registrarEnvios(
    ['ana@poa.ifrs.edu.br', 'bruno@x.br', 'carlos@aluno.br'],
    'Teste de envio'
);

$lista = $repo->listar(null);
verificar('total 3 envios', 3, $repo->total(null));
verificar('lista tem 3', 3, count($lista));

$porEmail = [];
foreach ($lista as $row) {
    $porEmail[strtolower((string)$row['email'])] = $row;
}

verificar('nome usuária/professora', 'Ana Professora', (string)$porEmail['ana@poa.ifrs.edu.br']['nome']);
verificar('nome professor', 'Bruno Docente', (string)$porEmail['bruno@x.br']['nome']);
verificar('nome aluno', 'Carlos Aluno', (string)$porEmail['carlos@aluno.br']['nome']);
verificar('assunto gravado', 'Teste de envio', (string)$porEmail['ana@poa.ifrs.edu.br']['assunto']);

$repo->registrarEnvios(['ana@poa.ifrs.edu.br'], 'Outro', ['ana@poa.ifrs.edu.br' => 'Nome Informado']);
$lista = $repo->listar(null);
verificar('usa nome informado no envio', 'Nome Informado', (string)$lista[0]['nome']);

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
