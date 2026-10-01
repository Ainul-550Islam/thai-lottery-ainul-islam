/**
 * ThaiLotto Privacy Policy Interactive Controller & Real API Connector
 */

(function () {
    'use strict';

    function initPrivacy() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        // Live search filter
        var searchInput = document.getElementById('privacySearchInput');
        var searchDebounce = null;

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(function () {
                    var q = searchInput.value.trim();
                    searchPrivacyApi(q);
                }, 300);
            });
        }

        function searchPrivacyApi(q) {
            var url = '/api/v1/public/privacy/search' + (q ? '?q=' + encodeURIComponent(q) : '');
            fetch(url, {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.results) {
                    renderPrivacySections(data.results);
                }
            })
            .catch(function (err) {
                console.error('Privacy search error:', err);
            });
        }

        function renderPrivacySections(sections) {
            var container = document.getElementById('privacySectionsContainer');
            if (!container) return;

            if (sections.length === 0) {
                container.innerHTML = '<div class="tl-card text-center py-12">' +
                    '<div class="text-3xl mb-2">🔒</div>' +
                    '<div class="font-bold text-white text-base">No Matching Privacy Clauses Found</div>' +
                    '<div class="text-slate-400 text-xs mt-1">Try searching for keywords like "cookies", "retention", "PDPA", or "encryption".</div>' +
                    '</div>';
                return;
            }

            var html = '';
            sections.forEach(function (sec) {
                html += '<div class="tl-card mb-6" id="' + sec.id + '">';
                html += '  <div class="flex items-center gap-3 border-b border-[#1c2b44] pb-3 mb-4">';
                html += '    <span class="w-8 h-8 rounded-lg bg-emerald-500/20 border border-emerald-500 text-emerald-400 font-black text-xs flex items-center justify-center font-mono">' + sec.number + '</span>';
                html += '    <div>';
                html += '      <h3 class="text-base font-black text-white">' + sec.title + '</h3>';
                html += '      <span class="text-xs text-slate-400">' + sec.summary + '</span>';
                html += '    </div>';
                html += '  </div>';

                html += '  <div class="space-y-4 text-xs">';
                sec.clauses.forEach(function (clause) {
                    html += '<div class="bg-[#080d17] p-4 rounded-xl border border-[#1c2b44]">';
                    html += '  <div class="flex items-center gap-2 mb-1.5">';
                    html += '    <span class="font-mono text-emerald-400 font-bold">' + clause.code + '</span>';
                    html += '    <span class="font-bold text-white">' + clause.heading + '</span>';
                    html += '  </div>';
                    html += '  <p class="text-slate-300 leading-relaxed text-[11px]">' + clause.text + '</p>';
                    html += '</div>';
                });
                html += '  </div>';
                html += '</div>';
            });

            container.innerHTML = html;
        }

        // Global methods
        window.privacyPortal = {
            submitDsar: function () {
                var emailInput = document.getElementById('dsarEmailInput');
                var typeSelect = document.getElementById('dsarTypeSelect');
                var email = emailInput ? emailInput.value.trim() : '';
                var type = typeSelect ? typeSelect.value : 'export';

                if (!email) {
                    alert('Please enter your registered email address.');
                    return;
                }

                fetch('/api/v1/public/privacy/dsar-request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ email: email, request_type: type })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        var modal = document.getElementById('dsarModal');
                        if (modal) modal.style.display = 'none';
                    } else {
                        alert(data.message || 'Request failed.');
                    }
                })
                .catch(function () {
                    alert('Failed to submit DSAR request.');
                });
            },
            downloadPrivacy: function () {
                fetch('/api/v1/public/privacy/download', {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    alert('Official Privacy Copy: ' + data.file_name + ' (' + data.file_size + ')\nSHA256: ' + data.sha256.substring(0, 24) + '...');
                });
            },
            openDsarModal: function () {
                var modal = document.getElementById('dsarModal');
                if (modal) modal.style.display = 'flex';
            },
            closeDsarModal: function () {
                var modal = document.getElementById('dsarModal');
                if (modal) modal.style.display = 'none';
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrivacy);
    } else {
        initPrivacy();
    }
})();
