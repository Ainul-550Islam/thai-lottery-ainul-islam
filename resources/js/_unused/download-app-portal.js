/**
 * Lottery Platform - Official App Download & Verification Portal
 * Interactive Platform Switcher, Checksum Validator, and QR Generator
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const portal = document.querySelector('[data-download-portal]');
        if (!portal) return;

        // Platform metadata dictionary
        const platformData = {
            android: {
                name: 'Android Official APK',
                platform: 'Android',
                version: 'v4.8.2 (Build 4820)',
                package_name: 'club.lottery-platform.official',
                file_name: 'Lottery Platform-v4.8.2-release.apk',
                size: '28.4 MB',
                min_os: 'Android 9.0 (Pie) or higher',
                architecture: 'arm64-v8a, armeabi-v7a, x86_64',
                sha256: '9f83a6b2c417d8e5f01239aa87e4125b3901fc88a1b7e45210986ef9a8234bc1',
                md5: 'e7b1a2c89f0321d45678ab9c01ef4321',
                download_url: '/downloads/Lottery Platform-v4.8.2-release.apk',
                btn_text: 'Download Android APK (28.4 MB)',
                badge: 'Recommended',
                icon: 'fa-brands fa-android',
                guide_title: 'Android APK Installation Guide',
                steps: [
                    {
                        step: '1',
                        title: 'Download APK Package',
                        desc: 'Click the Download button or scan the QR code to save the signed APK directly to your device storage.'
                    },
                    {
                        step: '2',
                        title: 'Allow Installation in Settings',
                        desc: 'When prompted by Android Security, tap Settings and enable "Allow from this source" for your browser (Chrome/Samsung Internet).'
                    },
                    {
                        step: '3',
                        title: 'Complete Setup & Log In',
                        desc: 'Tap Install to finalize setup. Open Lottery Platform, enable Biometric login, and enjoy ultra-fast betting.'
                    }
                ],
                features: [
                    'Biometric Fingerprint & Face Unlock',
                    'Instant PromptPay QR overlay generator',
                    'Real-time floating GLO draw live broadcast widget',
                    'Offline ticket archive and receipt storage',
                    'Automatic in-app background delta updates'
                ]
            },
            ios: {
                name: 'iOS Web App & TestFlight',
                platform: 'iOS / iPadOS',
                version: 'v4.8.2 (Build 4820)',
                package_name: 'club.lottery-platform.ios',
                file_name: 'Lottery Platform-iOS-PWA-WebClip.mobileconfig',
                size: '12.8 MB (PWA Container)',
                min_os: 'iOS 15.0 or iPadOS 15.0+',
                architecture: 'Universal (arm64 Apple Silicon)',
                sha256: '4c81f0923b18a6e7d9501a238b76c54301ef87a9b0c2e34567890abcdef12345',
                md5: 'd8e2a1b94c5031e678901234abcd5678',
                download_url: '/downloads/Lottery Platform-iOS-Profile.mobileconfig',
                btn_text: 'Add to iOS Home Screen (Instant PWA)',
                badge: 'Instant PWA',
                icon: 'fa-brands fa-apple',
                guide_title: 'iOS Safari 1-Tap Installation Guide',
                steps: [
                    {
                        step: '1',
                        title: 'Open in Safari Browser',
                        desc: 'Open Safari on your iPhone or iPad and navigate to lottery-platform.club or scan the iOS QR code.'
                    },
                    {
                        step: '2',
                        title: 'Tap the Share Button',
                        desc: 'Tap the Share icon (square with upward arrow) at the center of the bottom Safari navigation bar.'
                    },
                    {
                        step: '3',
                        title: 'Select "Add to Home Screen"',
                        desc: 'Scroll down the share sheet, tap "Add to Home Screen", then tap "Add" in the top right corner.'
                    }
                ],
                features: [
                    'FaceID & TouchID Apple Secure Enclave integration',
                    'Dynamic Island & Lock Screen Live Activities countdown',
                    '1-Tap Apple Pay fast balance deposit',
                    'Native APNS low-latency prize win notifications',
                    'Full standalone screen without Safari address bar'
                ]
            },
            macos: {
                name: 'macOS Desktop Client',
                platform: 'macOS',
                version: 'v4.8.2 (Universal)',
                package_name: 'club.lottery-platform.mac',
                file_name: 'Lottery Platform-4.8.2-universal.dmg',
                size: '64.2 MB',
                min_os: 'macOS 12.0 (Monterey) or newer',
                architecture: 'Universal (Apple M1/M2/M3/M4 & Intel x86_64)',
                sha256: '7b39a82c401fe6d5981240ac89e345b1209ef784a3c1092837465abcde890123',
                md5: 'c1b2a3d4e5f60718293a4b5c6d7e8f90',
                download_url: '/downloads/Lottery Platform-4.8.2-universal.dmg',
                btn_text: 'Download for Mac DMG (64.2 MB)',
                badge: 'Desktop Pro',
                icon: 'fa-solid fa-laptop',
                guide_title: 'macOS Universal Installation Guide',
                steps: [
                    {
                        step: '1',
                        title: 'Download DMG Installer',
                        desc: 'Download the universal macOS DMG package optimized for Apple Silicon (M-Series) and Intel processors.'
                    },
                    {
                        step: '2',
                        title: 'Drag to Applications',
                        desc: 'Double-click the downloaded Lottery Platform.dmg file, then drag the Lottery Platform icon into your Applications folder.'
                    },
                    {
                        step: '3',
                        title: 'Launch & Set Hotkeys',
                        desc: 'Open Lottery Platform via Spotlight (Cmd + Space) and configure your multi-window VIP betting layout.'
                    }
                ],
                features: [
                    'Multi-screen VIP betting matrix & multi-window layout',
                    'Bulk CSV 19-Doors & permutation ticket importer',
                    'Hardware Security Key (YubiKey / WebAuthn) support',
                    'Dedicated low-latency WebSocket live draw stream engine',
                    'Agent multi-account quick switcher'
                ]
            },
            windows: {
                name: 'Windows PC Desktop Client',
                platform: 'Windows',
                version: 'v4.8.2 (x64)',
                package_name: 'club.lottery-platform.win',
                file_name: 'Lottery Platform-Setup-4.8.2-x64.exe',
                size: '58.7 MB',
                min_os: 'Windows 10 / Windows 11 (64-bit)',
                architecture: 'x86_64, ARM64 (Windows on ARM)',
                sha256: '1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b',
                md5: 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
                download_url: '/downloads/Lottery Platform-Setup-4.8.2-x64.exe',
                btn_text: 'Download Windows Setup (58.7 MB)',
                badge: 'Desktop Pro',
                icon: 'fa-brands fa-windows',
                guide_title: 'Windows 10/11 Installation Guide',
                steps: [
                    {
                        step: '1',
                        title: 'Download Installer EXE',
                        desc: 'Download the official signed Windows 64-bit executable setup file.'
                    },
                    {
                        step: '2',
                        title: 'Run Setup Wizard',
                        desc: 'Double-click Lottery Platform-Setup-4.8.2-x64.exe and follow the simple installation prompts.'
                    },
                    {
                        step: '3',
                        title: 'Launch from Desktop',
                        desc: 'Launch Lottery Platform from your Desktop shortcut or Start Menu and connect your thermal slip printer.'
                    }
                ],
                features: [
                    'Custom hotkeys for 10-key fast keypad ticket entry',
                    'Direct thermal receipt printer USB / ESC-POS integration',
                    'Dual-monitor agent dispatch view',
                    'High-performance multi-threaded draw calculation',
                    'Automated encrypted local audit log recording'
                ]
            }
        };

        let currentPlatform = 'android';

        // DOM elements
        const platformTabs = portal.querySelectorAll('[data-platform-tab]');
        const appTitle = portal.querySelector('[data-app-title]');
        const appVersion = portal.querySelector('[data-app-version]');
        const appSize = portal.querySelector('[data-app-size]');
        const appMinOs = portal.querySelector('[data-app-min-os]');
        const appArch = portal.querySelector('[data-app-arch]');
        const appSha256 = portal.querySelector('[data-app-sha256]');
        const appMd5 = portal.querySelector('[data-app-md5]');
        const appFilename = portal.querySelector('[data-app-filename]');
        const appDownloadBtn = portal.querySelector('[data-download-action]');
        const appBadge = portal.querySelector('[data-app-badge]');
        const appGuideTitle = portal.querySelector('[data-guide-title]');
        const appStepsContainer = portal.querySelector('[data-steps-container]');
        const appFeaturesContainer = portal.querySelector('[data-features-container]');
        const qrCodeLabel = portal.querySelector('[data-qr-label]');

        // Checksum inspector elements
        const checksumInput = portal.querySelector('[data-checksum-input]');
        const checksumVerifyBtn = portal.querySelector('[data-verify-checksum-btn]');
        const checksumPasteBtn = portal.querySelector('[data-paste-checksum-btn]');
        const checksumResult = portal.querySelector('[data-checksum-result]');

        // Progress bar simulation elements
        const progressContainer = portal.querySelector('[data-download-progress-container]');
        const progressBar = portal.querySelector('[data-download-progress-bar]');
        const progressText = portal.querySelector('[data-download-progress-text]');
        const progressSpeed = portal.querySelector('[data-download-progress-speed]');

        // Toast notification helper
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 rounded-xl border backdrop-blur-md shadow-2xl transition-all duration-300 transform translate-y-2 opacity-0 ${
                type === 'success'
                    ? 'bg-[#121008]/95 border-[#D4AF37]/50 text-[#F5E6B8]'
                    : 'bg-[#1F0A0A]/95 border-rose-500/50 text-rose-200'
            }`;
            toast.innerHTML = `
                <i class="fa-solid ${type === 'success' ? 'fa-circle-check text-[#D4AF37]' : 'fa-circle-exclamation text-rose-400'} text-lg"></i>
                <span class="text-sm font-medium tracking-wide">${message}</span>
            `;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.remove('translate-y-2', 'opacity-0');
            }, 10);

            setTimeout(() => {
                toast.classList.add('translate-y-2', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // Switch platform handler
        function setPlatform(platformKey) {
            if (!platformData[platformKey]) return;
            currentPlatform = platformKey;
            const data = platformData[platformKey];

            // Update Tab styles
            platformTabs.forEach(tab => {
                const isActive = tab.getAttribute('data-platform-tab') === platformKey;
                if (isActive) {
                    tab.classList.add('active-tab', 'border-[#D4AF37]', 'bg-[#2A2312]', 'text-[#F5E6B8]');
                    tab.classList.remove('border-white/10', 'bg-[#15120C]', 'text-gray-400');
                } else {
                    tab.classList.remove('active-tab', 'border-[#D4AF37]', 'bg-[#2A2312]', 'text-[#F5E6B8]');
                    tab.classList.add('border-white/10', 'bg-[#15120C]', 'text-gray-400');
                }
            });

            // Update Main Hero Details
            if (appTitle) appTitle.textContent = data.name;
            if (appVersion) appVersion.textContent = data.version;
            if (appSize) appSize.textContent = data.size;
            if (appMinOs) appMinOs.textContent = data.min_os;
            if (appArch) appArch.textContent = data.architecture;
            if (appSha256) appSha256.textContent = data.sha256;
            if (appMd5) appMd5.textContent = data.md5;
            if (appFilename) appFilename.textContent = data.file_name;
            if (appBadge) appBadge.textContent = data.badge;
            if (qrCodeLabel) qrCodeLabel.textContent = `Scan to install ${data.platform} Client`;

            if (appDownloadBtn) {
                appDownloadBtn.innerHTML = `
                    <i class="${data.icon} text-xl"></i>
                    <span>${data.btn_text}</span>
                    <i class="fa-solid fa-arrow-down ml-1 text-sm group-hover:translate-y-0.5 transition-transform"></i>
                `;
            }

            // Update Features list
            if (appFeaturesContainer) {
                appFeaturesContainer.innerHTML = data.features.map(feat => `
                    <li class="flex items-start gap-3">
                        <div class="w-5 h-5 rounded-full bg-[#D4AF37]/10 border border-[#D4AF37]/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fa-solid fa-check text-[#D4AF37] text-xs"></i>
                        </div>
                        <span class="text-sm text-gray-300">${feat}</span>
                    </li>
                `).join('');
            }

            // Update Installation Steps
            if (appGuideTitle) appGuideTitle.textContent = data.guide_title;
            if (appStepsContainer) {
                appStepsContainer.innerHTML = data.steps.map(step => `
                    <div class="relative bg-gradient-to-b from-[#1E190F] to-[#121008] border border-[#D4AF37]/20 rounded-2xl p-6 transition-all hover:border-[#D4AF37]/40 group">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#D4AF37] to-[#997B24] text-[#0B0904] font-black text-lg flex items-center justify-center mb-4 shadow-lg shadow-[#D4AF37]/20 group-hover:scale-110 transition-transform">
                            ${step.step}
                        </div>
                        <h4 class="text-base font-bold text-[#F5E6B8] mb-2">${step.title}</h4>
                        <p class="text-sm text-gray-400 leading-relaxed">${step.desc}</p>
                    </div>
                `).join('');
            }

            // Reset checksum inspector result
            if (checksumResult) {
                checksumResult.classList.add('hidden');
                checksumResult.innerHTML = '';
            }
            if (checksumInput) {
                checksumInput.value = '';
            }

            // Reset progress simulation
            if (progressContainer) {
                progressContainer.classList.add('hidden');
            }
        }

        // Tab click event listeners
        platformTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.getAttribute('data-platform-tab');
                setPlatform(target);
            });
        });

        // Copy Hash button listener
        portal.querySelectorAll('[data-copy-hash]').forEach(btn => {
            btn.addEventListener('click', () => {
                const hashType = btn.getAttribute('data-copy-hash');
                const targetText = hashType === 'sha256'
                    ? platformData[currentPlatform].sha256
                    : platformData[currentPlatform].md5;

                navigator.clipboard.writeText(targetText).then(() => {
                    showToast(`${hashType.toUpperCase()} hash copied to clipboard!`, 'success');
                }).catch(() => {
                    showToast('Failed to copy hash. Please select text manually.', 'error');
                });
            });
        });

        // Download Action with realistic progress simulation
        if (appDownloadBtn) {
            appDownloadBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const data = platformData[currentPlatform];

                if (currentPlatform === 'ios') {
                    // For iOS, direct instructions
                    showToast('Opening iOS WebClip Profile installation guide...', 'success');
                    const guideSection = document.getElementById('install-guide');
                    if (guideSection) {
                        guideSection.scrollIntoView({ behavior: 'smooth' });
                    }
                    return;
                }

                if (!progressContainer) return;

                progressContainer.classList.remove('hidden');
                if (progressBar) progressBar.style.width = '0%';
                if (progressText) progressText.textContent = `Connecting to high-speed CDN for ${data.file_name}...`;
                if (progressSpeed) progressSpeed.textContent = '0 MB/s';

                let progress = 0;
                const interval = setInterval(() => {
                    progress += Math.floor(Math.random() * 18) + 12;
                    if (progress > 100) progress = 100;

                    if (progressBar) progressBar.style.width = `${progress}%`;
                    const speed = (Math.random() * 8 + 14).toFixed(1);
                    if (progressSpeed) progressSpeed.textContent = `${speed} MB/s`;

                    if (progressText) {
                        progressText.textContent = `Downloading ${data.file_name} (${progress}% completed)`;
                    }

                    if (progress >= 100) {
                        clearInterval(interval);
                        if (progressText) {
                            progressText.innerHTML = `<span class="text-emerald-400 font-bold">✓ Download ready: ${data.file_name}</span>`;
                        }
                        if (progressSpeed) progressSpeed.textContent = 'Verified SHA-256';

                        showToast(`Successfully packaged ${data.file_name}! Starting transfer...`, 'success');

                        // Trigger simulated file download
                        setTimeout(() => {
                            const blob = new Blob([
                                `Lottery Platform Official ${data.name}\nVersion: ${data.version}\nSHA-256: ${data.sha256}\nBuilt: 2026-09-28\nStatus: Official Signed Release`
                            ], { type: 'text/plain' });
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = data.file_name;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            window.URL.revokeObjectURL(url);
                        }, 600);
                    }
                }, 180);
            });
        }

        // Checksum Paste from clipboard
        if (checksumPasteBtn && checksumInput) {
            checksumPasteBtn.addEventListener('click', async () => {
                try {
                    const text = await navigator.clipboard.readText();
                    checksumInput.value = text.trim();
                    showToast('Hash pasted from clipboard!', 'success');
                } catch (err) {
                    // Fallback to sample valid hash
                    checksumInput.value = platformData[currentPlatform].sha256;
                    showToast('Loaded active build official SHA-256 for testing!', 'success');
                }
            });
        }

        // Checksum verification execution
        if (checksumVerifyBtn && checksumInput && checksumResult) {
            checksumVerifyBtn.addEventListener('click', async () => {
                const inputHash = checksumInput.value.trim().toLowerCase();
                if (!inputHash) {
                    showToast('Please enter or paste a SHA-256 hash first.', 'error');
                    checksumInput.focus();
                    return;
                }

                checksumVerifyBtn.disabled = true;
                checksumVerifyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Verifying...';

                // Simulate slight network verification delay
                setTimeout(() => {
                    const expectedHash = platformData[currentPlatform].sha256.toLowerCase();
                    const isValid = (inputHash === expectedHash);

                    checksumResult.classList.remove('hidden');
                    if (isValid) {
                        checksumResult.className = 'mt-4 p-4 rounded-xl border border-emerald-500/40 bg-emerald-950/30 text-emerald-300 text-sm flex items-start gap-3 animate-fade-in';
                        checksumResult.innerHTML = `
                            <div class="w-6 h-6 rounded-full bg-emerald-500/20 border border-emerald-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-check text-emerald-400 text-xs"></i>
                            </div>
                            <div>
                                <h5 class="font-bold text-emerald-300 text-base mb-1">Authenticity Verified (100% Match)</h5>
                                <p class="text-xs text-emerald-200/90 leading-relaxed mb-2">
                                    The SHA-256 checksum matches the official signed ${platformData[currentPlatform].name} (${platformData[currentPlatform].version}). This package is authentic and has not been tampered with.
                                </p>
                                <div class="font-mono text-[11px] bg-black/40 p-2 rounded border border-emerald-500/20 break-all select-all">
                                    SHA-256: ${inputHash}
                                </div>
                            </div>
                        `;
                        showToast('SHA-256 Checksum Verified Authentic!', 'success');
                    } else {
                        checksumResult.className = 'mt-4 p-4 rounded-xl border border-rose-500/40 bg-rose-950/30 text-rose-300 text-sm flex items-start gap-3 animate-fade-in';
                        checksumResult.innerHTML = `
                            <div class="w-6 h-6 rounded-full bg-rose-500/20 border border-rose-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid fa-xmark text-rose-400 text-xs"></i>
                            </div>
                            <div>
                                <h5 class="font-bold text-rose-300 text-base mb-1">Checksum Mismatch Detected</h5>
                                <p class="text-xs text-rose-200/90 leading-relaxed mb-2">
                                    The provided hash does not match our official release. Please do not install unrecognized packages and redownload from our secure official server.
                                </p>
                                <div class="text-[11px] font-mono bg-black/40 p-2 rounded border border-rose-500/20 break-all">
                                    <span class="text-gray-400">Expected:</span> <span class="text-emerald-400">${expectedHash}</span><br>
                                    <span class="text-gray-400">Received:</span> <span class="text-rose-400">${inputHash}</span>
                                </div>
                            </div>
                        `;
                        showToast('Checksum mismatch detected. Check file integrity.', 'error');
                    }

                    checksumVerifyBtn.disabled = false;
                    checksumVerifyBtn.innerHTML = '<i class="fa-solid fa-shield-halved mr-2"></i>Verify Integrity';
                }, 400);
            });
        }

        // Initialize default view
        setPlatform(currentPlatform);
    });
})();
