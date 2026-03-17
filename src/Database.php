<?php

declare(strict_types=1);

namespace TechRecruit;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function connect(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        /** @var array{host:string,database:string,username:string,password:string,charset:string} $config */
        $config = require dirname(__DIR__) . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$connection = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new DatabaseConnectionException(
                'Failed to connect to the database.',
                0,
                $exception
            );
        }

        return self::$connection;
    }
}
