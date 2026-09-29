<?php
declare(strict_types=1);

function read_repo(string $path): string {
    $value=file_get_contents(dirname(__DIR__).'/'.$path);
    if($value===false)throw new RuntimeException('Could not read '.$path);
    return $value;
}
function must_contain(string $haystack,string $needle,string $message): void {
    if(!str_contains($haystack,$needle))throw new RuntimeException($message);
}
function must_not_contain(string $haystack,string $needle,string $message): void {
    if(str_contains($haystack,$needle))throw new RuntimeException($message);
}

$schema=read_repo('includes/foundation-schema-v100.php');
$auth=read_repo('includes/auth.php');
$artists=read_repo('includes/artists.php');
$security=read_repo('includes/security.php');
$bootstrap=read_repo('includes/bootstrap.php');
$view=read_repo('includes/view.php');
$logout=read_repo('logout.php');
$workflow=read_repo('.github/workflows/foundation-v1.yml');

foreach(['users','user_profiles','auth_login_attempts','artists','artist_memberships','artist_authority_events'] as $table){
    must_contain($schema,'CREATE TABLE IF NOT EXISTS '.$table,'Missing foundation table '.$table);
}
must_contain($schema,'FOREIGN KEY (owner_user_id) REFERENCES users(id)','Artist owner must be a canonical user.');
must_contain($schema,'PRIMARY KEY (artist_id,user_id)','Artist memberships must not duplicate users.');
must_contain($schema,'UNIQUE KEY uq_artists_slug (slug)','Artist public identity must have a stable unique slug.');

must_contain($auth,'password_hash($password,PASSWORD_DEFAULT)','Passwords must use PHP password hashing.');
must_contain($auth,'password_verify(','Authentication must verify hashes.');
must_contain($auth,'dt_auth_dummy_hash','Unknown-account login must still perform password verification.');
must_contain($auth,'dt_auth_safe_user_select','Normal user projections must exclude credential hashes.');
must_contain($auth,'session_regenerate_id(true)','Login must rotate the session id.');
must_contain($auth,"unset(\$_SESSION['csrf_token'])",'Login must rotate CSRF state.');
must_contain($auth,'dt_auth_rate_limited','Login must have a rate limit gate.');
must_contain($auth,'INTERVAL 30 DAY','Expired login-attempt records must be bounded.');
must_contain($security,"'httponly'=>true",'Session cookie must be HttpOnly.');
must_contain($security,"'samesite'=>'Lax'",'Session cookie must define SameSite.');
must_contain($security,'hash_hmac','Authentication identifiers must be hashed before attempt logging.');
must_contain($security,'hash_equals','CSRF comparison must be constant-time.');
must_contain($logout,"REQUEST_METHOD']!=='POST'",'Logout must reject GET requests.');
must_contain($logout,'dt_verify_csrf();','Logout must be CSRF-protected.');
must_contain($view,'method="post" action="/logout.php"','Navigation must use the protected logout flow.');

must_contain($artists,"['owner','manager','editor','producer','viewer']",'Artist roles are incomplete.');
must_contain($artists,'dt_artist_transfer_owner','Canonical artist ownership transfer is missing.');
must_contain($artists,'FOR UPDATE','Ownership transfer must serialize the artist row.');
must_contain($artists,'artist_authority_events','Artist authority must be auditable.');
must_contain($artists,"account_status']!=='active'",'Artist membership must require an active user.');
must_not_contain($artists,'password_hash','Artist identities must not become a second authentication system.');

foreach(['db.php','security.php','foundation-schema-v100.php','auth.php','artists.php','view.php'] as $include){
    must_contain($bootstrap,"require_once __DIR__.'/{$include}';","Bootstrap is missing ".$include);
}
must_contain($workflow,"php: ['8.1','8.3']",'CI must cover PHP 8.1 and 8.3.');
must_contain($workflow,'mysql:8.0','CI must test against MySQL 8.');

echo "FOUNDATION_V1_SECTION1_CONTRACT=PASS\n";
