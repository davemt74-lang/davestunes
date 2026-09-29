<?php
declare(strict_types=1);

function dt_session_boot(): void
{
    if(session_status()===PHP_SESSION_ACTIVE)return;
    $secure=!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off';
    session_name('davestunes_session');
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/',
        'secure'=>$secure,
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);
    session_start();
}

function dt_app_key(): string
{
    $key=(string)dt_config('app.key','');
    if($key===''){
        if((string)dt_config('app.env','production')==='production'){
            throw new RuntimeException('DAVESTUNES_APP_KEY must be configured in production.');
        }
        return 'davestunes-development-only-key';
    }
    return $key;
}

function dt_e(mixed $value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function dt_csrf_token(): string
{
    if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}

function dt_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'.dt_e(dt_csrf_token()).'">';
}

function dt_verify_csrf(): void
{
    $given=(string)($_POST['csrf_token']??'');
    $known=(string)($_SESSION['csrf_token']??'');
    if($given===''||$known===''||!hash_equals($known,$given)){
        throw new RuntimeException('Your session expired. Please try again.');
    }
}

function dt_redirect(string $path): never
{
    header('Location: '.$path);
    exit;
}

function dt_identity_hash(string $value): string
{
    return hash_hmac('sha256',strtolower(trim($value)),dt_app_key());
}

function dt_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR']??'unknown'),0,64);
}
