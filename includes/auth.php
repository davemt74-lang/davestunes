<?php
declare(strict_types=1);

function dt_auth_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function dt_auth_safe_user_select(): string
{
    return "u.id,u.email,u.account_status,u.email_verified_at,u.last_login_at,u.created_at,u.updated_at,
        p.display_name,p.handle,p.bio,p.avatar_path,p.timezone";
}

function dt_auth_user_by_id(PDO $pdo,int $userId): ?array
{
    if($userId<1)return null;
    $stmt=$pdo->prepare("SELECT ".dt_auth_safe_user_select()."
        FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id
        WHERE u.id=? LIMIT 1");
    $stmt->execute([$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_auth_user_by_email(PDO $pdo,string $email): ?array
{
    $stmt=$pdo->prepare("SELECT ".dt_auth_safe_user_select()."
        FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id
        WHERE u.email=? LIMIT 1");
    $stmt->execute([dt_auth_normalize_email($email)]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_auth_credentials_by_email(PDO $pdo,string $email): ?array
{
    $stmt=$pdo->prepare('SELECT id,email,password_hash,account_status FROM users WHERE email=? LIMIT 1');
    $stmt->execute([dt_auth_normalize_email($email)]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_auth_dummy_hash(): string
{
    static $hash=null;
    if($hash===null)$hash=password_hash('davestunes-invalid-login-padding',PASSWORD_DEFAULT);
    return (string)$hash;
}

function dt_auth_register(PDO $pdo,string $email,string $password,string $displayName): array
{
    $email=dt_auth_normalize_email($email);
    $displayName=trim($displayName);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
    if(strlen($password)<12)throw new RuntimeException('Use a password with at least 12 characters.');
    if($displayName===''||mb_strlen($displayName)>120)throw new RuntimeException('Display name must be between 1 and 120 characters.');

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $hash=password_hash($password,PASSWORD_DEFAULT);
        if(!is_string($hash)||$hash==='')throw new RuntimeException('Password could not be secured.');
        $stmt=$pdo->prepare("INSERT INTO users (email,password_hash,account_status) VALUES (?,?,'active')");
        $stmt->execute([$email,$hash]);
        $userId=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_profiles (user_id,display_name) VALUES (?,?)')->execute([$userId,$displayName]);
        if($ownsTransaction)$pdo->commit();
        return dt_auth_user_by_id($pdo,$userId)??throw new RuntimeException('Account could not be loaded.');
    }catch(PDOException $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        if((string)$e->getCode()==='23000')throw new RuntimeException('An account already exists for that email address.');
        throw $e;
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_auth_record_attempt(PDO $pdo,string $email,bool $success): void
{
    $pdo->exec("DELETE FROM auth_login_attempts WHERE created_at<DATE_SUB(NOW(),INTERVAL 30 DAY)");
    $stmt=$pdo->prepare('INSERT INTO auth_login_attempts (email_hash,ip_hash,was_successful) VALUES (?,?,?)');
    $stmt->execute([dt_identity_hash($email),dt_identity_hash(dt_client_ip()),$success?1:0]);
}

function dt_auth_rate_limited(PDO $pdo,string $email): bool
{
    $since=(new DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s');
    $emailHash=dt_identity_hash($email);
    $ipHash=dt_identity_hash(dt_client_ip());
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM auth_login_attempts
        WHERE created_at>=? AND was_successful=0 AND (email_hash=? OR ip_hash=?)");
    $stmt->execute([$since,$emailHash,$ipHash]);
    return (int)$stmt->fetchColumn()>=8;
}

function dt_auth_attempt_login(PDO $pdo,string $email,string $password): ?array
{
    $email=dt_auth_normalize_email($email);
    if(dt_auth_rate_limited($pdo,$email))throw new RuntimeException('Too many sign-in attempts. Try again later.');

    $credentials=dt_auth_credentials_by_email($pdo,$email);
    $hash=(string)($credentials['password_hash']??dt_auth_dummy_hash());
    $passwordValid=password_verify($password,$hash);
    $valid=$credentials
        && (string)($credentials['account_status']??'')==='active'
        && $passwordValid;

    dt_auth_record_attempt($pdo,$email,(bool)$valid);
    if(!$valid)return null;

    $userId=(int)$credentials['id'];
    $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$userId]);
    return dt_auth_user_by_id($pdo,$userId);
}

function dt_auth_begin_session(array $user): void
{
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('Cannot start a session without a user.');
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id']=$userId;
}

function dt_current_user(?PDO $pdo=null): ?array
{
    $userId=(int)($_SESSION['user_id']??0);
    if($userId<1)return null;
    $pdo??=dt_db();
    $user=dt_auth_user_by_id($pdo,$userId);
    if(!$user||(string)($user['account_status']??'')!=='active'){
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function dt_require_user(?PDO $pdo=null): array
{
    $user=dt_current_user($pdo);
    if(!$user)dt_redirect('/login.php');
    return $user;
}

function dt_auth_logout(): void
{
    $_SESSION=[];
    if(ini_get('session.use_cookies')){
        $params=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$params['path'],$params['domain']??'',(bool)$params['secure'],(bool)$params['httponly']);
    }
    session_destroy();
}
