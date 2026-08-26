-- Schema MAPA (SQLite) — usuarios + learning analytics

CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    nome TEXT NOT NULL,
    email TEXT,
    cpf TEXT UNIQUE,
    senha_hash TEXT,
    auth_type TEXT NOT NULL DEFAULT 'local' CHECK (auth_type IN ('local', 'ldap')),
    perfil TEXT NOT NULL CHECK (perfil IN ('administrador', 'coordenador_curso', 'geral', 'professor')),
    ativo INTEGER NOT NULL DEFAULT 1,
    criado_em TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS coletas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    executada_em TEXT NOT NULL DEFAULT (datetime('now')),
    data_inicial TEXT,
    data_final TEXT,
    data_referencia TEXT,
    total_alunos INTEGER NOT NULL DEFAULT 0,
    total_disciplinas INTEGER NOT NULL DEFAULT 0,
    total_faltas_dia INTEGER NOT NULL DEFAULT 0,
    origem TEXT
);

CREATE TABLE IF NOT EXISTS alunos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    login TEXT NOT NULL,
    matricula TEXT NOT NULL,
    nome TEXT NOT NULL,
    nome_social TEXT,
    email TEXT,
    UNIQUE (login, matricula)
);

CREATE TABLE IF NOT EXISTS cursos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome_curso TEXT NOT NULL UNIQUE,
    curso_nivel TEXT
);

-- Ingresso do aluno no curso (ano/semestre e turma de entrada).
CREATE TABLE IF NOT EXISTS aluno_cursos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    aluno_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    ano_semestre_ingresso TEXT,
    turma_entrada TEXT,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (aluno_id, curso_id)
);

CREATE TABLE IF NOT EXISTS usuario_cursos (
    usuario_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    PRIMARY KEY (usuario_id, curso_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
);

-- E-mail institucional da coordenacao por curso (avisos automaticos ao staff).
CREATE TABLE IF NOT EXISTS curso_coordenacao (
    curso_id INTEGER PRIMARY KEY,
    email_coordenacao TEXT NOT NULL DEFAULT '',
    atualizado_em TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
);

-- Docentes vindos da consulta de matriculados (resposta_matriculas.json).
CREATE TABLE IF NOT EXISTS professores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cpf TEXT NOT NULL UNIQUE,
    nome TEXT NOT NULL,
    email TEXT
);

-- Vinculo N:N entre codigo da disciplina e professor.
CREATE TABLE IF NOT EXISTS disciplina_professores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo_disciplina TEXT NOT NULL,
    disciplina TEXT NOT NULL,
    professor_id INTEGER NOT NULL,
    tipo_docente TEXT,
    FOREIGN KEY (professor_id) REFERENCES professores(id) ON DELETE CASCADE,
    UNIQUE (codigo_disciplina, professor_id)
);

CREATE INDEX IF NOT EXISTS idx_disciplina_professores_codigo
    ON disciplina_professores(codigo_disciplina);

-- Grade da disciplina (metadados; datas efetivas em disciplina_aulas).
CREATE TABLE IF NOT EXISTS disciplina_grade (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo_disciplina TEXT NOT NULL,
    disciplina TEXT NOT NULL,
    curso_id INTEGER NOT NULL,
    dias_semana TEXT NOT NULL DEFAULT '',
    semestre_oferta TEXT,
    turno_turma TEXT,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (codigo_disciplina, curso_id)
);

CREATE INDEX IF NOT EXISTS idx_disciplina_grade_codigo
    ON disciplina_grade(codigo_disciplina);

-- Datas efetivas de aula por disciplina/curso (normalizado a partir de turno_turma).
CREATE TABLE IF NOT EXISTS disciplina_aulas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo_disciplina TEXT NOT NULL,
    curso_id INTEGER NOT NULL,
    data_aula TEXT NOT NULL,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (codigo_disciplina, curso_id, data_aula)
);

CREATE INDEX IF NOT EXISTS idx_disciplina_aulas_data
    ON disciplina_aulas(data_aula);

CREATE INDEX IF NOT EXISTS idx_disciplina_aulas_disciplina
    ON disciplina_aulas(codigo_disciplina, curso_id);

-- Historico de datas de chamada/registro observadas na API (ultima_aula_ministrada).
CREATE TABLE IF NOT EXISTS disciplina_chamadas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo_disciplina TEXT NOT NULL,
    disciplina TEXT NOT NULL,
    curso_id INTEGER NOT NULL,
    data_chamada TEXT NOT NULL,
    coleta_id INTEGER NOT NULL,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    UNIQUE (codigo_disciplina, curso_id, data_chamada)
);

CREATE INDEX IF NOT EXISTS idx_disciplina_chamadas_data
    ON disciplina_chamadas(data_chamada);

