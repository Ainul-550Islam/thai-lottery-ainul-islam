/**
 * Lottery Platform Interactive How to Play & Bet Type Simulator Controller
 */

(function () {
    'use strict';

    function initHowToPlay() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        var simBetType = document.getElementById('simBetType');
        var simDigitInput = document.getElementById('simDigitInput');
        var simStakeInput = document.getElementById('simStakeInput');

        var outDesc = document.getElementById('simOutDesc');
        var outTotalLines = document.getElementById('simOutTotalLines');
        var outTotalStake = document.getElementById('simOutTotalStake');
        var outWinPerLine = document.getElementById('simOutWinPerLine');
        var outLinesGrid = document.getElementById('simOutLinesGrid');

        function triggerSimulation() {
            var bType = simBetType ? simBetType.value : '19_doors';
            var digits = simDigitInput ? simDigitInput.value.trim() : '7';
            var stake = simStakeInput ? parseFloat(simStakeInput.value) : 10;

            if (isNaN(stake) || stake <= 0) stake = 10;
            if (!digits) digits = '7';

            fetch('/api/v1/public/how-to-play/simulate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    bet_type: bType,
                    digit_input: digits,
                    stake_per_line: stake
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.simulation) {
                    renderSimulation(data.simulation);
                }
            })
            .catch(function (err) {
                console.error('Simulation error:', err);
            });
        }

        function renderSimulation(sim) {
            if (outDesc) outDesc.textContent = sim.description;
            if (outTotalLines) outTotalLines.textContent = sim.total_lines_count + ' Lines';
            if (outTotalStake) outTotalStake.textContent = sim.total_stake_display;
            if (outWinPerLine) outWinPerLine.textContent = sim.potential_win_per_line + ' (' + sim.payout_multiplier + ')';

            if (outLinesGrid) {
                var html = '';
                sim.generated_lines.forEach(function (line) {
                    html += '<span class="px-2.5 py-1.5 rounded-lg bg-[#0a1424] border border-amber-500/30 text-amber-400 font-mono font-bold text-center text-xs">' + line + '</span>';
                });
                outLinesGrid.innerHTML = html;
            }
        }

        if (simBetType) {
            simBetType.addEventListener('change', function () {
                var val = this.value;
                if (simDigitInput) {
                    if (val === '19_doors' || val === 'running_top') {
                        simDigitInput.placeholder = 'Enter 1 digit (e.g. 7)';
                        if (simDigitInput.value.length > 1) simDigitInput.value = simDigitInput.value[0];
                    } else if (val === '3d_tod' || val === '3d_top') {
                        simDigitInput.placeholder = 'Enter 3 digits (e.g. 921)';
                        simDigitInput.value = '921';
                    } else if (val === '2d_top' || val === '2d_bottom') {
                        simDigitInput.placeholder = 'Enter 2 digits (e.g. 21)';
                        simDigitInput.value = '21';
                    }
                }
                triggerSimulation();
            });
        }

        if (simDigitInput) {
            var debounce;
            simDigitInput.addEventListener('input', function () {
                clearTimeout(debounce);
                debounce = setTimeout(triggerSimulation, 200);
            });
        }

        if (simStakeInput) {
            simStakeInput.addEventListener('input', triggerSimulation);
        }

        // Quick Bet Type Selectors
        var typePills = document.querySelectorAll('.htp-type-pill');
        typePills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                var t = this.getAttribute('data-type');
                if (simBetType) {
                    simBetType.value = t;
                    simBetType.dispatchEvent(new Event('change'));
                }
            });
        });

        // Initial Simulation
        triggerSimulation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHowToPlay);
    } else {
        initHowToPlay();
    }
})();
