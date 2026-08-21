<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

class ConfigRepository
{
    public const LDAP_HOST = 'ldap_host';
    public const LDAP_BASE_DN = 'ldap_base_dn';
    public const LDAP_BIND_DN = 'ldap_bind_dn';
    public const LDAP_BIND_PASSWORD = 'ldap_bind_password';
    public const LDAP_USER_ATTRIBUTE = 'ldap_user_attribute';

    public const API_OAUTH_URL = 'api_oauth_url';
    public const API_CLIENT_ID = 'api_client_id';
    public const API_CLIENT_SECRET = 'api_client_secret';
    public const API_URL_MATRICULADOS = 'api_url_matriculados';
    public const API_URL_ALUNOS = 'api_url_alunos';
    public const API_VERIFY_SSL = 'api_verify_ssl';
    public const API_PERIODO_LETIVO = 'api_periodo_letivo';
    public const FREQUENCIA_DATA_INICIAL = 'frequencia_data_inicial';
    public const FREQUENCIA_DATA_FINAL = 'frequencia_data_final';
    public const DATA_REFERENCIA = 'data_referencia';

    public const EMAIL_ENABLED = 'email_enabled';
    public const EMAIL_ALARMES_ENABLED = 'email_alarmes_enabled';
    public const EMAIL_ALARMES_ALUNOS_ENABLED = 'email_alarmes_alunos_enabled';
    public const EMAIL_ALARMES_STAFF_ENABLED = 'email_alarmes_staff_enabled';
    public const EMAIL_HOST = 'email_host';
    public const EMAIL_PORT = 'email_port';
    public const EMAIL_ENCRYPTION = 'email_encryption';
    public const EMAIL_USERNAME = 'email_username';
    public const EMAIL_PASSWORD = 'email_password';
    public const EMAIL_FROM_ADDRESS = 'email_from_address';
    public const EMAIL_FROM_NAME = 'email_from_name';

    /** @var PDO */
    private $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function get(string $chave, string $default = ''): string
    {
        $statement = $this->pdo->prepare(
            'SELECT valor FROM configuracoes WHERE chave = :chave LIMIT 1'
        );
        $statement->execute(['chave' => $chave]);
        $valor = $statement->fetchColumn();

        return $valor === false ? $default : (string)$valor;
    }

