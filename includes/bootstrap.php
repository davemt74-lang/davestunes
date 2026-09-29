<?php
declare(strict_types=1);

define('DAVESTUNES_ROOT',dirname(__DIR__));

require_once __DIR__.'/db.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/foundation-schema-v100.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/artists.php';
require_once __DIR__.'/view.php';

dt_session_boot();
