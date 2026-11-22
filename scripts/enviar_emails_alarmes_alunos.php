#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Envia e-mails automaticos de alarmes criticos aos alunos.
 *
 * Uso:
 *   php scripts/enviar_emails_alarmes_alunos.php
 *   php scripts/enviar_emails_alarmes_alunos.php --debug
 *   php scripts/enviar_emails_alarmes_alunos.php --debug ADILENY
 *   php scripts/enviar_emails_alarmes_alunos.php --debug 2026100407
 *   php scripts/enviar_emails_alarmes_alunos.php --sync-contatos 2026100407
 *
 * --debug não envia nada: lista por aluno/alarme o que bloqueia ou libera o envio.
 * --sync-contatos alinha contatos_aluno com alarme_emails (repara data na tela).
 */

$raiz = dirname(__DIR__);
chdir($raiz);

require $raiz . '/src/bootstrap.php';

use Mapa\Services\AlarmeEmailService;

$args = array_slice($argv, 1);
$debug = false;
$syncContatos = false;
$filtro = null;
foreach ($args as $arg) {
    if ($arg === '--debug' || $arg === '-d') {
        $debug = true;
        continue;
    }
    if ($arg === '--sync-contatos') {
        $syncContatos = true;
        continue;
    }
    if (str_starts_with($arg, '--debug=')) {
        $debug = true;
        $filtro = substr($arg, 8);
        continue;
    }
    if (str_starts_with($arg, '--sync-contatos=')) {
        $syncContatos = true;
        $filtro = substr($arg, 16);
        continue;
    }
    if (($debug || $syncContatos) && $filtro === null && !str_starts_with($arg, '-')) {
        $filtro = $arg;
    }
}

$servico = new AlarmeEmailService();

if ($syncContatos) {
    $resultado = $servico->sincronizarContatosDeEmails($filtro);
    echo "Sincronizar contatos a partir de alarme_emails\n";
    echo str_repeat('=', 60) . "\n";
    echo "Corrigidos: {$resultado['corrigidos']}\n";
    foreach ($resultado['linhas'] as $linha) {
        echo $linha . "\n";
    }
    exit(0);
}

if ($debug) {
    $diag = $servico->diagnosticarEnvioAlunos(null, $filtro);
    echo "Depuração — e-mails de alarmes (alunos)\n";
    echo str_repeat('=', 60) . "\n";
    foreach ($diag['linhas'] as $linha) {
        echo $linha . "\n";
    }
    exit(0);
}

$resumo = $servico->processarAlunos();

echo "E-mails de alarmes — alunos\n";
echo "Enviados: {$resumo['enviados']}\n";
echo "Ignorados: {$resumo['ignorados']}\n";
echo "Falhas: {$resumo['falhas']}\n";

foreach ($resumo['mensagens'] as $mensagem) {
    echo "- {$mensagem}\n";
}

exit($resumo['falhas'] > 0 && $resumo['enviados'] === 0 ? 1 : 0);
