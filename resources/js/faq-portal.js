/**
 * Lottery Platform FAQ & Interactive Knowledge Base Controller
 */

(function () {
    'use strict';

    function initFaqPortal() {
        var searchInput = document.getElementById('faqSearchInput');
        var faqContainer = document.getElementById('faqAccordionContainer');
        var categoryPills = document.querySelectorAll('.faq-category-pill');

        // Real-time server-side debounced search
        var debounceTimer;
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                var q = this.value.trim();
                debounceTimer = setTimeout(function () {
                    searchFaqApi(q);
                }, 250);
            });
        }

        function searchFaqApi(q) {
            fetch('/api/v1/public/faq/search' + (q ? '?q=' + encodeURIComponent(q) : ''), {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.results) {
                    renderFaqCategories(data.results);
                }
            })
            .catch(function (err) {
                console.error('FAQ search error:', err);
            });
        }

        function renderFaqCategories(categories) {
            if (!faqContainer) return;

            if (categories.length === 0) {
                faqContainer.innerHTML = '<div class="tl-card text-center py-12">' +
                    '<div class="text-3xl mb-2">🔍</div>' +
                    '<div class="font-bold text-white text-base">No Matching Questions Found</div>' +
                    '<div class="text-slate-400 text-xs mt-1">Try searching for keywords like "PromptPay", "900x", "VIP", or "Withdrawal".</div>' +
                    '</div>';
                return;
            }

            var html = '';
            categories.forEach(function (cat) {
                html += '<div class="faq-category-block mb-8" id="' + cat.id + '">';
                html += '  <div class="flex items-center gap-3 mb-4 pb-2 border-b border-[#1c2b44]">';
                html += '    <div class="w-8 h-8 rounded-lg bg-amber-500/20 border border-amber-500/40 text-amber-400 flex items-center justify-center text-sm">';
                html += '      <i class="fa-solid ' + cat.icon + '"></i>';
                html += '    </div>';
                html += '    <div>';
                html += '      <h3 class="text-base font-black text-white font-[\'Cinzel\']">' + cat.title + '</h3>';
                html += '      <p class="text-xs text-slate-400">' + cat.description + '</p>';
                html += '    </div>';
                html += '  </div>';

                html += '  <div class="space-y-3">';
                cat.questions.forEach(function (item, idx) {
                    var uid = cat.id + '_' + idx;
                    html += '<div class="faq-accordion-item tl-card p-4 transition-all">';
                    html += '  <button type="button" onclick="toggleFaq(\'' + uid + '\')" class="w-full flex items-center justify-between text-left group">';
                    html += '    <span class="text-xs sm:text-sm font-bold text-slate-200 group-hover:text-amber-400 transition-colors flex items-center gap-2">';
                    html += '      <i class="fa-solid fa-circle-question text-amber-400 text-xs shrink-0"></i> ' + item.q;
                    html += '    </span>';
                    html += '    <i id="faqIcon_' + uid + '" class="fa-solid fa-chevron-down text-slate-400 text-xs transition-transform duration-200"></i>';
                    html += '  </button>';
                    html += '  <div id="faqAns_' + uid + '" class="hidden pt-3 mt-3 border-t border-[#1c2b44] text-xs text-slate-400 leading-relaxed">';
                    html += '    ' + item.a;
                    html += '  </div>';
                    html += '</div>';
                });
                html += '  </div>';
                html += '</div>';
            });

            faqContainer.innerHTML = html;
        }

        // Global accordion toggle
        window.toggleFaq = function (uid) {
            var ans = document.getElementById('faqAns_' + uid);
            var icon = document.getElementById('faqIcon_' + uid);
            if (ans) {
                if (ans.classList.contains('hidden')) {
                    ans.classList.remove('hidden');
                    if (icon) icon.classList.add('rotate-180', 'text-amber-400');
                } else {
                    ans.classList.add('hidden');
                    if (icon) icon.classList.remove('rotate-180', 'text-amber-400');
                }
            }
        };

        // Category filter buttons
        categoryPills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                var targetId = this.getAttribute('data-category');
                categoryPills.forEach(function (p) {
                    p.classList.remove('bg-amber-500', 'text-slate-950', 'font-black');
                    p.classList.add('bg-slate-800', 'text-slate-300');
                });
                this.classList.remove('bg-slate-800', 'text-slate-300');
                this.classList.add('bg-amber-500', 'text-slate-950', 'font-black');

                var blocks = document.querySelectorAll('.faq-category-block');
                blocks.forEach(function (b) {
                    if (targetId === 'all' || b.id === targetId) {
                        b.style.display = 'block';
                    } else {
                        b.style.display = 'none';
                    }
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFaqPortal);
    } else {
        initFaqPortal();
    }
})();
