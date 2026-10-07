'use strict';

// Installer (install/index.php): Passwortfeld ein- und ausblenden, damit man vor dem Absenden sieht, was wirklich im
// Feld steht (z. B. ein vom Browser eingesetztes Passwort). Der Button (data-reveal = ID des Feldes) ist ohne
// JavaScript verborgen.
document.querySelectorAll('button[data-reveal]').forEach((button) => {
    const field = document.getElementById(button.dataset.reveal);
    if (!field) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        button.textContent = show ? button.dataset.hide : button.dataset.show;
        button.setAttribute('aria-pressed', String(show));
    });
});
