<?php
declare(strict_types=1);
/** Conexão singleton por requisição. Configuração por ambiente, sem senha no repositório. */
final class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $name = getenv('DB_NAME') ?: 'hydroponex';
            $user = getenv('DB_USER') ?: 'root'; // Conveniência SOMENTE XAMPP local.
            $pass = getenv('DB_PASSWORD') ?: '';
            if ((getenv('APP_ENV') ?: 'local') === 'production' && ($user === 'root' || $pass === '')) {
                throw new RuntimeException('Credenciais inseguras para produção.');
            }
            // DSN configurado por variáveis; NUNCA interpolar entradas do usuário em SQL.
            self::$instance = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        return self::$instance;
    }
}
