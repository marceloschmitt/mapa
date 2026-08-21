<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $this->render('dashboard/index', [
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
            'isAdmin' => Auth::isAdmin(),
            'podeVerChamadas' => Auth::canVerChamadas(),
        ]);
    }
}
