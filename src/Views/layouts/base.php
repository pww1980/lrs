<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body class="theme-<?= htmlspecialchars($_SESSION['theme'] ?? 'minecraft') ?>">
<?= $content ?? '' ?>
<script src="/js/app.js"></script>
</body>
</html>
