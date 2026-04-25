// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

// Entry point for password-strength tools (zxcvbn).
// Used in the installer (step 2) and user settings.
import zxcvbn from 'zxcvbn';

const field = document.getElementById('password') as HTMLInputElement | null;
const meter = document.getElementById('pw-strength') as HTMLElement | null;

if (field && meter) {
    field.addEventListener('input', () => {
        const result = zxcvbn(field.value);
        meter.dataset.score = String(result.score);
        meter.textContent = ['Sehr schwach', 'Schwach', 'Mittel', 'Stark', 'Sehr stark'][result.score] ?? '';
    });
}
