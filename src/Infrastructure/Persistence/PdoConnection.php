<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Config\Config;
use PDO;

final class PdoConnection
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $host = $config->get('DB_HOST', 'mysql');
        $port = (int)$config->get('DB_PORT', '3306');
        $db   = $config->get('DB_DATABASE', 'books');
        $user = $config->get('DB_USERNAME', 'books');
        $pass = $config->get('DB_PASSWORD', 'secret');
        $charset = $config->get('DB_CHARSET', 'utf8mb4');
        $collation = $config->get('DB_COLLATION', 'utf8mb4_general_ci');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$collation}",
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
