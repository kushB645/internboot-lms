/**
 * InternBoot - Batches & Slots Candidate Flow (M5 Integration)
 * Flow:
 *  1. Candidate sees all open slots admin has created (provisional)
 *  2. Each slot shows live preference count + progress bar
 *  3. Candidate picks a slot → preference saved
 *  4. Once 100+ prefer a slot → admin gets alert → creates batch → slot closed
 *  5. Closed slots are hidden; candidate sees their confirmed batch info
 */

let csrfToken = null;

async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag && metaTag.content) { csrfToken = metaTag.content; return csrfToken; }
    try {
        const res = await fetch("api/auth/csrf.php", { credentials: "same-origin", headers: { Accept: "application/json" } });
        const payload = await res.json();
        csrfToken = payload.data?.token || null;
    } catch {
        const res = await fetch("/api/admin/evaluate.php?action=csrf", { credentials: "same-origin", headers: { Accept: "application/json" } });
        const payload = await res.json();
        csrfToken = payload.data?.token || null;
    }
    if (!csrfToken) throw new Error("Security token could not be loaded.");
    return csrfToken;
}

function escapeHtml(str) {
    if (!str) return "";
    return String(str).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");
}

function formatHHMM(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    return parts.length >= 2 ? `${parts[0]}:${parts[1]}` : timeStr;
}

function getWeekdayLabel(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return '';
    return d.toLocaleDateString('en-US', { weekday: 'long' });
}

