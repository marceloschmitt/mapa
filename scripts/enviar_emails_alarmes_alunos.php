#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Envia e-mails automaticos de alarmes criticos aos alunos.
 *
 * Uso:
 *   php scripts/enviar_emails_alarmes_alunos.php
 */

$raiz = dirname(__DIR__);
chdir($raiz);

require $raiz . '/src/bootstrap.php';

use Mapa\Services\AlarmeEmailService;

$servico = new AlarmeEmailService();
$resumo = $servico->processarAlunos();

echo "E-mails de alarmes — alunos\n";
echo "Enviados: {$resumo['enviados']}\n";
echo "Ignorados: {$resumo['ignorados']}\n";
echo "Falhas: {$resumo['falhas']}\n";

foreach ($resumo['mensagens'] as $mensagem) {
    echo "- {$mensagem}\n";
}

exit($resumo['falhas'] > 0 && $resumo['enviados'] === 0 ? 1 : 0);
