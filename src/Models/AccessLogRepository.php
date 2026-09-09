<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Auth;
use Mapa\Core\Database;
use PDO;
use Throwable;

class AccessLogRepository
{
    /** @var array<string, string> */
    public const ROTULOS_ROTA = [
        '/' => 'Relatórios (início)',
        '/login' => 'Login',
        '/logout' => 'Logout',
        '/setup' => 'Configuração inicial',
        '/conta/senha' => 'Minha senha',
        '/analytics' => 'Relatório geral',
        '/alarmes' => 'Alarmes',
        '/alarmes/visualizar' => 'Alarmes — registrar contato',
        '/alarmes/enviar-email' => 'Alarmes — enviar e-mail',
        '/ingressantes' => 'Ingressantes',
        '/trancados' => 'Trancados',
        '/perda-vaga' => 'Perda de vaga',
        '/passe-livre' => 'Passe livre',
        '/passe-livre/gerar' => 'Passe livre — gerar',
        '/passe-livre/assinar' => 'Passe livre — assinar',
        '/passe-livre/pdf' => 'Passe livre — PDF',
        '/passe-livre/conferencia' => 'Passe livre — conferência',
        '/chamadas' => 'Chamadas',
        '/chamadas/exportar-atrasadas-1-semestre' => 'Chamadas — exportar',
        '/usuarios' => 'Usuários',
        '/usuarios/novo' => 'Usuários — novo',
        '/usuarios/editar' => 'Usuários — editar',
        '/usuarios/criar-professores' => 'Usuários — criar professores',
        '/configuracoes/ldap' => 'Configurações — LDAP',
        '/configuracoes/api' => 'Configurações — API',
        '/configuracoes/email' => 'Configurações — e-mail',
        '/configuracoes/coordenacao' => 'Configurações — coordenação',
        '/configuracoes/feriados' => 'Configurações — feriados',
        '/configuracoes/feriados/excluir' => 'Configurações — excluir feriado',
        '/estatisticas-uso' => 'Estatísticas de uso',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @param array<string, mixed>|null $usuario
     */
    public function registrar(
        string $tipo,
        string $rota,
        string $metodo = 'GET',
        ?array $usuario = null
    ): void {
        try {
            $usuario = $usuario ?? Auth::user();
            $rota = $this->normalizarRota($rota);
            if ($this->deveIgnorar($tipo, $rota)) {
                return;
            }

            $statement = $this->db->prepare(
                'INSERT INTO acessos_log (
                    usuario_id, username, nome, perfil, tipo,
                    metodo, rota, rota_rotulo, ip, user_agent, criado_em
                 ) VALUES (
                    :usuario_id, :username, :nome, :perfil, :tipo,
                    :metodo, :rota, :rota_rotulo, :ip, :user_agent, datetime(\'now\')
                 )'
            );
            $statement->execute([
                'usuario_id' => isset($usuario['id']) ? (int)$usuario['id'] : null,
                'username' => (string)($usuario['username'] ?? ''),
                'nome' => (string)($usuario['nome'] ?? ''),
                'perfil' => (string)($usuario['perfil'] ?? ''),
                'tipo' => $tipo,
                'metodo' => strtoupper($metodo),
                'rota' => $rota,
                'rota_rotulo' => self::rotuloRota($rota),
                'ip' => $this->ipCliente(),
                'user_agent' => $this->userAgent(),
            ]);
        } catch (Throwable $e) {
            // Log nao deve interromper a navegacao.
        }
    }

    public function registrarLogin(?array $usuario = null): void
    {
        $this->registrar('login', '/login', 'POST', $usuario);
    }

    public function registrarLogout(?array $usuario = null): void
    {
        $this->registrar('logout', '/logout', 'GET', $usuario);
    }

    public function registrarAcessoAtual(): void
    {
        $metodo = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->registrar('acesso', $this->rotaAtual(), $metodo);
    }

    /**
     * @return array{
     *   dias: int|null,
     *   desde: string|null,
     *   total_acessos: int,
     *   total_logins: int,
     *   usuarios_unicos: int,
     *   por_perfil: list<array{perfil: string, rotulo: string, acessos: int, usuarios: int}>,
     *   por_usuario: list<array{usuario_id: int|null, username: string, nome: string, perfil: string, acessos: int, logins: int, ultimo_acesso: string}>,
     *   por_rota: list<array{rota: string, rotulo: string, acessos: int, usuarios: int}>,
     *   por_dia: list<array{dia: string, acessos: int, logins: int}>,
     *   emails: array{
     *     alunos: int,
     *     staff_total: int,
     *     staff_professor: int,
     *     staff_coordenador: int,
     *     chamadas: int
     *   }
     * }
     */
    public function estatisticas(?int $dias = 30): array
    {
        $filtro = '';
        $params = [];
        $desde = null;
        if ($dias !== null && $dias > 0) {
            $filtro = " AND datetime(criado_em) >= datetime('now', :offset)";
            $params['offset'] = '-' . $dias . ' days';
            $desde = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
                ->modify('-' . $dias . ' days')
                ->format('Y-m-d');
        }

        return [
            'dias' => $dias,
            'desde' => $desde,
            'total_acessos' => $this->contar(
                "SELECT COUNT(*) FROM acessos_log WHERE tipo = 'acesso'" . $filtro,
                $params
            ),
            'total_logins' => $this->contar(
                "SELECT COUNT(*) FROM acessos_log WHERE tipo = 'login'" . $filtro,
                $params
            ),
            'usuarios_unicos' => $this->contar(
                "SELECT COUNT(DISTINCT usuario_id) FROM acessos_log
                 WHERE usuario_id IS NOT NULL" . $filtro,
                $params
            ),
            'por_perfil' => $this->agregarPorPerfil($filtro, $params),
            'por_usuario' => $this->agregarPorUsuario($filtro, $params),
            'por_rota' => $this->agregarPorRota($filtro, $params),
            'por_dia' => $this->agregarPorDia($filtro, $params),
            'emails' => $this->estatisticasEmails($dias),
        ];
    }

    public static function rotuloRota(string $rota): string
    {
        $rota = '/' . trim($rota, '/');
        if ($rota === '/') {
            return self::ROTULOS_ROTA['/'];
        }

        return self::ROTULOS_ROTA[$rota] ?? $rota;
    }

    private function agregarPorPerfil(string $filtro, array $params): array
    {
        $sql = "SELECT perfil,
                       COUNT(*) AS acessos,
                       COUNT(DISTINCT usuario_id) AS usuarios
                FROM acessos_log
                WHERE tipo IN ('acesso', 'login')" . $filtro . '
                GROUP BY perfil
                ORDER BY acessos DESC';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $linhas = [];
        foreach ($statement->fetchAll() as $row) {
            $perfil = (string)($row['perfil'] ?? '');
            $linhas[] = [
                'perfil' => $perfil,
                'rotulo' => Auth::ROTULOS_PERFIL[$perfil] ?? ($perfil !== '' ? $perfil : 'Desconhecido'),
                'acessos' => (int)$row['acessos'],
                'usuarios' => (int)$row['usuarios'],
            ];
        }

        return $linhas;
    }

    private function agregarPorUsuario(string $filtro, array $params): array
    {
        $sql = "SELECT usuario_id, username, nome, perfil,
                       SUM(CASE WHEN tipo = 'acesso' THEN 1 ELSE 0 END) AS acessos,
                       SUM(CASE WHEN tipo = 'login' THEN 1 ELSE 0 END) AS logins,
                       MAX(criado_em) AS ultimo_acesso
                FROM acessos_log
                WHERE tipo IN ('acesso', 'login')" . $filtro . '
                GROUP BY usuario_id, username, nome, perfil
                ORDER BY acessos DESC, logins DESC, ultimo_acesso DESC
                LIMIT 100';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $linhas = [];
        foreach ($statement->fetchAll() as $row) {
            $linhas[] = [
                'usuario_id' => $row['usuario_id'] !== null ? (int)$row['usuario_id'] : null,
                'username' => (string)$row['username'],
                'nome' => (string)$row['nome'],
                'perfil' => (string)$row['perfil'],
                'acessos' => (int)$row['acessos'],
                'logins' => (int)$row['logins'],
                'ultimo_acesso' => (string)$row['ultimo_acesso'],
            ];
        }

        return $linhas;
    }

    private function agregarPorRota(string $filtro, array $params): array
    {
        $sql = "SELECT rota, rota_rotulo,
                       COUNT(*) AS acessos,
                       COUNT(DISTINCT usuario_id) AS usuarios
                FROM acessos_log
                WHERE tipo = 'acesso'" . $filtro . '
                GROUP BY rota, rota_rotulo
                ORDER BY acessos DESC
                LIMIT 50';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $linhas = [];
        foreach ($statement->fetchAll() as $row) {
            $rota = (string)$row['rota'];
            $rotulo = trim((string)$row['rota_rotulo']);
            $linhas[] = [
                'rota' => $rota,
                'rotulo' => $rotulo !== '' ? $rotulo : self::rotuloRota($rota),
                'acessos' => (int)$row['acessos'],
                'usuarios' => (int)$row['usuarios'],
            ];
        }

        return $linhas;
    }

    private function agregarPorDia(string $filtro, array $params): array
    {
        $sql = "SELECT date(criado_em) AS dia,
                       SUM(CASE WHEN tipo = 'acesso' THEN 1 ELSE 0 END) AS acessos,
                       SUM(CASE WHEN tipo = 'login' THEN 1 ELSE 0 END) AS logins
                FROM acessos_log
                WHERE tipo IN ('acesso', 'login')" . $filtro . '
                GROUP BY date(criado_em)
                ORDER BY dia DESC
                LIMIT 90';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $linhas = [];
        foreach ($statement->fetchAll() as $row) {
            $linhas[] = [
                'dia' => (string)$row['dia'],
                'acessos' => (int)$row['acessos'],
                'logins' => (int)$row['logins'],
            ];
        }

        return $linhas;
    }

    /**
     * @return array{
     *   alunos: int,
     *   staff_total: int,
     *   staff_professor: int,
     *   staff_coordenador: int,
     *   chamadas: int
     * }
     */
    private function estatisticasEmails(?int $dias): array
    {
        $filtro = '';
        $params = [];
        if ($dias !== null && $dias > 0) {
            $filtro = " WHERE datetime(enviado_em) >= datetime('now', :offset)";
            $params['offset'] = '-' . $dias . ' days';
        }

        $staffFiltro = $filtro;
        $staffParams = $params;
        $profParams = $params;
        $coordParams = $params;
        if ($filtro === '') {
            $profFiltro = " WHERE papel = 'professor'";
            $coordFiltro = " WHERE papel = 'coordenador'";
        } else {
            $profFiltro = $filtro . " AND papel = 'professor'";
            $coordFiltro = $filtro . " AND papel = 'coordenador'";
        }

        return [
            'alunos' => $this->contar(
                'SELECT COUNT(*) FROM alarme_emails' . $filtro,
                $params
            ),
            'staff_total' => $this->contar(
                'SELECT COUNT(*) FROM staff_alarme_emails' . $staffFiltro,
                $staffParams
            ),
            'staff_professor' => $this->contar(
                'SELECT COUNT(*) FROM staff_alarme_emails' . $profFiltro,
                $profParams
            ),
            'staff_coordenador' => $this->contar(
                'SELECT COUNT(*) FROM staff_alarme_emails' . $coordFiltro,
                $coordParams
            ),
            'chamadas' => $this->contar(
                'SELECT COUNT(*) FROM chamada_emails' . $filtro,
                $params
            ),
        ];
    }

    /** @param array<string, mixed> $params */
    private function contar(string $sql, array $params = []): int
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return (int)$statement->fetchColumn();
    }

    private function deveIgnorar(string $tipo, string $rota): bool
    {
        return false;
    }

    private function normalizarRota(string $rota): string
    {
        $rota = trim($rota);
        if ($rota === '') {
            return '/';
        }
        if (!str_starts_with($rota, '/')) {
            $rota = '/' . $rota;
        }
        $rota = '/' . trim($rota, '/');

        return $rota === '/' ? '/' : rtrim($rota, '/');
    }

    private function rotaAtual(): string
    {
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if (is_string($pathInfo) && $pathInfo !== '') {
            return $this->normalizarRota($pathInfo);
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        if (preg_match('#/index\.php(/.*)?$#', $path, $matches)) {
            $path = isset($matches[1]) && $matches[1] !== '' ? $matches[1] : '/';
        }

        return $this->normalizarRota($path);
    }

    private function ipCliente(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $chave) {
            $valor = trim((string)($_SERVER[$chave] ?? ''));
            if ($valor === '') {
                continue;
            }
            if (str_contains($valor, ',')) {
                $valor = trim(explode(',', $valor)[0]);
            }

            return substr($valor, 0, 80);
        }

        return '';
    }

    private function userAgent(): string
    {
        return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 250);
    }
}
