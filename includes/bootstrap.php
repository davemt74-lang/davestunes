<?php
declare(strict_types=1);

define('DAVESTUNES_ROOT',dirname(__DIR__));

require_once __DIR__.'/release.php';

require_once __DIR__.'/db.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/foundation-schema-v100.php';
require_once __DIR__.'/music-catalog-schema-v110.php';
require_once __DIR__.'/library-schema-v120.php';
require_once __DIR__.'/playback-schema-v130.php';
require_once __DIR__.'/desktop-object-schema-v220.php';
require_once __DIR__.'/featured-schema-v270.php';
require_once __DIR__.'/experience-schema-v280.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/artists.php';
require_once __DIR__.'/music-catalog-v110.php';
require_once __DIR__.'/library-v120.php';
require_once __DIR__.'/playback-v130.php';
require_once __DIR__.'/desktop-data-v210.php';
require_once __DIR__.'/desktop-objects-v220.php';
require_once __DIR__.'/desktop-media-v240.php';
require_once __DIR__.'/featured-v270.php';
require_once __DIR__.'/experience-v280.php';
require_once __DIR__.'/view.php';

dt_session_boot();
