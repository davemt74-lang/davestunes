<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
dt_auth_logout();
dt_redirect('/');
