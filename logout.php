<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
dt_verify_csrf();
dt_auth_logout();
dt_redirect('/');
