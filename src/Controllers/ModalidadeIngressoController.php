<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Controller;
use Mapa\Models\AnalyticsRepository;

class ModalidadeIngressoController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $repo = new AnalyticsRepository();
        $coleta = $repo->ultimaColeta();
        $linhas = [];
        $totais = [
            'populacao' => 0,
            'com_alarme' => 0,
            'alarmes' => 0,
            'taxa_pct' => null,
        ];

        if ($coleta !== null) {
            $linhas = $repo->alarmesPorModalidadeIngresso((int)$coleta['id']);
            foreach ($linhas as $linha) {
                $totais['populacao'] += (int)$linha['populacao'];
                $totais['com_alarme'] += (int)$linha['com_alarme'];
                $totais['alarmes'] += (int)$linha['alarmes'];
            }
            if ($totais['populacao'] > 0) {
                $totais['taxa_pct'] = round(
                    100.0 * $totais['com_alarme'] / $totais['populacao'],
                    1
                );
            }
        }

        $comModalidade = 0;
        foreach ($linhas as $linha) {
            if ((string)$linha['modalidade'] !== '(sem modalidade)') {
                $comModalidade += (int)$linha['populacao'];
            }
        }

        $this->render('modalidade_ingresso/index', [
            'coleta' => $coleta,
            'linhas' => $linhas,
            'totais' => $totais,
            'cobertura_modalidade' => $totais['populacao'] > 0
                ? round(100.0 * $comModalidade / $totais['populacao'], 1)
                : null,
            'erro' => $coleta === null ? 'Nenhuma coleta importada.' : null,
        ]);
    }
}
