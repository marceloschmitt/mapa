#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Envia e-mails de alarmes (alunos + staff). Preferir scripts separados.
 *
 * Uso:
 *   php scripts/enviar_emails_alarmes.php
 */

$raiz = dirname(__DIR__);
chdir($raiz);

require $raiz . '/src/bootstrap.php';

use Mapa\Services\AlarmeEmailService;

$servico = new AlarmeEmailService();
$resumo = $servico->processar();

echo "E-mails de alarmes criticos\n";
echo "Enviados (alunos): {$resumo['enviados']}\n";
echo "Avisos (professores/coordenadores): {$resumo['avisos_staff']}\n";
echo "Ignorados: {$resumo['ignorados']}\n";
echo "Falhas: {$resumo['falhas']}\n";

foreach ($resumo['mensagens'] as $mensagem) {
    echo "- {$mensagem}\n";
}

exit($resumo['falhas'] > 0 && $resumo['enviados'] === 0 && $resumo['avisos_staff'] === 0 ? 1 : 0);
