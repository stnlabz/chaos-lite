<?php
require __DIR__ . '/app/bootstrap.php';   // setup only

include __DIR__ . "/public/themes/{$site_theme}/includes/header.php";
include __DIR__ . '/app/router.php';
include __DIR__ . "/public/themes/{$site_theme}/includes/footer.php";