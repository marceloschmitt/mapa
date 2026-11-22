<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Contatos/ações com o aluno (contatos_aluno): e-mail automático, e-mail pelo
 * botão, WhatsApp etc. Os alarmes cobertos ficam em contato_alarmes.
 * Registrar um contato nao encerra o caso.
 */
class ContatoRepository
{
    /** Canais que a equipe registra na tela (o automatico e 'email_automatico'). */
    public const CANAIS = [
        'email' => 'E-mail enviado',
        'whatsapp' => 'WhatsApp',
        'telefone' => 'Ligação telefônica',
        'presencial' => 'Conversa presencial',
        'assistencia' => 'Encaminhamento para Assistência Estudantil',
    ];

    public const ROTULOS_CANAL = self::CANAIS + [
        'email_automatico' => 'E-mail automático',
        'nao_informado' => 'Contato sem tipo registrado',
    ];

    /**
     * Motivo principal da falta. Lista provisória: a confirmar com a
     * Assistência Estudantil (a chave é o que fica gravado).
     */
    public const MOTIVOS = [
        'trabalho' => 'Trabalho',
        'saude' => 'Saúde',
        'transporte' => 'Transporte',
        'financeiro' => 'Situação financeira',
        'familia' => 'Questões familiares',
        'dificuldade' => 'Dificuldade nas disciplinas',
        'desmotivacao' => 'Desmotivação com o curso',
        'ja_decidiu_sair' => 'Já decidiu sair do curso',
        'outro' => 'Outro',
    ];

    /** Contato registrado na tela pode ser de até tantos dias atrás. */
    public const DIAS_RETROATIVOS = 60;

    public const MAX_OBSERVACAO = 500;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Valida o formulário de contato. $hoje no formato AAAA-MM-DD.
     *
     * @param array<string, mixed> $entrada
     * @return array{dados: array<string, mixed>, erros: list<string>}
     */
    public static function validar(array $entrada, string $hoje): array
    {
        $erros = [];

        $canal = trim((string)($entrada['canal'] ?? ''));
        if (!array_key_exists($canal, self::CANAIS)) {
            $erros[] = 'Escolha como foi o contato.';
        }

        $data = trim((string)($entrada['data_contato'] ?? ''));
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if ($dia === false || $dia->format('Y-m-d') !== $data) {
            $erros[] = 'Informe a data do contato.';
        } else {
            $limite = (new \DateTimeImmutable($hoje))->modify('-' . self::DIAS_RETROATIVOS . ' days')->format('Y-m-d');
            if ($data > $hoje) {
                $erros[] = 'A data do contato não pode ser futura.';
            } elseif ($data < $limite) {
                $erros[] = 'A data do contato pode ser de até ' . self::DIAS_RETROATIVOS . ' dias atrás.';
            }
        }

        $falou = trim((string)($entrada['conseguiu_falar'] ?? ''));
        $conseguiuFalar = match ($falou) {
            '1' => 1,
            '0' => 0,
            default => null,
        };

        $motivo = trim((string)($entrada['motivo'] ?? ''));
        if ($motivo !== '' && !array_key_exists($motivo, self::MOTIVOS)) {
            $erros[] = 'Motivo inválido.';
        }

        $observacao = trim((string)($entrada['observacao'] ?? ''));
        if (mb_strlen($observacao) > self::MAX_OBSERVACAO) {
            $erros[] = 'A observação pode ter até ' . self::MAX_OBSERVACAO . ' caracteres.';
        }

        return [
            'dados' => [
                'canal' => $canal,
                'data_contato' => $data,
                'conseguiu_falar' => $conseguiuFalar,
                'motivo' => $motivo !== '' ? $motivo : null,
                'observacao' => $observacao !== '' ? $observacao : null,
            ],
            'erros' => $erros,
        ];
    }