-- Snapshot da ultima aula por disciplina na coleta (NULL = sem registro ainda).
CREATE TABLE IF NOT EXISTS disciplina_ultima_aula (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coleta_id INTEGER NOT NULL,
    codigo_disciplina TEXT NOT NULL,
    disciplina TEXT NOT NULL,
    curso_id INTEGER NOT NULL,
    data_ultima_aula TEXT,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (coleta_id, codigo_disciplina, curso_id)
);

CREATE INDEX IF NOT EXISTS idx_disciplina_ultima_aula_data
    ON disciplina_ultima_aula(data_ultima_aula);

CREATE TABLE IF NOT EXISTS frequencia_disciplina (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coleta_id INTEGER NOT NULL,
    aluno_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    codigo_disciplina TEXT NOT NULL,
    disciplina TEXT NOT NULL,
    horarios INTEGER NOT NULL DEFAULT 0,
    ausencias INTEGER NOT NULL DEFAULT 0,
    presencas INTEGER NOT NULL DEFAULT 0,
    percentual_frequencia REAL,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (coleta_id, aluno_id, curso_id, codigo_disciplina)
);

-- Frequencia agregada do aluno no curso (percentual_frequencia_total da API).
CREATE TABLE IF NOT EXISTS frequencia_curso (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coleta_id INTEGER NOT NULL,
    aluno_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    horarios INTEGER NOT NULL DEFAULT 0,
    ausencias INTEGER NOT NULL DEFAULT 0,
    presencas INTEGER NOT NULL DEFAULT 0,
    percentual_frequencia REAL,
    -- Dia a partir do qual aulas contam (matricula atrasada: dia seguinte ao fim; senao NULL).
    data_inicio_aulas TEXT,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (coleta_id, aluno_id, curso_id)
);

CREATE TABLE IF NOT EXISTS faltas_dia (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coleta_id INTEGER NOT NULL,
    aluno_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    codigo_disciplina TEXT NOT NULL,
    data_falta TEXT NOT NULL,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (coleta_id, aluno_id, curso_id, codigo_disciplina, data_falta)
);

CREATE TABLE IF NOT EXISTS alarmes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coleta_id INTEGER NOT NULL,
    aluno_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    codigo_disciplina TEXT NOT NULL DEFAULT '',
    disciplina TEXT,
    tipo TEXT NOT NULL CHECK (tipo IN ('percentual_baixo', 'faltas_4dias', 'faltas_3semanas')),
    severidade TEXT NOT NULL DEFAULT 'alto' CHECK (severidade IN ('alto', 'critico')),
    mensagem TEXT NOT NULL,
    detalhe_json TEXT,
    gerado_em TEXT NOT NULL DEFAULT (datetime('now')),
    visualizado INTEGER NOT NULL DEFAULT 0,
    visualizado_em TEXT,
    visualizado_por INTEGER,
    contato_tipo TEXT CHECK (contato_tipo IS NULL OR contato_tipo IN ('email', 'email_automatico', 'whatsapp', 'telefone', 'presencial', 'assistencia')),
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    FOREIGN KEY (visualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    UNIQUE (coleta_id, aluno_id, curso_id, codigo_disciplina, tipo)
);

CREATE INDEX IF NOT EXISTS idx_faltas_data ON faltas_dia(data_falta);
CREATE INDEX IF NOT EXISTS idx_freq_percentual ON frequencia_disciplina(percentual_frequencia);
CREATE INDEX IF NOT EXISTS idx_alarmes_visualizado ON alarmes(visualizado);

-- Configuracoes do sistema (ex.: LDAP). Valores sensiveis ficam no banco, nao no .env.
CREATE TABLE IF NOT EXISTS configuracoes (
    chave TEXT PRIMARY KEY,
    valor TEXT NOT NULL DEFAULT '',
    descricao TEXT,
    atualizado_em TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Historico de e-mails automaticos por chamada em atraso.
CREATE TABLE IF NOT EXISTS chamada_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo_disciplina TEXT NOT NULL,
    disciplina TEXT NOT NULL,
    curso_id INTEGER NOT NULL,
    data_esperada TEXT NOT NULL,
    destinatarios TEXT NOT NULL,
    enviado_em TEXT NOT NULL DEFAULT (datetime('now')),
    coleta_id INTEGER,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE SET NULL,
    UNIQUE (codigo_disciplina, curso_id, data_esperada)
);

CREATE INDEX IF NOT EXISTS idx_chamada_emails_disciplina
    ON chamada_emails(codigo_disciplina, curso_id);

-- Historico de e-mails automaticos de alarmes criticos enviados aos alunos.
CREATE TABLE IF NOT EXISTS alarme_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coleta_id INTEGER NOT NULL,
    aluno_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    destinatario TEXT NOT NULL,
    alarme_ids TEXT NOT NULL,
    enviado_em TEXT NOT NULL DEFAULT (datetime('now')),
    staff_avisado_em TEXT,
    staff_piloto_avisado_em TEXT,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (coleta_id, aluno_id, curso_id)
);

CREATE INDEX IF NOT EXISTS idx_alarme_emails_coleta
    ON alarme_emails(coleta_id);

