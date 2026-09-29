<?php
declare(strict_types=1);

$root=__DIR__;
$configPath=$root.'/config.php';
$error=null;
$success=false;

function dt_install_e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function dt_install_write_config(string $path,array $input): void {
    $appKey=bin2hex(random_bytes(32));
    $name="Dave's Tunes";
    $adminEmail=strtolower(trim((string)$input['email']));
    $dsn='mysql:host='.$input['db_host'].';port='.(int)$input['db_port'].';dbname='.$input['db_name'].';charset=utf8mb4';
    $php="<?php\ndeclare(strict_types=1);\n\nreturn ".var_export([
        'app'=>['name'=>$name,'url'=>'','env'=>'production','key'=>$appKey,'installed'=>true],
        'admin'=>['emails'=>$adminEmail],
        'db'=>['dsn'=>$dsn,'user'=>$input['db_user'],'pass'=>$input['db_pass']],
        'storage'=>['media_root'=>dirname($path).'-media','max_upload_bytes'=>536870912],
    ],true).";\n";
    if(file_put_contents($path,$php,LOCK_EX)===false)throw new RuntimeException('Could not write config.php. Make the application folder writable and try again.');
    @chmod($path,0640);
}

function dt_install_connect(array $input): PDO {
    $dsn='mysql:host='.$input['db_host'].';port='.(int)$input['db_port'].';dbname='.$input['db_name'].';charset=utf8mb4';
    return new PDO($dsn,$input['db_user'],$input['db_pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}

function dt_install_has_user(string $configPath): bool {
    if(!is_file($configPath))return false;
    try{
        $cfg=require $configPath;
        if(!is_array($cfg))return false;
        $pdo=new PDO((string)($cfg['db']['dsn']??''),(string)($cfg['db']['user']??''),(string)($cfg['db']['pass']??''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'");
        if(!(bool)$stmt->fetchColumn())return false;
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0;
    }catch(Throwable){ return false; }
}

$installed=dt_install_has_user($configPath);
if($installed){
    http_response_code(200);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dave's Tunes Installed</title><link rel="stylesheet" href="/assets/app.css"></head><body><main class="shell"><section class="card auth-card"><div class="eyebrow">Installation complete</div><h2>Dave's Tunes is ready.</h2><p class="muted">The installer is locked because the platform already has a first user.</p><p><a class="button" href="/login.php">Sign in</a></p></section></main></body></html><?php
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        $input=[
            'db_host'=>trim((string)($_POST['db_host']??'127.0.0.1')),
            'db_port'=>(int)($_POST['db_port']??3306),
            'db_name'=>trim((string)($_POST['db_name']??'')),
            'db_user'=>trim((string)($_POST['db_user']??'')),
            'db_pass'=>(string)($_POST['db_pass']??''),
            'display_name'=>trim((string)($_POST['display_name']??'')),
            'email'=>strtolower(trim((string)($_POST['email']??''))),
            'password'=>(string)($_POST['password']??''),
        ];
        if($input['db_host']===''||$input['db_name']===''||$input['db_user']==='')throw new RuntimeException('Database host, name, and username are required.');
        if($input['db_port']<1||$input['db_port']>65535)throw new RuntimeException('Database port is invalid.');
        if(!filter_var($input['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid first-user email address.');
        if($input['display_name']===''||mb_strlen($input['display_name'])>120)throw new RuntimeException('Display name must be between 1 and 120 characters.');
        if(strlen($input['password'])<12)throw new RuntimeException('Use a password with at least 12 characters.');

        $pdo=dt_install_connect($input);
        $pdo->query('SELECT 1')->fetchColumn();

        $hadConfig=is_file($configPath);
        if(!$hadConfig)dt_install_write_config($configPath,$input);

        require_once $root.'/includes/db.php';
        require_once $root.'/includes/security.php';
        require_once $root.'/includes/foundation-schema-v100.php';
        require_once $root.'/includes/music-catalog-schema-v110.php';
        require_once $root.'/includes/library-schema-v120.php';
        require_once $root.'/includes/playback-schema-v130.php';
        require_once $root.'/includes/desktop-object-schema-v220.php';
        require_once $root.'/includes/featured-schema-v270.php';
        require_once $root.'/includes/experience-schema-v280.php';
        require_once $root.'/includes/auth.php';
        require_once $root.'/includes/artists.php';

        dt_foundation_ensure_schema($pdo);
        dt_catalog_ensure_schema($pdo);
        dt_library_ensure_schema($pdo);
        dt_playback_ensure_schema($pdo);
        dt_desktop_object_ensure_schema($pdo);
        dt_featured_ensure_schema($pdo);
        dt_experience_ensure_schema($pdo);

        if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0)throw new RuntimeException('A user already exists. The installer is locked.');
        $user=dt_auth_register($pdo,$input['email'],$input['password'],$input['display_name']);

        if($hadConfig){
            $cfg=require $configPath;
            if(is_array($cfg)){
                $cfg['app']['installed']=true;
                if(empty($cfg['app']['key']))$cfg['app']['key']=bin2hex(random_bytes(32));
                $cfg['admin']['emails']=$input['email'];
                $php="<?php\ndeclare(strict_types=1);\n\nreturn ".var_export($cfg,true).";\n";
                if(file_put_contents($configPath,$php,LOCK_EX)===false)throw new RuntimeException('Could not finalize config.php.');
                @chmod($configPath,0640);
            }
        }

        session_name('davestunes_session');
        session_start();
        session_regenerate_id(true);
        $_SESSION['user_id']=(int)$user['id'];
        $success=true;
    }catch(Throwable $e){
        $error=$e->getMessage();
        if(isset($hadConfig)&&!$hadConfig&&is_file($configPath))@unlink($configPath);
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Install Dave's Tunes</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell">
  <section class="card auth-card install-card">
    <div class="eyebrow">First-run setup</div>
    <h2><?= $success ? "Dave's Tunes is ready." : "Install Dave's Tunes" ?></h2>
    <?php if($success): ?>
      <p class="muted">Database tables are installed, the local application secret was generated automatically, and your first administrator account is ready.</p>
      <p><a class="button" href="/dashboard.php">Open Dashboard</a></p>
    <?php else: ?>
      <?php if($error): ?><div class="notice error" role="alert"><?=dt_install_e($error)?></div><?php endif; ?>
      <p class="muted">No API keys are required. Enter your MySQL details and create the first account.</p>
      <form method="post" class="stack" autocomplete="off">
        <h3>Database</h3>
        <label>Host<input name="db_host" value="<?=dt_install_e($_POST['db_host']??'127.0.0.1')?>" required></label>
        <label>Port<input name="db_port" type="number" min="1" max="65535" value="<?=dt_install_e($_POST['db_port']??'3306')?>" required></label>
        <label>Database name<input name="db_name" value="<?=dt_install_e($_POST['db_name']??'davestunes')?>" required></label>
        <label>Database username<input name="db_user" value="<?=dt_install_e($_POST['db_user']??'')?>" required></label>
        <label>Database password<input name="db_pass" type="password" value=""></label>
        <h3>First administrator</h3>
        <label>Display name<input name="display_name" maxlength="120" value="<?=dt_install_e($_POST['display_name']??'')?>" required autocomplete="name"></label>
        <label>Email<input name="email" type="email" maxlength="254" value="<?=dt_install_e($_POST['email']??'')?>" required autocomplete="email"></label>
        <label>Password<input name="password" type="password" minlength="12" required autocomplete="new-password"><span class="muted">At least 12 characters.</span></label>
        <button type="submit">Install & Create First User</button>
      </form>
    <?php endif; ?>
  </section>
</main>
</body></html>
