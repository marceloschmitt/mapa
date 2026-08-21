<?php
declare(strict_types=1);

namespace Mapa\Services;

use Mapa\Models\ConfigRepository;

/**
 * Autenticacao LDAP no mesmo fluxo do EduCuidar:
 * bind administrativo -> busca do usuario -> bind com a senha do usuario.
 * Parametros vêm da tabela configuracoes (tela /configuracoes/ldap).
 *
 * Antes de chamar a extensao LDAP, testa TCP com timeout. Sem isso, o
 * libldap do PHP pode travar ou gerar segmentation fault quando o
 * servidor esta inacessivel (firewall).
 */
class LdapAuthService
{
    private const TIMEOUT_SEGUNDOS = 3;

    /** @var string */
    private $lastError = '';

    /** @var ConfigRepository */
    private $config;

    public function __construct(?ConfigRepository $config = null)
    {
        $this->config = $config ?? new ConfigRepository();
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function authenticate(string $username, string $password): bool
    {
        $this->lastError = '';
        $username = trim($username);
        $password = (string)$password;

        if ($username === '' || $password === '') {
            $this->lastError = 'Usuário ou senha não informados.';
            return false;
        }

        if (!function_exists('ldap_connect')) {
            $this->lastError = 'Extensão PHP LDAP não está instalada.';
            throw new \RuntimeException($this->lastError);
        }

        $ldap = $this->config->getLdapConfig();
        $host = trim($ldap['host']);
        $baseDn = trim($ldap['base_dn']);
        $bindDn = trim($ldap['bind_dn']);
        $bindPassword = (string)$ldap['bind_password'];
        $userAttribute = trim($ldap['user_attribute']);

        if ($host === '' || $baseDn === '' || $userAttribute === '') {
            $this->lastError = 'LDAP não configurado. Acesse Configurações LDAP no painel do administrador.';
            return false;
        }

        $alvo = $this->parseHostPort($host);
        if (!$this->portaAberta($alvo['host'], $alvo['port'])) {
            $this->lastError = 'Servidor LDAP inacessível (' . $alvo['host'] . ':' . $alvo['port']
                . '). Tempo esgotado — verifique rede/VPN/firewall.';
            return false;
        }

        $connection = @ldap_connect($host);
        if ($connection === false) {
            $this->lastError = 'Falha ao conectar ao servidor LDAP ' . $host . '.';
            return false;
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, self::TIMEOUT_SEGUNDOS);
        ldap_set_option($connection, LDAP_OPT_TIMELIMIT, self::TIMEOUT_SEGUNDOS);
        if (defined('LDAP_OPT_TIMEOUT')) {
            ldap_set_option($connection, LDAP_OPT_TIMEOUT, self::TIMEOUT_SEGUNDOS);
        }

        $adminOk = false;
        if ($bindDn !== '' && $bindPassword !== '') {
            $adminOk = @ldap_bind($connection, $bindDn, $bindPassword);
            if (!$adminOk) {
                $this->lastError = 'Falha no bind administrativo LDAP (' . ldap_error($connection) . ').';
            }
        } else {
            $adminOk = @ldap_bind($connection);
            if (!$adminOk) {
                $this->lastError = 'Falha no bind anônimo LDAP (' . ldap_error($connection) . ').';
            }
        }

        if (!$adminOk) {
            @ldap_unbind($connection);
            return false;
        }

        // Mesmo criterio do EduCuidar: se atributo for uid, busca por sAMAccountName no AD.
        $searchAttribute = $userAttribute === 'uid' ? 'sAMAccountName' : $userAttribute;
        $escapedUsername = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $filter = '(&(objectClass=user)(' . $searchAttribute . '=' . $escapedUsername . '))';

        $search = @ldap_search($connection, $baseDn, $filter, ['dn']);
        if ($search === false) {
            $this->lastError = 'Falha na busca LDAP do usuário (' . ldap_error($connection) . ').';
            @ldap_unbind($connection);
            return false;
        }

        $entries = @ldap_get_entries($connection, $search);
        if ($entries === false || (int)$entries['count'] !== 1) {
            $count = is_array($entries) ? (int)$entries['count'] : 0;
            $this->lastError = "Usuário LDAP '{$username}' não encontrado ou duplicado (resultados: {$count}).";
            @ldap_unbind($connection);
            return false;
        }

        $userDn = (string)$entries[0]['dn'];
        if (!@ldap_bind($connection, $userDn, $password)) {
            $this->lastError = 'Senha LDAP inválida.';
            @ldap_unbind($connection);
            return false;
        }

        @ldap_unbind($connection);
        return true;
    }

    /**
     * @return array{host: string, port: int}
     */
    private function parseHostPort(string $uri): array
    {
        $uri = trim($uri);
        $scheme = 'ldap';
        $host = $uri;
        $port = 389;

        if (preg_match('#^(ldaps?)://(.+)$#i', $uri, $m)) {
            $scheme = strtolower($m[1]);
            $host = $m[2];
        }

        if ($scheme === 'ldaps') {
            $port = 636;
        }

        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $host, $m)) {
            return ['host' => $m[1], 'port' => (int)$m[2]];
        }
        if (preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
            return ['host' => $m[1], 'port' => (int)$m[2]];
        }

        return ['host' => $host, 'port' => $port];
    }

    private function portaAberta(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT_SEGUNDOS);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }
}
