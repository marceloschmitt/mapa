<?php
declare(strict_types=1);

namespace Mapa\Services;

use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Lib\SmtpMailer;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ConfigRepository;
use Mapa\Models\CursoCoordenacaoRepository;
use PDO;
use Throwable;

class AlarmeEmailService
{
    public const CONTATO_AUTOMATICO = 'email_automatico';

    public const INTERVALO_DIAS = 7;

    private PDO $pdo;
    private ConfigRepository $config;
    private AnalyticsRepository $analytics;
    private CursoCoordenacaoRepository $cursoCoordenacao;

    public function __construct(
        ?PDO $pdo = null,
        ?ConfigRepository $config = null,
        ?AnalyticsRepository $analytics = null,
        ?CursoCoordenacaoRepository $cursoCoordenacao = null
    ) {
        $this->pdo = $pdo ?? Database::connection();
        $this->config = $config ?? new ConfigRepository($this->pdo);
        $this->analytics = $analytics ?? new AnalyticsRepository();
        $this->cursoCoordenacao = $cursoCoordenacao ?? new CursoCoordenacaoRepository($this->pdo);
    }

    /**
     * Executa envio aos alunos e avisos ao staff (fluxos independentes).
     *
     * @return array{enviados: int, avisos_staff: int, ignorados: int, falhas: int, mensagens: list<string>}
     */
    public function processar(?int $coletaId = null): array
    {
        $alunos = $this->processarAlunos($coletaId);
        $staff = $this->processarStaff();

        return [
            'enviados' => $alunos['enviados'],
            'avisos_staff' => $staff['enviados'],
            'ignorados' => $alunos['ignorados'] + $staff['ignorados'],
            'falhas' => $alunos['falhas'] + $staff['falhas'],
            'mensagens' => array_merge($alunos['mensagens'], $staff['mensagens']),
        ];
    }

    /**
     * E-mails de acolhimento aos alunos com alarme critico aberto.
     *
     * @return array{enviados: int, ignorados: int, falhas: int, mensagens: list<string>}
     */
    public function processarAlunos(?int $coletaId = null): array
    {
        $resumo = $this->resumoParcial();

        $mailer = $this->prepararMailer('alunos', $resumo);
        if ($mailer === null) {
            return $resumo;
        }

        $coleta = $coletaId !== null
            ? ['id' => $coletaId]
            : $this->analytics->ultimaColeta();
        if ($coleta === null) {
            $resumo['mensagens'][] = 'Nenhuma coleta importada.';
            return $resumo;
        }
        $coletaId = (int)$coleta['id'];

        $grupos = $this->agruparAlarmesParaEnvio($coletaId);
        if ($grupos === []) {
            $resumo['mensagens'][] = 'Nenhum alarme critico pendente de e-mail ao aluno.';
            return $resumo;
        }

        $recentes = $this->enviosRecentes();

        foreach ($grupos as $grupo) {
            $destinatario = trim((string)($grupo['email'] ?? ''));
            $alunoId = (int)($grupo['aluno_id'] ?? 0);
            $emailNorm = strtolower($destinatario);

            if (isset($recentes['alunos'][$alunoId]) || ($emailNorm !== '' && isset($recentes['emails'][$emailNorm]))) {
                $resumo['ignorados']++;
                continue;
            }

            if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
                $resumo['ignorados']++;
                $resumo['mensagens'][] = 'Sem e-mail valido: '
                    . trim((string)($grupo['nome'] ?? ''))
                    . ' (' . trim((string)($grupo['matricula'] ?? '')) . ')';
                continue;
            }

            $assunto = $this->montarAssunto();
            $corpo = $this->montarMensagem($grupo);

            try {
                $mailer->send([$destinatario], $assunto, $corpo);
                $this->registrarEnvio($coletaId, $grupo, $destinatario);
                $this->marcarAlarmesEnviados($grupo['alarme_ids']);
                $recentes['alunos'][$alunoId] = true;
                $recentes['emails'][$emailNorm] = true;
                $resumo['enviados']++;
            } catch (Throwable $e) {
                $resumo['falhas']++;
                $resumo['mensagens'][] = 'Falha aluno '
                    . trim((string)($grupo['matricula'] ?? ''))
                    . ': '
                    . $e->getMessage();
            }
        }

