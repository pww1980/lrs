// Lennarts Diktat-Trainer — Basis JavaScript
// Wird sukzessive erweitert.

document.addEventListener('DOMContentLoaded', () => {
  // Autofokus auf erstes sichtbares Input-Feld falls nicht vorhanden
  const first = document.querySelector('input[autofocus]');
  if (first) first.focus();
});
