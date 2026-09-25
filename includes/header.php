<?php
/**
 * Shared page header: <head>, gray top bar, and the seal + PST clock row.
 * Expects, optionally, before including:
 *   $pageTitle  string  browser tab title
 */
$pageTitle = $pageTitle ?? APP_NAME;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>

<div class="utility-bar"></div>

<div class="seal-row">
  <div class="seal-brand">
    <a href="<?= base_url('portal.php') ?>"><img src="<?= asset('images/logo_virac.png') ?>" alt="Municipality of Virac Seal" class="seal-logo"></a>
  </div>
  <div class="seal-clock">
    <div>Philippine Standard Time:</div>
    <div id="pst-clock"><?= e((new DateTime('now'))->format('l, F j, Y g:i:s A')) ?></div>
  </div>
</div>
