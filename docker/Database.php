<?php

// Clean installer defaults for Docker builds. The web installer replaces this file.
namespace App\Config;

class Database extends \CodeIgniter\Database\Config
{
    public string $defaultGroup = 'cms';
    public array $cms = [
        'hostname' => 'localhost', 'username' => '', 'password' => '', 'database' => '',
        'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false, 'DBDebug' => false,
        'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci', 'port' => 3306,
    ];
    public array $account = [
        'hostname' => 'localhost', 'username' => '', 'password' => '', 'database' => '',
        'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false, 'DBDebug' => false,
        'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci', 'port' => 3306,
    ];
}
