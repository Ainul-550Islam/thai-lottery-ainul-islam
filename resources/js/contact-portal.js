/**
 * ThaiLotto Customer Support & Ticket Dispatch Controller
 */

(function () {
    'use strict';

    function initContactPortal() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        // Ticket Dispatch Form
        var contactForm = document.getElementById('contactSupportForm');
        var submitBtn = document.getElementById('contactSubmitBtn');
        var responseBox = document.getElementById('contactResponseBox');

        if (contactForm) {
            contactForm.addEventListener('submit', function (e) {
                e.preventDefault();

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Dispatching Ticket...';
                }

                var payload = {
                    full_name: document.getElementById('contactName') ? document.getElementById('contactName').value : '',
                    email_or_phone: document.getElementById('contactContact') ? document.getElementById('contactContact').value : '',
                    department: document.getElementById('contactDepartment') ? document.getElementById('contactDepartment').value : 'general',
                    priority: document.getElementById('contactPriority') ? document.getElementById('contactPriority').value : 'normal',
                    subject: document.getElementById('contactSubject') ? document.getElementById('contactSubject').value : '',
                    message: document.getElementById('contactMessage') ? document.getElementById('contactMessage').value : ''
                };

                fetch('/api/v1/public/contact/submit', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(payload)
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane mr-2"></i> Submit Support Ticket';
                    }

                    if (data.success) {
                        if (responseBox) {
                            responseBox.classList.remove('hidden');
                            responseBox.innerHTML = '<div class="p-6 bg-emerald-500/10 border border-emerald-500/40 rounded-2xl text-center space-y-3">' +
                                '<div class="w-12 h-12 rounded-full bg-emerald-500/20 text-emerald-400 text-2xl flex items-center justify-center mx-auto"><i class="fa-solid fa-check"></i></div>' +
                                '<h4 class="text-base font-black text-white font-[\'Cinzel\']">Ticket Dispatched Successfully!</h4>' +
                                '<p class="text-xs text-slate-300 max-w-sm mx-auto leading-relaxed">' + data.message + '</p>' +
                                '<div class="p-2.5 bg-amber-500/10 border border-amber-500/30 rounded-xl inline-block">' +
                                '  <span class="text-[10px] text-slate-400 font-mono block">YOUR TICKET TRACKING ID</span>' +
                                '  <span class="text-base font-mono font-black text-amber-400">' + data.ticket_id + '</span>' +
                                '</div>' +
                                '</div>';
                        }
                        contactForm.reset();
                    } else {
                        alert(data.message || 'Submission failed. Please check required fields.');
                    }
                })
                .catch(function (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane mr-2"></i> Submit Support Ticket';
                    }
                    alert('Network error connecting to support dispatch gateway.');
                });
            });
        }

        // Ticket Status Tracker Form
        var trackerForm = document.getElementById('ticketTrackerForm');
        var trackerInput = document.getElementById('trackerTicketId');
        var trackerResult = document.getElementById('trackerResultBox');

        if (trackerForm) {
            trackerForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var tId = trackerInput ? trackerInput.value.trim() : '';
                if (!tId) {
                    alert('Please enter your Ticket ID (e.g. TKT-98421).');
                    return;
                }

                if (trackerResult) {
                    trackerResult.classList.remove('hidden');
                    trackerResult.innerHTML = '<div class="flex items-center justify-center gap-2 py-6 text-amber-400 font-mono text-xs"><i class="fa-solid fa-circle-notch fa-spin"></i> Checking ticket status...</div>';
                }

                fetch('/api/v1/public/contact/status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ ticket_id: tId })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && data.ticket) {
                        renderTicketResult(data.ticket);
                    } else {
                        if (trackerResult) {
                            trackerResult.innerHTML = '<div class="p-4 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-xs text-center"><i class="fa-solid fa-circle-exclamation mr-1"></i> ' + (data.message || 'Ticket not found.') + '</div>';
                        }
                    }
                })
                .catch(function (err) {
                    if (trackerResult) {
                        trackerResult.innerHTML = '<div class="p-4 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-xs text-center"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Network error connecting to support tracker.</div>';
                    }
                });
            });
        }

        function renderTicketResult(t) {
            if (!trackerResult) return;

            var html = '<div class="p-4 bg-[#0a1424] border border-amber-500/30 rounded-xl space-y-3">' +
                '<div class="flex items-center justify-between border-b border-[#1c2b44] pb-2">' +
                '  <div><span class="text-[10px] font-mono text-slate-400 block uppercase">Ticket Reference</span><span class="font-mono font-bold text-white text-sm">' + t.ticket_id + '</span></div>' +
                '  <span class="px-2.5 py-0.5 rounded bg-amber-500/20 text-amber-400 border border-amber-500/30 font-mono font-bold text-[10px]">' + t.status + '</span>' +
                '</div>' +
                '<div class="grid grid-cols-2 gap-2 text-xs">' +
                '  <div><span class="text-slate-400 text-[10px] block">Department</span><span class="text-white font-bold">' + t.department + '</span></div>' +
                '  <div><span class="text-slate-400 text-[10px] block">Assigned Officer</span><span class="text-cyan-400 font-bold">' + t.assigned_officer + '</span></div>' +
                '</div>' +
                '<div class="text-[11px] text-slate-300 bg-slate-900/80 p-2.5 rounded border border-[#1e2f47]">' +
                '  <span class="font-bold text-slate-400 block mb-0.5">Latest Update:</span>' + t.last_update +
                '</div>' +
                '</div>';

            trackerResult.innerHTML = html;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initContactPortal);
    } else {
        initContactPortal();
    }
})();
