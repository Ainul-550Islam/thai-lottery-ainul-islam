<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Contact Customer Support & VIP Helpdesk — ThaiLotto Official</title>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/resources/css/thailotto-theme.css">
    <style>
        body {
            background-color: #050b14;
            color: #cbd5e1;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-image:
                radial-gradient(circle at 50% 0%, rgba(217, 119, 6, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(59, 130, 246, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 10% 80%, rgba(217, 119, 6, 0.04) 0%, transparent 50%);
            background-attachment: fixed;
        }
        .tl-gold-gradient {
            background: linear-gradient(135deg, #FFF6D6 0%, #F59E0B 50%, #B45309 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .tl-gold-bg {
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 50%, #92400E 100%);
        }
        .tl-card {
            background: linear-gradient(180deg, rgba(16, 27, 45, 0.85) 0%, rgba(10, 18, 30, 0.95) 100%);
            border: 1px solid rgba(217, 119, 6, 0.2);
            border-radius: 1.25rem;
            backdrop-filter: blur(12px);
            padding: 1.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .tl-card:hover {
            border-color: rgba(245, 158, 11, 0.4);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5), 0 0 20px 0 rgba(217, 119, 6, 0.1);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-amber-500/30 selection:text-amber-200">

    <!-- Top Navigation Bar -->
    <header class="sticky top-0 z-50 bg-[#070e1b]/90 backdrop-blur-md border-b border-amber-500/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="/" class="flex items-center gap-3 group">
                    <div class="w-11 h-11 rounded-xl tl-gold-bg flex items-center justify-center shadow-lg shadow-amber-500/20 group-hover:scale-105 transition-transform">
                        <i class="fa-solid fa-gem text-slate-950 text-xl font-black"></i>
                    </div>
                    <div>
                        <span class="text-xl font-black tracking-wider text-white uppercase font-['Cinzel'] block leading-none">Thai<span class="tl-gold-gradient">Lotto</span></span>
                        <span class="text-[10px] text-amber-400/80 font-mono tracking-widest uppercase">Support Center</span>
                    </div>
                </a>
            </div>

            <nav class="hidden md:flex items-center gap-8">
                <a href="/" class="text-sm font-semibold text-slate-300 hover:text-amber-400 transition-colors">Home</a>
                <a href="/about" class="text-sm font-semibold text-slate-300 hover:text-amber-400 transition-colors">About Us</a>
                <a href="/how-to-play" class="text-sm font-semibold text-slate-300 hover:text-amber-400 transition-colors">How to Play</a>
                <a href="/our-fees" class="text-sm font-semibold text-slate-300 hover:text-amber-400 transition-colors">Fees</a>
                <a href="/faq" class="text-sm font-semibold text-slate-300 hover:text-amber-400 transition-colors">FAQ</a>
                <a href="/contact" class="text-sm font-semibold text-amber-400 border-b-2 border-amber-400 pb-1">Contact Us</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="/login" class="px-5 py-2.5 rounded-lg text-sm font-bold text-white bg-slate-800/80 hover:bg-slate-700 border border-slate-700 transition-all">Sign In</a>
                <a href="/register" class="px-5 py-2.5 rounded-lg text-sm font-black text-slate-950 tl-gold-bg hover:brightness-110 shadow-lg shadow-amber-500/20 transition-all">Register</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow py-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
        <!-- Page Title & Hero Header -->
        <div class="text-center max-w-3xl mx-auto mb-14">
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-mono mb-4">
                <i class="fa-solid fa-headset"></i> 24/7/365 Dedicated Customer Care & VIP Concierge
            </div>
            <h1 class="text-3xl sm:text-4xl md:text-5xl font-black text-white font-['Cinzel'] tracking-tight mb-4">
                Contact <span class="tl-gold-gradient">Customer Support</span>
            </h1>
            <p class="text-slate-400 text-sm sm:text-base leading-relaxed">
                Have a question regarding deposits, ticket verification, or VIP white-glove jackpot claims? Our multi-lingual support team is ready to assist you immediately.
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-center gap-4 text-xs font-mono text-slate-400">
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-bolt text-emerald-400"></i> Average Response: &lt; 90 Seconds</span>
                <span class="hidden sm:inline">•</span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-shield-check text-amber-400"></i> Encrypted Support Desk</span>
                <span class="hidden sm:inline">•</span>
                <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-earth-americas text-cyan-400"></i> English & Thai Support</span>
            </div>
        </div>

        <!-- 4 Omnichannel Contact Cards Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-16">
            <!-- Telegram -->
            <a href="https://t.me/thailotto_official" target="_blank" class="tl-card group flex flex-col justify-between">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-sky-500/20 border border-sky-500/40 text-sky-400 flex items-center justify-center text-xl mb-4 group-hover:scale-105 transition-transform">
                        <i class="fa-brands fa-telegram"></i>
                    </div>
                    <span class="text-[10px] font-mono text-slate-400 uppercase font-bold block mb-1">Instant Messaging</span>
                    <h3 class="text-base font-black text-white mb-1 group-hover:text-amber-400">Telegram VIP Support</h3>
                    <p class="text-xs text-slate-400 leading-relaxed">Direct 1-on-1 assistance for deposits, payouts, and VIP accounts.</p>
                </div>
                <div class="mt-4 pt-3 border-t border-[#1c2b44] text-xs font-bold text-sky-400 flex items-center gap-1">
                    <span>@thailotto_official</span> <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </div>
            </a>

            <!-- LINE -->
            <a href="#" class="tl-card group flex flex-col justify-between">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 flex items-center justify-center text-xl mb-4 group-hover:scale-105 transition-transform">
                        <i class="fa-brands fa-line"></i>
                    </div>
                    <span class="text-[10px] font-mono text-slate-400 uppercase font-bold block mb-1">Official Chat</span>
                    <h3 class="text-base font-black text-white mb-1 group-hover:text-amber-400">LINE Official</h3>
                    <p class="text-xs text-slate-400 leading-relaxed">Add our verified official LINE bot for daily draw updates and alerts.</p>
                </div>
                <div class="mt-4 pt-3 border-t border-[#1c2b44] text-xs font-bold text-emerald-400 flex items-center gap-1">
                    <span>@thailotto</span> <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </div>
            </a>

            <!-- Email -->
            <a href="mailto:support@thailotto.club" class="tl-card group flex flex-col justify-between">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-amber-500/20 border border-amber-500/40 text-amber-400 flex items-center justify-center text-xl mb-4 group-hover:scale-105 transition-transform">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                    <span class="text-[10px] font-mono text-slate-400 uppercase font-bold block mb-1">Email Desk</span>
                    <h3 class="text-base font-black text-white mb-1 group-hover:text-amber-400">General Support</h3>
                    <p class="text-xs text-slate-400 leading-relaxed">Formal inquiries, ticket inquiries, and technical troubleshooting.</p>
                </div>
                <div class="mt-4 pt-3 border-t border-[#1c2b44] text-xs font-bold text-amber-400 flex items-center gap-1">
                    <span>support@thailotto.club</span> <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </div>
            </a>

            <!-- Operations HQ -->
            <div class="tl-card flex flex-col justify-between">
                <div>
                    <div class="w-12 h-12 rounded-xl bg-purple-500/20 border border-purple-500/40 text-purple-400 flex items-center justify-center text-xl mb-4">
                        <i class="fa-solid fa-building"></i>
                    </div>
                    <span class="text-[10px] font-mono text-slate-400 uppercase font-bold block mb-1">Headquarters</span>
                    <h3 class="text-base font-black text-white mb-1">Bangkok Operations</h3>
                    <p class="text-xs text-slate-400 leading-relaxed">Silom Complex Tower, 24th Floor, Silom Road, Bang Rak, Bangkok 10500.</p>
                </div>
                <div class="mt-4 pt-3 border-t border-[#1c2b44] text-[11px] font-mono text-slate-400">
                    Mon–Sun: 24/7 Operations
                </div>
            </div>
        </div>

        <!-- Ticket Dispatch Form & Ticket Tracker Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-16">
            <!-- Left: Ticket Dispatch Form -->
            <div class="lg:col-span-8">
                <div class="tl-card p-6 sm:p-8">
                    <div class="flex items-center gap-3 mb-6 border-b border-[#1c2b44] pb-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/20 border border-amber-500/40 text-amber-400 flex items-center justify-center text-lg">
                            <i class="fa-solid fa-paper-plane"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-white font-['Cinzel']">Submit a Support Ticket</h2>
                            <p class="text-xs text-slate-400">Directly dispatched to the appropriate department for rapid resolution.</p>
                        </div>
                    </div>

                    <form id="contactSupportForm" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1 font-mono">Full Legal Name</label>
                                <input type="text" id="contactName" placeholder="e.g. Somchai Prasert" required class="w-full bg-[#0a121e] border border-amber-500/30 rounded-lg px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-400">
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1 font-mono">Email or Mobile Number</label>
                                <input type="text" id="contactContact" placeholder="e.g. member@thailotto.club or 0812345678" required class="w-full bg-[#0a121e] border border-amber-500/30 rounded-lg px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-400">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1 font-mono">Department</label>
                                <select id="contactDepartment" class="w-full bg-[#0a121e] border border-amber-500/30 rounded-lg px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-amber-400">
                                    <option value="billing">Billing & Payment Settlements</option>
                                    <option value="verification">KYC & Account Verification</option>
                                    <option value="vip_concierge">VIP Concierge & 1st Prize Claims</option>
                                    <option value="technical">Technical Support & App Issues</option>
                                    <option value="affiliate">Affiliate & Business Partnerships</option>
                                    <option value="general" selected>General Inquiry</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1 font-mono">Priority Level</label>
                                <select id="contactPriority" class="w-full bg-[#0a121e] border border-amber-500/30 rounded-lg px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-amber-400">
                                    <option value="normal" selected>Normal Priority (Reply &lt; 15 mins)</option>
                                    <option value="high">High Priority (Reply &lt; 5 mins)</option>
                                    <option value="urgent">Urgent Priority (VIP / 1st Prize Winner)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1 font-mono">Subject</label>
                            <input type="text" id="contactSubject" placeholder="Brief summary of your inquiry..." required class="w-full bg-[#0a121e] border border-amber-500/30 rounded-lg px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-400">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1 font-mono">Message Details</label>
                            <textarea id="contactMessage" rows="4" placeholder="Please describe your question or issue in detail..." required class="w-full bg-[#0a121e] border border-amber-500/30 rounded-lg p-3.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-400"></textarea>
                        </div>

                        <div class="pt-2">
                            <button type="submit" id="contactSubmitBtn" class="w-full py-3.5 rounded-xl text-xs font-black text-slate-950 tl-gold-bg hover:brightness-110 shadow-lg shadow-amber-500/20 transition-all flex items-center justify-center gap-2">
                                <i class="fa-solid fa-paper-plane"></i> Submit Support Ticket
                            </button>
                        </div>
                    </form>

                    <!-- Feedback Output Container -->
                    <div id="contactResponseBox" class="mt-6 hidden"></div>
                </div>
            </div>

            <!-- Right: Support Ticket Tracker -->
            <div class="lg:col-span-4 flex flex-col justify-between">
                <div class="tl-card p-6 sm:p-7">
                    <div class="flex items-center gap-3 mb-5 border-b border-[#1c2b44] pb-3">
                        <div class="w-9 h-9 rounded-lg bg-cyan-500/20 text-cyan-400 flex items-center justify-center text-base">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-white font-['Cinzel']">Track Existing Ticket</h3>
                            <p class="text-[11px] text-slate-400">Look up ticket progress & officer replies.</p>
                        </div>
                    </div>

                    <form id="ticketTrackerForm" class="space-y-3">
                        <div>
                            <label class="block text-[10px] font-mono text-slate-400 uppercase mb-1">Ticket Reference ID</label>
                            <input type="text" id="trackerTicketId" placeholder="e.g. TKT-98421" required class="w-full bg-[#0a121e] border border-amber-500/30 rounded-lg px-3 py-2 text-xs font-mono text-white placeholder-slate-500 focus:outline-none focus:border-amber-400">
                        </div>

                        <button type="submit" class="w-full py-2.5 rounded-lg text-xs font-bold text-white bg-slate-800 hover:bg-slate-700 border border-slate-700 transition-colors">
                            <i class="fa-solid fa-satellite-dish mr-1"></i> Track Ticket Status
                        </button>
                    </form>

                    <div id="trackerResultBox" class="mt-4 hidden"></div>
                </div>

                <!-- Guarantee Card -->
                <div class="tl-card p-5 mt-6 text-xs text-slate-400">
                    <div class="flex items-center gap-2 font-bold text-amber-400 mb-1">
                        <i class="fa-solid fa-shield-halved"></i> 100% Confidentiality Guarantee
                    </div>
                    All submitted tickets and attachments are protected under strict Bank of Thailand financial confidentiality standards and PDPA privacy policies.
                </div>
            </div>
        </div>
    </main>

    <!-- Footer matching visual mockup -->
    <footer class="bg-[#050b14] border-t border-amber-500/20 pt-16 pb-12 mt-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10 pb-12 border-b border-[#1c2b44]">
                <div class="lg:col-span-2">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl tl-gold-bg flex items-center justify-center">
                            <i class="fa-solid fa-gem text-slate-950 font-black"></i>
                        </div>
                        <span class="text-xl font-black tracking-wider text-white uppercase font-['Cinzel']">Thai<span class="tl-gold-gradient">Lotto</span></span>
                    </div>
                    <p class="text-xs text-slate-400 leading-relaxed max-w-sm mb-6">
                        Thailand's premier digital lottery platform. Officially licensed and regulated digital gateway providing transparent, instant, and high-security draw access for global players.
                    </p>
                    <div class="flex items-center gap-3">
                        <a href="#" class="w-9 h-9 rounded-lg bg-slate-900 border border-slate-700 hover:border-amber-400 text-slate-400 hover:text-amber-400 flex items-center justify-center transition-colors"><i class="fa-brands fa-telegram"></i></a>
                        <a href="#" class="w-9 h-9 rounded-lg bg-slate-900 border border-slate-700 hover:border-amber-400 text-slate-400 hover:text-amber-400 flex items-center justify-center transition-colors"><i class="fa-brands fa-line"></i></a>
                        <a href="#" class="w-9 h-9 rounded-lg bg-slate-900 border border-slate-700 hover:border-amber-400 text-slate-400 hover:text-amber-400 flex items-center justify-center transition-colors"><i class="fa-brands fa-facebook-f"></i></a>
                        <a href="#" class="w-9 h-9 rounded-lg bg-slate-900 border border-slate-700 hover:border-amber-400 text-slate-400 hover:text-amber-400 flex items-center justify-center transition-colors"><i class="fa-brands fa-twitter"></i></a>
                    </div>
                </div>

                <div>
                    <h5 class="text-xs font-bold text-amber-400 uppercase tracking-widest font-mono mb-4">Quick Navigation</h5>
                    <ul class="space-y-2.5 text-xs text-slate-400">
                        <li><a href="/" class="hover:text-amber-400 transition-colors">Home Page</a></li>
                        <li><a href="/about" class="hover:text-amber-400 transition-colors">About ThaiLotto</a></li>
                        <li><a href="/vision-mission" class="hover:text-amber-400 transition-colors">Vision & Mission</a></li>
                        <li><a href="/how-to-play" class="hover:text-amber-400 transition-colors">How to Play</a></li>
                        <li><a href="/faq" class="hover:text-amber-400 transition-colors">Frequently Asked Questions</a></li>
                    </ul>
                </div>

                <div>
                    <h5 class="text-xs font-bold text-amber-400 uppercase tracking-widest font-mono mb-4">Legal & Compliance</h5>
                    <ul class="space-y-2.5 text-xs text-slate-400">
                        <li><a href="/terms-conditions" class="hover:text-amber-400 transition-colors">Terms & Conditions</a></li>
                        <li><a href="/privacy-policy" class="hover:text-amber-400 transition-colors">Privacy Policy</a></li>
                        <li><a href="/our-fees" class="hover:text-amber-400 transition-colors">Fee Schedules</a></li>
                        <li><a href="/prize-verification" class="hover:text-amber-400 transition-colors">Prize Verification</a></li>
                        <li><a href="/contact" class="text-amber-400 font-bold transition-colors">Contact Support</a></li>
                    </ul>
                </div>

                <div>
                    <h5 class="text-xs font-bold text-amber-400 uppercase tracking-widest font-mono mb-4">Security & Trust</h5>
                    <div class="space-y-3">
                        <div class="flex items-center gap-2 text-xs text-slate-300">
                            <i class="fa-solid fa-lock text-emerald-400"></i>
                            <span>256-Bit SSL Encryption</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-slate-300">
                            <i class="fa-solid fa-shield-halved text-amber-400"></i>
                            <span>GLO Certified Results</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-slate-300">
                            <i class="fa-solid fa-server text-blue-400"></i>
                            <span>99.99% Server Uptime</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-mono">
                <div>&copy; 2026 ThaiLotto Platform. All Rights Reserved. Regulated lottery entertainment service.</div>
                <div class="flex gap-6">
                    <a href="/terms-conditions" class="hover:text-slate-400">Terms</a>
                    <a href="/privacy-policy" class="hover:text-slate-400">Privacy</a>
                    <a href="/our-fees" class="hover:text-slate-400">Fees</a>
                    <a href="/contact" class="text-amber-400">Contact Us</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Runtime JavaScript -->
    <script src="/resources/js/contact-portal.js"></script>
</body>
</html>
