<?php

// The front controller lives in /api, but Laravel routes already include that
// prefix. Present it as a root front controller so /api/health stays /api/health.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require '/home/ytfucmimat/xpulse/backend/public/index.php';
