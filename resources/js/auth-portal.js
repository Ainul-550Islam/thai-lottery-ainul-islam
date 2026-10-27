/**
 * Thai Lottery Interactive Auth Portal JavaScript Engine
 * Reference: https://lottery-platform.club/
 */

(function (window, document) {
    'use strict';

    function initAuthPortal() {
        // 1. Password Visibility Toggles
        const toggles = document.querySelectorAll('[data-password-toggle]');
        toggles.forEach(function (button) {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                const targetId = button.getAttribute('data-target');
                if (!targetId) return;

                const input = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                const eyeOpen = button.querySelector('.eye-open');
                const eyeClosed = button.querySelector('.eye-closed');

                if (eyeOpen && eyeClosed) {
                    eyeOpen.classList.toggle('hidden', !isPassword);
                    eyeClosed.classList.toggle('hidden', isPassword);
                }

                button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        });

        // 2. Real-Time Password Strength Meter
        const passwordInput = document.getElementById('register-password');
        const strengthBar = document.getElementById('password-strength-bar');
        const strengthLabel = document.getElementById('password-strength-label');

        if (passwordInput && strengthBar) {
            passwordInput.addEventListener('input', function () {
                const password = passwordInput.value || '';
                let score = 0;
                if (password.length >= 8) score++;
                if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score++;
                if (/[0-9]/.test(password)) score++;
                if (/[^A-Za-z0-9]/.test(password)) score++;

                let className = '';
                let label = '';

                switch (score) {
                    case 1:
                        className = 'tl-strength-weak';
                        label = 'Weak';
                        break;
                    case 2:
                        className = 'tl-strength-fair';
                        label = 'Fair';
                        break;
                    case 3:
                        className = 'tl-strength-good';
                        label = 'Good';
                        break;
                    case 4:
                        className = 'tl-strength-strong';
                        label = 'Strong';
                        break;
                    default:
                        className = password.length > 0 ? 'tl-strength-weak' : '';
                        label = password.length > 0 ? 'Very Weak' : '';
                }

                strengthBar.className = 'tl-strength-bar ' + className;
                if (strengthLabel) {
                    strengthLabel.textContent = label ? 'Strength: ' + label : '';
                }
            });
        }

        // 3. Auto-populate Referral Code from URL
        const refInput = document.getElementById('referral_code');
        if (refInput && !refInput.value) {
            const urlParams = new URLSearchParams(window.location.search);
            const refParam = urlParams.get('ref') || urlParams.get('agent');
            if (refParam) {
                refInput.value = refParam.toUpperCase();
            }
        }

        // 4. Form Submit Guard (Double-Click Prevention)
        const forms = document.querySelectorAll('form[data-auth-form]');
        forms.forEach(function (form) {
            form.addEventListener('submit', function (e) {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    if (submitBtn.disabled) {
                        e.preventDefault();
                        return;
                    }
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAuthPortal);
    } else {
        initAuthPortal();
    }
})(window, document);
