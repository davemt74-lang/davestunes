<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
$pdo=dt_db();
dt_foundation_ensure_schema($pdo);
fwrite(STDOUT,DAVESTUNES_FOUNDATION_V100." migrated successfully\n");
