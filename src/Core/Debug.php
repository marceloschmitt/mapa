<?php
declare(strict_types=1);

namespace Mapa\Core;

class Debug
{
    /** @var string[] */
    private $messages = [];

    public function log(string $message): void
    {
        $this->messages[] = date('H:i:s') . ' - ' . $message;
    }

    /** @return string[] */
    public function all(): array
    {
        return $this->messages;
    }

    public function asText(): string
    {
        return implode("\n", $this->messages);
    }
}
