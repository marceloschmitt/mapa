<?php

$linhas = $linhas ?? [];
$disciplinasPorLinha = $disciplinasPorLinha ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$filtroNome = (string)($filtroNome ?? '');
$meta = $meta ?? null;
$periodoAtual = (string)($periodoAtual ?? '');
$cursoExibido = (string)($cursoExibido ?? 'Todos os cursos');

/**
 * @param mixed $valor
 */
$fmtPct = static function ($valor): string {
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return '—';
    }

    return number_format((float)$valor, 1, ',', '.') . '%';
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Frequência corrente</h1>
        <p class="text-secondary mb-2">
            Percentuais de frequência do semestre atual
            <?php if ($periodoAtual !== ''): ?>
                (<?= htmlspecialchars($periodoAtual, ENT_QUOTES, 'UTF-8') ?>)
            <?php endif; ?>,
            atualizado a cada coleta. Clique na linha para ver as disciplinas.
            <?php if (is_array($meta)): ?>
                Intervalo
                <?= htmlspecialchars((string)$meta['data_inicial'], ENT_QUOTES, 'UTF-8') ?>
                a <?= htmlspecialchars((string)$meta['data_final'], ENT_QUOTES, 'UTF-8') ?>.
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
        <?php if (is_array($meta)): ?>
            <form id="form-frequencia-anual-filtro" method="get"
                  action="<?= htmlspecialchars(url('/frequencia-anual'), ENT_QUOTES, 'UTF-8') ?>"
                  class="d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label for="filtro-nome" class="form-label mb-0 small text-secondary">Nome</label>
                    <input type="search" class="form-control" id="filtro-nome" name="nome"
                           value="<?= htmlspecialchars($filtroNome, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Filtrar por nome" style="min-width: 200px;"
                           autocomplete="off">
                </div>
                <?php if ($cursosDisponiveis !== []): ?>
                    <div>
                        <label for="curso" class="form-label mb-0 small text-secondary">Curso</label>
                        <select class="form-select" id="curso" name="curso" style="min-width: 260px;"
                                onchange="this.form.submit()">
                            <option value="todos" <?= $cursoSelecionado === 'todos' ? 'selected' : '' ?>>
                                Todos os cursos
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
                <?php if ($filtroNome !== '' || $cursoSelecionado !== 'todos'): ?>
                    <a href="<?= htmlspecialchars(url('/frequencia-anual'), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-outline-secondary">Limpar</a>
                <?php endif; ?>
            </form>
            <script>
            (function () {
                const form = document.getElementById('form-frequencia-anual-filtro');
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

<?php if (is_array($meta) && empty($erro) && $linhas === []): ?>
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
                                            'situacao' => (string)($d['situacao'] ?? ''),
                                            'data_trancamento' => (string)($d['data_trancamento'] ?? ''),
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
                                data-id="<?= (int)$linha['id'] ?>"
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
                    <h2 class="modal-title h6 mb-0" id="modalPasseLivreTitulo">Frequência por disciplina</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light">
                    <div class="atestado-passe-livre mx-auto bg-white border shadow-sm p-4 p-md-5">
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

                        <p class="small text-secondary mb-0">
                            * A frequência é o percentual de presença em relação ao número de aulas ministradas.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
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
            color: #111;
            line-height: 1.45;
        }
        .atestado-tabela th,
        .atestado-tabela td {
            vertical-align: middle;
        }
        .text-justify {
            text-align: justify;
        }
    </style>
    <script>
    window.addEventListener('load', function () {
        const modalEl = document.getElementById('modalPasseLivre');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        const texto = document.getElementById('modalPasseLivreTexto');
        const tbody = document.getElementById('modalPasseLivreDisciplinas');

        function fmtPct(valor) {
            if (valor === null || valor === undefined || valor === '' || Number.isNaN(Number(valor))) {
                return '—';
            }
            return Number(valor).toLocaleString('pt-BR', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%';
        }

        function fmtFreqDiscHtml(d) {
            const sit = String((d && d.situacao) || '').trim();
            if (sit !== '') {
                const data = String((d && d.data_trancamento) || '').trim();
                if (data !== '') {
                    return escapeHtml(sit) + '<br>' + escapeHtml(data);
                }
                return escapeHtml(sit);
            }
            return escapeHtml(fmtPct(d && d.frequencia));
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
                'Frequência do(a) aluno(a) '
                + nome
                + ', matrícula nº '
                + matricula
                + ', com ingresso em '
                + ingresso
                + ', no curso '
                + curso
                + ', no semestre letivo '
                + periodo
                + ':';

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
                    '<td class="text-end">' + fmtFreqDiscHtml(d) + '</td>';
                tbody.appendChild(tr);
            });

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
