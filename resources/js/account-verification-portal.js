/**
 * ThaiLotto Account Verification & Real-Time KYC Portal Runtime Controller
 */

(function () {
    'use strict';

    function initVerificationPortal() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        // Status Check Form
        var statusForm = document.getElementById('kycStatusForm');
        var statusInputRef = document.getElementById('kycStatusRef');
        var statusInputContact = document.getElementById('kycStatusContact');
        var statusResultBox = document.getElementById('kycStatusResult');

        if (statusForm) {
            statusForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var ref = statusInputRef ? statusInputRef.value.trim() : '';
                var contact = statusInputContact ? statusInputContact.value.trim() : '';

                if (!ref) {
                    alert('Please enter your KYC Submission Reference ID (e.g., KYC-88291).');
                    return;
                }

                if (statusResultBox) {
                    statusResultBox.classList.remove('hidden');
                    statusResultBox.innerHTML = '<div class="flex items-center justify-center gap-3 py-6 text-amber-400 font-mono text-xs"><i class="fa-solid fa-circle-notch fa-spin text-base"></i> Querying automated KYC ledger...</div>';
                }

                fetch('/api/v1/public/verification/check-status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        reference_id: ref,
                        phone_or_email: contact || 'member@thailotto.club'
                    })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && data.verification) {
                        renderStatusResult(data.verification);
                    } else {
                        if (statusResultBox) {
                            statusResultBox.innerHTML = '<div class="p-4 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-xs text-center"><i class="fa-solid fa-circle-exclamation mr-1.5"></i> ' + (data.message || 'Reference ID not found.') + '</div>';
                        }
                    }
                })
                .catch(function (err) {
                    if (statusResultBox) {
                        statusResultBox.innerHTML = '<div class="p-4 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-xs text-center"><i class="fa-solid fa-triangle-exclamation mr-1.5"></i> Network error connecting to verification gateway.</div>';
                    }
                });
            });
        }

        function renderStatusResult(v) {
            if (!statusResultBox) return;

            var statusBadge = '';
            if (v.status === 'APPROVED') {
                statusBadge = '<span class="px-2.5 py-1 rounded bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 font-black text-xs font-mono">APPROVED</span>';
            } else if (v.status === 'PENDING_REVIEW' || v.status === 'IN_REVIEW') {
                statusBadge = '<span class="px-2.5 py-1 rounded bg-amber-500/20 text-amber-400 border border-amber-500/40 font-black text-xs font-mono">UNDER REVIEW</span>';
            } else {
                statusBadge = '<span class="px-2.5 py-1 rounded bg-slate-800 text-slate-300 border border-slate-700 font-black text-xs font-mono">' + v.status + '</span>';
            }

            var html = '<div class="p-4 bg-[#0a1424] border border-amber-500/30 rounded-xl space-y-3">' +
                '<div class="flex items-center justify-between border-b border-[#1c2b44] pb-2.5">' +
                '  <div>' +
                '    <span class="text-[10px] font-mono uppercase text-slate-400 block">Reference ID</span>' +
                '    <span class="font-mono font-bold text-white text-sm">' + v.reference_id + '</span>' +
                '  </div>' +
                '  <div>' + statusBadge + '</div>' +
                '</div>' +
                '<div class="grid grid-cols-2 gap-2 text-xs">' +
                '  <div><span class="text-slate-400 text-[10px] block">Tier Level</span><span class="text-amber-400 font-bold">' + v.tier + '</span></div>' +
                '  <div><span class="text-slate-400 text-[10px] block">Daily Limit</span><span class="text-emerald-400 font-mono font-bold">' + v.withdrawal_limit + '</span></div>' +
                '</div>' +
                '<div class="text-[11px] text-slate-300 bg-slate-900/80 p-2.5 rounded border border-[#1e2f47]">' +
                '  <span class="font-bold text-slate-400 block mb-0.5">Verification Remarks:</span>' + v.remarks +
                '</div>' +
                '</div>';

            statusResultBox.innerHTML = html;
        }

        // KYC Submission Modal
        var kycModal = document.getElementById('kycModal');
        var kycForm = document.getElementById('kycSubmissionForm');

        window.openKycModal = function () {
            if (kycModal) {
                kycModal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        };

        window.closeKycModal = function () {
            if (kycModal) {
                kycModal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        };

        if (kycForm) {
            kycForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var submitBtn = document.getElementById('kycSubmitBtn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Submitting Documents...';
                }

                var payload = {
                    full_name: document.getElementById('kycFullName') ? document.getElementById('kycFullName').value : '',
                    id_type: document.getElementById('kycIdType') ? document.getElementById('kycIdType').value : 'national_id',
                    id_number: document.getElementById('kycIdNumber') ? document.getElementById('kycIdNumber').value : '',
                    country_code: document.getElementById('kycCountryCode') ? document.getElementById('kycCountryCode').value : '+66',
                    phone_number: document.getElementById('kycPhone') ? document.getElementById('kycPhone').value : '',
                    birth_date: document.getElementById('kycBirthDate') ? document.getElementById('kycBirthDate').value : '1995-01-01'
                };

                fetch('/api/v1/public/verification/submit-kyc', {
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
                        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane mr-2"></i> Submit Verification Request';
                    }

                    if (data.success) {
                        var modalBody = document.getElementById('kycModalContent');
                        if (modalBody) {
                            modalBody.innerHTML = '<div class="text-center py-8 space-y-4">' +
                                '<div class="w-16 h-16 rounded-full bg-emerald-500/20 border-2 border-emerald-500 text-emerald-400 text-3xl flex items-center justify-center mx-auto"><i class="fa-solid fa-check"></i></div>' +
                                '<h3 class="text-xl font-black text-white font-[\'Cinzel\']">Verification Submitted Successfully!</h3>' +
                                '<p class="text-xs text-slate-300 max-w-sm mx-auto leading-relaxed">' + data.message + '</p>' +
                                '<div class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl inline-block">' +
                                '  <span class="text-[10px] text-slate-400 block font-mono">YOUR TRACKING ID</span>' +
                                '  <span class="text-lg font-mono font-black text-amber-400">' + data.reference_id + '</span>' +
                                '</div>' +
                                '<div class="pt-4">' +
                                '  <button onclick="closeKycModal()" class="px-6 py-2.5 rounded-lg text-xs font-bold text-slate-950 tl-gold-bg hover:brightness-110">Close & Return to Portal</button>' +
                                '</div>' +
                                '</div>';
                        }
                    } else {
                        alert(data.message || 'Submission failed. Please check your details.');
                    }
                })
                .catch(function (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane mr-2"></i> Submit Verification Request';
                    }
                    alert('Submission error. Please verify network connectivity.');
                });
            });
        }

        // File drop styling
        var dropZones = document.querySelectorAll('.kyc-dropzone');
        dropZones.forEach(function (zone) {
            zone.addEventListener('dragover', function (e) {
                e.preventDefault();
                zone.classList.add('border-amber-400', 'bg-amber-500/10');
            });
            zone.addEventListener('dragleave', function (e) {
                e.preventDefault();
                zone.classList.remove('border-amber-400', 'bg-amber-500/10');
            });
            zone.addEventListener('drop', function (e) {
                e.preventDefault();
                zone.classList.remove('border-amber-400', 'bg-amber-500/10');
                var files = e.dataTransfer.files;
                if (files.length > 0) {
                    var nameSpan = zone.querySelector('.file-name-display');
                    if (nameSpan) {
                        nameSpan.textContent = 'Selected: ' + files[0].name;
                        nameSpan.classList.add('text-emerald-400', 'font-bold');
                    }
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVerificationPortal);
    } else {
        initVerificationPortal();
    }
})();
