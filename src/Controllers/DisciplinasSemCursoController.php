<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Models\AnalyticsRepository;

class DisciplinasSemCursoController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $repo = new AnalyticsRepository();
        $disciplinas = $repo->disciplinasSemCurso();

        $this->render('disciplinas_sem_curso/index', [
            'disciplinas' => $disciplinas,
            'total' => count($disciplinas),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }
}
