<?php
declare(strict_types=1);

namespace Mapa\Models;

class Aluno
{
    /** @var string */
    public $nome;

    /** @var string */
    public $login;

    /** @var int|string */
    public $matricula;

    public function __construct(string $nome, string $login, $matricula)
    {
        $this->nome = $nome;
        $this->login = $login;
        $this->matricula = $matricula;
    }

    public static function fromApiRecord(array $record): self
    {
        $nome = (string)($record['Nome'] ?? $record['nome_completo'] ?? $record['nome'] ?? '');
        $login = (string)($record['Login'] ?? $record['login'] ?? '');
        $matricula = $record['Matricula'] ?? $record['matricula'] ?? '';

        return new self($nome, $login, $matricula);
    }
}