    public function set(string $chave, string $valor, ?string $descricao = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO configuracoes (chave, valor, descricao, atualizado_em)
             VALUES (:chave, :valor, :descricao, datetime(\'now\'))
             ON CONFLICT(chave) DO UPDATE SET
                valor = excluded.valor,
                descricao = COALESCE(excluded.descricao, configuracoes.descricao),
                atualizado_em = datetime(\'now\')'
        );
        $statement->execute([
            'chave' => $chave,
            'valor' => $valor,
            'descricao' => $descricao,
        ]);
    }

    /** @return array{host: string, base_dn: string, bind_dn: string, bind_password: string, user_attribute: string} */
    public function getLdapConfig(): array
    {
        return [
            'host' => $this->get(self::LDAP_HOST),
            'base_dn' => $this->get(self::LDAP_BASE_DN),
            'bind_dn' => $this->get(self::LDAP_BIND_DN),
            'bind_password' => $this->get(self::LDAP_BIND_PASSWORD),
            'user_attribute' => $this->get(self::LDAP_USER_ATTRIBUTE, 'sAMAccountName'),
        ];
    }

    /**
     * @param array{
     *   host: string,
     *   base_dn: string,
     *   bind_dn: string,
     *   user_attribute: string,
     *   bind_password?: string|null
     * } $dados
     */
    public function saveLdapConfig(array $dados): void
    {
        $this->set(self::LDAP_HOST, $dados['host'], 'Endereço do servidor LDAP');
        $this->set(self::LDAP_BASE_DN, $dados['base_dn'], 'Base DN para busca de usuários');
        $this->set(self::LDAP_BIND_DN, $dados['bind_dn'], 'DN para bind administrativo (opcional)');
        $this->set(
            self::LDAP_USER_ATTRIBUTE,
            $dados['user_attribute'],
            'Atributo usado para buscar usuários (uid, sAMAccountName, etc.)'
        );

        if (array_key_exists('bind_password', $dados) && $dados['bind_password'] !== null && $dados['bind_password'] !== '') {
            $this->set(
                self::LDAP_BIND_PASSWORD,
                (string)$dados['bind_password'],
                'Senha do bind administrativo LDAP'
            );
        }
    }

    public function hasLdapBindPassword(): bool
    {
        return trim($this->get(self::LDAP_BIND_PASSWORD)) !== '';
    }

    public function isLdapConfigured(): bool
    {
        $config = $this->getLdapConfig();

        return $config['host'] !== ''
            && $config['base_dn'] !== ''
            && $config['user_attribute'] !== '';
    }

    /**
     * @return array{
     *   oauth_url: string,
     *   client_id: string,
     *   client_secret: string,
     *   url_matriculados: string,
     *   url_alunos: string,
     *   verify_ssl: bool,
     *   periodo_letivo: string,
     *   frequencia_data_inicial: string,
     *   frequencia_data_final: string,
     *   data_referencia: string
     * }
     */
    public function getApiConfig(): array
    {
        return [
            'oauth_url' => $this->get(self::API_OAUTH_URL),
            'client_id' => $this->get(self::API_CLIENT_ID),
            'client_secret' => $this->get(self::API_CLIENT_SECRET),
            'url_matriculados' => $this->get(self::API_URL_MATRICULADOS),
            'url_alunos' => $this->get(self::API_URL_ALUNOS),
            'verify_ssl' => in_array(
                strtolower($this->get(self::API_VERIFY_SSL, 'false')),
                ['1', 'true', 'yes', 'on'],
                true
            ),
            'periodo_letivo' => $this->get(self::API_PERIODO_LETIVO),
            'frequencia_data_inicial' => $this->get(self::FREQUENCIA_DATA_INICIAL),
            'frequencia_data_final' => $this->get(self::FREQUENCIA_DATA_FINAL),
            'data_referencia' => $this->get(self::DATA_REFERENCIA, 'hoje-2'),
        ];
    }

    /**
     * @param array{
     *   oauth_url: string,
     *   client_id: string,
     *   url_matriculados: string,
     *   url_alunos: string,
     *   verify_ssl: bool,
     *   periodo_letivo: string,
     *   frequencia_data_inicial: string,
     *   frequencia_data_final: string,
     *   data_referencia: string,
     *   client_secret?: string|null
     * } $dados
     */
    public function saveApiConfig(array $dados): void
    {
        $urlMatriculados = $this->aplicarPeriodoNaUrl(
            $dados['url_matriculados'],
            $dados['periodo_letivo']
        );

        $this->set(self::API_OAUTH_URL, $dados['oauth_url'], 'URL OAuth token da API SIGAA');
        $this->set(self::API_CLIENT_ID, $dados['client_id'], 'Client ID OAuth da API SIGAA');
        $this->set(self::API_URL_MATRICULADOS, $urlMatriculados, 'URL da consulta de matriculados');
        $this->set(self::API_URL_ALUNOS, $dados['url_alunos'], 'URL da consulta de alunos (use {login})');
        $this->set(
            self::API_VERIFY_SSL,
            !empty($dados['verify_ssl']) ? 'true' : 'false',
            'Verificar certificado SSL nas consultas Python'
        );
        $this->set(self::API_PERIODO_LETIVO, $dados['periodo_letivo'], 'Período letivo da coleta (ex.: 2026/2)');
        $this->set(
            self::FREQUENCIA_DATA_INICIAL,
            $dados['frequencia_data_inicial'],
            'Data inicial do intervalo de frequência'
        );
        $this->set(
            self::FREQUENCIA_DATA_FINAL,
            $dados['frequencia_data_final'],
            'Data final do intervalo de frequência'
        );
        $this->set(
            self::DATA_REFERENCIA,
            $dados['data_referencia'],
            'Data de referência dos alarmes (hoje-2 ou DD-MM-AAAA)'
        );

        if (array_key_exists('client_secret', $dados)
            && $dados['client_secret'] !== null
            && $dados['client_secret'] !== ''
        ) {
            $this->set(
                self::API_CLIENT_SECRET,
                (string)$dados['client_secret'],
                'Client Secret OAuth da API SIGAA'
            );
        }

        $this->sincronizarConsultasJson(
            $dados['frequencia_data_inicial'],
            $dados['frequencia_data_final'],
            $dados['data_referencia']
        );
    }

    public function hasApiClientSecret(): bool
    {
        return trim($this->get(self::API_CLIENT_SECRET)) !== '';
    }

    public function isApiConfigured(): bool
    {
        $config = $this->getApiConfig();

        return $config['oauth_url'] !== ''
            && $config['client_id'] !== ''
            && $config['url_matriculados'] !== ''
            && $config['url_alunos'] !== ''
            && $this->hasApiClientSecret();
    }

    /**
     * @return array{
     *   enabled: bool,
     *   host: string,
     *   port: int,
     *   encryption: string,
     *   username: string,
     *   password: string,
     *   from_address: string,
     *   from_name: string
     * }
     */
    public function getEmailConfig(): array
    {
        $port = (int)$this->get(self::EMAIL_PORT, '587');
        if ($port <= 0) {
            $port = 587;
        }

        $encryption = strtolower(trim($this->get(self::EMAIL_ENCRYPTION, 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        $legadoAlarmes = in_array(
            strtolower($this->get(self::EMAIL_ALARMES_ENABLED, 'false')),
            ['1', 'true', 'yes', 'on'],
            true
        );
        $alarmesAlunos = $this->get(self::EMAIL_ALARMES_ALUNOS_ENABLED, '');
        $alarmesStaff = $this->get(self::EMAIL_ALARMES_STAFF_ENABLED, '');

        return [
            'enabled' => in_array(
                strtolower($this->get(self::EMAIL_ENABLED, 'false')),
                ['1', 'true', 'yes', 'on'],
                true
            ),
            'alarmes_enabled' => $legadoAlarmes,
            'alarmes_alunos_enabled' => $alarmesAlunos === ''
                ? $legadoAlarmes
                : in_array(strtolower($alarmesAlunos), ['1', 'true', 'yes', 'on'], true),
            'alarmes_staff_enabled' => $alarmesStaff === ''
                ? $legadoAlarmes
                : in_array(strtolower($alarmesStaff), ['1', 'true', 'yes', 'on'], true),
            'host' => $this->get(self::EMAIL_HOST),
            'port' => $port,
            'encryption' => $encryption,
            'username' => $this->get(self::EMAIL_USERNAME),
            'password' => $this->get(self::EMAIL_PASSWORD),
            'from_address' => $this->get(self::EMAIL_FROM_ADDRESS),
            'from_name' => $this->get(self::EMAIL_FROM_NAME, 'MAPA'),
        ];
    }

    /**
     * @param array{
     *   enabled: bool,
     *   host: string,
     *   port: int|string,
     *   encryption: string,
     *   username: string,
     *   from_address: string,
     *   from_name: string,
     *   password?: string|null
     * } $dados
     */
    public function saveEmailConfig(array $dados): void
    {
        $this->set(
            self::EMAIL_ENABLED,
            !empty($dados['enabled']) ? 'true' : 'false',
            'Enviar e-mails automaticos de chamadas'
        );
        $this->set(
            self::EMAIL_ALARMES_ENABLED,
            !empty($dados['alarmes_alunos_enabled']) ? 'true' : 'false',
            'Enviar e-mails automaticos de alarmes criticos aos alunos (legado)'
        );
        $this->set(
            self::EMAIL_ALARMES_ALUNOS_ENABLED,
            !empty($dados['alarmes_alunos_enabled']) ? 'true' : 'false',
            'Enviar e-mails automaticos de alarmes criticos aos alunos'
        );
        $this->set(
            self::EMAIL_ALARMES_STAFF_ENABLED,
            !empty($dados['alarmes_staff_enabled']) ? 'true' : 'false',
            'Enviar avisos de alarmes a professores e coordenadores'
        );
        $this->set(self::EMAIL_HOST, trim((string)$dados['host']), 'Host SMTP');
        $this->set(self::EMAIL_PORT, (string)(int)$dados['port'], 'Porta SMTP');
        $this->set(
            self::EMAIL_ENCRYPTION,
            strtolower(trim((string)$dados['encryption'])),
            'Criptografia SMTP (tls, ssl ou none)'
        );
        $this->set(self::EMAIL_USERNAME, trim((string)$dados['username']), 'Usuario SMTP');
        $this->set(
            self::EMAIL_FROM_ADDRESS,
            trim((string)$dados['from_address']),
            'Remetente (From)'
        );
        $this->set(
            self::EMAIL_FROM_NAME,
            trim((string)$dados['from_name']),
            'Nome do remetente'
        );

        if (array_key_exists('password', $dados)
            && $dados['password'] !== null
            && $dados['password'] !== ''
        ) {
            $this->set(self::EMAIL_PASSWORD, (string)$dados['password'], 'Senha SMTP');
        }
    }

    public function hasEmailPassword(): bool
    {
        return trim($this->get(self::EMAIL_PASSWORD)) !== '';
    }

    public function isEmailConfigured(): bool
    {
        $config = $this->getEmailConfig();

        return $config['host'] !== ''
            && $config['from_address'] !== ''
            && $config['port'] > 0;
    }

    /**
     * Trava de ambiente (.env): se EMAIL_SEND nao for true, nenhum e-mail sai.
     * Independente do interruptor gravado no banco.
     */
    public function permiteEnvioEmail(): bool
    {
        return \Mapa\Core\Env::getBool('EMAIL_SEND', false);
    }

    private function aplicarPeriodoNaUrl(string $url, string $periodo): string
    {
        $periodo = trim($periodo);
        if ($periodo === '') {
            return $url;
        }

        if (strpos($url, '{periodo_letivo}') !== false) {
            return str_replace('{periodo_letivo}', $periodo, $url);
        }

        if (preg_match('/([?&])periodo_letivo=[^&]*/', $url)) {
            return (string)preg_replace(
                '/([?&])periodo_letivo=[^&]*/',
                '$1periodo_letivo=' . $periodo,
                $url,
                1
            );
        }

        $separador = strpos($url, '?') === false ? '?' : '&';
        return $url . $separador . 'periodo_letivo=' . $periodo;
    }

    private function sincronizarConsultasJson(
        string $dataInicial,
        string $dataFinal,
        string $dataReferencia
    ): void {
        $path = 'config/consultas.json';
        $payload = [
            'frequencia_data_inicial' => $dataInicial,
            'frequencia_data_final' => $dataFinal,
            'data_referencia' => $dataReferencia,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        file_put_contents($path, $json . PHP_EOL);
    }
}
