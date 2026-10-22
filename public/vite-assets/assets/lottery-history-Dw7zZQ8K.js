var e=class{currentStatusFilter=`all`;currentMarketFilter=`all`;currentDrawDateFilter=``;searchQuery=``;csrfToken=``;constructor(){let e=document.querySelector(`meta[name="csrf-token"]`);this.csrfToken=e?.content||``,this.initEventListeners()}initEventListeners(){let e=document.querySelectorAll(`[data-lh-status]`);e.forEach(t=>{t.addEventListener(`click`,()=>{e.forEach(e=>e.classList.remove(`active`)),t.classList.add(`active`),this.currentStatusFilter=t.getAttribute(`data-lh-status`)||`all`,this.fetchAndRenderSlips()})});let t=document.getElementById(`marketSelectFilter`);t?.addEventListener(`change`,()=>{this.currentMarketFilter=t.value,this.fetchAndRenderSlips()});let n=document.getElementById(`drawDateSelectFilter`);n?.addEventListener(`change`,()=>{this.currentDrawDateFilter=n.value,this.fetchAndRenderSlips()});let r=document.getElementById(`historySearchInput`),i=null;r?.addEventListener(`input`,()=>{clearTimeout(i),i=setTimeout(()=>{this.searchQuery=r.value.trim(),this.fetchAndRenderSlips()},300)}),document.getElementById(`historyRefreshBtn`)?.addEventListener(`click`,()=>{this.fetchAndRenderSlips()})}async fetchAndRenderSlips(){try{let e=new URLSearchParams;this.currentStatusFilter!==`all`&&e.append(`status`,this.currentStatusFilter),this.currentMarketFilter!==`all`&&e.append(`market`,this.currentMarketFilter),this.currentDrawDateFilter&&e.append(`draw_date`,this.currentDrawDateFilter),this.searchQuery&&e.append(`q`,this.searchQuery);let t=await fetch(`/api/v1/player/history?${e.toString()}`,{headers:{Accept:`application/json`,"X-CSRF-TOKEN":this.csrfToken}});if(!t.ok)throw Error(`Failed to load history`);let n=await t.json();this.renderSlipsList(n.data||[])}catch(e){console.error(`Error fetching slips:`,e)}}renderSlipsList(e){let t=document.getElementById(`slipsContainer`);t&&(t.innerHTML=e.length===0?`
                <div class="lh-slip-card text-center py-12">
                    <div class="text-4xl mb-2">📜</div>
                    <div class="text-white font-bold text-base">No Lottery Wager Records Found</div>
                    <div class="text-slate-400 text-xs mt-1">Try adjusting your filters or date selection.</div>
                </div>
            `:e.map(e=>{let t=e.status===`won`?`won`:e.status===`lost`?`lost`:`pending`;return`
                <div class="lh-slip-card ${`status-${e.status}`}" id="slip-${e.slip_id}">
                    <div class="lh-slip-head">
                        <div class="lh-slip-id-wrap">
                            <span class="lh-slip-ref">${e.slip_id}</span>
                            <span class="lh-status-badge ${t}">${e.status_label}</span>
                        </div>
                        <div class="text-xs text-slate-400 font-mono">
                            Draw Date: <span class="font-bold text-white">${e.draw_date} (${e.draw_time})</span>
                        </div>
                    </div>

                    <div class="text-xs font-bold text-amber-400">
                        ${e.market_name}
                    </div>

                    <table class="lh-items-table">
                        <thead>
                            <tr>
                                <th>Play Type (ประเภท)</th>
                                <th>Number (เลข)</th>
                                <th>Stake (บาท)</th>
                                <th>Odds (จ่าย)</th>
                                <th>Result / Payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${e.items.map(e=>`
                                <tr>
                                    <td><span class="font-bold text-white">${e.play_label}</span></td>
                                    <td><span class="lh-num-badge">${e.number}</span></td>
                                    <td class="font-mono">${e.stake.toFixed(2)} ฿</td>
                                    <td class="font-mono text-amber-400">x${e.rate}</td>
                                    <td>
                                        ${e.status===`won`?`<span class="font-mono font-bold text-emerald-400">+${(e.payout||0).toLocaleString()} ฿</span>`:e.status===`lost`?`<span class="text-slate-500 font-bold">0.00 ฿</span>`:`<span class="text-amber-400 text-xs font-bold">Pending</span>`}
                                    </td>
                                </tr>
                            `).join(``)}
                        </tbody>
                    </table>

                    <div class="lh-slip-foot">
                        <div class="flex items-center gap-4 text-xs font-mono">
                            <div>Total Stake: <span class="font-bold text-white">${e.total_stake.toFixed(2)} THB</span></div>
                            <div>Total Payout: <span class="font-bold ${e.total_payout>0?`text-emerald-400`:`text-slate-400`}">${e.total_payout.toFixed(2)} THB</span></div>
                        </div>

                        <div class="lh-slip-actions">
                            ${e.can_cancel?`
                                <button type="button" class="lh-action-btn danger" onclick="window.lotteryHistory.cancelSlip('${e.slip_id}')">
                                    Cancel Slip
                                </button>
                            `:``}
                            <button type="button" class="lh-action-btn" onclick="window.lotteryHistory.rebetSlip('${e.slip_id}')">
                                🔁 Re-Bet (แทงซ้ำ)
                            </button>
                            <button type="button" class="lh-action-btn primary" onclick="window.lotteryHistory.openSlipDetailModal('${e.slip_id}')">
                                View Official Slip
                            </button>
                        </div>
                    </div>
                </div>
            `}).join(``))}async cancelSlip(e){if(confirm(`Are you sure you want to cancel and refund slip ${e}?`))try{let t=await(await fetch(`/api/v1/player/history/cancel/${e}`,{method:`POST`,headers:{"Content-Type":`application/json`,Accept:`application/json`,"X-CSRF-TOKEN":this.csrfToken}})).json();t.success?(alert(t.message),this.fetchAndRenderSlips()):alert(t.message||`Could not cancel slip`)}catch{alert(`Failed to cancel slip.`)}}async rebetSlip(e){try{let t=await(await fetch(`/api/v1/player/history/rebet/${e}`,{method:`POST`,headers:{"Content-Type":`application/json`,Accept:`application/json`,"X-CSRF-TOKEN":this.csrfToken}})).json();t.success&&t.redirect&&(window.location.href=t.redirect)}catch{alert(`Could not re-bet slip.`)}}openSlipDetailModal(e){let t=document.getElementById(`slipDetailModal`);t&&(t.style.display=`flex`)}closeSlipDetailModal(){let e=document.getElementById(`slipDetailModal`);e&&(e.style.display=`none`)}};document.addEventListener(`DOMContentLoaded`,()=>{window.lotteryHistory=new e});