    /**
     * @param array{
     *   aluno_id: int, curso_id: int, data_contato: string, canal: string,
     *   origem?: string, conseguiu_falar?: int|null, motivo?: string|null,
     *   observacao?: string|null, codigo_disciplina?: string|null,
     *   registrado_por?: int|null, alarme_email_id?: int|null,
     *   coleta_id?: int|null, alarme_ids?: list<int>
     * } $contato
     */
    public function registrar(array $contato): int
    {
        // O mesmo envio (alarme_email_id) vira um contato só.
        // - Reenvio automático: atualiza data_contato/coleta, sem rebaixar e-mail da equipe.
        // - Envio pelo botão (manual): promove canal/origem para e-mail da equipe.
        $statement = $this->db->prepare(
            'INSERT INTO contatos_aluno (
                aluno_id, curso_id, data_contato, canal, origem, conseguiu_falar, motivo,
                observacao, codigo_disciplina, coleta_id, registrado_por, alarme_email_id
             ) VALUES (
                :aluno_id, :curso_id, :data_contato, :canal, :origem, :conseguiu_falar, :motivo,
                :observacao, :codigo_disciplina, :coleta_id, :registrado_por, :alarme_email_id
             )
             ON CONFLICT(alarme_email_id) DO UPDATE SET
                data_contato = CASE
                    WHEN excluded.data_contato > contatos_aluno.data_contato
                        THEN excluded.data_contato
                    ELSE contatos_aluno.data_contato
                END,
                coleta_id = COALESCE(excluded.coleta_id, contatos_aluno.coleta_id),
                canal = CASE
                    WHEN excluded.origem = \'manual\' THEN excluded.canal
                    ELSE contatos_aluno.canal
                END,
                origem = CASE
                    WHEN excluded.origem = \'manual\' THEN excluded.origem
                    ELSE contatos_aluno.origem
                END,
                registrado_por = CASE
                    WHEN excluded.origem = \'manual\'
                        THEN COALESCE(excluded.registrado_por, contatos_aluno.registrado_por)
                    ELSE contatos_aluno.registrado_por
                END'
        );
        $registradoPor = $contato['registrado_por'] ?? null;
        $coletaId = isset($contato['coleta_id']) ? (int)$contato['coleta_id'] : 0;
        $alarmeEmailId = $contato['alarme_email_id'] ?? null;
        $statement->execute([
            'aluno_id' => (int)$contato['aluno_id'],
            'curso_id' => (int)$contato['curso_id'],
            'data_contato' => $contato['data_contato'],
            'canal' => $contato['canal'],
            'origem' => $contato['origem'] ?? 'manual',
            'conseguiu_falar' => $contato['conseguiu_falar'] ?? null,
            'motivo' => $contato['motivo'] ?? null,
            'observacao' => $contato['observacao'] ?? null,
            'codigo_disciplina' => ($contato['codigo_disciplina'] ?? '') !== '' ? $contato['codigo_disciplina'] : null,
            'coleta_id' => $coletaId > 0 ? $coletaId : null,
            'registrado_por' => $registradoPor !== null && $registradoPor > 0 ? $registradoPor : null,
            'alarme_email_id' => $alarmeEmailId,
        ]);

        // Após ON CONFLICT DO UPDATE o lastInsertId do PDO/SQLite é instável:
        // resolve sempre pelo alarme_email_id quando houver; senão lastInsertId.
        $contatoId = 0;
        if ($alarmeEmailId !== null && (int)$alarmeEmailId > 0) {
            $busca = $this->db->prepare(
                'SELECT id FROM contatos_aluno WHERE alarme_email_id = :id LIMIT 1'
            );
            $busca->execute(['id' => (int)$alarmeEmailId]);
            $contatoId = (int)($busca->fetchColumn() ?: 0);
        }
        if ($contatoId <= 0) {
            $contatoId = (int)$this->db->lastInsertId();
        }

        $alarmeIds = array_values(array_unique(array_filter(
            array_map('intval', $contato['alarme_ids'] ?? []),
            static fn(int $id): bool => $id > 0
        )));
        if ($contatoId > 0 && $alarmeIds !== []) {
            $this->sincronizarAlarmesDoContato($contatoId, $alarmeIds);
        }

