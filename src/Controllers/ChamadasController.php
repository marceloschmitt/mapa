<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Models\AnalyticsRepository;
use Mapa\Services\ChamadaEmailService;

class ChamadasController extends Controller
{
    public function index(): void
    {
        $this->requireAdminOuGeral();

        $repo = new AnalyticsRepository();
        $coleta = $repo->ultimaColeta();
        $isCoordenador = Auth::isCoordenador();
        $escopo = $this->escopoCursos($repo);
        $cursosDisponiveis = $escopo['cursosDisponiveis'];
        $cursoSelecionado = $escopo['cursoSelecionado'];
        $filtroCursoIds = $escopo['filtroCursoIds'];
        $cursoExibido = $escopo['cursoExibido'];
        $avisoEscopo = $escopo['aviso'];

        $disciplinas = [];
        $erro = $avisoEscopo;

        if ($avisoEscopo !== null) {
            $disciplinas = [];
        } elseif ($coleta === null) {
            $erro = 'Nenhuma coleta importada.';
        } else {
            $disciplinas = $repo->disciplinasUltimaAula((int)$coleta['id'], $filtroCursoIds);
            if ($disciplinas === [] && $cursoSelecionado === 'todos' && !$isCoordenador) {
                $erro = 'Nenhuma data de chamada importada. Rode python3 importar_chamadas.py';
            }
            $disciplinas = $this->anexarEmailChamada($disciplinas);
        }

        $disciplinasAtrasadas = [];
        $disciplinasEmDia = [];
        $semRegistroAtrasadas = 0;
        $semRegistroSemData = 0;
        $atrasadasPrimeiroSemestre = 0;
        $atrasadasComEmail = 0;
        foreach ($disciplinas as $linha) {
            $semData = trim((string)($linha['data_ultima_aula'] ?? '')) === '';
            if (!empty($linha['atrasado'])) {
                $disciplinasAtrasadas[] = $linha;
                if ($semData) {
                    $semRegistroAtrasadas++;
                }
                if (!empty($linha['email_enviado'])) {
                    $atrasadasComEmail++;
                }
                $nivel = strtoupper(trim((string)($linha['curso_nivel'] ?? '')));
                if (
                    trim((string)($linha['semestre_oferta'] ?? '')) === '1'
                    && $nivel !== 'N'
                ) {
                    $atrasadasPrimeiroSemestre++;
                }
            } else {
                $disciplinasEmDia[] = $linha;
                if ($semData) {
                    $semRegistroSemData++;
                }
            }
        }

        $this->render('chamadas/index', [
            'coleta' => $coleta,
            'disciplinasAtrasadas' => $disciplinasAtrasadas,
            'disciplinasEmDia' => $disciplinasEmDia,
            'totalDisciplinas' => count($disciplinas),
            'semRegistroAtrasadas' => $semRegistroAtrasadas,
            'semRegistroSemData' => $semRegistroSemData,
            'atrasadas' => count($disciplinasAtrasadas),
            'atrasadasComEmail' => $atrasadasComEmail,
            'atrasadasPrimeiroSemestre' => $atrasadasPrimeiroSemestre,
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $cursoExibido,
            'semSeletorCurso' => $isCoordenador,
            'rotuloGeral' => 'Todos os cursos',
            'erro' => $erro,
            'isAdmin' => Auth::isAdmin(),
            'podeVerChamadas' => true,
        ]);
    }

