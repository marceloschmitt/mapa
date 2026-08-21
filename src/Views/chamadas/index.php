<?php

use Mapa\Core\View;

$disciplinasAtrasadas = $disciplinasAtrasadas ?? [];
$disciplinasEmDia = $disciplinasEmDia ?? [];
$totalDisciplinas = (int)($totalDisciplinas ?? 0);
$semRegistroAtrasadas = (int)($semRegistroAtrasadas ?? 0);
$semRegistroSemData = (int)($semRegistroSemData ?? 0);
$atrasadas = (int)($atrasadas ?? 0);
$atrasadasComEmail = (int)($atrasadasComEmail ?? 0);
$atrasadasPrimeiroSemestre = (int)($atrasadasPrimeiroSemestre ?? 0);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$semSeletorCurso = !empty($semSeletorCurso);
$cursoExibido = (string)($cursoExibido ?? '');
$vazioGeral = $disciplinasAtrasadas === [] && $disciplinasEmDia === [];
$queryCurso = $cursoSelecionado !== 'todos' ? '?curso=' . rawurlencode($cursoSelecionado) : '';
$urlExportar = url('/chamadas/exportar-atrasadas-1-semestre') . $queryCurso;

$formatarData = static function (?string $data): string {
    $data = trim((string)$data);
    if ($data === '') {
        return '';
    }
    $ts = strtotime($data);
    return $ts !== false ? date('d/m/Y', $ts) : $data;
};

$diasSemana = [
    'Sunday' => 'domingo',
    'Monday' => 'segunda-feira',
    'Tuesday' => 'terça-feira',
    'Wednesday' => 'quarta-feira',
    'Thursday' => 'quinta-feira',
    'Friday' => 'sexta-feira',
    'Saturday' => 'sábado',
];

$formatarDiaSemana = static function (?string $data) use ($diasSemana): string {
    $data = trim((string)$data);
    if ($data === '') {
        return '';
    }
    $ts = strtotime($data);
    if ($ts === false) {
        return '';
    }
    return $diasSemana[date('l', $ts)] ?? '';
};

