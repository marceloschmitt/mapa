#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Envia e-mails automaticos de chamadas em atraso (2+ dias).
 *
 * Uso:
 *   php scripts/enviar_emails_chamadas.php
 */

$raiz = dirname(__DIR__);
chdir($raiz);

require $raiz . '/src/bootstrap.php';

use Mapa\Services\ChamadaEmailService;

$servico = new ChamadaEmailService();
$resumo = $servico->processar();

echo "E-mails de chamadas\n";
echo "Enviados: {$resumo['enviados']}\n";
echo "Ignorados: {$resumo['ignorados']}\n";
echo "Falhas: {$resumo['falhas']}\n";

foreach ($resumo['mensagens'] as $mensagem) {
    echo "- {$mensagem}\n";
}

exit($resumo['falhas'] > 0 && $resumo['enviados'] === 0 ? 1 : 0);
