<?php
// Lernbereich für Kinder (Platzhalter)
$pageTitle = 'Lernen — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body class="theme-<?= htmlspecialchars($_SESSION['theme'] ?? 'minecraft') ?>">
<nav class="navbar">
  <span class="navbar-brand"><?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">🎮 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
  <a href="/logout" class="btn btn-sm">Abmelden</a>
</nav>
<main class="container">
  <h2>Willkommen, <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?>!</h2>
  <p>Dein Abenteuer wartet...</p>
  <p><em>Lernbereich wird in einem späteren Schritt implementiert.</em></p>
</main>
</body>
</html>
