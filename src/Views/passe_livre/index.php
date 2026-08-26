<?php

$linhas = $linhas ?? [];
$disciplinasPorLinha = $disciplinasPorLinha ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$filtroNome = (string)($filtroNome ?? '');
$meta = $meta ?? null;
$mostrarBadgeCurso = $semSeletorCurso;
$podeGerarPasseLivre = !empty($podeGerarPasseLivre);

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
        <h1 class="h4 mb-1">Passe livre</h1>
        <?php if ($mostrarBadgeCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($cursoExibido ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Percentual de frequência dos alunos no semestre anterior
            <?php if (is_array($meta)): ?>
                (<?= htmlspecialchars((string)$meta['periodo'], ENT_QUOTES, 'UTF-8') ?>;
                <?= htmlspecialchars((string)$meta['data_inicial'], ENT_QUOTES, 'UTF-8') ?>
                a <?= htmlspecialchars((string)$meta['data_final'], ENT_QUOTES, 'UTF-8') ?>).
                Clique na linha para ver as disciplinas.
            <?php else: ?>
                .
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
                    Essa função só precisa ser executada no início do semestre, uma vez.
                </p>
            </div>
        <?php endif; ?>
        <?php if (is_array($meta)): ?>
            <form method="get" action="<?= htmlspecialchars(url('/passe-livre'), ENT_QUOTES, 'UTF-8') ?>"
                  class="d-flex flex-wrap align-items-end gap-2">
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
                        <select class="form-select" id="curso" name="curso" style="min-width: 260px;">
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
                <button type="submit" class="btn btn-outline-primary">Filtrar</button>
                <?php if ($filtroNome !== '' || (!$semSeletorCurso && $cursoSelecionado !== 'todos')): ?>
                    <a href="<?= htmlspecialchars(url('/passe-livre'), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-outline-secondary">Limpar</a>
                <?php endif; ?>
            </form>
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
                            <th class="text-end">Frequência</th>
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
                                'nome' => $nome,
                                'matricula' => (string)($linha['matricula'] ?? ''),
                                'curso' => (string)($linha['nome_curso'] ?? ''),
                                'periodo' => (string)($linha['periodo'] ?? ($meta['periodo'] ?? '')),
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
        </div>
    </div>

    <div class="modal fade" id="modalPasseLivre" tabindex="-1"
         aria-labelledby="modalPasseLivreTitulo" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5 mb-1" id="modalPasseLivreTitulo">Frequência</h2>
                        <div class="small text-secondary" id="modalPasseLivreMeta"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 bg-light rounded border">
                        <div class="fw-semibold">Frequência no curso:
                            <code id="modalPasseLivreGeral"></code>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Semestre</th>
                                    <th>Código</th>
                                    <th>Disciplina</th>
                                    <th class="text-end">Frequência</th>
                                </tr>
                            </thead>
                            <tbody id="modalPasseLivreDisciplinas"></tbody>
                        </table>
                    </div>
                    <p class="text-secondary mb-0 d-none mt-3" id="modalPasseLivreVazio">
                        Nenhuma disciplina com frequência neste semestre.
                    </p>
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
    </style>
    <script>
    window.addEventListener('load', function () {
        const modalEl = document.getElementById('modalPasseLivre');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        const titulo = document.getElementById('modalPasseLivreTitulo');
        const meta = document.getElementById('modalPasseLivreMeta');
        const geral = document.getElementById('modalPasseLivreGeral');
        const tbody = document.getElementById('modalPasseLivreDisciplinas');
        const vazio = document.getElementById('modalPasseLivreVazio');

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

        function abrir(dados) {
            titulo.textContent = dados.nome || 'Frequência';
            meta.textContent = [
                dados.matricula ? ('Matrícula ' + dados.matricula) : '',
                dados.curso || '',
                dados.periodo ? ('Semestre ' + dados.periodo) : ''
            ].filter(Boolean).join(' · ');
            geral.textContent = fmtPct(dados.frequencia);

            tbody.innerHTML = '';
            const discs = Array.isArray(dados.disciplinas) ? dados.disciplinas : [];
            vazio.classList.toggle('d-none', discs.length > 0);
            const periodo = dados.periodo || '';
            discs.forEach(function (d) {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + (periodo
                        ? escapeHtml(periodo)
                        : '<span class="text-secondary">—</span>') + '</td>' +
                    '<td>' + (d.codigo
                        ? '<code>' + escapeHtml(d.codigo) + '</code>'
                        : '<span class="text-secondary">—</span>') + '</td>' +
                    '<td>' + escapeHtml(d.nome || '') + '</td>' +
                    '<td class="text-end"><code>' + escapeHtml(fmtPct(d.frequencia)) + '</code></td>';
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
