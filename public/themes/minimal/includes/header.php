<!doctype html>
<html lang="en">
<head>
  <title><?= $site_name ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <!-- Theme CSS -->
  <link rel = "stylesheet" href="/public/themes/<?= $site_theme ?>/assets/css/<?= $site_theme?>.css">
  
  <!-- Images/Icons/SVG -->
  <link rel="apple-touch-icon" sizes="180x180" href="/public/themes/<?= $site_theme ?>/assets/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/public/themes/<?= $site_theme ?>/assets/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/public/themes/<?= $site_theme ?>/assets/images/favicon-16x16.png">
  <link rel="manifest" href="/public/themes/<?= $site_theme ?>/assets/images/site.webmanifest">
  <link rel="icon" type="image/x-icon" href="/public/themes/<?= $site_theme ?>/assets/images/favicon.ico">
  
  <!-- Sitemaps, Google, ROR: Generated from the SEO Plugin -->
  <link rel="sitemap" type="application/xml" title="Sitemap" href="/sitemap.xml">
  <link rel="alternate" type="application/rss+xml" title="ROR" href="/ror.xml" />
</head>
<body>
<header class="header container py-3 d-flex align-items-center justify-content-between">
  <div class="d-flex align-items-center gap-2">
    <a class="brand navbar-brand" href="/">
      <img src="/public/themes/<?= $site_theme ?>/assets/images/poe-icon.png"
           alt="<?= $site_name ?>" width="180" height="150">
    </a>
  </div>
  <?php
  include __DIR__ . '/nav.php';
  ?>
</header>

<div class="container">
<main>
<!-- Module Content goes here -->
