<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Controller;

/**
 * Tela antiga "Efeito dos contatos": substituída por Eficácia dos alarmes
 * (/acompanhamento), que traz a curva semanal e as mesmas análises. As rotas
 * ficam só para links e favoritos antigos.
 */
class EfeitoContatosController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $this->redirect('/acompanhamento');
    }

    public function gerar(): void
    {
        (new AcompanhamentoController())->gerar();
    }
}
