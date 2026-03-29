<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Einrichtungsassistent – Schritt <?= $currentStep ?> von <?= $totalSteps ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
</head>
<body class="wizard-page">

<main class="wizard-container">
  <div class="wizard-box">

    <!-- Header -->
    <div class="wizard-header">
      <div class="wizard-logo">🧙 Einrichtungsassistent</div>
      <div class="wizard-subtitle">
        Schritt <?= $currentStep ?> von <?= $totalSteps ?>:
        <strong><?= htmlspecialchars($stepTitles[$currentStep]) ?></strong>
      </div>
    </div>

    <!-- Schritt-Indikator -->
    <div class="wizard-stepper">
      <?php foreach ($stepTitles as $n => $title): ?>
        <?php
          $cls = 'step-dot';
          if ($n < $currentStep)       $cls .= ' done';
          elseif ($n === $currentStep) $cls .= ' active';
        ?>
        <div class="<?= $cls ?>" title="<?= htmlspecialchars($title) ?>">
          <?php if ($n < $currentStep): ?>
            <span class="dot-inner">✓</span>
          <?php else: ?>
            <span class="dot-inner"><?= $n ?></span>
          <?php endif; ?>
          <span class="dot-label"><?= htmlspecialchars($title) ?></span>
        </div>
        <?php if ($n < $totalSteps): ?>
          <div class="step-line <?= $n < $currentStep ? 'done' : '' ?>"></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <!-- Fehlermeldungen -->
    <?php if (!empty($errors)): ?>
      <div class="alert alert-error" role="alert">
        <strong>Bitte folgende Fehler korrigieren:</strong>
        <ul>
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- Schritt-Inhalt -->
    <?php require __DIR__ . "/steps/step{$currentStep}.php"; ?>

  </div>
</main>

<script src="/public/js/app.js"></script>
</body>
</html>