        return $resumo;
    }

    /**
     * Resumos a professores/coordenadores sobre e-mails ja enviados aos alunos.
     *
     * @return array{enviados: int, ignorados: int, falhas: int, mensagens: list<string>}
     */
    public function processarStaff(): array
    {
        $resumo = $this->resumoParcial();

        $mailer = $this->prepararMailer('staff', $resumo);
        if ($mailer === null) {
            return $resumo;
        }

        if (!$this->diaPermitidoParaEnvio('staff')) {
            $resumo['mensagens'][] = 'Envio ao staff permitido apenas as '
                . $this->rotuloDiaEnvio('staff')
                . ' (fuso America/Sao_Paulo).';
            return $resumo;
        }

        $cancelados = $this->cancelarAvisosStaffAlunosAusentes();
        if ($cancelados > 0) {
            $resumo['mensagens'][] = "Avisos staff cancelados (aluno ausente da coleta): {$cancelados}.";
        }

        $grupos = $this->gruposDeEnviosAlunoPendentesStaff();
        if ($grupos === []) {
            $resumo['mensagens'][] = 'Nenhum e-mail de aluno pendente de aviso ao staff.';
            return $resumo;
        }

        $this->enviarResumosStaff($mailer, $grupos, $resumo);

        return $resumo;
    }

    /**
     * Fecha avisos pendentes ao staff para alunos que nao estao na ultima coleta.
     *
     * @return int Quantidade de registros fechados
     */
    private function cancelarAvisosStaffAlunosAusentes(): int
    {
        $coleta = $this->analytics->ultimaColeta();
        if ($coleta === null) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'UPDATE alarme_emails
             SET staff_avisado_em = datetime(\'now\')
             WHERE staff_avisado_em IS NULL
               AND (
                   NOT EXISTS (
                       SELECT 1
                       FROM frequencia_curso fc
                       WHERE fc.coleta_id = :coleta_id
                         AND fc.aluno_id = alarme_emails.aluno_id
                         AND fc.curso_id = alarme_emails.curso_id
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM alunos_trancados at
                       WHERE at.coleta_id = :coleta_id2
                         AND at.aluno_id = alarme_emails.aluno_id
                         AND at.curso_id = alarme_emails.curso_id
                   )
               )'
        );
        $statement->execute([
            'coleta_id' => (int)$coleta['id'],
            'coleta_id2' => (int)$coleta['id'],
        ]);

        return $statement->rowCount();
    }

    /**
     * @return array{enviados: int, ignorados: int, falhas: int, mensagens: list<string>}
     */
    private function resumoParcial(): array
    {
        return [
            'enviados' => 0,
            'ignorados' => 0,
            'falhas' => 0,
            'mensagens' => [],
        ];
    }

    /**
     * @param array{enviados: int, ignorados: int, falhas: int, mensagens: list<string>} $resumo
     */
    private function prepararMailer(string $tipo, array &$resumo): ?SmtpMailer
    {
        if (!$this->config->permiteEnvioEmail()) {
            $resumo['mensagens'][] = 'Envio bloqueado neste ambiente (EMAIL_SEND=false no .env).';
            return null;
        }

        $emailConfig = $this->config->getEmailConfig();
        if ($tipo === 'alunos' && !$emailConfig['alarmes_alunos_enabled']) {
            $resumo['mensagens'][] = 'Envio automatico de alarmes aos alunos desligado na configuracao.';
            return null;
        }
        if ($tipo === 'staff' && !$emailConfig['alarmes_staff_enabled']) {
            $resumo['mensagens'][] = 'Envio automatico de avisos ao staff desligado na configuracao.';
            return null;
        }

        if (!$this->config->isEmailConfigured()) {
            $resumo['mensagens'][] = 'Servidor de e-mail incompleto (host/remetente).';
            $resumo['falhas']++;
            return null;
        }

        return new SmtpMailer($emailConfig);
    }

    public function montarAssunto(): string
    {
        $app = $this->stringsApp();

        return $app['short_name'] . ' — Queremos te apoiar a continuar nos estudos';
    }

    public function montarAssuntoStaffResumo(int $totalAlunos, array $papeis = []): string
    {
        $app = $this->stringsApp();
        $destino = $this->rotuloDestinatarioStaff($papeis);
        $rotulo = $totalAlunos === 1 ? '1 aluno' : "{$totalAlunos} alunos";

        return $app['short_name'] . " — Aviso para {$destino}: {$rotulo} com risco de evasão";
    }

    /**
     * @param list<array{
     *   nome: string,
     *   matricula: string,
     *   nome_curso: string,
     *   alarmes?: list<array<string, mixed>>
     * }> $entradas
     * @param list<string> $papeis
     */
    public function montarMensagemStaffResumo(array $entradas, array $papeis = []): string
    {
        $app = $this->stringsApp();
        $nomeMapa = $app['full_name'];
        $nomeCurto = $app['short_name'];
        $destino = $this->rotuloDestinatarioStaff($papeis);

        $hoje = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $data = $hoje->format('d/m/Y');

        $linhas = $this->linhasAlunosStaff($entradas, $papeis);

        $linkServidor = $this->urlPublicaServidor();
        $instrucaoAcesso = $linkServidor !== ''
            ? "Se desejar visualizar os detalhes associados aos riscos, entre em {$linkServidor} e selecione Alarmes."
            : 'Se desejar visualizar os detalhes associados aos riscos, acesse o sistema MAPA e selecione Alarmes.';

        return "Esta é uma mensagem automática do {$nomeMapa} ({$nomeCurto}), "
            . "destinada a {$destino}.\n\n"
            . "No dia {$data}, constatou-se algum risco de abandono ou evasão "
            . "dos alunos listados abaixo. Foi-lhes enviado um e-mail automático. "
            . $instrucaoAcesso
            . "\n\n"
            . implode("\n", $linhas)
            . "\n\n"
            . "Este e-mail é apenas informativo. Por favor, não responda a esta mensagem.\n\n"
            . "Atenciosamente,\n"
            . $nomeCurto;
    }

    /**
     * @param list<string> $papeis
     */
    private function rotuloDestinatarioStaff(array $papeis): string
    {
        $temCoord = in_array('coordenador', $papeis, true);
        $temProf = in_array('professor', $papeis, true);

        if ($temCoord) {
            return 'coordenadores de curso';
        }
        if ($temProf) {
            return 'professores';
        }

        return 'coordenadores de curso ou professores';
    }

    /**
     * @param list<array{
     *   nome: string,
     *   matricula: string,
     *   nome_curso: string,
     *   alarmes?: list<array<string, mixed>>
     * }> $entradas
     * @param list<string> $papeis
     * @return list<string>
     */
    private function linhasAlunosStaff(array $entradas, array $papeis): array
    {
        $somenteProfessor = in_array('professor', $papeis, true);

        $itens = [];
        foreach ($entradas as $entrada) {
            $nomeAluno = trim((string)($entrada['nome'] ?? 'estudante'));

            if ($somenteProfessor) {
                $disciplinas = $this->disciplinasEntrada($entrada);
                if ($disciplinas === []) {
                    $itens[] = ['nome' => $nomeAluno, 'sufixo' => ''];
                    continue;
                }
                foreach ($disciplinas as $disciplina) {
                    $itens[] = ['nome' => $nomeAluno, 'sufixo' => $disciplina];
                }
                continue;
            }

            $nomeCurso = trim((string)($entrada['nome_curso'] ?? ''));
            $itens[] = ['nome' => $nomeAluno, 'sufixo' => $nomeCurso];
        }

        usort(
            $itens,
            static function (array $a, array $b): int {
                $cmp = strcasecmp($a['nome'], $b['nome']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp($a['sufixo'], $b['sufixo']);
            }
        );

        $linhas = [];
        foreach ($itens as $item) {
            $sufixo = trim((string)($item['sufixo'] ?? ''));
            $linhas[] = $sufixo !== ''
                ? '• ' . $item['nome'] . ' — ' . $sufixo
                : '• ' . $item['nome'];
        }

        return $linhas;
    }

    /**
     * @param array{alarmes?: list<array<string, mixed>>} $entrada
     * @return list<string>
     */
    private function disciplinasEntrada(array $entrada): array
    {
        $disciplinas = [];
        foreach ($entrada['alarmes'] ?? [] as $alarme) {
            $nome = trim((string)($alarme['disciplina'] ?? ''));
            $codigo = trim((string)($alarme['codigo_disciplina'] ?? ''));
            if ($nome === '' && $codigo !== '') {
                $nome = $codigo;
            }
            if ($nome !== '') {
                $disciplinas[$nome] = $nome;
            }
        }

        $lista = array_values($disciplinas);
        sort($lista, SORT_FLAG_CASE | SORT_STRING);

        return $lista;
    }

    private function urlPublicaServidor(): string
    {
        return $this->config->getAppUrl();
    }

    /**
     * @param array{nome: string} $grupo
     * @deprecated Usado apenas em testes legados; preferir montarAssuntoStaffResumo().
     */
    public function montarAssuntoStaff(array $grupo): string
    {
        return $this->montarAssuntoStaffResumo(1);
    }

    /**
     * @param array{
     *   nome: string,
     *   matricula: string,
     *   nome_curso: string,
     *   alarmes: list<array<string, mixed>>
     * } $grupo
     * @deprecated Usado apenas em testes legados; preferir montarMensagemStaffResumo().
     */
    public function montarMensagemStaff(array $grupo): string
    {
        return $this->montarMensagemStaffResumo([$grupo]);
    }

    /**
     * @param array{
     *   nome: string,
     *   nome_curso: string,
     *   alarmes: list<array<string, mixed>>
     * } $grupo
     */
    public function montarMensagem(array $grupo): string
    {
        $app = $this->stringsApp();
        $nomeMapa = $app['full_name'];
        $nomeCurto = $app['short_name'];
        $nomeAluno = trim((string)($grupo['nome'] ?? 'estudante'));
        $nomeCurso = trim((string)($grupo['nome_curso'] ?? 'seu curso'));

        $linhas = [];
        foreach ($grupo['alarmes'] as $alarme) {
            $linhas[] = '• ' . $this->descreverAlarme($alarme, false);
        }

        return "Esta é uma mensagem automática do {$nomeMapa} ({$nomeCurto}).\n\n"
            . "Olá, {$nomeAluno},\n\n"
            . "Estamos preocupados com você e queremos que saiba que não está "
            . "sozinho(a) nessa jornada.\n\n"
            . "Percebemos alguns sinais de que sua frequência no curso {$nomeCurso} "
            . "merece atenção:\n\n"
            . implode("\n", $linhas)
            . "\n\n"
            . "Sabemos que a vida acadêmica pode ter momentos difíceis. "
            . "Você já conversou com algum professor ou com o coordenador do curso? "
            . "Estamos aqui para ajudar — procure a coordenação ou a Assistência "
            . "Estudantil do campus. Juntos, podemos encontrar caminhos para você "
            . "seguir firme nos estudos.\n\n"
            . "Conte conosco.\n\n"
            . "Este e-mail foi gerado automaticamente. Por favor, não responda "
            . "a esta mensagem.\n\n"
            . "Atenciosamente,\n"
            . $nomeCurto;
    }

    /**
     * @param list<array{
     *   aluno_id: int,
     *   curso_id: int,
     *   nome: string,
     *   matricula: string,
     *   email: string,
     *   nome_curso: string,
     *   alarme_ids: list<int>,
     *   alarmes: list<array<string, mixed>>
     * }> $gruposEnviados
     * @param array{enviados: int, ignorados: int, falhas: int, mensagens: list<string>} $resumo
     */
    private function enviarResumosStaff(SmtpMailer $mailer, array $gruposEnviados, array &$resumo): void
    {
        $digest = $this->montarDigestStaff($gruposEnviados);
        $digest = $this->filtrarDestinatariosStaff($digest);
        if ($digest === []) {
            $apenas = $this->emailStaffApenas();
            if ($apenas !== null) {
                $resumo['mensagens'][] = 'Nenhum aviso staff para ' . $apenas . ' nesta execucao.';
            } else {
                $resumo['mensagens'][] = 'Nenhum professor ou coordenador com e-mail para aviso.';
            }
            return;
        }

        $registrosAvisados = [];
        $recentes = $this->enviosStaffRecentes();

        foreach ($digest as $pacote) {
            $email = trim((string)($pacote['email'] ?? ''));
            $emailNorm = strtolower($email);
            $entradas = $pacote['entradas'] ?? [];
            $papeis = $pacote['papeis'] ?? [];
            $papel = (string)($papeis[0] ?? '');
            if ($email === '' || $entradas === [] || $papel === '') {
                continue;
            }

            $chaveRecente = $emailNorm . '|' . $papel;
            if (isset($recentes[$chaveRecente])) {
                $resumo['ignorados']++;
                continue;
            }

            $assunto = $this->montarAssuntoStaffResumo(count($entradas), $papeis);
            $corpo = $this->montarMensagemStaffResumo($entradas, $papeis);

            try {
                $mailer->send([$email], $assunto, $corpo);
                $this->registrarEnvioStaff($email, $papel, count($entradas));
                $recentes[$chaveRecente] = true;
                $resumo['enviados']++;
                foreach ($entradas as $entrada) {
                    $registroId = (int)($entrada['registro_id'] ?? 0);
                    if ($registroId > 0) {
                        $registrosAvisados[$registroId] = true;
                    }
                }
            } catch (Throwable $e) {
                $resumo['falhas']++;
                $resumo['mensagens'][] = 'Falha aviso staff (' . $email . '): ' . $e->getMessage();
            }
        }

        if ($registrosAvisados !== []) {
            if ($this->emailStaffApenas() !== null) {
                $this->marcarStaffPilotoAvisado(array_keys($registrosAvisados));
            } else {
                $this->marcarStaffAvisado(array_keys($registrosAvisados));
            }
        }
    }

    /**
     * Piloto (.env EMAIL_ALARMES_STAFF_APENAS): so envia ao e-mail indicado,
     * com conteudo filtrado pelas disciplinas/cursos dele (sem avisos de terceiros).
     *
     * @param list<array{
     *   email: string,
     *   papeis: list<string>,
     *   entradas: list<array{
     *     nome: string,
     *     matricula: string,
     *     nome_curso: string,
     *     alarmes: list<array<string, mixed>>
     *   }>
     * }> $digest
     * @return list<array{
     *   email: string,
     *   papeis: list<string>,
     *   entradas: list<array{
     *     nome: string,
     *     matricula: string,
     *     nome_curso: string,
     *     alarmes: list<array<string, mixed>>
     *   }>
     * }>
     */
    private function filtrarDestinatariosStaff(array $digest): array
    {
        $apenas = $this->emailStaffApenas();
        if ($apenas === null) {
            return $digest;
        }

        $apenasNorm = strtolower($apenas);
        $filtrado = [];
        foreach ($digest as $pacote) {
            $email = strtolower(trim((string)($pacote['email'] ?? '')));
            if ($email === $apenasNorm) {
                $filtrado[] = $pacote;
            }
        }

        return $filtrado;
    }

    private function emailStaffApenas(): ?string
    {
        $email = trim(Env::get('EMAIL_ALARMES_STAFF_APENAS', ''));
        if ($email === '') {
            $email = trim(Env::get('EMAIL_ALARMES_STAFF_TEST', ''));
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return null;
    }

    private function diaPermitidoParaEnvio(string $tipo): bool
    {
        $diaConfig = $this->configuracaoDiaEnvio($tipo);
        if ($diaConfig === '' || $diaConfig === 'todos' || $diaConfig === 'qualquer') {
            return true;
        }

        $hoje = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $diaSemana = (int)$hoje->format('N');

        $mapa = [
            'segunda' => 1,
            'terca' => 2,
            'terça' => 2,
            'quarta' => 3,
            'quinta' => 4,
            'sexta' => 5,
            'sabado' => 6,
            'sábado' => 6,
            'domingo' => 7,
        ];

        if (isset($mapa[$diaConfig])) {
            return $diaSemana === $mapa[$diaConfig];
        }

        if (ctype_digit($diaConfig)) {
            $numero = (int)$diaConfig;
            return $numero >= 1 && $numero <= 7 && $diaSemana === $numero;
        }

        return true;
    }

    private function configuracaoDiaEnvio(string $tipo): string
    {
        $especifico = strtolower(trim(Env::get(
            $tipo === 'staff' ? 'EMAIL_ALARMES_DIA_STAFF' : 'EMAIL_ALARMES_DIA_ALUNOS',
            ''
        )));
        if ($especifico !== '') {
            return $especifico;
        }

        return strtolower(trim(Env::get('EMAIL_ALARMES_DIA', 'quarta')));
    }

    private function diaPadraoEnvio(string $tipo): string
    {
        return $tipo === 'alunos' ? 'todos' : 'quarta';
    }

    private function rotuloDiaEnvio(string $tipo): string
    {
        $diaConfig = $this->configuracaoDiaEnvio($tipo);
        $rotulos = [
            'segunda' => 'segundas-feiras',
            'terca' => 'terças-feiras',
            'terça' => 'terças-feiras',
            'quarta' => 'quartas-feiras',
            'quinta' => 'quintas-feiras',
            'sexta' => 'sextas-feiras',
            'sabado' => 'sábados',
            'sábado' => 'sábados',
            'domingo' => 'domingos',
        ];

        return $rotulos[$diaConfig] ?? $diaConfig;
    }

    /**
     * @return list<array{
     *   registro_id: int,
     *   aluno_id: int,
     *   curso_id: int,
     *   nome: string,
     *   matricula: string,
     *   email: string,
     *   nome_curso: string,
     *   alarme_ids: list<int>,
     *   alarmes: list<array<string, mixed>>
     * }>
     */
    private function gruposDeEnviosAlunoPendentesStaff(): array
    {
        $piloto = $this->emailStaffApenas() !== null;
        $filtroPiloto = $piloto ? ' AND ae.staff_piloto_avisado_em IS NULL' : '';

        $statement = $this->pdo->query(
            'SELECT ae.id AS registro_id, ae.coleta_id, ae.aluno_id, ae.curso_id,
                    ae.destinatario, ae.alarme_ids,
                    a.nome AS aluno_nome, a.nome_social AS aluno_nome_social,
                    a.matricula, a.email AS aluno_email,
                    c.nome_curso
             FROM alarme_emails ae
             INNER JOIN alunos a ON a.id = ae.aluno_id
             INNER JOIN cursos c ON c.id = ae.curso_id
             WHERE ae.staff_avisado_em IS NULL'
            . $filtroPiloto
            . ' AND NOT EXISTS (
                    SELECT 1
                    FROM alunos_trancados at
                    INNER JOIN coletas col ON col.id = at.coleta_id
                    WHERE at.aluno_id = ae.aluno_id
                      AND at.curso_id = ae.curso_id
                      AND col.id = (SELECT MAX(id) FROM coletas)
                )
             ORDER BY ae.enviado_em ASC'
        );

        $grupos = [];
        foreach ($statement->fetchAll() as $row) {
            $alarmeIds = array_values(array_filter(array_map(
                'intval',
                explode(',', (string)($row['alarme_ids'] ?? ''))
            )));
            if ($alarmeIds === []) {
                continue;
            }

            $alarmes = $this->carregarAlarmesPorIds($alarmeIds);
            if ($alarmes === []) {
                continue;
            }

            $nomeSocial = trim((string)($row['aluno_nome_social'] ?? ''));
            $nome = $nomeSocial !== ''
                ? $nomeSocial
                : trim((string)($row['aluno_nome'] ?? ''));

            $grupos[] = [
                'registro_id' => (int)$row['registro_id'],
                'aluno_id' => (int)$row['aluno_id'],
                'curso_id' => (int)$row['curso_id'],
                'nome' => $nome,
                'matricula' => trim((string)($row['matricula'] ?? '')),
                'email' => trim((string)($row['aluno_email'] ?? '')),
                'nome_curso' => trim((string)($row['nome_curso'] ?? '')),
                'alarme_ids' => $alarmeIds,
                'alarmes' => $alarmes,
            ];
        }

        return $grupos;
    }

    /**
     * @param list<int> $alarmeIds
     * @return list<array<string, mixed>>
     */
    private function carregarAlarmesPorIds(array $alarmeIds): array
    {
        $placeholders = [];
        $params = [];
        foreach ($alarmeIds as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, tipo, severidade, mensagem, codigo_disciplina, disciplina,
                    aluno_id, curso_id
             FROM alarmes
             WHERE id IN (' . implode(', ', $placeholders) . ')
             ORDER BY CASE severidade WHEN \'critico\' THEN 0 ELSE 1 END, disciplina, tipo'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param list<int> $registroIds
     */
    private function marcarStaffAvisado(array $registroIds): void
    {
        if ($registroIds === []) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($registroIds as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $statement = $this->pdo->prepare(
            'UPDATE alarme_emails
             SET staff_avisado_em = datetime(\'now\')
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND staff_avisado_em IS NULL'
        );
        $statement->execute($params);
    }

    /**
     * Piloto: marca aviso enviado ao destinatario de teste sem fechar aviso geral ao staff.
     *
     * @param list<int> $registroIds
     */
    private function marcarStaffPilotoAvisado(array $registroIds): void
    {
        if ($registroIds === []) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($registroIds as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $statement = $this->pdo->prepare(
            'UPDATE alarme_emails
             SET staff_piloto_avisado_em = datetime(\'now\')
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND staff_piloto_avisado_em IS NULL'
        );
        $statement->execute($params);
    }

    /**
     * Um e-mail por destinatario; professores veem so alarmes das suas disciplinas.
     *
     * @param list<array{
     *   aluno_id: int,
     *   curso_id: int,
     *   nome: string,
     *   matricula: string,
     *   email: string,
     *   nome_curso: string,
     *   alarme_ids: list<int>,
     *   alarmes: list<array<string, mixed>>
     * }> $gruposEnviados
     * @return list<array{
     *   email: string,
     *   papeis: list<string>,
     *   entradas: list<array{
     *     nome: string,
     *     matricula: string,
     *     nome_curso: string,
     *     alarmes: list<array<string, mixed>>
     *   }>
     * }>
     */
    private function montarDigestStaff(array $gruposEnviados): array
    {
        $digest = [];
        $codigos = [];

        foreach ($gruposEnviados as $grupo) {
            foreach ($grupo['alarmes'] as $alarme) {
                $codigo = trim((string)($alarme['codigo_disciplina'] ?? ''));
                if ($codigo !== '') {
                    $codigos[$codigo] = true;
                }
            }
        }

        $professoresPorCodigo = $this->mapaProfessoresPorCodigo(array_keys($codigos));

        foreach ($gruposEnviados as $grupo) {
            $emailAluno = strtolower(trim((string)($grupo['email'] ?? '')));
            $chaveAluno = (int)$grupo['aluno_id'] . '|' . (int)$grupo['curso_id'];

            foreach ($this->emailsCoordenadoresCurso((int)$grupo['curso_id']) as $emailCoord) {
                if (strtolower($emailCoord) === $emailAluno) {
                    continue;
                }
                $this->adicionarEntradaDigest(
                    $digest,
                    $emailCoord,
                    $chaveAluno,
                    $grupo,
                    $grupo['alarmes'],
                    'coordenador'
                );
            }

            foreach ($grupo['alarmes'] as $alarme) {
                $codigo = trim((string)($alarme['codigo_disciplina'] ?? ''));
                if ($codigo === '') {
                    continue;
                }
                foreach ($professoresPorCodigo[$codigo] ?? [] as $emailProf) {
                    if (strtolower($emailProf) === $emailAluno) {
                        continue;
                    }
                    $this->adicionarEntradaDigest(
                        $digest,
                        $emailProf,
                        $chaveAluno,
                        $grupo,
                        [$alarme],
                        'professor'
                    );
                }
            }
        }

        $saida = [];
        foreach ($digest as $email => $porPapel) {
            foreach ($porPapel as $papel => $dados) {
                $entradas = array_values($dados['por_aluno']);
                if ($entradas === []) {
                    continue;
                }
                $saida[] = [
                    'email' => $email,
                    'papeis' => [$papel],
                    'entradas' => $entradas,
                ];
            }
        }

        return $saida;
    }

    /**
     * @param array<string, array<string, array{
     *   por_aluno: array<string, array{
     *     nome: string,
     *     matricula: string,
     *     nome_curso: string,
     *     alarmes: list<array<string, mixed>>
     *   }>
     * }>> $digest
     * @param list<array<string, mixed>> $alarmes
     * @param array{
     *   nome: string,
     *   matricula: string,
     *   nome_curso: string,
     *   alarmes: list<array<string, mixed>>
     * } $grupo
     */
    private function adicionarEntradaDigest(
        array &$digest,
        string $email,
        string $chaveAluno,
        array $grupo,
        array $alarmes,
        string $papel
    ): void {
        if ($alarmes === []) {
            return;
        }

        if (!isset($digest[$email][$papel])) {
            $digest[$email][$papel] = [
                'por_aluno' => [],
            ];
        }

        if (!isset($digest[$email][$papel]['por_aluno'][$chaveAluno])) {
            $digest[$email][$papel]['por_aluno'][$chaveAluno] = [
                'registro_id' => (int)($grupo['registro_id'] ?? 0),
                'nome' => $grupo['nome'],
                'matricula' => $grupo['matricula'],
                'nome_curso' => $grupo['nome_curso'],
                'alarmes' => [],
            ];
        }

        $idsExistentes = [];
        foreach ($digest[$email][$papel]['por_aluno'][$chaveAluno]['alarmes'] as $existente) {
            $idsExistentes[(int)($existente['id'] ?? 0)] = true;
        }

        foreach ($alarmes as $alarme) {
            $id = (int)($alarme['id'] ?? 0);
            if ($id > 0 && isset($idsExistentes[$id])) {
                continue;
            }
            $digest[$email][$papel]['por_aluno'][$chaveAluno]['alarmes'][] = $alarme;
            if ($id > 0) {
                $idsExistentes[$id] = true;
            }
        }
    }

    /**
     * @param list<string> $codigos
     * @return array<string, list<string>>
     */
    private function mapaProfessoresPorCodigo(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($codigos as $index => $codigo) {
            $key = 'codigo_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $codigo;
        }

        $statement = $this->pdo->prepare(
            'SELECT dp.codigo_disciplina, TRIM(p.email) AS email
             FROM disciplina_professores dp
             INNER JOIN professores p ON p.id = dp.professor_id
             WHERE dp.codigo_disciplina IN (' . implode(', ', $placeholders) . ')
               AND p.email IS NOT NULL
               AND TRIM(p.email) != \'\''
        );
        $statement->execute($params);

        $mapa = [];
        foreach ($statement->fetchAll() as $row) {
            $codigo = trim((string)($row['codigo_disciplina'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            if ($codigo === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $mapa[$codigo][$email] = $email;
        }

        $saida = [];
        foreach ($mapa as $codigo => $emails) {
            $saida[$codigo] = array_values($emails);
        }

        return $saida;
    }

    /**
     * @param array<string, mixed> $alarme
     */
    private function descreverAlarme(array $alarme, bool $comSeveridade): string
    {
        $tipo = (string)($alarme['tipo'] ?? '');
        $mensagem = trim((string)($alarme['mensagem'] ?? ''));
        $disciplina = trim((string)($alarme['disciplina'] ?? ''));
        $codigo = trim((string)($alarme['codigo_disciplina'] ?? ''));
        $severidade = (string)($alarme['severidade'] ?? '');

        if ($disciplina === '' && $codigo !== '') {
            $disciplina = $codigo;
        }

        $contexto = $disciplina !== ''
            ? $disciplina
            : ($comSeveridade ? 'Curso (sem disciplina específica)' : 'No curso, de forma geral');

        $prefixo = '';
        if ($comSeveridade) {
            $prefixo = $severidade === 'critico' ? '[Crítico] ' : '[Alto] ';
        }

        $descricao = match ($tipo) {
            'percentual_baixo' => $comSeveridade
                ? "{$contexto}: frequência abaixo do mínimo"
                    . ($mensagem !== '' ? " — {$mensagem}" : '')
                : "{$contexto}: sua frequência precisa de atenção"
                    . ($mensagem !== '' ? " ({$mensagem})" : ''),
            'faltas_4dias' => $comSeveridade
                ? "{$contexto}: faltas em dias letivos consecutivos"
                    . ($mensagem !== '' ? " — {$mensagem}" : '')
                : "{$contexto}: faltas recentes em dias letivos consecutivos"
                    . ($mensagem !== '' ? " ({$mensagem})" : ''),
            'faltas_3semanas' => $comSeveridade
                ? "{$contexto}: faltas em três semanas consecutivas"
                    . ($mensagem !== '' ? " — {$mensagem}" : '')
                : "{$contexto}: faltas em semanas consecutivas"
                    . ($mensagem !== '' ? " ({$mensagem})" : ''),
            default => "{$contexto}"
                . ($mensagem !== '' ? ($comSeveridade ? ": {$mensagem}" : " ({$mensagem})") : ''),
        };

        return $prefixo . $descricao;
    }

    /**
     * Agrupa alunos com alarme critico aberto e inclui todos os alarmes abertos do mesmo curso.
     *
     * @return array<string, array{
     *   aluno_id: int,
     *   curso_id: int,
     *   nome: string,
     *   matricula: string,
     *   email: string,
     *   nome_curso: string,
     *   alarme_ids: list<int>,
     *   alarmes: list<array<string, mixed>>
     * }>
     */
    private function agruparAlarmesParaEnvio(int $coletaId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT al.aluno_id, al.curso_id
             FROM alarmes al
             WHERE al.coleta_id = :coleta_id
               AND al.severidade = \'critico\'
               AND al.visualizado = 0
               AND NOT EXISTS (
                   SELECT 1
                   FROM alunos_trancados at
                   WHERE at.coleta_id = al.coleta_id
                     AND at.aluno_id = al.aluno_id
                     AND at.curso_id = al.curso_id
               )'
        );
        $statement->execute(['coleta_id' => $coletaId]);

        $chavesCriticas = [];
        foreach ($statement->fetchAll() as $row) {
            $chave = (int)$row['aluno_id'] . '|' . (int)$row['curso_id'];
            $chavesCriticas[$chave] = [
                'aluno_id' => (int)$row['aluno_id'],
                'curso_id' => (int)$row['curso_id'],
            ];
        }

        if ($chavesCriticas === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT al.id, al.tipo, al.severidade, al.mensagem, al.codigo_disciplina, al.disciplina,
                    al.aluno_id, al.curso_id,
                    a.nome AS aluno_nome, a.nome_social AS aluno_nome_social,
                    a.email AS aluno_email, a.matricula,
                    c.nome_curso
             FROM alarmes al
             INNER JOIN alunos a ON a.id = al.aluno_id
             INNER JOIN cursos c ON c.id = al.curso_id
             WHERE al.coleta_id = :coleta_id
               AND al.visualizado = 0
               AND NOT EXISTS (
                   SELECT 1
                   FROM alunos_trancados at
                   WHERE at.coleta_id = al.coleta_id
                     AND at.aluno_id = al.aluno_id
                     AND at.curso_id = al.curso_id
               )
             ORDER BY al.aluno_id, al.curso_id,
                      CASE al.severidade WHEN \'critico\' THEN 0 ELSE 1 END,
                      al.disciplina, al.tipo'
        );
        $statement->execute(['coleta_id' => $coletaId]);

        $grupos = [];
        foreach ($statement->fetchAll() as $row) {
            $alunoId = (int)$row['aluno_id'];
            $cursoId = (int)$row['curso_id'];
            $chave = $alunoId . '|' . $cursoId;

            if (!isset($chavesCriticas[$chave])) {
                continue;
            }

            if (!isset($grupos[$chave])) {
                $nomeSocial = trim((string)($row['aluno_nome_social'] ?? ''));
                $nome = $nomeSocial !== ''
                    ? $nomeSocial
                    : trim((string)($row['aluno_nome'] ?? ''));

                $grupos[$chave] = [
                    'aluno_id' => $alunoId,
                    'curso_id' => $cursoId,
                    'nome' => $nome,
                    'matricula' => trim((string)($row['matricula'] ?? '')),
                    'email' => trim((string)($row['aluno_email'] ?? '')),
                    'nome_curso' => trim((string)($row['nome_curso'] ?? '')),
                    'alarme_ids' => [],
                    'alarmes' => [],
                ];
            }

            $grupos[$chave]['alarme_ids'][] = (int)$row['id'];
            $grupos[$chave]['alarmes'][] = $row;
        }

        return $grupos;
    }

    /**
     * @return list<string>
     */
    private function emailsCoordenadoresCurso(int $cursoId): array
    {
        if ($cursoId <= 0) {
            return [];
        }

        $emailsInstitucionais = $this->cursoCoordenacao->emailsCoordenacaoCurso($cursoId);
        if ($emailsInstitucionais !== []) {
            return $emailsInstitucionais;
        }

        $statement = $this->pdo->prepare(
            'SELECT DISTINCT TRIM(u.email) AS email
             FROM usuario_cursos uc
             INNER JOIN usuarios u ON u.id = uc.usuario_id
             WHERE uc.curso_id = :curso_id
               AND u.perfil = \'coordenador_curso\'
               AND u.ativo = 1
               AND u.email IS NOT NULL
               AND TRIM(u.email) != \'\''
        );
        $statement->execute(['curso_id' => $cursoId]);

        return $this->filtrarEmailsValidos($statement->fetchAll());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function filtrarEmailsValidos(array $rows): array
    {
        $emails = [];
        foreach ($rows as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = true;
            }
        }

        return array_keys($emails);
    }

    /**
     * Destinatarios staff que ja receberam aviso na janela de 7 dias (por e-mail + papel).
     *
     * @return array<string, true> chave "email|papel"
     */
    private function enviosStaffRecentes(): array
    {
        $dias = (int)self::INTERVALO_DIAS;
        $statement = $this->pdo->query(
            "SELECT destinatario, papel
             FROM staff_alarme_emails
             WHERE datetime(enviado_em) >= datetime('now', '-{$dias} days')"
        );

        $chaves = [];
        foreach ($statement->fetchAll() as $row) {
            $email = strtolower(trim((string)($row['destinatario'] ?? '')));
            $papel = trim((string)($row['papel'] ?? ''));
            if ($email !== '' && $papel !== '') {
                $chaves[$email . '|' . $papel] = true;
            }
        }

        return $chaves;
    }

    /**
     * @param list<string> $papeis
     */
    private function registrarEnvioStaff(string $destinatario, string $papel, int $totalAlunos): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO staff_alarme_emails (
                destinatario, papel, total_alunos, enviado_em
             ) VALUES (
                :destinatario, :papel, :total_alunos, datetime(\'now\')
             )'
        );
        $statement->execute([
            'destinatario' => $destinatario,
            'papel' => $papel,
            'total_alunos' => $totalAlunos,
        ]);
    }

    /**
     * Alunos e endereços que já receberam e-mail automático na janela de 7 dias.
     *
     * @return array{alunos: array<int, true>, emails: array<string, true>}
     */
    private function enviosRecentes(): array
    {
        $dias = (int)self::INTERVALO_DIAS;
        $statement = $this->pdo->query(
            "SELECT aluno_id, destinatario
             FROM alarme_emails
             WHERE datetime(enviado_em) >= datetime('now', '-{$dias} days')"
        );

        $alunos = [];
        $emails = [];
        foreach ($statement->fetchAll() as $row) {
            $alunos[(int)$row['aluno_id']] = true;
            $email = strtolower(trim((string)($row['destinatario'] ?? '')));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        return [
            'alunos' => $alunos,
            'emails' => $emails,
        ];
    }

    /**
     * @param array{
     *   aluno_id: int,
     *   curso_id: int,
     *   alarme_ids: list<int>
     * } $grupo
     */
    private function registrarEnvio(int $coletaId, array $grupo, string $destinatario): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO alarme_emails (
                coleta_id, aluno_id, curso_id, destinatario, alarme_ids, enviado_em
             ) VALUES (
                :coleta_id, :aluno_id, :curso_id, :destinatario, :alarme_ids, datetime(\'now\')
             )
             ON CONFLICT(coleta_id, aluno_id, curso_id) DO UPDATE SET
                destinatario = excluded.destinatario,
                alarme_ids = excluded.alarme_ids,
                enviado_em = excluded.enviado_em'
        );
        $statement->execute([
            'coleta_id' => $coletaId,
            'aluno_id' => (int)$grupo['aluno_id'],
            'curso_id' => (int)$grupo['curso_id'],
            'destinatario' => $destinatario,
            'alarme_ids' => implode(',', array_map('strval', $grupo['alarme_ids'])),
        ]);
    }

    /**
     * @param list<int> $alarmeIds
     */
    private function marcarAlarmesEnviados(array $alarmeIds): void
    {
        if ($alarmeIds === []) {
            return;
        }

        $placeholders = [];
        $params = ['contato_tipo' => self::CONTATO_AUTOMATICO];
        foreach ($alarmeIds as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $statement = $this->pdo->prepare(
            'UPDATE alarmes
             SET visualizado = 1,
                 visualizado_em = datetime(\'now\'),
                 visualizado_por = NULL,
                 contato_tipo = :contato_tipo
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND visualizado = 0'
        );
        $statement->execute($params);
    }

    /**
     * @return array{short_name: string, full_name: string}
     */
    private function stringsApp(): array
    {
        /** @var array{short_name: string, full_name: string} $strings */
        $strings = require dirname(__DIR__) . '/Strings/app.php';

        return $strings;
    }
}
