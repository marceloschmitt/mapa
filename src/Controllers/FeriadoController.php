<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\FeriadoRepository;

class FeriadoController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $ano = $this->anoSelecionado();
        $repo = new FeriadoRepository();

        $this->render('feriados/index', [
            'feriados' => $repo->listar($ano),
            'ano' => $ano,
            'anos' => $this->anosDisponiveis($ano),
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
        ]);
    }

    public function criar(): void
    {
        $this->requireAdmin();

        $data = trim((string)($_POST['data'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $resultado = (new FeriadoRepository())->adicionar($data, $descricao);

        if (!$resultado['ok']) {
            Session::flash('erro', (string)$resultado['erro']);
        } else {
            Session::flash(
                'sucesso',
                'Feriado cadastrado. A data foi removida da grade de aulas previstas.'
            );
        }

        $this->redirect($this->urlVoltar($data));
    }

    public function excluir(): void
    {
        $this->requireAdmin();

        $data = trim((string)($_POST['data'] ?? ''));
        $ok = (new FeriadoRepository())->excluir($data);
        if ($ok) {
            Session::flash(
                'sucesso',
                'Feriado removido. Rode novamente a importação da grade para recolocar a data nas disciplinas, se for dia letivo.'
            );
        } else {
            Session::flash('erro', 'Feriado não encontrado.');
        }

        $this->redirect($this->urlVoltar($data));
    }

    private function anoSelecionado(): int
    {
        $ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
        if ($ano < 2000 || $ano > 2100) {
            return (int)date('Y');
        }

        return $ano;
    }

    /**
     * @return list<int>
     */
    private function anosDisponiveis(int $anoAtual): array
    {
        $anos = [
            $anoAtual - 1,
            $anoAtual,
            $anoAtual + 1,
            (int)date('Y') - 1,
            (int)date('Y'),
            (int)date('Y') + 1,
        ];
        $anos = array_values(array_unique(array_filter(
            $anos,
            static fn (int $a): bool => $a >= 2000 && $a <= 2100
        )));
        sort($anos);

        return $anos;
    }

    private function urlVoltar(string $data): string
    {
        $ano = (int)date('Y');
        if (preg_match('/^(\d{4})-/', $data, $m) === 1) {
            $ano = (int)$m[1];
        } elseif (isset($_GET['ano'])) {
            $ano = (int)$_GET['ano'];
        }

        return '/configuracoes/feriados?ano=' . $ano;
    }
}
