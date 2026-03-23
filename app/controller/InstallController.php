<?php

namespace app\controller;

use support\Request;
use support\Response;
use PDO;
use Redis;
use Exception;

class InstallController
{
    /**
     * 显示安装向导
     */
    public function index(Request $request): Response
    {
        // 检查是否已安装
        if (file_exists(base_path() . '/install.lock')) {
            return response('', 404);
        }
        
        return view('install/index');
    }

    /**
     * 环境检测
     */
    public function checkEnv(Request $request): Response
    {
        if (file_exists(base_path() . '/install.lock')) {
            return response('', 404);
        }
        
        $checks = [
            'php_version' => [
                'name' => 'PHP 版本 >= 8.0',
                'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'success' : 'error',
                'message' => '当前版本: ' . PHP_VERSION
            ]
        ];

        // 检测必需扩展
        $extensions = ['PDO', 'pdo_mysql', 'redis', 'mbstring', 'json', 'openssl', 'curl'];
        foreach ($extensions as $ext) {
            $checks['ext_' . $ext] = [
                'name' => $ext . ' 扩展',
                'status' => extension_loaded($ext) ? 'success' : 'error',
                'message' => extension_loaded($ext) ? '已安装' : '未安装'
            ];
        }

        // 检测目录权限
        $dirs = ['runtime', 'config'];
        foreach ($dirs as $dir) {
            $path = base_path() . '/' . $dir;
            $writable = is_writable($path);
            $checks['dir_' . $dir] = [
                'name' => $dir . '/ 目录可写',
                'status' => $writable ? 'success' : 'error',
                'message' => $writable ? '可写' : '不可写'
            ];
        }

        return json(['code' => 0, 'data' => $checks]);
    }

    /**
     * 测试数据库连接
     */
    public function testDb(Request $request): Response
    {
        if (file_exists(base_path() . '/install.lock')) {
            return response('', 404);
        }
        
        $host = $request->post('host', '127.0.0.1');
        $port = $request->post('port', 3306);
        $database = $request->post('database');
        $username = $request->post('username');
        $password = $request->post('password');

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            return json(['code' => 0, 'message' => '数据库连接成功']);
        } catch (Exception $e) {
            return json(['code' => 1, 'message' => '连接失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 测试 Redis 连接
     */
    public function testRedis(Request $request): Response
    {
        if (file_exists(base_path() . '/install.lock')) {
            return response('', 404);
        }
        
        $host = $request->post('host', '127.0.0.1');
        $port = $request->post('port', 6379);
        $password = $request->post('password', '');
        $database = $request->post('database', 0);

        try {
            $redis = new Redis();
            $redis->connect($host, $port, 2);
            
            if ($password) {
                $redis->auth($password);
            }
            
            $redis->select($database);
            $redis->ping();
            
            return json(['code' => 0, 'message' => 'Redis 连接成功']);
        } catch (Exception $e) {
            return json(['code' => 1, 'message' => '连接失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 执行安装
     */
    public function execute(Request $request): Response
    {
        if (file_exists(base_path() . '/install.lock')) {
            return response('', 404);
        }
        
        try {
            $data = $request->post();
            
            // 1. 连接数据库
            $dsn = "mysql:host={$data['db_host']};port={$data['db_port']};dbname={$data['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $data['db_user'], $data['db_pass']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 2. 读取 SQL 文件
            $sql = file_get_contents(base_path() . '/database/install.sql');
            $prefix = $data['db_prefix'] ?? '';
            if ($prefix) {
                $sql = str_replace('CREATE TABLE `', 'CREATE TABLE `' . $prefix, $sql);
                $sql = str_replace('INSERT INTO `', 'INSERT INTO `' . $prefix, $sql);
            }
            
            // 统计表数量
            preg_match_all('/CREATE TABLE/i', $sql, $matches);
            $tableCount = count($matches[0]);
            
            // 3. 执行 SQL
            $pdo->exec($sql);
            
            // 4. 创建管理员账户
            $adminPass = password_hash($data['admin_pass'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}users` (username, email, password, display_name, role, status, aff_code, created_at) VALUES (?, ?, ?, ?, 100, 1, 'admin001', NOW())");
            $stmt->execute([$data['admin_user'], $data['admin_email'], $adminPass, $data['admin_user']]);
            
            // 5. 更新站点设置
            $stmt = $pdo->prepare("UPDATE `{$prefix}options` SET `value` = ? WHERE `key` = 'site_name'");
            $stmt->execute([$data['site_name']]);
            
            // 6. 写入配置文件
            $this->writeDbConfig($data);
            $this->writeRedisConfig($data);
            
            // 6. 创建安装锁
            file_put_contents(base_path() . '/install.lock', date('Y-m-d H:i:s'));
            
            // 7. 删除 database 目录
            $this->removeDirectory(base_path() . '/database');
            
            return json(['code' => 0, 'message' => '安装成功', 'data' => ['table_count' => $tableCount]]);
        } catch (Exception $e) {
            return json(['code' => 1, 'message' => '安装失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 写入数据库配置
     */
    private function writeDbConfig(array $data): void
    {
        $config = <<<PHP
<?php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '{$data['db_host']}',
            'port' => {$data['db_port']},
            'database' => '{$data['db_name']}',
            'username' => '{$data['db_user']}',
            'password' => '{$data['db_pass']}',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '{$data['db_prefix']}',
            'strict' => true,
            'engine' => null,
        ],
    ],
];
PHP;
        file_put_contents(config_path() . '/database.php', $config);
    }

    /**
     * 写入 Redis 配置
     */
    private function writeRedisConfig(array $data): void
    {
        $password = $data['redis_pass'] ? "'{$data['redis_pass']}'" : "''";
        $config = <<<PHP
<?php
return [
    'default' => [
        'host' => '{$data['redis_host']}',
        'port' => {$data['redis_port']},
        'password' => {$password},
        'database' => {$data['redis_db']},
        'timeout' => 2,
    ],
];
PHP;
        file_put_contents(config_path() . '/redis.php', $config);
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