CREATE INDEX IF NOT EXISTS idx_alarme_emails_aluno_enviado
    ON alarme_emails(aluno_id, enviado_em);

-- Historico de e-mails de aviso ao staff (max. 1 por destinatario a cada 7 dias).
CREATE TABLE IF NOT EXISTS staff_alarme_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    destinatario TEXT NOT NULL,
    papel TEXT NOT NULL DEFAULT '',
    total_alunos INTEGER NOT NULL DEFAULT 0,
    enviado_em TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_staff_alarme_emails_destinatario_enviado
    ON staff_alarme_emails(destinatario, enviado_em);

-- Alunos com status TRANCADO / TRANC. AUTOMATICO na segunda consulta (fora de alarmes).
CREATE TABLE IF NOT EXISTS alunos_trancados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coleta_id INTEGER NOT NULL,
    aluno_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    login TEXT NOT NULL,
    matricula TEXT NOT NULL DEFAULT '',
    nome TEXT NOT NULL DEFAULT '',
    nome_social TEXT,
    email TEXT,
    nome_curso TEXT NOT NULL DEFAULT '',
    status_discente TEXT NOT NULL,
    ano_semestre_ingresso TEXT,
    turma_entrada TEXT,
    FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    UNIQUE (coleta_id, aluno_id, curso_id)
);

CREATE INDEX IF NOT EXISTS idx_alunos_trancados_coleta
    ON alunos_trancados(coleta_id);

CREATE INDEX IF NOT EXISTS idx_alunos_trancados_curso
    ON alunos_trancados(curso_id);

-- Candidatos a perda de vaga (reprovacao em todas as disciplinas nos 2 semestres anteriores).
CREATE TABLE IF NOT EXISTS perda_vaga_execucoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periodo_atual TEXT NOT NULL,
    semestre_a TEXT NOT NULL,
    semestre_b TEXT NOT NULL,
    total_candidatos INTEGER NOT NULL DEFAULT 0,
    executado_em TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS perda_vaga_candidatos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    execucao_id INTEGER NOT NULL,
    aluno_id INTEGER,
    curso_id INTEGER,
    login TEXT NOT NULL,
    matricula TEXT NOT NULL DEFAULT '',
    nome TEXT NOT NULL DEFAULT '',
    nome_social TEXT,
    email TEXT,
    nome_curso TEXT NOT NULL DEFAULT '',
    matriculado_periodo_atual INTEGER NOT NULL DEFAULT 0,
    status_periodo_atual TEXT,
    FOREIGN KEY (execucao_id) REFERENCES perda_vaga_execucoes(id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_perda_vaga_candidatos_execucao
    ON perda_vaga_candidatos(execucao_id);

CREATE INDEX IF NOT EXISTS idx_perda_vaga_candidatos_curso
    ON perda_vaga_candidatos(curso_id);

CREATE TABLE IF NOT EXISTS perda_vaga_reprovacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    candidato_id INTEGER NOT NULL,
    semestre TEXT NOT NULL,
    disciplina TEXT NOT NULL DEFAULT '',
    cod_disciplina TEXT NOT NULL DEFAULT '',
    id_disciplina INTEGER,
    causa TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (candidato_id) REFERENCES perda_vaga_candidatos(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_perda_vaga_reprovacoes_candidato
    ON perda_vaga_reprovacoes(candidato_id);

-- Passe livre: percentual de frequencia do semestre anterior (carga manual).
-- Um aluno em dois cursos aparece em duas linhas.
CREATE TABLE IF NOT EXISTS passe_livre_aluno_curso (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periodo TEXT NOT NULL,
    data_inicial TEXT NOT NULL DEFAULT '',
    data_final TEXT NOT NULL DEFAULT '',
    gerado_em TEXT NOT NULL DEFAULT (datetime('now')),
    aluno_id INTEGER,
    curso_id INTEGER,
    login TEXT NOT NULL,
    matricula TEXT NOT NULL DEFAULT '',
    nome TEXT NOT NULL DEFAULT '',
    nome_social TEXT,
    email TEXT,
    nome_curso TEXT NOT NULL DEFAULT '',
    frequencia REAL,
    FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_passe_livre_aluno_curso_nome
    ON passe_livre_aluno_curso(nome);

CREATE INDEX IF NOT EXISTS idx_passe_livre_aluno_curso_periodo
    ON passe_livre_aluno_curso(periodo);

CREATE TABLE IF NOT EXISTS passe_livre_disciplina (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    aluno_curso_id INTEGER NOT NULL,
    codigo_disciplina TEXT NOT NULL DEFAULT '',
    disciplina TEXT NOT NULL DEFAULT '',
    frequencia REAL,
    FOREIGN KEY (aluno_curso_id) REFERENCES passe_livre_aluno_curso(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_passe_livre_disciplina_aluno_curso
    ON passe_livre_disciplina(aluno_curso_id);

