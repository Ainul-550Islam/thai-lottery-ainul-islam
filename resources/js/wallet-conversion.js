/**
 * Wallet conversion — the Cash <-> Win lane's browser side.
 *
 * WHAT THIS SCRIPT IS ALLOWED TO DO
 * Three jobs, all of them presentation: write the idempotency key the form
 * submits, ask the server for the live price as the member types, and count
 * down the expiry of the prices already on the page. It computes NO fee and
 * decides NO amount: the browser must never be the authority on what a
 * conversion costs — `/wallet/convert/preview` runs the real fee engine, and
 * the server re-prices everything before any money moves.
 *
 * WHY THE KEY IS WRITTEN HERE AND NOT IN THE BLADE
 * A key baked into the HTML is the same key for every submission of that page,
 * including a double-click or a back-button resubmit — which is exactly the
 * case the key exists to stop. A fresh value on every load and a fresh value
 * after every successful execute mean each submission carries its own key.
 */
(function () {
    'use strict';

    var KEY_MIN_LENGTH = 16;

    function freshKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'wc-' + window.crypto.randomUUID();
        }

        // RFC4122-shaped fallback; uniqueness is what matters, not the hash.
        return 'wc-' + 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function csrfToken(form) {
        var field = form.querySelector('input[name="_token"]');
        return field ? field.value : '';
    }

    function normaliseAmount(raw) {
        return String(raw || '').trim().replace(/,/g, '');
    }

    function isPositiveDecimal(value) {
        return /^\d+(\.\d{1,2})?$/.test(value) && parseFloat(value) > 0;
    }

    function decimalCompare(a, b) {
        // Exact at the scale the forms use (2dp); enough for hints.
        var pa = parseFloat(a);
        var pb = parseFloat(b);
        return pa === pb ? 0 : pa < pb ? -1 : 1;
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

        var previewUrl = form.getAttribute('data-wc-preview-url') || '';
        var currency = form.getAttribute('data-wc-currency') || '';
        var minimum = form.getAttribute('data-wc-min') || '';
        var maximum = form.getAttribute('data-wc-max') || '';
        var available = form.getAttribute('data-wc-available') || '';
        var unpricedNote = form.getAttribute('data-wc-unpriced-note') || '';
        var amountHint = form.getAttribute('data-wc-amount-hint') || '';

        // --- the idempotency key -------------------------------------
        if (keyField) {
            keyField.value = freshKey();
        }

        form.addEventListener('submit', function () {
            if (keyField) {
                keyField.value = freshKey();
            }
            if (submitButton) {
                submitButton.disabled = true;
            }
        });

        // --- get-price posts to the quote action as a plain round trip --
        if (quoteButton && quoteField) {
            quoteButton.addEventListener('click', function () {
                if (!amountInput || !isPositiveDecimal(normaliseAmount(amountInput.value))) {
                    if (amountInput) {
                        amountInput.focus();
                    }
                    return;
                }

                // The server's quote action answers with a redirect and a
                // flash line carrying the fee, so the member sees the exact
                // sentence the engine produced — not a browser paraphrase.
                form.action = form.getAttribute('data-wc-quote-url') || form.action;
                form.submit();
            });
        }

        // --- live preview ---------------------------------------------
        var previewTimer = null;
        var previewSeq = 0;

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
            if (!helpSlot) {
                return;
            }
            if (!amountHint) {
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

            fetch(previewUrl, {
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
                        return; // a newer keystroke already answered
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