    public function exportarAtrasadasPrimeiroSemestre(): void
    {
        $this->requireAdminOuGeral();

        $repo = new AnalyticsRepository();
        $coleta = $repo->ultimaColeta();
        if ($coleta === null) {
            http_response_code(404);
            echo 'Nenhuma coleta importada.';
            exit;
        }

        $escopo = $this->escopoCursos($repo);
        if ($escopo['aviso'] !== null) {
            http_response_code(403);
            echo $escopo['aviso'];
            exit;
        }

        $filtroCursoIds = $escopo['filtroCursoIds'];
        $disciplinas = $repo->disciplinasUltimaAula((int)$coleta['id'], $filtroCursoIds);

        $porCurso = [];
        foreach ($disciplinas as $linha) {
            if (empty($linha['atrasado'])) {
                continue;
            }
            if (trim((string)($linha['semestre_oferta'] ?? '')) !== '1') {
                continue;
            }
            // Exclui cursos integrados ao ensino medio (curso_nivel = N).
            $nivel = strtoupper(trim((string)($linha['curso_nivel'] ?? '')));
            if ($nivel === 'N') {
                continue;
            }
            $curso = trim((string)($linha['nome_curso'] ?? ''));
            if ($curso === '') {
                $curso = 'Curso nao informado';
            }
            $codigo = trim((string)($linha['codigo_disciplina'] ?? ''));
            if ($codigo === '') {
                continue;
            }
            $porCurso[$curso][$codigo] = [
                'disciplina' => trim((string)($linha['disciplina'] ?? '')),
                'professores' => trim((string)($linha['professores'] ?? '')),
                'data_faltante' => trim((string)($linha['dia_esperado'] ?? '')),
            ];
        }

        ksort($porCurso, SORT_STRING);
        foreach ($porCurso as $curso => $itens) {
            ksort($itens, SORT_STRING);
            $porCurso[$curso] = $itens;
        }

        $nomeArquivo = 'chamadas-atrasadas-1-semestre-' . date('Y-m-d') . '.pdf';

        $pdf = new \Mapa\Lib\SimplePdf(true);
        $larguras = [95.0, 280.0, 220.0, 120.0];

        $pdf->documentTitle(
            'Relatório para ser utilizado na primeira semana de aula (apenas disciplinas do primeiro semestre)'
        );
        $pdf->spacer(10);

        if ($porCurso === []) {
            $pdf->setFontSize(11);
            $pdf->sectionTitle($larguras, 'Nenhuma disciplina atrasada do 1o semestre para exportar.');
        }

        $primeiro = true;
        foreach ($porCurso as $curso => $itens) {
            if (!$primeiro) {
                $pdf->spacer(14);
            }
            $primeiro = false;

            $pdf->setFontSize(11);
            $pdf->sectionTitle($larguras, $curso);
            $pdf->setFontSize(9);
            $pdf->tableHeader($larguras, [
                'Codigo disciplina',
                'Disciplina',
                'Professor(es)',
                'Dia não registrado',
            ]);

            foreach ($itens as $codigo => $info) {
                $faltante = trim((string)($info['data_faltante'] ?? ''));
                $faltanteFmt = '';
                if ($faltante !== '') {
                    $ts = strtotime($faltante);
                    $faltanteFmt = $ts !== false ? date('d/m/Y', $ts) : $faltante;
                }
                $pdf->tableRow($larguras, [
                    (string)$codigo,
                    (string)($info['disciplina'] ?? ''),
                    (string)($info['professores'] ?? ''),
                    $faltanteFmt,
                ]);
            }
        }

        $pdf->output($nomeArquivo);
        exit;
    }

    /**
     * @param list<array<string, mixed>> $disciplinas
     * @return list<array<string, mixed>>
     */
    private function anexarEmailChamada(array $disciplinas): array
    {
        $mapa = (new ChamadaEmailService())->mapaEmailsEnviados();
        foreach ($disciplinas as &$linha) {
            $codigo = trim((string)($linha['codigo_disciplina'] ?? ''));
            $cursoId = (int)($linha['curso_id'] ?? 0);
            $diaEsperado = trim((string)($linha['dia_esperado'] ?? ''));
            $chave = $codigo . '|' . $cursoId . '|' . $diaEsperado;
            $info = $mapa[$chave] ?? null;
            $linha['email_enviado'] = $info !== null;
            $linha['email_enviado_em'] = $info['enviado_em'] ?? '';
            $linha['email_destinatarios'] = $info['destinatarios'] ?? '';
        }
        unset($linha);

        return $disciplinas;
    }

    /**
     * @return array{
     *   cursosDisponiveis: list<array{id: int, nome_curso: string}>,
     *   cursoSelecionado: string,
     *   filtroCursoIds: list<int>|null,
     *   cursoExibido: string|null,
     *   aviso: string|null
     * }
     */
    private function escopoCursos(AnalyticsRepository $repo): array
    {
        if (Auth::isCoordenador()) {
            $cursoIds = Auth::cursoIds();
            $aviso = $cursoIds === []
                ? 'Nenhum curso vinculado ao seu usuário.'
                : null;
            $cursoExibido = 'Curso vinculado';
            if ($cursoIds !== []) {
                $cursos = $repo->listarCursos($cursoIds);
                if (count($cursoIds) === 1) {
                    $cursoExibido = (string)($cursos[0]['nome_curso'] ?? 'Curso vinculado');
                } else {
                    $cursoExibido = count($cursoIds) . ' cursos vinculados';
                }
            }

            return [
                'cursosDisponiveis' => [],
                'cursoSelecionado' => 'todos',
                'filtroCursoIds' => $cursoIds,
                'cursoExibido' => $cursoExibido,
                'aviso' => $aviso,
            ];
        }

        $cursosDisponiveis = $repo->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis);
        $filtroCursoIds = $cursoSelecionado === 'todos' ? null : [(int)$cursoSelecionado];

        return [
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'filtroCursoIds' => $filtroCursoIds,
            'cursoExibido' => null,
            'aviso' => null,
        ];
    }

    /**
     * @param list<array{id: int, nome_curso: string}> $cursosDisponiveis
     */
    private function cursoSelecionado(array $cursosDisponiveis): string
    {
        $param = isset($_GET['curso']) ? trim((string)$_GET['curso']) : null;
        if ($param === null || $param === '' || $param === 'todos') {
            return 'todos';
        }

        $id = (int)$param;
        foreach ($cursosDisponiveis as $curso) {
            if ((int)$curso['id'] === $id) {
                return (string)$id;
            }
        }

        return 'todos';
    }
}
