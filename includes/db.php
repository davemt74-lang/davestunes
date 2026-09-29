<?php
declare(strict_types=1);

function dt_config(?string $key=null,mixed $default=null): mixed
{
    static $config=null;
    if($config===null){
        $root=dirname(__DIR__);
        $file=is_file($root.'/config.php')?$root.'/config.php':$root.'/config.example.php';
        $loaded=require $file;
        if(!is_array($loaded))throw new RuntimeException('Dave\'s Tunes configuration is invalid.');
        $config=$loaded;
    }
    if($key===null)return $config;
    $value=$config;
    foreach(explode('.',$key) as $part){
        if(!is_array($value)||!array_key_exists($part,$value))return $default;
        $value=$value[$part];
    }
    return $value;
}

function dt_db(): PDO
{
    static $pdo=null;
    if($pdo instanceof PDO)return $pdo;
    $dsn=(string)dt_config('db.dsn','');
    if($dsn==='')throw new RuntimeException('Database DSN is not configured.');
    $pdo=new PDO($dsn,(string)dt_config('db.user',''),(string)dt_config('db.pass',''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    return $pdo;
}

function dt_table_exists(PDO $pdo,string $table): bool
{
    $stmt=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
