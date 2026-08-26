<?php
declare(strict_types=1);

namespace Mapa\Core;

class Auth
{
    public const PERFIL_ADMIN = 'administrador';
    public const PERFIL_COORDENADOR = 'coordenador_curso';
    public const PERFIL_GERAL = 'geral';
    public const PERFIL_PROFESSOR = 'professor';

    public const PERFIS = [
        self::PERFIL_ADMIN,
        self::PERFIL_COORDENADOR,
        self::PERFIL_GERAL,
        self::PERFIL_PROFESSOR,
    ];

    public const ROTULOS_PERFIL = [
        self::PERFIL_ADMIN => 'Administrador',
        self::PERFIL_COORDENADOR => 'Coordenador de curso',
        self::PERFIL_GERAL => 'Geral',
        self::PERFIL_PROFESSOR => 'Professor',
    ];

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        $user = Session::get('usuario');

        return is_array($user) ? $user : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * @param array<string, mixed> $user
     * @param list<int> $cursoIds
     * @param list<string> $disciplinaCodigos
     */
    public static function login(array $user, array $cursoIds = [], array $disciplinaCodigos = []): void
    {
        Session::set('usuario', [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'nome' => (string)$user['nome'],
            'email' => (string)($user['email'] ?? ''),
            'cpf' => (string)($user['cpf'] ?? ''),
            'perfil' => (string)$user['perfil'],
            'auth_type' => (string)($user['auth_type'] ?? 'local'),
            'curso_ids' => array_values(array_map('intval', $cursoIds)),
            'disciplina_codigos' => array_values(array_map('strval', $disciplinaCodigos)),
        ]);
    }

    public static function logout(): void
    {
        Session::remove('usuario');
    }

    public static function isAdmin(): bool
    {
        return self::hasPerfil(self::PERFIL_ADMIN);
    }

    public static function isCoordenador(): bool
    {
        return self::hasPerfil(self::PERFIL_COORDENADOR);
    }

    public static function isGeral(): bool
    {
        return self::hasPerfil(self::PERFIL_GERAL);
    }

    public static function isProfessor(): bool
    {
        return self::hasPerfil(self::PERFIL_PROFESSOR);
    }

    public static function canManageUsers(): bool
    {
        return self::isAdmin();
    }

    /** Admin, perfil geral ou coordenador (este último só vê o próprio curso). */
    public static function canVerChamadas(): bool
    {
        return self::isAdmin() || self::isGeral() || self::isCoordenador();
    }

    /** Administrador ou perfil geral — gerar passe livre pela tela. */
    public static function canGerarPasseLivre(): bool
    {
        return self::isAdmin() || self::isGeral();
    }

    /** @return list<int> */
    public static function cursoIds(): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }

        $ids = $user['curso_ids'] ?? [];
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    /** @return list<string> */
    public static function disciplinaCodigos(): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }

        $codigos = $user['disciplina_codigos'] ?? [];
        if (!is_array($codigos)) {
            return [];
        }

        $resultado = [];
        foreach ($codigos as $codigo) {
            $codigo = trim((string)$codigo);
            if ($codigo !== '') {
                $resultado[] = $codigo;
            }
        }

        return array_values(array_unique($resultado));
    }

    public static function usesLocalPassword(): bool
    {
        $user = self::user();

        return $user !== null && ($user['auth_type'] ?? 'local') === 'local';
    }

    public static function hasPerfil(string ...$perfis): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        return in_array($user['perfil'], $perfis, true);
    }
}
