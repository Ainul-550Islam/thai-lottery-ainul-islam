/**
 * Wallet conversion — the Cash <-> Win lane's browser side.
 *
 * Canonical existing web endpoints (routes/web.php):
 * - GET  /wallet/convert          (player.wallet.conversion)
 * - POST /wallet/convert/preview  (player.wallet.conversion.preview)
 * - POST /wallet/convert/quote    (player.wallet.conversion.quote)
 * - POST /wallet/convert          (player.wallet.conversion.store)
 *
 * WHAT THIS SCRIPT IS ALLOWED TO DO
 * Three jobs, all of them presentation: write the CSPRNG idempotency key the
 * form submits and preserve it across retries of the same form instance, ask
 * the server (`/wallet/convert/preview`) for the live price as the member
 * types, and count down the expiry of the prices already on the page. It
 * computes NO fee and decides NO amount: the browser must never be the
 * authority on what a conversion costs.
 */
(function () {
    'use strict';

    var KEY_MIN_LENGTH = 16;
    var KEY_MAX_LENGTH = 128;

    function freshKey() {
        var cryptoObj = window.crypto || window.msCrypto;
        if (cryptoObj && typeof cryptoObj.randomUUID === 'function') {
            return 'wc-' + cryptoObj.randomUUID();
        }

        if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
            var bytes = new Uint8Array(16);
            cryptoObj.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            var hex = [];
            for (var i = 0; i < 16; i += 1) {
                hex.push((bytes[i] + 0x100).toString(16).slice(1));
            }
            return (
                'wc-' +
                hex.slice(0, 4).join('') + '-' +
                hex.slice(4, 6).join('') + '-' +
                hex.slice(6, 8).join('') + '-' +
                hex.slice(8, 10).join('') + '-' +
                hex.slice(10, 16).join('')
            );
        }

        return null;
    }

    function csrfToken(form) {
        var field = form.querySelector('input[name="_token"]');
        if (field && field.value) {
            return field.value;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function normaliseAmount(raw) {
        return String(raw || '').trim().replace(/,/g, '');
    }

    function parseMinorUnits(value) {
        var trimmed = normaliseAmount(value);
        var match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(trimmed);
        if (!match) {
            return null;
        }
        var whole = BigInt(match[1]);
        var frac = (match[2] || '').padEnd(2, '0');
        return whole * 100n + BigInt(frac);
    }

    function isPositiveDecimal(value) {
        var minor = parseMinorUnits(value);
        return minor !== null && minor > 0n;
    }

    function decimalCompare(a, b) {
        var ma = parseMinorUnits(a);
        var mb = parseMinorUnits(b);
        if (ma === null || mb === null) {
            return 0;
        }
        return ma === mb ? 0 : ma < mb ? -1 : 1;
    }

    function isValidKey(value) {
        return typeof value === 'string' && value.length >= KEY_MIN_LENGTH && value.length <= KEY_MAX_LENGTH;
    }

    function initForm(form) {
        var keyField = form.querySelector('[data-wc-idempotency-key]');
        var quoteField = form.querySelector('[data-wc-quote-reference]');
        var amountInput = form.querySelector('[data-wc-amount]');
        var previewFee = form.querySelector('[data-wc-preview-fee]');
        var previewNet = form.querySelector('[data-wc-preview-net]');
        var previewRate = form.querySelector('[data-wc-preview-rate]');
        var previewNote = form.querySelector('[data-wc-preview-note]');
        var submitButton = form.querySelector('[data-wc-submit]');
        var quoteButton = form.querySelector('[data-wc-quote]');
        var helpSlot = form.querySelector('[data-wc-amount-hint]');
        var disabledNote = form.querySelector('[data-wc-disabled-note]');

        var executeUrl = form.getAttribute('action') || '/wallet/convert';
        var previewUrl = form.getAttribute('data-wc-preview-url') || '';
        var quoteUrl = form.getAttribute('data-wc-quote-url') || '';
        var currency = form.getAttribute('data-wc-currency') || '';
        var minimum = form.getAttribute('data-wc-min') || '';
        var maximum = form.getAttribute('data-wc-max') || '';
        var available = form.getAttribute('data-wc-available') || '';
        var unpricedNote = form.getAttribute('data-wc-unpriced-note') || '';
        var amountHint = form.getAttribute('data-wc-amount-hint') || '';
        var noCryptoNote = form.getAttribute('data-wc-no-crypto-note') || '';

        var submitting = false;
        var cryptoAvailable = true;

        // --- CSPRNG idempotency key (minted once per rendered form instance) ---
        if (keyField) {
            if (!isValidKey(keyField.value)) {
                var initialKey = freshKey();
                if (!initialKey) {
                    cryptoAvailable = false;
                    keyField.value = '';
                    if (submitButton) {
                        submitButton.disabled = true;
                    }
                    if (disabledNote) {
                        if (noCryptoNote) {
                            disabledNote.textContent = noCryptoNote;
                        }
                        disabledNote.hidden = false;
                    }
                } else {
                    keyField.value = initialKey;
                }
            }
        }

        form.addEventListener('submit', function (event) {
            if (!cryptoAvailable) {
                event.preventDefault();
                return;
            }
            if (submitting) {
                event.preventDefault();
                return;
            }

            // Ensure execute submissions always target the canonical store route
            // (`POST /wallet/convert`), even if `Get price` previously mutated action.
            form.setAttribute('action', executeUrl);

            if (keyField && !isValidKey(keyField.value)) {
                var fallbackKey = freshKey();
                if (!fallbackKey) {
                    event.preventDefault();
                    if (submitButton) {
                        submitButton.disabled = true;
                    }
                    if (disabledNote) {
                        disabledNote.hidden = false;
                    }
                    return;
                }
                keyField.value = fallbackKey;
            }

            submitting = true;
            form.setAttribute('aria-busy', 'true');
            if (submitButton) {
                submitButton.disabled = true;
            }
        });

        // --- get-price posts to POST /wallet/convert/quote as a plain round trip ---
        if (quoteButton && quoteField) {
            quoteButton.addEventListener('click', function () {
                if (submitting) {
                    return;
                }
                if (!amountInput || !isPositiveDecimal(normaliseAmount(amountInput.value))) {
                    if (amountInput) {
                        amountInput.focus();
                    }
                    return;
                }

                submitting = true;
                form.setAttribute('aria-busy', 'true');
                if (submitButton) {
                    submitButton.disabled = true;
                }
                quoteButton.disabled = true;
                form.setAttribute('action', quoteUrl || executeUrl);
                form.submit();
            });
        }

        // --- live preview (POST /wallet/convert/preview) ------------------------
        var previewTimer = null;
        var previewSeq = 0;

        function invalidatePreview() {
            // Invalidate immediately, before debounce/validation can return early.
            // A response for the previous input must never price the current one.
            previewSeq += 1;
            if (previewFee) {
                previewFee.textContent = '—';
            }
            if (previewNet) {
                previewNet.textContent = '—';
            }
            if (previewRate) {
                previewRate.textContent = '—';
            }
        }

        function showNote(message) {
            if (!previewNote) {
                return;
            }
            if (message) {
                previewNote.textContent = message;
                previewNote.hidden = false;
            } else {
                previewNote.textContent = '';
                previewNote.hidden = true;
            }
        }

        function fieldHint() {
            if (!helpSlot || !amountHint) {
                return;
            }
            helpSlot.textContent = amountHint
                .replace(':min', minimum || '—')
                .replace(':max', maximum || '—')
                .replace(':available', (available || '0') + ' ' + currency);
        }

        function fetchPreview() {
            if (!previewUrl || !amountInput) {
                return;
            }

            var amount = normaliseAmount(amountInput.value);
            if (amount === '') {
                showNote('');
                fieldHint();
                return;
            }

            if (!isPositiveDecimal(amount)) {
                showNote(amountHint || '');
                fieldHint();
                return;
            }

            if (minimum && decimalCompare(amount, minimum) < 0) {
                showNote(amountHint || '');
                fieldHint();
                return;
            }

            if (maximum && decimalCompare(amount, maximum) > 0) {
                showNote(amountHint || '');
                fieldHint();
                return;
            }

            if (available && decimalCompare(amount, available) > 0) {
                showNote(amountHint || '');
                fieldHint();
                return;
            }

            var seq = ++previewSeq;

            window.fetch(previewUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(form),
                },
                body: JSON.stringify({
                    direction: form.getAttribute('data-wc-direction') || '',
                    amount: amount,
                }),
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('preview refused');
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (seq !== previewSeq) {
                        return;
                    }

                    if (previewFee) {
                        previewFee.textContent = data.fee_amount + ' ' + (data.currency || currency);
                    }
                    if (previewNet) {
                        previewNet.textContent = data.amount_out + ' ' + (data.currency || currency);
                    }
                    if (previewRate) {
                        previewRate.textContent = data.percentage + '%';
                    }

                    if (data.configured === false) {
                        showNote(unpricedNote || '');
                    } else {
                        showNote('');
                    }
                })
                .catch(function () {
                    if (seq !== previewSeq) {
                        return;
                    }
                    // The server is the only price authority: if it cannot be
                    // reached, show no number at all rather than a guessed one.
                    if (previewFee) {
                        previewFee.textContent = '—';
                    }
                    if (previewNet) {
                        previewNet.textContent = '—';
                    }
                    if (previewRate) {
                        previewRate.textContent = '—';
                    }
                    showNote(unpricedNote || '');
                });
        }

        if (amountInput) {
            amountInput.addEventListener('input', function () {
                invalidatePreview();
                if (previewTimer) {
                    window.clearTimeout(previewTimer);
                }
                previewTimer = window.setTimeout(fetchPreview, 350);
            });

            fieldHint();
            fetchPreview();
        }
    }

    function initExpiryCountdowns() {
        var slots = document.querySelectorAll('[data-wc-expiry]');

        function tick() {
            var now = Date.now();

            Array.prototype.forEach.call(slots, function (slot) {
                var raw = slot.getAttribute('data-wc-expiry') || '';
                var expiry = Date.parse(raw);

                if (isNaN(expiry)) {
                    return;
                }

                var remaining = Math.max(0, Math.floor((expiry - now) / 1000));

                if (remaining === 0) {
                    slot.textContent = slot.getAttribute('data-wc-expired-label') || 'expired';
                    slot.setAttribute('data-wc-expired', '1');
                    return;
                }

                var minutes = Math.floor(remaining / 60);
                var seconds = remaining % 60;
                slot.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            });
        }

        if (slots.length === 0) {
            return;
        }

        tick();
        window.setInterval(tick, 1000);
    }

    function boot() {
        var forms = document.querySelectorAll('[data-wc-conversion-form]');
        Array.prototype.forEach.call(forms, initForm);
        initExpiryCountdowns();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
