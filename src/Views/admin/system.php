<?php
// Superadmin System-Übersicht (Platzhalter)
$pageTitle = 'Systemübersicht — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<nav class="navbar">
  <span class="navbar-brand"><?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">⭐ <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?> (Superadmin)</span>
  <a href="/logout" class="btn btn-sm">Abmelden</a>
</nav>
<main class="container">
  <h2>Systemübersicht</h2>
  <p>Willkommen im Superadmin-Bereich.</p>
  <p><em>Systemverwaltung wird in einem späteren Schritt implementiert.</em></p>
</main>
</body>
</html>
