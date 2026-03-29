<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Anmelden — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
</head>
<body class="login-page">

<main class="login-container">
  <div class="login-box">

    <div class="login-logo">
      <span class="login-logo-icon">📚</span>
      <h1><?= htmlspecialchars(APP_NAME) ?></h1>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error" role="alert">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= url('/login') ?>" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token"
             value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

      <div class="form-group">
        <label for="username">Benutzername</label>
        <input
          type="text"
          id="username"
          name="username"
          autocomplete="username"
          required
          autofocus
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="password">Passwort</label>
        <input
          type="password"
          id="password"
          name="password"
          autocomplete="current-password"
          required>
      </div>

      <button type="submit" class="btn btn-primary btn-block">
        Anmelden
      </button>
    </form>

  </div>
</main>

</body>
</html>