        // Id do contato (novo ou já existente para este alarme_email_id); 0 se falhou.
        return $contatoId;
    }

    /**
     * IDs de alarmes da coleta para o aluno/curso (opcionalmente uma disciplina).
     *
     * @return list<int>
     */
    public function idsAlarmesDaColeta(
        int $coletaId,
        int $alunoId,
        int $cursoId,
        ?string $codigoDisciplina = null
    ): array {
        if ($coletaId <= 0 || $alunoId <= 0 || $cursoId <= 0) {
            return [];
        }

        $sql = 'SELECT id FROM alarmes
                WHERE coleta_id = :coleta_id
                  AND aluno_id = :aluno_id
                  AND curso_id = :curso_id';
        $params = [
            'coleta_id' => $coletaId,
            'aluno_id' => $alunoId,
            'curso_id' => $cursoId,
        ];
        $codigo = trim((string)($codigoDisciplina ?? ''));
        if ($codigo !== '') {
            $sql .= ' AND codigo_disciplina = :codigo';
            $params['codigo'] = $codigo;
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<int> $alarmeIds */
    private function sincronizarAlarmesDoContato(int $contatoId, array $alarmeIds): void
    {
        $insert = $this->db->prepare(
            'INSERT OR IGNORE INTO contato_alarmes (contato_id, alarme_id)
             VALUES (:contato_id, :alarme_id)'
        );
        foreach ($alarmeIds as $alarmeId) {
            $insert->execute([
                'contato_id' => $contatoId,
                'alarme_id' => $alarmeId,
            ]);
        }
    }

    /**
     * Datas de e-mail (automático ou pelo botão) por aluno/curso, ordem cronológica.
     *
     * @param list<int> $alunoIds
     * @return array<string, list<array{data_contato: string, canal: string}>>
     */
    public function enviosEmailPorAluno(array $alunoIds): array
    {
        $alunoIds = array_values(array_unique(array_map('intval', $alunoIds)));
        if ($alunoIds === []) {
            return [];
        }

        $saida = [];
        foreach (array_chunk($alunoIds, 500) as $lote) {
            $placeholders = implode(', ', array_fill(0, count($lote), '?'));
            $statement = $this->db->prepare(
                'SELECT aluno_id, curso_id, data_contato, canal
                 FROM contatos_aluno
                 WHERE aluno_id IN (' . $placeholders . ')
                   AND canal IN (\'email\', \'email_automatico\')
                 ORDER BY data_contato, id'
            );
            $statement->execute($lote);
            foreach ($statement->fetchAll() as $row) {
                $chave = (int)$row['aluno_id'] . '|' . (int)$row['curso_id'];
                $saida[$chave][] = [
                    'data_contato' => (string)$row['data_contato'],
                    'canal' => (string)$row['canal'],
                ];
            }
        }

        return $saida;
    }

    /**
     * Quantos contatos e o mais recente de cada aluno/curso (chave "aluno|curso").
     *
     * @param list<int> $alunoIds
     * @return array<string, array{total: int, ultimo: array<string, mixed>}>
     */
    public function resumoPorAluno(array $alunoIds): array
    {
        $alunoIds = array_values(array_unique(array_map('intval', $alunoIds)));
        if ($alunoIds === []) {
            return [];
        }

        $saida = [];
        foreach (array_chunk($alunoIds, 500) as $lote) {
            $placeholders = implode(', ', array_fill(0, count($lote), '?'));
            $statement = $this->db->prepare(
                'SELECT c.aluno_id, c.curso_id, c.data_contato, c.canal, c.origem,
                        c.conseguiu_falar, c.motivo, c.observacao, c.registrado_em,
                        u.nome AS registrado_por_nome
                 FROM contatos_aluno c
                 LEFT JOIN usuarios u ON u.id = c.registrado_por
                 WHERE c.aluno_id IN (' . $placeholders . ')
                 ORDER BY c.data_contato, c.registrado_em, c.id'
            );
            $statement->execute($lote);
            foreach ($statement->fetchAll() as $row) {
                $chave = (int)$row['aluno_id'] . '|' . (int)$row['curso_id'];
                $saida[$chave] = [
                    'total' => ($saida[$chave]['total'] ?? 0) + 1,
                    'ultimo' => $row,
                ];
            }
        }

        return $saida;
    }
}
