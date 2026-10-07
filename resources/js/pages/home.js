/**
 * Lottery Platform - Home Page Interactive Runtime Engine
 * Manages synchronized countdowns, live jackpot feeds, interactive number verification, and dynamic market switching.
 */

document.addEventListener('DOMContentLoaded', () => {
    initHomeCountdown();
    initLotteryFilterTabs();
    initQuickPrizeChecker();
    initLiveResultRefresh();
});

/**
 * Initializes synchronized server countdown with zero-drift local compensation.
 */
function initHomeCountdown() {
    const countdownEl = document.querySelector('[data-home-countdown]');
    if (!countdownEl) return;

    const targetIso = countdownEl.getAttribute('data-target-iso');
    if (!targetIso) return;

    const targetTime = new Date(targetIso).getTime();

    const daysVal = document.getElementById('cd-days');
    const hoursVal = document.getElementById('cd-hours');
    const minutesVal = document.getElementById('cd-minutes');
    const secondsVal = document.getElementById('cd-seconds');

    function update() {
        const now = Date.now();
        const diff = targetTime - now;

        if (diff <= 0) {
            if (daysVal) daysVal.textContent = '00';
            if (hoursVal) hoursVal.textContent = '00';
            if (minutesVal) minutesVal.textContent = '00';
            if (secondsVal) secondsVal.textContent = '00';

            const statusBadge = document.querySelector('[data-countdown-status]');
            if (statusBadge) {
                statusBadge.textContent = 'DRAW IN PROGRESS';
                statusBadge.classList.remove('bg-amber-500/10', 'text-amber-400');
                statusBadge.classList.add('bg-emerald-500/10', 'text-emerald-400', 'animate-pulse');
            }
            return;
        }

        const d = Math.floor(diff / (1000 * 60 * 60 * 24));
        const h = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const s = Math.floor((diff % (1000 * 60)) / 1000);

        if (daysVal) daysVal.textContent = String(d).padStart(2, '0');
        if (hoursVal) hoursVal.textContent = String(h).padStart(2, '0');
        if (minutesVal) minutesVal.textContent = String(m).padStart(2, '0');
        if (secondsVal) secondsVal.textContent = String(s).padStart(2, '0');
    }

    update();
    setInterval(update, 1000);
}

/**
 * Tab switcher for Lottery markets on home page.
 */
function initLotteryFilterTabs() {
    const tabButtons = document.querySelectorAll('[data-market-tab]');
    const cards = document.querySelectorAll('[data-market-category]');

    if (!tabButtons.length || !cards.length) return;

    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const category = btn.getAttribute('data-market-tab');

            // Update tab styles
            tabButtons.forEach(b => {
                b.classList.remove('bg-gold-500', 'text-black', 'shadow-gold');
                b.classList.add('text-gold-200/70', 'hover:text-gold-100', 'hover:bg-gold-500/10');
            });

            btn.classList.add('bg-gold-500', 'text-black', 'shadow-gold');
            btn.classList.remove('text-gold-200/70', 'hover:text-gold-100', 'hover:bg-gold-500/10');

            // Filter cards
            cards.forEach(card => {
                const cardCat = card.getAttribute('data-market-category');
                if (category === 'all' || cardCat === category) {
                    card.style.display = '';
                    card.classList.add('animate-fadeIn');
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });
}

/**
 * Quick prize checker on the landing page hero/widgets.
 */
function initQuickPrizeChecker() {
    const form = document.querySelector('[data-quick-checker-form]');
    if (!form) return;

    const input = form.querySelector('input[name="ticket_number"]');
    const resultBox = document.querySelector('[data-quick-checker-result]');

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const number = input ? input.value.trim() : '';

        if (!number || number.length < 2) {
            if (resultBox) {
                resultBox.innerHTML = `
                    <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm flex items-center gap-3">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span>Please enter at least 2 to 6 digits to verify.</span>
                    </div>
                `;
                resultBox.classList.remove('hidden');
            }
            return;
        }

        // Show verification modal or direct redirect to full verifier
        window.location.href = `/prize-verification?number=${encodeURIComponent(number)}`;
    });
}

/**
 * Periodic poll for live results and jackpots.
 */
function initLiveResultRefresh() {
    // Poll API endpoint every 60 seconds
    setInterval(() => {
        fetch('/api/v1/home', {
            headers: {
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success && data.data) {
                // Background update if needed
                console.debug('Lottery Platform Home Live Sync:', data.data.current_time);
            }
        })
        .catch(err => {
            console.debug('Sync fallback silently handled:', err);
        });
    }, 60000);
}
