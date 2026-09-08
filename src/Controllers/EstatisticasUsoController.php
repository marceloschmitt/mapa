<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Controller;
use Mapa\Models\AccessLogRepository;

class EstatisticasUsoController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $dias = $this->periodoSelecionado();
        $repo = new AccessLogRepository();
        $stats = $repo->estatisticas($dias);

        $this->render('estatisticas_uso/index', [
            'stats' => $stats,
            'periodo' => $dias,
            'periodos' => [
                7 => 'Últimos 7 dias',
                30 => 'Últimos 30 dias',
                90 => 'Últimos 90 dias',
                0 => 'Todo o período',
            ],
        ]);
    }

    private function periodoSelecionado(): ?int
    {
        $param = isset($_GET['dias']) ? trim((string)$_GET['dias']) : '30';
        if ($param === '0' || $param === 'todos' || $param === 'all') {
            return null;
        }

        $dias = (int)$param;
        if (!in_array($dias, [7, 30, 90], true)) {
            return 30;
        }

        return $dias;
    }
}
