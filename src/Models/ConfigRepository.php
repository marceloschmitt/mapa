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

    public const ALARME_FREQUENCIA_ATIVO = 'alarme_frequencia_ativo';
    public const ALARME_FREQUENCIA_LIMITE = 'alarme_frequencia_limite';
    public const ALARME_FREQUENCIA_LIMITE_CRITICO = 'alarme_frequencia_limite_critico';
    public const ALARME_FREQUENCIA_CARENCIA_SEMANAS = 'alarme_frequencia_carencia_semanas';
    public const ALARME_FREQUENCIA_MENSAGEM = 'alarme_frequencia_mensagem';

    public const ALARME_FALTAS_DIAS_ATIVO = 'alarme_faltas_dias_ativo';
    public const ALARME_FALTAS_DIAS_MINIMO = 'alarme_faltas_dias_minimo';
    public const ALARME_FALTAS_DIAS_JANELA = 'alarme_faltas_dias_janela';
    public const ALARME_FALTAS_DIAS_CRITICO = 'alarme_faltas_dias_critico';
    public const ALARME_FALTAS_DIAS_MENSAGEM = 'alarme_faltas_dias_mensagem';

    public const ALARME_FALTAS_SEMANAS_ATIVO = 'alarme_faltas_semanas_ativo';
    public const ALARME_FALTAS_SEMANAS_TOTAL = 'alarme_faltas_semanas_total';
    public const ALARME_FALTAS_SEMANAS_JANELA_DIAS = 'alarme_faltas_semanas_janela_dias';
    public const ALARME_FALTAS_SEMANAS_SEVERIDADE = 'alarme_faltas_semanas_severidade';
    public const ALARME_FALTAS_SEMANAS_MENSAGEM = 'alarme_faltas_semanas_mensagem';

    /** Valores usados quando o administrador ainda nao configurou os alarmes. */
    public const ALARME_PADRAO = [
        'frequencia_ativo' => true,
        'frequencia_limite' => 75.0,
        'frequencia_limite_critico' => 50.0,
        'frequencia_carencia_semanas' => 3,
        'frequencia_mensagem' => 'Frequência {percentual}% (abaixo de {limite}%)',
        'faltas_dias_ativo' => true,
        'faltas_dias_minimo' => 3,
        'faltas_dias_janela' => 4,
        'faltas_dias_critico' => 4,
        'faltas_dias_mensagem' => '{dias} dias úteis: {datas}',
        'faltas_semanas_ativo' => true,
        'faltas_semanas_total' => 3,
        'faltas_semanas_janela_dias' => 7,
        'faltas_semanas_severidade' => 'critico',
        'faltas_semanas_mensagem' => 'Faltas em {semanas} semanas consecutivas na disciplina',
    ];

    public const APP_URL = 'app_url';

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed>|null */
    private $alarmeConfigCache = null;

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
        $periodo = $this->get(self::API_PERIODO_LETIVO);
        $urlMatriculados = $this->get(self::API_URL_MATRICULADOS);

        // Exibe URL sem período; se o BD ainda tiver o parâmetro embutido, limpa na tela.
        if ($periodo !== '') {
            $urlMatriculados = $this->removerPeriodoDaUrl($urlMatriculados);
        }

        return [
            'oauth_url' => $this->get(self::API_OAUTH_URL),
            'client_id' => $this->get(self::API_CLIENT_ID),
            'client_secret' => $this->get(self::API_CLIENT_SECRET),
            'url_matriculados' => $urlMatriculados,
            'url_alunos' => $this->get(self::API_URL_ALUNOS),
            'verify_ssl' => in_array(
                strtolower($this->get(self::API_VERIFY_SSL, 'false')),
                ['1', 'true', 'yes', 'on'],
                true
            ),
            'periodo_letivo' => $periodo,
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
        // Período fica só em api_periodo_letivo; a URL base não o inclui.
        $urlMatriculados = $this->removerPeriodoDaUrl($dados['url_matriculados']);

        $this->set(self::API_OAUTH_URL, $dados['oauth_url'], 'URL OAuth token da API SIGAA');
        $this->set(self::API_CLIENT_ID, $dados['client_id'], 'Client ID OAuth da API SIGAA');
        $this->set(self::API_URL_MATRICULADOS, $urlMatriculados, 'URL da consulta de matriculados (sem periodo_letivo)');
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

    /**
     * Parametros das regras de alarme (limites, janelas e mensagens).
     *
     * Lidos tambem pelo Python (python/config_alarmes.py) na geracao dos
     * alarmes: o portal e a coleta usam exatamente os mesmos valores.
     *
     * @return array{
     *   frequencia_ativo: bool,
     *   frequencia_limite: float,
     *   frequencia_limite_critico: float,
     *   frequencia_carencia_semanas: int,
     *   frequencia_mensagem: string,
     *   faltas_dias_ativo: bool,
     *   faltas_dias_minimo: int,
     *   faltas_dias_janela: int,
     *   faltas_dias_critico: int,
     *   faltas_dias_mensagem: string,
     *   faltas_semanas_ativo: bool,
     *   faltas_semanas_total: int,
     *   faltas_semanas_janela_dias: int,
     *   faltas_semanas_severidade: string,
     *   faltas_semanas_mensagem: string
     * }
     */
    public function getAlarmeConfig(): array
    {
        if ($this->alarmeConfigCache !== null) {
            return $this->alarmeConfigCache;
        }

        $statement = $this->pdo->query(
            "SELECT chave, valor FROM configuracoes WHERE chave LIKE 'alarme|_%' ESCAPE '|'"
        );
        $gravado = [];
        foreach ($statement !== false ? $statement->fetchAll() : [] as $row) {
            $gravado[(string)$row['chave']] = (string)$row['valor'];
        }

        $entrada = [];
        foreach (self::chavesAlarme() as $campo => $chave) {
            if (array_key_exists($chave, $gravado) && trim($gravado[$chave]) !== '') {
                $entrada[$campo] = $gravado[$chave];
            }
        }

        $this->alarmeConfigCache = $this->normalizarAlarmeConfig($entrada);

        return $this->alarmeConfigCache;
    }

    /**
     * Grava os parametros das regras de alarme (ja validados).
     *
     * @param array<string, mixed> $config
     */
    public function saveAlarmeConfig(array $config): void
    {
        $config = $this->normalizarAlarmeConfig($config);
        $descricoes = self::descricoesAlarme();

        foreach (self::chavesAlarme() as $campo => $chave) {
            $valor = $config[$campo];
            if (is_bool($valor)) {
                $valor = $valor ? 'true' : 'false';
            }
            $this->set($chave, (string)$valor, $descricoes[$campo] ?? null);
        }

        $this->alarmeConfigCache = $config;
    }

    /**
     * Valida a entrada do formulario de alarmes.
     *
     * @param array<string, mixed> $entrada
     * @return array{config: array<string, mixed>, erros: list<string>}
     */
    public function validarAlarmeConfig(array $entrada): array
    {
        $erros = [];
        $config = $this->normalizarAlarmeConfig($entrada);

        $limite = self::numero($entrada['frequencia_limite'] ?? null);
        if ($limite === null || $limite <= 0.0 || $limite > 100.0) {
            $erros[] = 'O limite de frequência deve ser um número entre 0,1 e 100.';
        }

        $critico = self::numero($entrada['frequencia_limite_critico'] ?? null);
        if ($critico === null || $critico < 0.0 || $critico > 100.0) {
            $erros[] = 'O limite crítico de frequência deve ser um número entre 0 e 100.';
        } elseif ($limite !== null && $critico > $limite) {
            $erros[] = 'O limite crítico não pode ser maior que o limite de frequência.';
        }

        $carencia = self::inteiro($entrada['frequencia_carencia_semanas'] ?? null);
        if ($carencia === null || $carencia < 0 || $carencia > 52) {
            $erros[] = 'A carência do início da disciplina deve ter de 0 a 52 semanas.';
        }

        $minimo = self::inteiro($entrada['faltas_dias_minimo'] ?? null);
        if ($minimo === null || $minimo < 2 || $minimo > 30) {
            $erros[] = 'O mínimo de dias úteis consecutivos deve ficar entre 2 e 30.';
        }

        $janela = self::inteiro($entrada['faltas_dias_janela'] ?? null);
        if ($janela === null || $janela < 1 || $janela > 30) {
            $erros[] = 'A janela de dias úteis recentes deve ficar entre 1 e 30.';
        }

        $diasCritico = self::inteiro($entrada['faltas_dias_critico'] ?? null);
        if ($diasCritico === null || $diasCritico < 2 || $diasCritico > 60) {
            $erros[] = 'Os dias para severidade crítica devem ficar entre 2 e 60.';
        } elseif ($minimo !== null && $diasCritico < $minimo) {
            $erros[] = 'Os dias para severidade crítica não podem ser menores que o mínimo de dias consecutivos.';
        }

        $semanas = self::inteiro($entrada['faltas_semanas_total'] ?? null);
        if ($semanas === null || $semanas < 2 || $semanas > 20) {
            $erros[] = 'O número de semanas consecutivas deve ficar entre 2 e 20.';
        }

        $janelaSemanas = self::inteiro($entrada['faltas_semanas_janela_dias'] ?? null);
        if ($janelaSemanas === null || $janelaSemanas < 1 || $janelaSemanas > 90) {
            $erros[] = 'A janela de recência das semanas deve ficar entre 1 e 90 dias.';
        }

        $severidade = strtolower(trim((string)($entrada['faltas_semanas_severidade'] ?? '')));
        if (!in_array($severidade, ['alto', 'critico'], true)) {
            $erros[] = 'Severidade inválida para a regra de semanas consecutivas.';
        }

        foreach (
            [
                'frequencia_mensagem' => 'da regra de frequência',
                'faltas_dias_mensagem' => 'da regra de dias consecutivos',
                'faltas_semanas_mensagem' => 'da regra de semanas consecutivas',
            ] as $campo => $rotulo
        ) {
            $texto = trim((string)($entrada[$campo] ?? ''));
            if ($texto === '') {
                $erros[] = 'A mensagem ' . $rotulo . ' não pode ficar em branco.';
            } elseif (mb_strlen($texto) > 200) {
                $erros[] = 'A mensagem ' . $rotulo . ' deve ter no máximo 200 caracteres.';
            }
        }

        return ['config' => $config, 'erros' => $erros];
    }

    /**
     * Aplica padroes e limites seguros a qualquer origem de dados.
     *
     * @param array<string, mixed> $entrada
     * @return array<string, mixed>
     */
    private function normalizarAlarmeConfig(array $entrada): array
    {
        $padrao = self::ALARME_PADRAO;

        $limite = self::numero($entrada['frequencia_limite'] ?? null) ?? $padrao['frequencia_limite'];
        $limite = min(100.0, max(0.1, $limite));

        $critico = self::numero($entrada['frequencia_limite_critico'] ?? null)
            ?? $padrao['frequencia_limite_critico'];
        $critico = min($limite, max(0.0, $critico));

        $minimo = self::inteiro($entrada['faltas_dias_minimo'] ?? null) ?? $padrao['faltas_dias_minimo'];
        $minimo = min(30, max(2, $minimo));

        $diasCritico = self::inteiro($entrada['faltas_dias_critico'] ?? null) ?? $padrao['faltas_dias_critico'];
        $diasCritico = min(60, max($minimo, $diasCritico));

        $severidade = strtolower(trim((string)($entrada['faltas_semanas_severidade'] ?? '')));
        if (!in_array($severidade, ['alto', 'critico'], true)) {
            $severidade = (string)$padrao['faltas_semanas_severidade'];
        }

        return [
            'frequencia_ativo' => self::booleano($entrada['frequencia_ativo'] ?? null, (bool)$padrao['frequencia_ativo']),
            'frequencia_limite' => round($limite, 1),
            'frequencia_limite_critico' => round($critico, 1),
            'frequencia_carencia_semanas' => min(52, max(0, self::inteiro($entrada['frequencia_carencia_semanas'] ?? null)
                ?? $padrao['frequencia_carencia_semanas'])),
            'frequencia_mensagem' => self::texto(
                $entrada['frequencia_mensagem'] ?? null,
                (string)$padrao['frequencia_mensagem']
            ),
            'faltas_dias_ativo' => self::booleano($entrada['faltas_dias_ativo'] ?? null, (bool)$padrao['faltas_dias_ativo']),
            'faltas_dias_minimo' => $minimo,
            'faltas_dias_janela' => min(30, max(1, self::inteiro($entrada['faltas_dias_janela'] ?? null)
                ?? $padrao['faltas_dias_janela'])),
            'faltas_dias_critico' => $diasCritico,
            'faltas_dias_mensagem' => self::texto(
                $entrada['faltas_dias_mensagem'] ?? null,
                (string)$padrao['faltas_dias_mensagem']
            ),
            'faltas_semanas_ativo' => self::booleano(
                $entrada['faltas_semanas_ativo'] ?? null,
                (bool)$padrao['faltas_semanas_ativo']
            ),
            'faltas_semanas_total' => min(20, max(2, self::inteiro($entrada['faltas_semanas_total'] ?? null)
                ?? $padrao['faltas_semanas_total'])),
            'faltas_semanas_janela_dias' => min(90, max(1, self::inteiro($entrada['faltas_semanas_janela_dias'] ?? null)
                ?? $padrao['faltas_semanas_janela_dias'])),
            'faltas_semanas_severidade' => $severidade,
            'faltas_semanas_mensagem' => self::texto(
                $entrada['faltas_semanas_mensagem'] ?? null,
                (string)$padrao['faltas_semanas_mensagem']
            ),
        ];
    }

    /** @return array<string, string> campo do formulario => chave em configuracoes */
    public static function chavesAlarme(): array
    {
        return [
            'frequencia_ativo' => self::ALARME_FREQUENCIA_ATIVO,
            'frequencia_limite' => self::ALARME_FREQUENCIA_LIMITE,
            'frequencia_limite_critico' => self::ALARME_FREQUENCIA_LIMITE_CRITICO,
            'frequencia_carencia_semanas' => self::ALARME_FREQUENCIA_CARENCIA_SEMANAS,
            'frequencia_mensagem' => self::ALARME_FREQUENCIA_MENSAGEM,
            'faltas_dias_ativo' => self::ALARME_FALTAS_DIAS_ATIVO,
            'faltas_dias_minimo' => self::ALARME_FALTAS_DIAS_MINIMO,
            'faltas_dias_janela' => self::ALARME_FALTAS_DIAS_JANELA,
            'faltas_dias_critico' => self::ALARME_FALTAS_DIAS_CRITICO,
            'faltas_dias_mensagem' => self::ALARME_FALTAS_DIAS_MENSAGEM,
            'faltas_semanas_ativo' => self::ALARME_FALTAS_SEMANAS_ATIVO,
            'faltas_semanas_total' => self::ALARME_FALTAS_SEMANAS_TOTAL,
            'faltas_semanas_janela_dias' => self::ALARME_FALTAS_SEMANAS_JANELA_DIAS,
            'faltas_semanas_severidade' => self::ALARME_FALTAS_SEMANAS_SEVERIDADE,
            'faltas_semanas_mensagem' => self::ALARME_FALTAS_SEMANAS_MENSAGEM,
        ];
    }

    /** @return array<string, string> */
    private static function descricoesAlarme(): array
    {
        return [
            'frequencia_ativo' => 'Gerar alarmes de frequência abaixo do limite',
            'frequencia_limite' => 'Limite de frequência (%) que gera alarme',
            'frequencia_limite_critico' => 'Frequência (%) abaixo da qual o alarme é crítico',
            'frequencia_carencia_semanas' => 'Semanas de carência após o início da disciplina',
            'frequencia_mensagem' => 'Mensagem do alarme de frequência (placeholders entre chaves)',
            'faltas_dias_ativo' => 'Gerar alarmes de faltas em dias úteis consecutivos',
            'faltas_dias_minimo' => 'Mínimo de dias úteis consecutivos de falta',
            'faltas_dias_janela' => 'Janela de dias úteis recentes considerada',
            'faltas_dias_critico' => 'Dias consecutivos a partir dos quais o alarme é crítico',
            'faltas_dias_mensagem' => 'Mensagem do alarme de dias consecutivos',
            'faltas_semanas_ativo' => 'Gerar alarmes de faltas em semanas consecutivas',
            'faltas_semanas_total' => 'Semanas consecutivas com falta que geram alarme',
            'faltas_semanas_janela_dias' => 'Dias de recência da última falta da sequência',
            'faltas_semanas_severidade' => 'Severidade do alarme de semanas consecutivas',
            'faltas_semanas_mensagem' => 'Mensagem do alarme de semanas consecutivas',
        ];
    }

    private static function numero(mixed $valor): ?float
    {
        if (is_float($valor) || is_int($valor)) {
            return (float)$valor;
        }

        $texto = str_replace(',', '.', trim((string)$valor));
        if ($texto === '' || !is_numeric($texto)) {
            return null;
        }

        return (float)$texto;
    }

    private static function inteiro(mixed $valor): ?int
    {
        $numero = self::numero($valor);
        if ($numero === null || $numero != (int)$numero) {
            return null;
        }

        return (int)$numero;
    }

    private static function booleano(mixed $valor, bool $padrao): bool
    {
        if ($valor === null) {
            return $padrao;
        }

        if (is_bool($valor)) {
            return $valor;
        }

        return in_array(strtolower(trim((string)$valor)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function texto(mixed $valor, string $padrao): string
    {
        $texto = trim((string)($valor ?? ''));
        if ($texto === '') {
            return $padrao;
        }

        return mb_substr($texto, 0, 200);
    }

    /**
     * URL pública do portal (descoberta nas visitas web; usada nos e-mails CLI).
     * APP_URL no .env, se preenchido, tem prioridade.
     */
    public function getAppUrl(): string
    {
        $env = rtrim(trim(\Mapa\Core\Env::get('APP_URL', '')), '/');
        if ($env !== '') {
            return $env;
        }

        return rtrim(trim($this->get(self::APP_URL)), '/');
    }

    /**
     * Grava a URL pública detectada no pedido HTTP atual (no-op em CLI).
     */
    public function lembrarAppUrlDoPedido(): void
    {
        $detectada = \Mapa\Core\Url::detectPublicBase();
        if ($detectada === null || $detectada === '') {
            return;
        }

        if ($this->get(self::APP_URL) === $detectada) {
            return;
        }

        $this->set(self::APP_URL, $detectada, 'URL pública do portal (detectada automaticamente)');
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

    /**
     * Remove periodo_letivo da URL base (fica só no campo api_periodo_letivo).
     */
    private function removerPeriodoDaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        $url = str_replace(['{periodo_letivo}', rawurlencode('{periodo_letivo}')], '', $url);
        $url = (string)preg_replace('/([?&])periodo_letivo=[^&]*/', '$1', $url);
        $url = (string)preg_replace('/\?&+/', '?', $url);
        $url = (string)preg_replace('/&&+/', '&', $url);
        $url = (string)preg_replace('/[?&]$/', '', $url);

        return $url;
    }

    /**
     * Acrescenta o período configurado à URL na hora da execução.
     */
    public function aplicarPeriodoNaUrl(string $url, string $periodo): string
    {
        $url = $this->removerPeriodoDaUrl($url);
        $periodo = trim($periodo);
        if ($periodo === '') {
            return $url;
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
