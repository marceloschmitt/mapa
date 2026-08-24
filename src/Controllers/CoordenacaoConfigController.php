<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\CursoCoordenacaoRepository;

class CoordenacaoConfigController extends Controller
{
    public function form(): void
    {
        $this->requireAdmin();
        $repository = new CursoCoordenacaoRepository();

        $this->render('coordenacao/config', [
            'cursos' => $repository->listarCursosComCoordenacao(),
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
        ]);
    }

    public function save(): void
    {
        $this->requireAdmin();

        $repository = new CursoCoordenacaoRepository();
        $cursos = $repository->listarCursosComCoordenacao();
        $enviados = $_POST['email_coordenacao'] ?? [];
        if (!is_array($enviados)) {
            Session::flash('erro', 'Dados inválidos.');
            $this->redirect('/configuracoes/coordenacao');
        }

        $salvar = [];
        foreach ($cursos as $curso) {
            $cursoId = (int)($curso['id'] ?? 0);
            if ($cursoId <= 0) {
                continue;
            }

            $email = trim((string)($enviados[$cursoId] ?? $enviados[(string)$cursoId] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Session::flash(
                    'erro',
                    'E-mail inválido para o curso '
                    . trim((string)($curso['nome_curso'] ?? ''))
                    . '.'
                );
                $this->redirect('/configuracoes/coordenacao');
            }

            $salvar[$cursoId] = $email;
        }

        $repository->salvarEmails($salvar);
        Session::flash('sucesso', 'E-mails de coordenação salvos com sucesso.');
        $this->redirect('/configuracoes/coordenacao');
    }
}
