'use strict';

class LoginCooldown {
    constructor(root) {
        this.output = document.getElementById('login-countdown');
        this.seconds = Number(root.dataset.loginRetrySeconds);
        if (!this.output || !Number.isFinite(this.seconds) || this.seconds <= 0) return;
        this.deadline = Date.now() + this.seconds * 1000;
        this.timer = window.setInterval(() => this.tick(), 1000);
        document.addEventListener('visibilitychange', () => this.tick());
    }

    tick() {
        const left = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000));
        this.output.textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
        if (left === 0) {
            window.clearInterval(this.timer);
            // The server rechecks the real deadline before offering access again.
            window.location.reload();
        }
    }
}

const loginCooldownRoot = document.querySelector('[data-login-retry-seconds]');
if (loginCooldownRoot) new LoginCooldown(loginCooldownRoot);
