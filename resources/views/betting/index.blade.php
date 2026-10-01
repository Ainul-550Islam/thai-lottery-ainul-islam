@extends('layouts.app')

@section('title', 'Thai Lottery Betting Terminal & Live Bet Slip | ThaiLotto')
@section('meta_description', 'Interactive luxury lottery betting terminal with real-time bet slip, 3D/2D permutations, 19 Doors generator, VIP discount rebates, and sub-100ms ticket submission.')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/thailotto-theme.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .gold-gradient-text {
            background: linear-gradient(135deg, #FFF6D6 0%, #D4AF37 50%, #AA7C11 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .gold-border-glow {
            box-shadow: 0 0 35px rgba(212, 175, 55, 0.15);
        }
        .bet-keypad-btn {
            background: linear-gradient(180deg, #1C170E 0%, #100D06 100%);
            border: 1px solid rgba(212, 175, 55, 0.2);
            color: #F5E6B8;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            transition: all 0.15s ease-in-out;
        }
        .bet-keypad-btn:hover {
            border-color: #D4AF37;
            background: linear-gradient(180deg, #2A2213 0%, #17130A 100%);
            color: #FFFFFF;
            box-shadow: 0 0 12px rgba(212, 175, 55, 0.25);
            transform: translateY(-1px);
        }
    </style>
@endpush

@section('content')
<main class="min-h-screen bg-[#0B0904] text-white pt-24 pb-20 px-4 sm:px-6 lg:px-8" data-betting-terminal>
    <div class="max-w-7xl mx-auto space-y-8">

        {{-- TOP BAR: USER BALANCE & DRAW COUNTDOWN --}}
        <section class="bg-gradient-to-r from-[#17130A] via-[#241D0E] to-[#17130A] border border-[#D4AF37]/30 rounded-2xl p-4 sm:p-6 shadow-xl flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#D4AF37] to-[#8C6D1F] flex items-center justify-center text-black font-black text-xl shadow-lg">
                    TL
                </div>
                <div>
                    <span class="text-xs font-bold text-[#D4AF37] uppercase tracking-wider block">VIP Member Terminal</span>
                    <h2 class="text-lg font-black text-[#F5E6B8]">Thai Government Lottery (GLO L6)</h2>
                </div>
            </div>

            <div class="flex items-center gap-6 text-sm">
                <div class="text-right">
                    <span class="text-[11px] text-gray-400 block">Available Cash Balance</span>
                    <span class="text-lg sm:text-xl font-black font-mono text-emerald-400">฿{{ number_format($userBalance ?? 12500, 2) }}</span>
                </div>
                <div class="h-10 w-px bg-white/10 hidden sm:block"></div>
                <div class="text-right hidden sm:block">
                    <span class="text-[11px] text-gray-400 block">Next Draw: 16 Oct 2026</span>
                    <span class="text-sm font-bold font-mono text-[#D4AF37]">Closing in 15d 11h</span>
                </div>
            </div>
        </section>

        {{-- MAIN TWO-COLUMN BETTING INTERFACE --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

            {{-- LEFT COLUMN: BET KEYPAD & GAME CONFIGURATOR (7 COLS) --}}
            <div class="lg:col-span-7 space-y-6">
                <div class="bg-[#141007] border border-[#D4AF37]/25 rounded-3xl p-6 sm:p-8 shadow-2xl space-y-6">
                    <div class="border-b border-[#D4AF37]/15 pb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-black text-[#F5E6B8] flex items-center gap-2">
                                <i class="fa-solid fa-calculator text-[#D4AF37]"></i>
                                Number Selection & Permutation Terminal
                            </h3>
                            <p class="text-xs text-gray-400 mt-0.5">Select bet type, input numbers, or use quick generation algorithms.</p>
                        </div>
                        <button type="button" data-bet-quickpick class="px-3 py-1.5 rounded-xl bg-[#221B0E] border border-[#D4AF37]/40 text-xs font-bold text-[#F5E6B8] hover:bg-[#D4AF37] hover:text-[#0B0904] transition-all flex items-center gap-1.5">
                            <i class="fa-solid fa-wand-magic-sparkles text-[#D4AF37]"></i>
                            <span>Quick Pick</span>
                        </button>
                    </div>

                    {{-- Bet Type Tabs --}}
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-[#D4AF37] uppercase tracking-wider">Bet Category</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <button type="button" data-bet-cat="3_up" class="py-2.5 px-3 rounded-xl border border-[#D4AF37] bg-[#2A2312] text-[#F5E6B8] font-bold text-xs transition-all flex flex-col items-center">
                                <span>3 Digits Top</span>
                                <span class="text-[10px] text-emerald-400 font-mono">฿900 Odds</span>
                            </button>
                            <button type="button" data-bet-cat="3_tod" class="py-2.5 px-3 rounded-xl border border-white/10 bg-[#0B0904] text-gray-400 hover:text-white font-bold text-xs transition-all flex flex-col items-center">
                                <span>3 Tod Permute</span>
                                <span class="text-[10px] text-gray-500 font-mono">฿150 Odds</span>
                            </button>
                            <button type="button" data-bet-cat="2_up" class="py-2.5 px-3 rounded-xl border border-white/10 bg-[#0B0904] text-gray-400 hover:text-white font-bold text-xs transition-all flex flex-col items-center">
                                <span>2 Digits Top</span>
                                <span class="text-[10px] text-gray-500 font-mono">฿95 Odds</span>
                            </button>
                            <button type="button" data-bet-cat="2_down" class="py-2.5 px-3 rounded-xl border border-white/10 bg-[#0B0904] text-gray-400 hover:text-white font-bold text-xs transition-all flex flex-col items-center">
                                <span>2 Digits Bottom</span>
                                <span class="text-[10px] text-gray-500 font-mono">฿95 Odds</span>
                            </button>
                        </div>
                    </div>

                    {{-- Number Input Slots --}}
                    <div class="space-y-2 text-center">
                        <label class="block text-xs font-bold text-gray-300">Selected Digit Slots</label>
                        <div class="flex items-center justify-center gap-3">
                            <input type="text" maxlength="1" data-slot="0" value="4" class="w-14 h-18 text-center font-black text-3xl font-mono rounded-2xl bg-[#2A2312] border-2 border-[#D4AF37] text-[#FFF6D6] shadow-xl focus:outline-none transition-all">
                            <input type="text" maxlength="1" data-slot="1" value="8" class="w-14 h-18 text-center font-black text-3xl font-mono rounded-2xl bg-[#141007] border border-white/20 text-[#FFF6D6] shadow-xl focus:outline-none transition-all">
                            <input type="text" maxlength="1" data-slot="2" value="2" class="w-14 h-18 text-center font-black text-3xl font-mono rounded-2xl bg-[#141007] border border-white/20 text-[#FFF6D6] shadow-xl focus:outline-none transition-all">
                        </div>
                    </div>

                    {{-- Quick Action Generators --}}
                    <div class="flex flex-wrap items-center justify-center gap-2 pt-1">
                        <button type="button" data-gen="19doors" class="px-3 py-1.5 rounded-lg bg-[#1C160B] border border-[#D4AF37]/30 text-xs font-semibold text-[#D4AF37] hover:bg-[#D4AF37]/10 transition-colors">
                            <i class="fa-solid fa-door-open mr-1"></i> 19 Doors Generator
                        </button>
                        <button type="button" data-gen="tod6" class="px-3 py-1.5 rounded-lg bg-[#1C160B] border border-[#D4AF37]/30 text-xs font-semibold text-[#D4AF37] hover:bg-[#D4AF37]/10 transition-colors">
                            <i class="fa-solid fa-shuffle mr-1"></i> 6 Tod Permutations
                        </button>
                        <button type="button" data-gen="reverse2d" class="px-3 py-1.5 rounded-lg bg-[#1C160B] border border-[#D4AF37]/30 text-xs font-semibold text-[#D4AF37] hover:bg-[#D4AF37]/10 transition-colors">
                            <i class="fa-solid fa-repeat mr-1"></i> Reverse 2D
                        </button>
                    </div>

                    {{-- Numeric Keypad --}}
                    <div class="bg-[#0B0904] border border-[#D4AF37]/20 rounded-2xl p-5">
                        <div class="grid grid-cols-3 gap-3">
                            <button type="button" data-key="1" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">1</button>
                            <button type="button" data-key="2" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">2</button>
                            <button type="button" data-key="3" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">3</button>

                            <button type="button" data-key="4" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">4</button>
                            <button type="button" data-key="5" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">5</button>
                            <button type="button" data-key="6" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">6</button>

                            <button type="button" data-key="7" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">7</button>
                            <button type="button" data-key="8" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">8</button>
                            <button type="button" data-key="9" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">9</button>

                            <button type="button" data-key="clear" class="py-3.5 rounded-xl bg-rose-950/40 border border-rose-500/30 text-rose-300 font-bold text-sm hover:bg-rose-900/50 transition-colors">
                                CLEAR
                            </button>
                            <button type="button" data-key="0" class="bet-keypad-btn py-3.5 rounded-xl text-2xl">0</button>
                            <button type="button" data-key="backspace" class="py-3.5 rounded-xl bg-[#1C160B] border border-[#D4AF37]/30 text-[#D4AF37] font-bold text-lg hover:bg-[#2A2312] transition-colors">
                                <i class="fa-solid fa-delete-left"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Stake Input & Add to Slip CTA --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                        <div>
                            <label class="block text-xs font-bold text-gray-400 mb-1">Stake / Line (฿ THB)</label>
                            <input type="number" min="1" step="10" value="100" data-bet-stake class="w-full bg-[#0B0904] border border-[#D4AF37]/30 rounded-xl px-4 py-2.5 text-base font-bold text-white font-mono focus:outline-none focus:border-[#D4AF37]">
                        </div>
                        <div class="pt-5 sm:pt-0">
                            <button type="button" data-add-to-slip class="w-full py-3.5 px-5 rounded-xl bg-gradient-to-r from-[#D4AF37] via-[#F3E5AB] to-[#AA7C11] text-[#0B0904] font-black text-sm shadow-xl shadow-[#D4AF37]/20 hover:brightness-110 active:scale-[0.99] transition-all flex items-center justify-center gap-2">
                                <i class="fa-solid fa-plus"></i>
                                <span>Add to Bet Slip</span>
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            {{-- RIGHT COLUMN: ACTIVE BET SLIP & SUBMISSION DRAWER (5 COLS) --}}
            <div class="lg:col-span-5 space-y-6">
                <div class="bg-[#141007] border border-[#D4AF37]/30 rounded-3xl p-6 sm:p-8 shadow-2xl space-y-6 gold-border-glow">
                    <div class="flex items-center justify-between border-b border-[#D4AF37]/15 pb-4">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-receipt text-[#D4AF37] text-lg"></i>
                            <h3 class="text-lg font-black text-[#F5E6B8]">Active Bet Slip</h3>
                        </div>
                        <span data-slip-count class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/30">
                            3 Items
                        </span>
                    </div>

                    {{-- Items List --}}
                    <div data-slip-items class="space-y-3 max-h-72 overflow-y-auto pr-1">
                        <div class="bg-[#0B0904] border border-[#D4AF37]/20 rounded-xl p-3.5 flex items-center justify-between text-xs">
                            <div>
                                <span class="font-bold text-[#F5E6B8] block text-sm font-mono">482 (3 Digits Top)</span>
                                <span class="text-gray-400">Odds: ฿900 • Payout: <strong class="text-emerald-400 font-mono">฿90,000.00</strong></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-bold font-mono text-white">฿100.00</span>
                                <button type="button" class="text-rose-400 hover:text-rose-300"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>

                        <div class="bg-[#0B0904] border border-[#D4AF37]/20 rounded-xl p-3.5 flex items-center justify-between text-xs">
                            <div>
                                <span class="font-bold text-[#F5E6B8] block text-sm font-mono">82 (2 Digits Top)</span>
                                <span class="text-gray-400">Odds: ฿95 • Payout: <strong class="text-emerald-400 font-mono">฿9,500.00</strong></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-bold font-mono text-white">฿100.00</span>
                                <button type="button" class="text-rose-400 hover:text-rose-300"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>

                        <div class="bg-[#0B0904] border border-[#D4AF37]/20 rounded-xl p-3.5 flex items-center justify-between text-xs">
                            <div>
                                <span class="font-bold text-[#F5E6B8] block text-sm font-mono">28 (2 Digits Bottom)</span>
                                <span class="text-gray-400">Odds: ฿95 • Payout: <strong class="text-emerald-400 font-mono">฿9,500.00</strong></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-bold font-mono text-white">฿100.00</span>
                                <button type="button" class="text-rose-400 hover:text-rose-300"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        </div>
                    </div>

                    {{-- Financial Breakdown --}}
                    <div class="border-t border-[#D4AF37]/15 pt-4 space-y-2 text-xs">
                        <div class="flex items-center justify-between text-gray-400">
                            <span>Gross Stake (3 Lines)</span>
                            <span class="font-mono text-white">฿300.00</span>
                        </div>
                        <div class="flex items-center justify-between text-gray-400">
                            <span>VIP Grade Discount (Gold 5.0%)</span>
                            <span class="font-mono text-emerald-400">-฿15.00</span>
                        </div>
                        <div class="flex items-center justify-between text-sm font-bold pt-2 border-t border-white/10">
                            <span class="text-[#F5E6B8]">Net Amount Due</span>
                            <span class="font-mono text-[#FFF6D6] text-base">฿285.00</span>
                        </div>
                        <div class="flex items-center justify-between text-xs text-gray-400">
                            <span>Max Potential Return</span>
                            <span class="font-mono text-emerald-400 font-bold">฿109,000.00</span>
                        </div>
                    </div>

                    {{-- Submit CTA --}}
                    <div class="space-y-2">
                        <button type="button" data-confirm-wager class="w-full py-4 px-6 rounded-2xl bg-gradient-to-r from-emerald-500 to-emerald-700 text-white font-black text-base shadow-xl hover:brightness-110 active:scale-[0.99] transition-all flex items-center justify-center gap-2">
                            <i class="fa-solid fa-shield-check"></i>
                            <span>Confirm & Place Wager (฿285.00)</span>
                        </button>
                        <p class="text-[11px] text-gray-400 text-center">Protected by AES-256 GCM cryptographic hash signing.</p>
                    </div>

                </div>
            </div>

        </div>

    </div>
</main>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const root = document.querySelector('[data-betting-terminal]');
            if (!root) return;

            const slots = root.querySelectorAll('[data-slot]');
            const keys = root.querySelectorAll('[data-key]');
            const quickPickBtn = root.querySelector('[data-bet-quickpick]');
            const confirmBtn = root.querySelector('[data-confirm-wager]');
            const addBtn = root.querySelector('[data-add-to-slip]');

            let currentSlot = 0;

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

                setTimeout(() => toast.classList.remove('translate-y-2', 'opacity-0'), 10);
                setTimeout(() => {
                    toast.classList.add('translate-y-2', 'opacity-0');
                    setTimeout(() => toast.remove(), 300);
                }, 3500);
            }

            function focusSlot(index) {
                if (index >= 0 && index < slots.length) {
                    currentSlot = index;
                    slots.forEach((s, i) => {
                        if (i === index) {
                            s.classList.add('border-[#D4AF37]', 'bg-[#2A2312]', 'scale-105');
                            s.classList.remove('border-white/20', 'bg-[#141007]');
                        } else {
                            s.classList.remove('border-[#D4AF37]', 'bg-[#2A2312]', 'scale-105');
                            s.classList.add('border-white/20', 'bg-[#141007]');
                        }
                    });
                }
            }

            slots.forEach((slot, idx) => {
                slot.addEventListener('click', () => focusSlot(idx));
            });

            keys.forEach(k => {
                k.addEventListener('click', () => {
                    const action = k.getAttribute('data-key');
                    if (action === 'clear') {
                        slots.forEach(s => s.value = '');
                        focusSlot(0);
                    } else if (action === 'backspace') {
                        slots[currentSlot].value = '';
                        if (currentSlot > 0) focusSlot(currentSlot - 1);
                    } else {
                        slots[currentSlot].value = action;
                        if (currentSlot < slots.length - 1) focusSlot(currentSlot + 1);
                    }
                });
            });

            if (quickPickBtn) {
                quickPickBtn.addEventListener('click', () => {
                    slots.forEach(s => s.value = Math.floor(Math.random() * 10).toString());
                    focusSlot(slots.length - 1);
                    showToast('Quick Pick 3D Generated!', 'success');
                });
            }

            if (addBtn) {
                addBtn.addEventListener('click', () => {
                    const num = Array.from(slots).map(s => s.value).join('');
                    showToast(`Added [${num}] to Bet Slip!`, 'success');
                });
            }

            if (confirmBtn) {
                confirmBtn.addEventListener('click', () => {
                    showToast('Wager Confirmed & Placed! Ticket ID: TKT-GLO-98241', 'success');
                });
            }

            focusSlot(0);
        });
    </script>
@endpush