function formatDateLabel(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

document.addEventListener("DOMContentLoaded", async () => {
    await initSlotsModule();
});

async function initSlotsModule() {
    const noticeContainer = document.getElementById("notice-container");
    const slotsListEl = document.getElementById("slots-list");

    try {
        const response = await fetch("api/dashboard.php", { method: "GET", headers: { "Accept": "application/json" } });
        const payload = await response.json();
        if (!response.ok || payload.status !== "success" || !payload.data) {
            throw new Error(payload.message || "Failed to load candidate details.");
        }

        const data = payload.data;

        // Render Booked Slot status if already booked
        updateBookedSlotSection(data);

        // Check enrollment eligibility
        const isEligible = data.enrollment && (data.enrollment.eligibility_status === "eligible" || data.enrollment.status === "Enrolled");
        if (!isEligible) {
            if (noticeContainer) {
                noticeContainer.innerHTML = `
                    <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                        <strong>Action Required:</strong> Your registration fee payment or enrollment eligibility is pending.
                        Please complete your payment to unlock exam slot selection.
                        <a href="payment.html" class="btn btn-ib-primary btn-sm" style="margin-left:12px; background:#2563eb; color:#fff; padding:6px 12px; border-radius:6px; text-decoration:none; display:inline-block;">Go to Payment →</a>
                    </div>`;
            }
            if (slotsListEl) slotsListEl.innerHTML = `<p style="color:#60728b;">Slot selection unlocks automatically once your payment is completed.</p>`;
            return;
        }

        const assessmentId = data.assessment ? data.assessment.id : 1;

        // If candidate already has a confirmed batch, show that
        if (data.batch && data.batch.name && !data.batch.name.includes("Not Assigned")) {
            showConfirmedBatch(data, noticeContainer, slotsListEl);
            return;
        }

        // Load public slots for selection
        await loadPublicSlots(assessmentId);

    } catch (err) {
        console.error("Slots module error:", err);
        if (document.getElementById("notice-container")) {
            document.getElementById("notice-container").innerHTML = `
                <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                    ${escapeHtml(err.message || "Unable to load slot details.")}
                </div>`;
        }
    }
}

function showConfirmedBatch(data, noticeEl, slotsEl) {
    if (noticeEl) {
        noticeEl.innerHTML = `
            <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1px solid #6ee7b7; border-radius:12px; padding:20px 24px; margin-bottom:20px;">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">
                    <span style="font-size:28px;">🎉</span>
                    <div>
                        <div style="font-weight:800; font-size:18px; color:#065f46;">Batch Confirmed!</div>
                        <div style="color:#047857; font-size:14px;">You have been successfully assigned to a batch.</div>
                    </div>
                </div>
                <div style="background:#fff; border-radius:8px; padding:14px; margin-top:10px; font-size:15px; color:#1f2937; line-height:1.8;">
                    <strong>Batch:</strong> ${escapeHtml(data.batch.name || '—')}<br>
                    <strong>Exam Date:</strong> ${escapeHtml(data.exam?.exam_date || '—')}<br>
                    <strong>Slot:</strong> ${escapeHtml(data.exam?.slot_time || '—')}
                </div>
                <a href="exam.html" style="display:inline-block; margin-top:14px; background:#059669; color:#fff; padding:10px 22px; border-radius:8px; font-weight:700; text-decoration:none;">Go to Exam →</a>
            </div>`;
    }
    if (slotsEl) slotsEl.innerHTML = '';
}

async function loadPublicSlots(assessmentId) {
    const slotsListEl = document.getElementById("slots-list");
    if (!slotsListEl) return;

    try {
        const res = await fetch(`api/slots/public_slots.php?assessment_id=${encodeURIComponent(assessmentId)}`, {
            headers: { "Accept": "application/json" }
        });
        const payload = await res.json();
        if (!res.ok || payload.status !== 'success') throw new Error(payload.message || 'Failed to load slots.');

        const { slots, my_preference_id, batch_assigned, preference_failed } = payload.data;

        // If candidate is already assigned to a confirmed batch
        if (batch_assigned) {
            slotsListEl.innerHTML = `
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:20px; text-align:center;">
                    <div style="font-size:32px;">✅</div>
                    <h3 style="color:#047857; margin:10px 0 4px;">You're in! Batch Confirmed.</h3>
                    <p style="color:#065f46;">Exam on <strong>${escapeHtml(batch_assigned.exam_date)}</strong> · ${escapeHtml(formatHHMM(batch_assigned.start_time))} – ${escapeHtml(formatHHMM(batch_assigned.end_time))}</p>
                    <a href="exam.html" style="display:inline-block; margin-top:12px; background:#059669; color:#fff; padding:10px 22px; border-radius:8px; font-weight:700; text-decoration:none;">Go to Exam →</a>
                </div>`;
            return;
        }

        if (!slots || slots.length === 0) {
            slotsListEl.innerHTML = `
                <div style="text-align:center; padding:40px; color:#6b7280;">
                    <div style="font-size:40px; margin-bottom:12px;">📅</div>
                    <h3 style="color:#374151;">No Slots Available Right Now</h3>
                    <p>The admin hasn't opened any exam slots yet. Check back soon!</p>
                </div>`;
            return;
        }

        const threshold = slots[0]?.threshold || 100;

        // Header info
        let html = '';

        if (preference_failed) {
            html += `
                <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:16px 20px; margin-bottom:22px; display:flex; gap:14px; align-items:flex-start;">
                    <span style="font-size:28px;">⚠️</span>
                    <div>
                        <div style="font-weight:700; font-size:16px; color:#b91c1c;">Slot Cancelled</div>
                        <div style="color:#991b1b; font-size:14px; margin-top:4px; line-height:1.5;">
                            Your previously selected slot was cancelled because it did not reach the minimum required candidates. Please select a new slot below to continue.
                        </div>
                    </div>
                </div>`;
        }

        html += `
            <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1px solid #bfdbfe; border-radius:10px; padding:16px 20px; margin-bottom:22px; display:flex; gap:14px; align-items:flex-start;">
                <span style="font-size:28px;">📋</span>
                <div>
                    <div style="font-weight:700; font-size:16px; color:#1e40af;">How This Works</div>
                    <div style="color:#1d4ed8; font-size:14px; margin-top:4px; line-height:1.5;">
                        Choose your preferred exam slot below. The exam will only be conducted for a slot once a minimum of <strong>${threshold} candidates</strong> select it. Your final exam schedule will be confirmed once the batch is formed!
                        ${my_preference_id ? '<br>✅ <strong>You have already selected a slot.</strong> You can change it anytime before the batch is formed.' : ''}
                    </div>
                </div>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:18px;">`;

        slots.forEach(slot => {
            const count = slot.preference_count;
            const pct = slot.percentage;
            const isMine = slot.is_my_preference;
            const dayLabel = getWeekdayLabel(slot.exam_date);
            const dateFormatted = formatDateLabel(slot.exam_date);
            const startFmt = formatHHMM(slot.start_time);
            const endFmt = formatHHMM(slot.end_time);

            const progressColor = pct >= 100 ? '#10b981' : pct >= 70 ? '#f59e0b' : '#3b82f6';
            const cardBg = isMine ? 'linear-gradient(135deg,#ecfdf5,#d1fae5)' : '#ffffff';
            const cardBorder = isMine ? '#6ee7b7' : '#e5e7eb';

            html += `
                <div style="background:${cardBg}; border:2px solid ${cardBorder}; border-radius:12px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,0.06); position:relative; transition:all 0.2s;">
                    ${isMine ? '<div style="position:absolute;top:12px;right:12px;background:#10b981;color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;">YOUR CHOICE ✓</div>' : ''}
                    
                    <div style="font-weight:800; font-size:17px; color:#111827; margin-bottom:2px;">${escapeHtml(dayLabel)}</div>
                    <div style="font-size:13px; color:#6b7280; margin-bottom:10px;">${escapeHtml(dateFormatted)}</div>
                    
                    <div style="display:flex; align-items:center; gap:8px; font-size:15px; color:#374151; margin-bottom:14px;">
                        <span style="font-size:18px;">🕐</span>
                        <strong>${escapeHtml(startFmt)} – ${escapeHtml(endFmt)}</strong>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div style="margin-bottom:12px;">
                        <div style="display:flex; justify-content:space-between; font-size:13px; color:#6b7280; margin-bottom:5px;">
                            <span>👥 <strong style="color:#111827;">${count}</strong> candidates interested</span>
                            <span style="color:${progressColor}; font-weight:700;">${pct}%</span>
                        </div>
                        <div style="background:#e5e7eb; border-radius:99px; height:8px; overflow:hidden;">
                            <div style="height:100%; width:${Math.min(pct,100)}%; background:${progressColor}; border-radius:99px; transition:width 0.5s ease;"></div>
                        </div>
                        <div style="font-size:11px; color:#9ca3af; margin-top:4px; text-align:right;">${threshold - count > 0 ? `${threshold - count} more needed to form batch` : '✅ Ready for batch!'}</div>
                    </div>
                    
                    <!-- Action Button -->
                    ${isMine 
                        ? `<button disabled style="width:100%; background:#d1fae5; color:#047857; border:1px solid #6ee7b7; padding:10px; border-radius:8px; font-weight:700; font-size:14px; cursor:default;">✓ Currently Selected</button>`
                        : `<button class="btn-set-preference" 
                                data-schedule-id="${slot.schedule_id}" 
                                data-assessment-id="${slot.slot_id}"
                                data-schedule-ref="${slot.schedule_id}"
                                style="width:100%; background:#2563eb; color:#fff; border:none; padding:10px; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; transition:background 0.2s;"
                                onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                                Choose This Slot →
                           </button>`
                    }
                </div>`;
        });

        html += `</div>`;
        slotsListEl.innerHTML = html;

        slotsListEl.querySelectorAll(".btn-set-preference").forEach(btn => {
            btn.addEventListener("click", () => handleSetPreference(btn, assessmentId));
        });

    } catch (err) {
        slotsListEl.innerHTML = `<p style="color:#dc2626;">Error: ${escapeHtml(err.message)}</p>`;
    }
}

async function handleSetPreference(btn, assessmentId) {
    const scheduleId = parseInt(btn.dataset.scheduleRef || btn.dataset.scheduleId);
    const noticeContainer = document.getElementById("notice-container");

    if (!scheduleId || scheduleId <= 0) return;

    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = 'Saving...';

    try {
        const token = await getCsrfToken();
        const res = await fetch("api/slots/preference.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": token },
            body: JSON.stringify({ assessment_id: assessmentId, provisional_schedule_id: scheduleId })
        });
        const payload = await res.json();
        if (!res.ok || payload.status !== 'success') throw new Error(payload.message || 'Failed to save preference.');

        const count = payload.data?.preference_count || 0;
        const threshold = payload.data?.threshold || 100;

        if (noticeContainer) {
            noticeContainer.innerHTML = `
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:14px 18px; border-radius:8px; margin-bottom:18px; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:20px;">✅</span>
                    <div>
                        <strong>Preference Saved!</strong><br>
                        <span style="font-size:13px;">${count} / ${threshold} candidates have chosen this slot. ${count >= threshold ? '🎉 Batch ready for creation!' : `${threshold - count} more needed.`}</span>
                    </div>
                </div>`;
        }

        // Reload slots to show updated counts
        await loadPublicSlots(assessmentId);

    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = orig;
        if (noticeContainer) {
            noticeContainer.innerHTML = `
                <div style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                    ❌ ${escapeHtml(err.message)}
                </div>`;
        }
    }
}

function updateBookedSlotSection(dashData) {
    const bookedDateEl = document.getElementById("booked-exam-date");
    const bookedTimeEl = document.getElementById("booked-slot-time");
    const bookedStatusEl = document.getElementById("booked-slot-status");

    if (dashData.exam && dashData.exam.status && dashData.exam.status !== "🕓" && dashData.exam.status !== "Not Started") {
        if (bookedDateEl) bookedDateEl.textContent = dashData.exam.exam_date || "Scheduled";
        if (bookedTimeEl) bookedTimeEl.textContent = dashData.exam.slot_time || "Assigned Slot";
        if (bookedStatusEl) {
            bookedStatusEl.textContent = dashData.exam.status;
            bookedStatusEl.className = "badge green";
        }
    }
}
