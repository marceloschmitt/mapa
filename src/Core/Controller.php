<?php
declare(strict_types=1);

namespace Mapa\Core;

abstract class Controller
{
    protected function render(string $view, array $data = [], string $layout = 'layouts/main'): void
    {
        $data['usuario'] = Auth::user();
        $data['isAdmin'] = $data['isAdmin'] ?? Auth::isAdmin();
        $data['podeVerChamadas'] = $data['podeVerChamadas'] ?? Auth::canVerChamadas();

        View::render($view, $data, $layout);
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . Url::to($path));
        exit;
    }

    /** @return array<string, mixed> */
    protected function requireAuth(): array
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('erro', 'Faça login para continuar.');
            $this->redirect('/login');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    protected function requireAdmin(): array
    {
        $user = $this->requireAuth();
        if (!Auth::canManageUsers()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores.');
            $this->redirect('/');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    protected function requireAdminOuGeral(): array
    {
        $user = $this->requireAuth();
        if (!Auth::canVerChamadas()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores, perfil geral e coordenadores.');
            $this->redirect('/');
        }

        return $user;
    }
}
