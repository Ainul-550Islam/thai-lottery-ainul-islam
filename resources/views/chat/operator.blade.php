{{--
    Operator chat console.

    A single page the desk lives in: the waiting queue on the left, the
    active conversation on the right, and the desk summary above. All data
    arrives through the operator JSON API (see ChatOperatorController) —
    this template renders the shell and the operator JS does the rest.

    Access is enforced THREE times, and that layering is deliberate:
      1. route middleware: auth + can:access-admin,
      2. the controller's per-action permission checks,
      3. the fact that nothing on this page is server-rendered data —
         an unprivileged visitor who somehow loaded the template would see
         an empty shell, because every number comes from an authorized fetch.
--}}
@extends('layouts.app')

@section('title', __('chat.console_queue'))

@section('content')
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" id="tlco-mount"
         data-dashboard-url="{{ route('operator.chat.dashboard') }}"
         data-take-url="{{ route('operator.chat.take') }}"
         data-canned-url="{{ route('operator.chat.canned') }}"
         data-pause-url="{{ route('operator.chat.pause') }}"
         data-resume-url="{{ route('operator.chat.resume') }}"
         data-csrf="{{ csrf_token() }}">

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-emerald-100">{{ __('chat.console_queue') }}</h1>
            <div class="flex gap-2">
                <button type="button" id="tlco-take"
                        class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-emerald-50 font-semibold">
                    {{ __('chat.console_claim') }}
                </button>
                <button type="button" id="tlco-pause"
                        class="px-4 py-2 rounded-lg border border-slate-700 text-slate-300 hover:bg-slate-800">
                    ⏸
                </button>
                <button type="button" id="tlco-resume"
                        class="px-4 py-2 rounded-lg border border-slate-700 text-slate-300 hover:bg-slate-800">
                    ▶
                </button>
            </div>
        </div>

        {{-- Desk summary: presence + warnings. Populated by operator.js. --}}
        <div id="tlco-summary" class="mb-6 grid grid-cols-2 sm:grid-cols-5 gap-3"></div>
        <ul id="tlco-warnings" class="tlco-warnings mb-6"></ul>

        <div class="tlco">
            {{-- Left column: the queue ----------------------------------}}
            <div>
                <div class="tlco__card">
                    <div class="tlco__heading">{{ __('chat.console_queue') }}</div>
                    <table class="tlco-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Visitor</th>
                                <th>Priority</th>
                                <th>Waiting</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tlco-queue"></tbody>
                    </table>
                </div>

                <div class="tlco__card">
                    <div class="tlco__heading">{{ __('chat.console_workload') }}</div>
                    <table class="tlco-table">
                        <tbody id="tlco-workload"></tbody>
                    </table>
                </div>
            </div>

            {{-- Right column: the conversation ---------------------------}}
            <div class="tlco__card">
                <div class="tlco__heading" id="tlco-conv-title">—</div>

                <div id="tlco-thread" class="tlco-thread"></div>

                <div class="tlc-composer mt-3">
                    <textarea id="tlco-reply" class="flex-1" rows="2"
                              placeholder="{{ __('chat.composer_placeholder') }}"></textarea>
                    <div class="flex flex-col gap-1">
                        <button type="button" id="tlco-send" class="tlc-btn">Send</button>
                        <button type="button" id="tlco-note" class="tlc-btn tlc-btn--ghost"
                                title="{{ __('chat.console_note_placeholder') }}">Note</button>
                    </div>
                </div>

                <div class="tlco-actions">
                    <button type="button" id="tlco-close" class="tlc-btn tlc-btn--ghost">
                        {{ __('chat.console_close') }}
                    </button>
                    <button type="button" id="tlco-release" class="tlc-btn tlc-btn--ghost">
                        {{ __('chat.console_release') }}
                    </button>
                </div>

                <div id="tlco-canned" class="mt-4"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @vite(['resources/js/chat/operator.js'])
@endpush
