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
        $data['podeVerPasseLivre'] = $data['podeVerPasseLivre'] ?? Auth::canVerPasseLivre();
        // Limites e textos das regras de alarme: as telas nao fixam mais 75%.
        $data['alarmeConfig'] = $data['alarmeConfig'] ?? self::alarmeConfig();

        View::render($view, $data, $layout);
    }

    /**
     * Configuracao das regras de alarme (uma leitura por requisicao).
     *
     * @return array<string, mixed>
     */
    protected static function alarmeConfig(): array
    {
        static $config = null;
        if ($config === null) {
            $config = (new \Mapa\Models\ConfigRepository())->getAlarmeConfig();
        }

        return $config;
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

        (new \Mapa\Models\AccessLogRepository())->registrarAcessoAtual();

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