$renderLinhas = static function (
    array $linhas,
    bool $mostrarFaltante,
    callable $formatarData,
    callable $formatarDiaSemana
): void {
    $numero = 0;
    foreach ($linhas as $linha) {
        $numero++;
        $data = trim((string)($linha['data_ultima_aula'] ?? ''));
        $semData = $data === '';
        $dataFmt = $semData ? '—' : $formatarData($data);
        $diaSemana = $semData ? '' : $formatarDiaSemana($data);
        $profs = trim((string)($linha['professores'] ?? ''));
        $diasAula = trim((string)($linha['dias_aula_rotulo'] ?? ''));
        $semestreRotulo = View::rotuloSemestre($linha['semestre_oferta'] ?? '');
        $nomeDisciplina = (string)$linha['disciplina'];
        if ($semestreRotulo !== '') {
            $nomeDisciplina .= ' (' . $semestreRotulo . ')';
        }
        $diaEsperado = trim((string)($linha['dia_esperado'] ?? ''));
        $exibirFaltante = $mostrarFaltante && $diaEsperado !== '';
        $diaEsperadoFmt = $exibirFaltante ? $formatarData($diaEsperado) : '';
        $diaEsperadoSemana = $exibirFaltante ? $formatarDiaSemana($diaEsperado) : '';
        $emailEnviado = !empty($linha['email_enviado']);
        $emailEnviadoEm = trim((string)($linha['email_enviado_em'] ?? ''));
        $emailEnviadoFmt = '';
        if ($emailEnviadoEm !== '') {
            $tsEmail = strtotime($emailEnviadoEm);
            $emailEnviadoFmt = $tsEmail !== false
                ? date('d/m/Y H:i', $tsEmail)
                : $emailEnviadoEm;
        }
        $emailDestinatarios = trim((string)($linha['email_destinatarios'] ?? ''));
        $datas = $linha['datas_chamada'] ?? [];
        if (!is_array($datas)) {
            $datas = [];
        }
        $datasModal = [];
        foreach ($datas as $dataChamada) {
            $fmt = $formatarData($dataChamada);
            if ($fmt === '') {
                continue;
            }
            $datasModal[] = [
                'data' => $fmt,
                'dia' => $formatarDiaSemana($dataChamada),
            ];
        }
        $tituloModal = trim((string)$linha['codigo_disciplina'] . ' — ' . $nomeDisciplina);
        $classeLinha = $mostrarFaltante ? 'table-danger' : ($semData ? 'table-warning' : '');
        ?>
        <tr class="linha-chamada <?= $classeLinha ?>"
            role="button"
            tabindex="0"
            data-titulo="<?= htmlspecialchars($tituloModal, ENT_QUOTES, 'UTF-8') ?>"
            data-curso="<?= htmlspecialchars((string)$linha['nome_curso'], ENT_QUOTES, 'UTF-8') ?>"
            data-professores="<?= htmlspecialchars($profs, ENT_QUOTES, 'UTF-8') ?>"
            data-datas="<?= htmlspecialchars(json_encode($datasModal, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
            <td class="text-secondary text-end pe-1" style="width: 2.5rem;"><?= $numero ?></td>
            <td>
                <code><?= htmlspecialchars((string)$linha['codigo_disciplina'], ENT_QUOTES, 'UTF-8') ?></code>
            </td>
            <td><?= htmlspecialchars($nomeDisciplina, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$linha['nome_curso'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <?= $diasAula !== ''
                    ? htmlspecialchars($diasAula, ENT_QUOTES, 'UTF-8')
                    : '<span class="text-secondary">—</span>' ?>
            </td>
            <td>
                <?= $profs !== ''
                    ? htmlspecialchars($profs, ENT_QUOTES, 'UTF-8')
                    : '<span class="text-secondary">—</span>' ?>
            </td>
            <td class="text-end"><?= (int)($linha['total_registros'] ?? 0) ?></td>
            <td class="text-end <?= $semData ? 'text-danger' : '' ?>">
                <div class="fw-semibold"><?= htmlspecialchars($dataFmt, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($diaSemana !== ''): ?>
                    <div class="small text-secondary fw-normal"><?= htmlspecialchars($diaSemana, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </td>
            <?php if ($mostrarFaltante): ?>
                <td class="text-end text-danger">
                    <?php if ($exibirFaltante): ?>
                        <div class="fw-semibold"><?= htmlspecialchars($diaEsperadoFmt, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($diaEsperadoSemana !== ''): ?>
                            <div class="small text-secondary fw-normal"><?= htmlspecialchars($diaEsperadoSemana, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-secondary">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($emailEnviado): ?>
                        <span class="badge text-bg-success">Enviado</span>
                        <?php if ($emailEnviadoFmt !== ''): ?>
                            <div class="small text-secondary mt-1"><?= htmlspecialchars($emailEnviadoFmt, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($emailDestinatarios !== ''): ?>
                            <div class="small text-secondary"><?= htmlspecialchars($emailDestinatarios, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-secondary">—</span>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
        <?php
    }
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Últimas chamadas</h1>
        <?php if ($semSeletorCurso && $cursoExibido !== ''): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($cursoExibido, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Chamadas em atraso e demais disciplinas, separadas.
            Clique na linha para ver todas as datas de chamada.
            <?php if ($coleta !== null): ?>
                <?= htmlspecialchars(View::rotuloColeta($coleta), ENT_QUOTES, 'UTF-8') ?>.
            <?php endif; ?>
        </p>
        <?php if ($coleta !== null): ?>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge text-bg-secondary">
                    <?= $totalDisciplinas ?> disciplina<?= $totalDisciplinas === 1 ? '' : 's' ?>
                </span>
                <span class="badge text-bg-danger">
                    <?= $atrasadas ?> atrasada<?= $atrasadas === 1 ? '' : 's' ?><?php if ($atrasadas > 0): ?>,
                    das quais <?= $atrasadasComEmail ?> já tiveram e-mail enviado<?php endif; ?>
                </span>
                <?php if ($semRegistroAtrasadas > 0): ?>
                    <span class="badge text-bg-danger">
                        <?= $semRegistroAtrasadas ?> sem nenhum registro e atrasada<?= $semRegistroAtrasadas === 1 ? '' : 's' ?>
                    </span>
                <?php endif; ?>
                <?php if ($semRegistroSemData > 0): ?>
                    <span class="badge text-bg-secondary">
                        <?= $semRegistroSemData ?> sem data para registrar
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
        <form method="get" action="<?= htmlspecialchars(url('/chamadas'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
            <label for="curso" class="form-label mb-0 text-nowrap">Curso</label>
            <select class="form-select" id="curso" name="curso" style="min-width: 260px;"
                    onchange="this.form.submit()">
                <option value="todos" <?= $cursoSelecionado === 'todos' ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rotuloGeral ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php foreach ($cursosDisponiveis as $curso): ?>
                    <option value="<?= (int)$curso['id'] ?>"
                        <?= $cursoSelecionado === (string)$curso['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$curso['nome_curso'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (!empty($erro) && $vazioGeral): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php elseif ($vazioGeral): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">Nenhuma disciplina encontrada para o filtro atual.</div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-4">
        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3 pb-2 px-3">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h2 class="h6 mb-1 text-danger">Disciplinas com chamadas em atraso</h2>
                        <p class="small text-secondary mb-0">
                            Ordenadas pela data faltante (mais recente primeiro).
                            <?= $atrasadas ?> disciplina<?= $atrasadas === 1 ? '' : 's' ?><?php if ($atrasadas > 0): ?>,
                            das quais <?= (int)($atrasadasComEmail ?? 0) ?> já tiveram e-mail enviado<?php endif; ?>.
                        </p>
                    </div>
                    <?php if ($atrasadasPrimeiroSemestre > 0): ?>
                        <a class="btn btn-outline-danger btn-sm"
                           href="<?= htmlspecialchars($urlExportar, ENT_QUOTES, 'UTF-8') ?>">
                            Exportar PDF 1º semestre (<?= $atrasadasPrimeiroSemestre ?>)
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-outline-danger btn-sm" disabled>
                            Exportar PDF 1º semestre (0)
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body px-3 pt-2">
                <?php if ($disciplinasAtrasadas === []): ?>
                    <p class="text-secondary mb-0">Nenhuma chamada em atraso no filtro atual.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small tabela-chamadas">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-end pe-1" style="width: 2.5rem;">#</th>
                                    <th>Código</th>
                                    <th>Disciplina</th>
                                    <th>Curso</th>
                                    <th>Dias de aula</th>
                                    <th>Professor(es)</th>
                                    <th class="text-end">Datas no histórico</th>
                                    <th class="text-end">Último registro</th>
                                    <th class="text-end">Dia não registrado</th>
                                    <th>E-mail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $renderLinhas($disciplinasAtrasadas, true, $formatarData, $formatarDiaSemana); ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3 pb-0 px-3">
                <h2 class="h6 mb-1">Disciplinas com chamadas em dia</h2>
                <p class="small text-secondary mb-0">
                    Inclui as ainda sem data para registrar (sem aula esperada).
                    Ordenadas pelo último registro.
                    <?= count($disciplinasEmDia) ?> disciplina<?= count($disciplinasEmDia) === 1 ? '' : 's' ?><?php if ($semRegistroSemData > 0): ?>
                    · <?= $semRegistroSemData ?> sem data para registrar<?php endif; ?>.
                </p>
            </div>
            <div class="card-body px-3 pt-2">
                <?php if ($disciplinasEmDia === []): ?>
                    <p class="text-secondary mb-0">Nenhuma disciplina neste grupo para o filtro atual.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small tabela-chamadas">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-end pe-1" style="width: 2.5rem;">#</th>
                                    <th>Código</th>
                                    <th>Disciplina</th>
                                    <th>Curso</th>
                                    <th>Dias de aula</th>
                                    <th>Professor(es)</th>
                                    <th class="text-end">Datas no histórico</th>
                                    <th class="text-end">Último registro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $renderLinhas($disciplinasEmDia, false, $formatarData, $formatarDiaSemana); ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="modal fade" id="modalDatasChamada" tabindex="-1" aria-labelledby="modalDatasChamadaTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5 mb-1" id="modalDatasChamadaTitulo">Datas de chamada</h2>
                        <div class="small text-secondary" id="modalDatasChamadaCurso"></div>
                        <div class="small text-secondary" id="modalDatasChamadaProfs"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <ul class="list-group list-group-flush" id="modalDatasChamadaLista"></ul>
                    <p class="text-secondary mb-0 d-none" id="modalDatasChamadaVazio">Nenhuma chamada registrada.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .tabela-chamadas .linha-chamada { cursor: pointer; }
        .tabela-chamadas .linha-chamada:focus { outline: 2px solid #2c5282; outline-offset: -2px; }
    </style>
    <script>
    window.addEventListener('load', function () {
        const modalEl = document.getElementById('modalDatasChamada');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        const titulo = document.getElementById('modalDatasChamadaTitulo');
        const curso = document.getElementById('modalDatasChamadaCurso');
        const profs = document.getElementById('modalDatasChamadaProfs');
        const lista = document.getElementById('modalDatasChamadaLista');
        const vazio = document.getElementById('modalDatasChamadaVazio');

        function abrirLinha(linha) {
            let datas = [];
            try {
                datas = JSON.parse(linha.getAttribute('data-datas') || '[]');
            } catch (e) {
                datas = [];
            }
            if (!Array.isArray(datas)) {
                datas = [];
            }

            titulo.textContent = linha.getAttribute('data-titulo') || 'Datas de chamada';
            curso.textContent = linha.getAttribute('data-curso') || '';
            const nomes = (linha.getAttribute('data-professores') || '').trim();
            profs.textContent = nomes !== '' ? 'Professor(es): ' + nomes : '';
            profs.classList.toggle('d-none', nomes === '');

            lista.innerHTML = '';
            if (datas.length === 0) {
                lista.classList.add('d-none');
                vazio.classList.remove('d-none');
            } else {
                lista.classList.remove('d-none');
                vazio.classList.add('d-none');
                datas.forEach(function (item) {
                    const li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
                    const dataSpan = document.createElement('span');
                    dataSpan.className = 'fw-semibold';
                    dataSpan.textContent = item.data || '';
                    const diaSpan = document.createElement('span');
                    diaSpan.className = 'text-secondary';
                    diaSpan.textContent = item.dia || '';
                    li.appendChild(dataSpan);
                    li.appendChild(diaSpan);
                    lista.appendChild(li);
                });
            }

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        document.querySelectorAll('.tabela-chamadas .linha-chamada').forEach(function (linha) {
            linha.addEventListener('click', function () {
                abrirLinha(linha);
            });
            linha.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault();
                    abrirLinha(linha);
                }
            });
        });
    });
    </script>
<?php endif; ?>
