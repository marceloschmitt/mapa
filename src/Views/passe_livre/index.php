<?php

$linhas = $linhas ?? [];
$disciplinasPorLinha = $disciplinasPorLinha ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$filtroNome = (string)($filtroNome ?? '');
$meta = $meta ?? null;
$periodosDisponiveis = $periodosDisponiveis ?? [];
$semestreSelecionado = (string)($semestreSelecionado ?? '');
$mostrarBadgeCurso = $semSeletorCurso;
$podeGerarPasseLivre = !empty($podeGerarPasseLivre);

$scriptDir = dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$assetBase = ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') ? '' : $scriptDir;
$brasaoUrl = $assetBase . '/assets/img/brasao.jpeg';

/**
 * @param mixed $valor
 */
$fmtPct = static function ($valor): string {
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return '—';
    }

    return number_format((float)$valor, 1, ',', '.') . '%';
};

$dataExtenso = static function (): string {
    $meses = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];
    $mes = $meses[(int)date('n')] ?? '';

    return sprintf('Porto Alegre-RS, %d de %s de %d', (int)date('j'), $mes, (int)date('Y'));
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Passe livre</h1>
        <?php if ($mostrarBadgeCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($cursoExibido ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Percentual de frequência dos alunos ATIVO/FORMANDO do semestre atual,
            consultado nos três semestres anteriores
            <?php if (is_array($meta)): ?>
                (exibindo <?= htmlspecialchars((string)$meta['periodo'], ENT_QUOTES, 'UTF-8') ?>;
                <?= htmlspecialchars((string)$meta['data_inicial'], ENT_QUOTES, 'UTF-8') ?>
                a <?= htmlspecialchars((string)$meta['data_final'], ENT_QUOTES, 'UTF-8') ?>).
                Clique na linha para ver as disciplinas.
            <?php else: ?>
                . Selecione o semestre quando houver dados gerados.
            <?php endif; ?>
        </p>
        <?php if (is_array($meta)): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    <?= $totalAlunos ?> registro<?= $totalAlunos === 1 ? '' : 's' ?>
                </span>
                <span class="badge text-bg-light text-dark border">
                    Gerado em <?= htmlspecialchars((string)($meta['gerado_em'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-column align-items-stretch align-items-md-end gap-2">
        <?php if ($podeGerarPasseLivre): ?>
            <div class="text-md-end">
                <form method="post" action="<?= htmlspecialchars(url('/passe-livre/gerar'), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-primary">Gerar passe livre</button>
                </form>
                <p class="small text-secondary mb-0 mt-1" style="max-width: 280px;">
                    Gera os 3 semestres anteriores ao atual. Execute no início do semestre, uma vez.
                </p>
            </div>
        <?php endif; ?>
        <?php if ($periodosDisponiveis !== []): ?>
            <form id="form-passe-livre-filtro" method="get"
                  action="<?= htmlspecialchars(url('/passe-livre'), ENT_QUOTES, 'UTF-8') ?>"
                  class="d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label for="semestre" class="form-label mb-0 small text-secondary">Semestre</label>
                    <select class="form-select" id="semestre" name="semestre" style="min-width: 120px;"
                            onchange="this.form.submit()">
                        <?php foreach ($periodosDisponiveis as $periodo): ?>
                            <option value="<?= htmlspecialchars((string)$periodo, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $semestreSelecionado === (string)$periodo ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$periodo, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="filtro-nome" class="form-label mb-0 small text-secondary">Nome</label>
                    <input type="search" class="form-control" id="filtro-nome" name="nome"
                           value="<?= htmlspecialchars($filtroNome, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Filtrar por nome" style="min-width: 200px;"
                           autocomplete="off">
                </div>
                <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
                    <div>
                        <label for="curso" class="form-label mb-0 small text-secondary">Curso</label>
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
                    </div>
                <?php endif; ?>
                <?php
                $temFiltroExtra = $filtroNome !== ''
                    || (!$semSeletorCurso && $cursoSelecionado !== 'todos');
                if ($temFiltroExtra):
                    $urlLimpar = url('/passe-livre');
                    if ($semestreSelecionado !== '') {
                        $urlLimpar .= '?semestre=' . rawurlencode($semestreSelecionado);
                    }
                ?>
                    <a href="<?= htmlspecialchars($urlLimpar, ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-outline-secondary">Limpar</a>
                <?php endif; ?>
            </form>
            <script>
            (function () {
                const form = document.getElementById('form-passe-livre-filtro');
                const nome = document.getElementById('filtro-nome');
                if (!form || !nome) {
                    return;
                }
                let timer = null;
                nome.addEventListener('input', function () {
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        form.submit();
                    }, 400);
                });
            })();
            </script>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($avisoCoordenador)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($avisoCoordenador, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (is_array($meta) && empty($erro) && empty($avisoCoordenador) && $linhas === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">
            <?php if ($filtroNome !== ''): ?>
                Nenhum aluno encontrado para “<?= htmlspecialchars($filtroNome, ENT_QUOTES, 'UTF-8') ?>”.
            <?php else: ?>
                Nenhum aluno com frequência neste semestre.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($linhas !== []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small tabela-passe-livre">
                    <thead class="table-light">
                        <tr>
                            <th class="text-end pe-1" style="width: 2.5rem;">#</th>
                            <th>Nome</th>
                            <th>Matrícula</th>
                            <th>Curso</th>
                            <th class="text-end">Frequência*</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linhas as $i => $linha): ?>
                            <?php
                            $nomeSocial = trim((string)($linha['nome_social'] ?? ''));
                            $nome = $nomeSocial !== ''
                                ? $nomeSocial
                                : trim((string)($linha['nome'] ?? ''));
                            $disciplinas = $disciplinasPorLinha[(int)$linha['id']] ?? [];
                            $payload = [
                                'id' => (int)$linha['id'],
                                'nome' => $nome,
                                'matricula' => (string)($linha['matricula'] ?? ''),
                                'curso' => (string)($linha['nome_curso'] ?? ''),
                                'periodo' => (string)($linha['periodo'] ?? ($meta['periodo'] ?? '')),
                                'ingresso' => (string)($linha['ano_semestre_ingresso'] ?? ''),
                                'frequencia' => $linha['frequencia'],
                                'disciplinas' => array_map(
                                    static function (array $d): array {
                                        return [
                                            'codigo' => (string)($d['codigo_disciplina'] ?? ''),
                                            'nome' => (string)($d['disciplina'] ?? ''),
                                            'frequencia' => $d['frequencia'],
                                        ];
                                    },
                                    $disciplinas
                                ),
                            ];
                            $json = htmlspecialchars(
                                (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                            <tr class="linha-passe-livre"
                                tabindex="0"
                                role="button"
                                data-passe-livre="<?= $json ?>">
                                <td class="text-end text-secondary pe-1"><?= $i + 1 ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($linha['matricula'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($linha['nome_curso'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end">
                                    <code><?= htmlspecialchars($fmtPct($linha['frequencia'] ?? null), ENT_QUOTES, 'UTF-8') ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-secondary px-3 py-2 mb-0 border-top">
                * A frequência é o percentual de presença em relação ao número de aulas ministradas.
            </p>
        </div>
    </div>

    <div class="modal fade" id="modalPasseLivre" tabindex="-1"
         aria-labelledby="modalPasseLivreTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header py-2 px-3 bg-primary text-white">
                    <h2 class="modal-title h6 mb-0" id="modalPasseLivreTitulo">Visualizar Documento</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light">
                    <div class="atestado-passe-livre mx-auto bg-white border shadow-sm p-4 p-md-5">
                        <div class="atestado-cabecalho text-center mb-3">
                            <img src="<?= htmlspecialchars($brasaoUrl, ENT_QUOTES, 'UTF-8') ?>"
                                 alt="Brasão da República Federativa do Brasil"
                                 class="atestado-brasao-img">
                            <div class="atestado-cabecalho-texto small fw-bold text-uppercase">
                                <div>Ministério da Educação</div>
                                <div>Secretaria de Educação Profissional e Tecnológica</div>
                                <div>Instituto Federal de Educação, Ciência e Tecnologia do Rio Grande do Sul</div>
                            </div>
                        </div>

                        <p class="atestado-titulo fw-bold text-uppercase mb-2">
                            Atestado de Matrícula Nº
                            <span class="atestado-numero-vazio"></span>
                            /
                            <span class="atestado-numero-vazio"></span>
                        </p>

                        <div class="d-flex flex-wrap justify-content-between gap-2 small mb-4">
                            <div>Nº do Protocolo: PROTOCOLO INDEFINIDO</div>
                            <div id="modalPasseLivreData"><?= htmlspecialchars($dataExtenso(), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <p class="small text-justify mb-4" id="modalPasseLivreTexto"></p>

                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle mb-0 small atestado-tabela">
                                <thead class="table-light">
                                    <tr>
                                        <th>Semestre</th>
                                        <th>Código</th>
                                        <th>Disciplina</th>
                                        <th class="text-end">Frequência*</th>
                                    </tr>
                                </thead>
                                <tbody id="modalPasseLivreDisciplinas"></tbody>
                            </table>
                        </div>

                        <p class="small text-secondary mb-3">
                            * A frequência é o percentual de presença em relação ao número de aulas ministradas.
                        </p>

                        <p class="small fw-semibold mb-0">
                            Frequência* global no curso:
                            <span id="modalPasseLivreGeral"></span>
                        </p>

                        <div class="atestado-assinatura text-center small">
                            <p class="fst-italic mb-2" id="modalPasseLivreAssinaturaData"></p>
                            <p class="fw-bold mb-1">GRACIELA DA SILVA LEITES</p>
                            <p class="mb-1">COORDENADOR (TITULAR) - TITULAR</p>
                            <p class="mb-1">COORD. DE REGISTROS ESTUDANTIS (PORTO ALEGRE)</p>
                            <p class="mb-0">Matrícula: 1760610</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <a href="#" class="btn btn-primary" id="modalPasseLivrePdf" download>
                        Baixar PDF
                    </a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .tabela-passe-livre .linha-passe-livre { cursor: pointer; }
        .tabela-passe-livre .linha-passe-livre:focus {
            outline: 2px solid #2c5282;
            outline-offset: -2px;
        }
        .atestado-passe-livre {
            max-width: 820px;
            font-family: Georgia, "Times New Roman", Times, serif;
            color: #111;
            line-height: 1.45;
        }
        .atestado-brasao-img {
            display: block;
            width: min(180px, 39%);
            max-width: 100%;
            height: auto;
            margin: 0 auto 0.15rem;
            object-fit: contain;
        }
        .atestado-cabecalho-texto {
            line-height: 1.2;
        }
        .atestado-cabecalho-texto > div + div {
            margin-top: 0.1rem;
        }
        .atestado-titulo {
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }
        .atestado-numero-vazio {
            display: inline-block;
            min-width: 4.5rem;
            border-bottom: 1px solid #333;
            vertical-align: baseline;
            margin: 0 0.15rem;
        }
        .atestado-tabela th,
        .atestado-tabela td {
            vertical-align: middle;
        }
        .text-justify {
            text-align: justify;
        }
        .atestado-assinatura {
            margin-top: 3.5rem;
            padding-top: 1rem;
        }
        .atestado-assinatura p {
            margin-bottom: 0.25rem;
        }
    </style>
    <script>
    window.addEventListener('load', function () {
        const modalEl = document.getElementById('modalPasseLivre');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        const texto = document.getElementById('modalPasseLivreTexto');
        const geral = document.getElementById('modalPasseLivreGeral');
        const assinaturaData = document.getElementById('modalPasseLivreAssinaturaData');
        const tbody = document.getElementById('modalPasseLivreDisciplinas');
        const pdfLink = document.getElementById('modalPasseLivrePdf');
        const pdfBase = <?= json_encode(url('/passe-livre/pdf'), JSON_UNESCAPED_UNICODE) ?>;

        function fmtPct(valor) {
            if (valor === null || valor === undefined || valor === '' || Number.isNaN(Number(valor))) {
                return '—';
            }
            return Number(valor).toLocaleString('pt-BR', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%';
        }

        function escapeHtml(texto) {
            return String(texto)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function valorOu(traco, valor) {
            const limpo = String(valor || '').trim();
            return limpo !== '' ? limpo : traco;
        }

        function abrir(dados) {
            const nome = valorOu('---', dados.nome);
            const matricula = valorOu('---', dados.matricula);
            const ingresso = valorOu('---', dados.ingresso);
            const curso = valorOu('---', dados.curso);
            const periodo = valorOu('---', dados.periodo);

            texto.textContent =
                'Atestamos, para os devidos fins, que o(a) aluno(a) '
                + nome
                + ', matrícula nº '
                + matricula
                + ', com ingresso em '
                + ingresso
                + ', no curso '
                + curso
                + ' obteve, no semestre letivo '
                + periodo
                + ', a frequência abaixo discriminada:';

            geral.textContent = fmtPct(dados.frequencia);

            const agora = new Date();
            const pad = function (n) { return String(n).padStart(2, '0'); };
            assinaturaData.textContent = '(Assinado digitalmente em '
                + pad(agora.getDate()) + '/'
                + pad(agora.getMonth() + 1) + '/'
                + agora.getFullYear() + ' '
                + pad(agora.getHours()) + ':'
                + pad(agora.getMinutes()) + ')';

            tbody.innerHTML = '';
            const discs = Array.isArray(dados.disciplinas) ? dados.disciplinas : [];
            discs.forEach(function (d) {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + escapeHtml(periodo) + '</td>' +
                    '<td>' + (d.codigo
                        ? '<code>' + escapeHtml(d.codigo) + '</code>'
                        : '<span class="text-secondary">—</span>') + '</td>' +
                    '<td>' + escapeHtml(d.nome || '') + '</td>' +
                    '<td class="text-end">' + escapeHtml(fmtPct(d.frequencia)) + '</td>';
                tbody.appendChild(tr);
            });

            if (dados.id) {
                pdfLink.href = pdfBase + '?id=' + encodeURIComponent(String(dados.id));
                pdfLink.classList.remove('disabled');
            } else {
                pdfLink.href = '#';
                pdfLink.classList.add('disabled');
            }

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        function lerDados(linha) {
            try {
                return JSON.parse(linha.getAttribute('data-passe-livre') || '{}');
            } catch (e) {
                return null;
            }
        }

        document.querySelectorAll('.linha-passe-livre').forEach(function (linha) {
            linha.addEventListener('click', function () {
                const dados = lerDados(linha);
                if (dados) {
                    abrir(dados);
                }
            });
            linha.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    const dados = lerDados(linha);
                    if (dados) {
                        abrir(dados);
                    }
                }
            });
        });
    });
    </script>
<?php endif; ?>
