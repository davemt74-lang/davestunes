<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
$pdo=dt_db();
dt_foundation_ensure_schema($pdo);
dt_catalog_ensure_schema($pdo);
dt_library_ensure_schema($pdo);
fwrite(STDOUT,DAVESTUNES_FOUNDATION_V100." migrated successfully\n");
fwrite(STDOUT,DAVESTUNES_CATALOG_V110." migrated successfully\n");
fwrite(STDOUT,DAVESTUNES_LIBRARY_V120." migrated successfully\n");
