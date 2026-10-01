<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use Mapa\Lib\PasseLivreAtestadoPdf;
use PDO;
use RuntimeException;

class PasseLivreAtestadoRepository
{
    private const COLUNAS = 'id, passe_livre_aluno_curso_id, numero, ano, data_documento,
                    assinado_em, codigo_verificacao, usuario_id,
                    nome_aluno, matricula, nome_curso, periodo,
                    frequencia_geral, disciplinas_json';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buscarPorAlunoCursoId(int $alunoCursoId): ?array
    {
        if ($alunoCursoId <= 0) {
            return null;
        }

        $this->revincularOrfaos();

        $statement = $this->db->prepare(
            'SELECT ' . self::COLUNAS . '
             FROM passe_livre_atestados
             WHERE passe_livre_aluno_curso_id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $alunoCursoId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->normalizar($row);
    }

    /**
     * @param list<int> $alunoCursoIds
     * @return array<int, array<string, mixed>>
     */
    public function mapearPorAlunoCursoIds(array $alunoCursoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $alunoCursoIds))));
        if ($ids === []) {
            return [];
        }

        $this->revincularOrfaos();

        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $statement = $this->db->prepare(
            'SELECT ' . self::COLUNAS . '
             FROM passe_livre_atestados
             WHERE passe_livre_aluno_curso_id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);

        $mapa = [];
        $idPorChave = [];
        foreach ($statement->fetchAll() as $row) {
            $item = $this->normalizar($row);
            $item['anteriores'] = [];
            $alunoCursoId = (int)$item['passe_livre_aluno_curso_id'];
            $mapa[$alunoCursoId] = $item;
            $idPorChave[$item['matricula'] . '|' . $item['periodo']] = $alunoCursoId;
        }

        foreach ($this->listarSubstituidos() as $antigo) {
            $alunoCursoId = $idPorChave[$antigo['matricula'] . '|' . $antigo['periodo']] ?? null;
            if ($alunoCursoId !== null) {
                $mapa[$alunoCursoId]['anteriores'][] = [
                    'numero_formatado' => $antigo['numero_formatado'],
                    'assinado_em' => $antigo['assinado_em'],
                ];
            }
        }

        return $mapa;
    }

    /**
     * Atestados desvinculados (substituídos por uma nova assinatura); o número
     * antigo continua válido na conferência.
     *
     * @return list<array<string, mixed>>
     */
    private function listarSubstituidos(): array
    {
        $statement = $this->db->query(
            "SELECT " . self::COLUNAS . "
             FROM passe_livre_atestados
             WHERE passe_livre_aluno_curso_id IS NULL AND matricula <> ''
             ORDER BY ano, numero"
        );

        return array_map(fn(array $row): array => $this->normalizar($row), $statement->fetchAll());
    }

    /**
     * O gerar_passe_livre.py recria as linhas do semestre com novos ids, e o
     * ON DELETE SET NULL deixa os atestados sem vínculo. Religa pelo par
     * matrícula/período, só o atestado mais recente de cada par e só se nenhum
     * deles estiver vinculado (substituídos continuam desvinculados).
     */
    private function revincularOrfaos(): void
    {
        $this->db->exec(
            "UPDATE OR IGNORE passe_livre_atestados
             SET passe_livre_aluno_curso_id = (
                 SELECT ac.id FROM passe_livre_aluno_curso ac
                 WHERE ac.matricula = passe_livre_atestados.matricula
                   AND ac.periodo = passe_livre_atestados.periodo
                 ORDER BY ac.id LIMIT 1
             )
             WHERE passe_livre_aluno_curso_id IS NULL
               AND matricula <> ''
               AND id = (
                   SELECT MAX(b.id) FROM passe_livre_atestados b
                   WHERE b.matricula = passe_livre_atestados.matricula
                     AND b.periodo = passe_livre_atestados.periodo
               )
               AND NOT EXISTS (
                   SELECT 1 FROM passe_livre_atestados c
                   WHERE c.matricula = passe_livre_atestados.matricula
                     AND c.periodo = passe_livre_atestados.periodo
                     AND c.passe_livre_aluno_curso_id IS NOT NULL
               )
               AND EXISTS (
                   SELECT 1 FROM passe_livre_aluno_curso ac
                   WHERE ac.matricula = passe_livre_atestados.matricula
                     AND ac.periodo = passe_livre_atestados.periodo
               )"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buscarPorCodigo(string $codigo): ?array
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT ' . self::COLUNAS . '
             FROM passe_livre_atestados
             WHERE codigo_verificacao = :codigo
             LIMIT 1'
        );
        $statement->execute(['codigo' => $codigo]);
        $row = $statement->fetch();

        return $row === false ? null : $this->normalizar($row);
    }

    /**
     * Assina o documento: gera número sequencial único no ano e congela data/frequências.
     * Se já existir assinatura e $substituir for false, devolve a existente.
     * Se $substituir for true, desvincula a anterior (número antigo permanece
     * válido na conferência) e cria uma nova assinatura.
     *
     * @param array<string, mixed> $linha
     * @param list<array<string, mixed>> $disciplinas
     * @return array<string, mixed>
     */
    public function assinar(
        array $linha,
        array $disciplinas = [],
        ?int $usuarioId = null,
        bool $substituir = false
    ): array {
        $alunoCursoId = (int)($linha['id'] ?? 0);
        if ($alunoCursoId <= 0) {
            throw new RuntimeException('Linha de passe livre inválida.');
        }

        $existente = $this->buscarPorAlunoCursoId($alunoCursoId);
        if ($existente !== null && !$substituir) {
            return $existente;
        }

        $this->db->beginTransaction();
        try {
            $existente = $this->buscarPorAlunoCursoId($alunoCursoId);
            if ($existente !== null && !$substituir) {
                $this->db->commit();

                return $existente;
            }

            if ($existente !== null && $substituir) {
                $detach = $this->db->prepare(
                    'UPDATE passe_livre_atestados
                     SET passe_livre_aluno_curso_id = NULL
                     WHERE passe_livre_aluno_curso_id = :id'
                );
                $detach->execute(['id' => $alunoCursoId]);
            }

            $agora = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
            $ano = (int)$agora->format('Y');
            $numero = $this->proximoNumero($ano);
            $assinadoEm = $agora->format('Y-m-d H:i:s');
            $dataDocumento = PasseLivreAtestadoPdf::dataExtensoEm($agora);
            $codigo = $this->gerarCodigoVerificacao();

            $nomeSocial = trim((string)($linha['nome_social'] ?? ''));
            $nome = $nomeSocial !== ''
                ? $nomeSocial
                : trim((string)($linha['nome'] ?? ''));

            $frequencia = $linha['frequencia'] ?? null;
            $disciplinasSnapshot = $this->snapshotDisciplinas($disciplinas);

            $statement = $this->db->prepare(
                'INSERT INTO passe_livre_atestados (
                    passe_livre_aluno_curso_id, numero, ano, data_documento, assinado_em,
                    codigo_verificacao, usuario_id, nome_aluno, matricula, nome_curso, periodo,
                    frequencia_geral, disciplinas_json
                 ) VALUES (
                    :aluno_curso_id, :numero, :ano, :data_documento, :assinado_em,
                    :codigo, :usuario_id, :nome_aluno, :matricula, :nome_curso, :periodo,
                    :frequencia_geral, :disciplinas_json
                 )'
            );
            $statement->execute([
                'aluno_curso_id' => $alunoCursoId,
                'numero' => $numero,
                'ano' => $ano,
                'data_documento' => $dataDocumento,
                'assinado_em' => $assinadoEm,
                'codigo' => $codigo,
                'usuario_id' => $usuarioId,
                'nome_aluno' => $nome,
                'matricula' => (string)($linha['matricula'] ?? ''),
                'nome_curso' => (string)($linha['nome_curso'] ?? ''),
                'periodo' => (string)($linha['periodo'] ?? ''),
                'frequencia_geral' => is_numeric($frequencia) ? (float)$frequencia : null,
                'disciplinas_json' => (string)json_encode(
                    $disciplinasSnapshot,
                    JSON_UNESCAPED_UNICODE
                ),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $criado = $this->buscarPorAlunoCursoId($alunoCursoId);
        if ($criado === null) {
            throw new RuntimeException('Falha ao gravar assinatura do atestado.');
        }

        return $criado;
    }

    /**
     * @param list<array<string, mixed>> $disciplinas
     * @return list<array{codigo: string, nome: string, frequencia: float|null, situacao: string, data_trancamento: string}>
     */
    private function snapshotDisciplinas(array $disciplinas): array
    {
        $saida = [];
        foreach ($disciplinas as $disc) {
            $codigo = trim((string)($disc['codigo_disciplina'] ?? $disc['codigo'] ?? ''));
            $nome = trim((string)($disc['disciplina'] ?? $disc['nome'] ?? ''));
            $freq = $disc['frequencia'] ?? null;
            $situacao = trim((string)($disc['situacao'] ?? ''));
            $dataTranc = trim((string)($disc['data_trancamento'] ?? ''));
            if ($situacao !== '') {
                $freq = null;
            } else {
                $dataTranc = '';
            }
            $saida[] = [
                'codigo' => $codigo,
                'nome' => $nome,
                'frequencia' => is_numeric($freq) ? (float)$freq : null,
                'situacao' => $situacao,
                'data_trancamento' => $dataTranc,
            ];
        }

        return $saida;
    }

    /** A numeração recomeça em 1 a cada ano (o número é exibido como numero/ano). */
    private function proximoNumero(int $ano): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(numero), 0) + 1 AS proximo FROM passe_livre_atestados WHERE ano = :ano'
        );
        $statement->execute(['ano' => $ano]);
        $proximo = (int)$statement->fetchColumn();

        return max(1, $proximo);
    }

    private function gerarCodigoVerificacao(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $codigo = strtoupper(bin2hex(random_bytes(8)));
            $statement = $this->db->prepare(
                'SELECT 1 FROM passe_livre_atestados WHERE codigo_verificacao = :codigo LIMIT 1'
            );
            $statement->execute(['codigo' => $codigo]);
            if ($statement->fetchColumn() === false) {
                return $codigo;
            }
        }

        throw new RuntimeException('Não foi possível gerar código de verificação.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizar(array $row): array
    {
        $numero = (int)$row['numero'];
        $ano = (int)$row['ano'];
        $freq = $row['frequencia_geral'] ?? null;
        $disciplinas = [];
        $bruto = (string)($row['disciplinas_json'] ?? '');
        if ($bruto !== '') {
            $decoded = json_decode($bruto, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $freqDisc = $item['frequencia'] ?? null;
                    $situacao = trim((string)($item['situacao'] ?? ''));
                    $dataTranc = trim((string)($item['data_trancamento'] ?? ''));
                    $disciplinas[] = [
                        'codigo' => (string)($item['codigo'] ?? ''),
                        'nome' => (string)($item['nome'] ?? ''),
                        'frequencia' => is_numeric($freqDisc) ? (float)$freqDisc : null,
                        'situacao' => $situacao,
                        'data_trancamento' => $dataTranc,
                    ];
                }
            }
        }

        return [
            'id' => (int)$row['id'],
            'passe_livre_aluno_curso_id' => isset($row['passe_livre_aluno_curso_id'])
                ? (int)$row['passe_livre_aluno_curso_id']
                : null,
            'numero' => $numero,
            'ano' => $ano,
            'numero_formatado' => $numero . '/' . $ano,
            'data_documento' => (string)$row['data_documento'],
            'assinado_em' => (string)$row['assinado_em'],
            'codigo_verificacao' => (string)$row['codigo_verificacao'],
            'usuario_id' => $row['usuario_id'] !== null ? (int)$row['usuario_id'] : null,
            'nome_aluno' => (string)($row['nome_aluno'] ?? ''),
            'matricula' => (string)($row['matricula'] ?? ''),
            'nome_curso' => (string)($row['nome_curso'] ?? ''),
            'periodo' => (string)($row['periodo'] ?? ''),
            'frequencia_geral' => is_numeric($freq) ? (float)$freq : null,
            'disciplinas' => $disciplinas,
        ];
    }
}
