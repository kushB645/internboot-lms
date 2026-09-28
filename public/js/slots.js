/**
 * InternBoot - Batches & Slots Candidate Flow (M5 Integration)
 * Connects public/batches-slots.html to backend APIs:
 * - GET api/dashboard.php
 * - GET api/slots/available.php
 * - POST api/slots/book.php
 */
let csrfToken = null;

async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag && metaTag.content) {
        csrfToken = metaTag.content;
        return csrfToken;
    }
    try {
        const res = await fetch("api/auth/csrf.php", {
            credentials: "same-origin",
            headers: { Accept: "application/json" }
        });
        const payload = await res.json();
        csrfToken = payload.data?.token || null;
    } catch {
        const res = await fetch("/api/admin/evaluate.php?action=csrf", {
            credentials: "same-origin",
            headers: { Accept: "application/json" }
        });
        const payload = await res.json();
        csrfToken = payload.data?.token || null;
    }
    if (!csrfToken) throw new Error("Security token could not be loaded.");
    return csrfToken;
}

document.addEventListener("DOMContentLoaded", async () => {
    await initSlotsModule();
});

async function initSlotsModule() {
    const noticeContainer = document.getElementById("notice-container");
    const slotsListEl = document.getElementById("slots-list");

    try {
        const response = await fetch("api/dashboard.php", {
            method: "GET",
            headers: { "Accept": "application/json" }
        });

        const payload = await response.json();
        if (!response.ok || payload.status !== "success" || !payload.data) {
            throw new Error(payload.message || "Failed to load candidate details.");
        }

        const data = payload.data;

        // Render Batch Details
        if (data.batch) {
            const batchNameEl = document.getElementById("batch-name");
            const batchStatusEl = document.getElementById("batch-status-badge");
            const batchCandEl = document.getElementById("batch-candidates");

            if (batchNameEl) batchNameEl.textContent = data.batch.name || "Awaiting Formation";
            if (batchStatusEl) {
                batchStatusEl.textContent = data.batch.status || "Pending";
                batchStatusEl.className = `badge ${data.batch.status === "Assigned" ? "green" : "gray"}`;
            }
            if (batchCandEl) batchCandEl.textContent = data.batch.candidates || "—";
        }

        // Render Booked Slot status if already booked
        updateBookedSlotSection(data);

        // === If candidate already has a booked slot — hide booking UI entirely ===
        if (data.exam && data.exam.booked === true) {
            const slotsCard = document.getElementById("available-slots-card");
            if (slotsCard) {
                slotsCard.innerHTML = `
                    <div style="display:flex; align-items:center; gap:16px; padding:24px 28px;">
                        <div style="width:48px; height:48px; border-radius:14px; background:#ecfdf5; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div>
                            <div style="font-size:15px; font-weight:700; color:#166534; margin-bottom:3px;">Exam Slot Already Confirmed</div>
                            <div style="font-size:13px; color:#4b7a5e; line-height:1.5;">
                                Your slot is booked for <strong>${escapeHtml(data.exam.date || data.exam.exam_date || 'today')}</strong> at <strong>${escapeHtml(data.exam.slot_time || data.exam.time || '—')}</strong>.
                                No action needed — you will be notified before your exam.
                            </div>
                        </div>
                    </div>`;
            }
            return;
        }

        // Check enrollment eligibility
        const isEligible = data.enrollment && (data.enrollment.eligibility_status === "eligible" || data.enrollment.status === "Enrolled");
        if (!isEligible) {
            if (noticeContainer) {
                noticeContainer.innerHTML = `
                    <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                        <strong>Action Required:</strong> Your registration fee payment or enrollment eligibility is pending. 
                        Please complete your payment to unlock exam slot booking.
                        <a href="payment.html" class="btn btn-ib-primary btn-sm" style="margin-left:12px; background:#2563eb; color:#fff; padding:6px 12px; border-radius:6px; text-decoration:none; display:inline-block;">Go to Payment →</a>
                    </div>`;
            }
            if (slotsListEl) {
                slotsListEl.innerHTML = `<p style="color:#60728b;">Slot booking unlocks automatically once your payment is completed.</p>`;
            }
            return;
        }

        // Check if candidate is awaiting batch formation
        if (!data.batch || !data.batch.name || data.batch.name === "—") {
            const assessmentId = data.assessment ? data.assessment.id : 1;
            await loadPreferences(assessmentId);
            return;
        }

        // Fetch available slots
        const assessmentId = data.assessment ? data.assessment.id : 1;
        await loadAvailableSlots(assessmentId);

    } catch (err) {
        console.error("Slots module error:", err);
        if (noticeContainer) {
            noticeContainer.innerHTML = `
                <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                    ${escapeHtml(err.message || "Unable to load slot details.")}
                </div>`;
        }
    }
}

function updateBookedSlotSection(dashData) {
    const bookedDateEl = document.getElementById("booked-exam-date");
    const bookedTimeEl = document.getElementById("booked-slot-time");
    const bookedStatusEl = document.getElementById("booked-slot-status");

    const attemptId = localStorage.getItem("ib_attempt_id");

    if (dashData.exam && dashData.exam.status && dashData.exam.status !== "—" && dashData.exam.status !== "Not Started") {
        if (bookedDateEl) bookedDateEl.textContent = dashData.exam.exam_date || "Scheduled";
        if (bookedTimeEl) bookedTimeEl.textContent = dashData.exam.slot_time || "Assigned Slot";
        if (bookedStatusEl) {
            bookedStatusEl.textContent = dashData.exam.status;
            bookedStatusEl.className = "badge green";
        }
    } else if (attemptId) {
        if (bookedStatusEl) {
            bookedStatusEl.textContent = "Booked";
            bookedStatusEl.className = "badge green";
        }
    }
}

function getWeekdayLabel(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return '';
    return d.toLocaleDateString('en-US', { weekday: 'long' });
}

function formatHHMM(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    if (parts.length >= 2) {
        return `${parts[0]}:${parts[1]}`;
    }
    return timeStr;
}

async function loadAvailableSlots(assessmentId) {
    const slotsListEl = document.getElementById("slots-list");
    if (!slotsListEl) return;

    try {
        const response = await fetch(`api/slots/available.php?assessment_id=${encodeURIComponent(assessmentId)}`, {
            method: "GET",
            headers: { "Accept": "application/json" }
        });

        const payload = await response.json();
        if (!response.ok || payload.status !== "success" || !Array.isArray(payload.data)) {
            throw new Error(payload.message || "Failed to fetch available slots.");
        }

        const slots = payload.data;
        if (slots.length === 0) {
            slotsListEl.innerHTML = `<p style="color:#60728b;">No available slots found for your batch at this time.</p>`;
            return;
        }

        slotsListEl.innerHTML = `
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:16px;">
                ${slots.map(s => {
                    const dayLabel = getWeekdayLabel(s.exam_date);
                    const dateHeader = dayLabel ? `${dayLabel} (${s.exam_date || ''})` : (s.exam_date || '');
                    const startTimeFormatted = formatHHMM(s.start_time);
                    const endTimeFormatted = formatHHMM(s.end_time);
                    const isFull = s.seats_remaining <= 0;
                    return `
                    <div style="border:1px solid ${isFull ? '#f1f5f9' : '#dbeafe'}; border-radius:14px; padding:20px 22px; background:${isFull ? '#f8fafc' : '#fff'}; box-shadow:0 2px 12px rgba(37,99,235,0.06); transition:box-shadow 0.2s;">
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                            <div style="width:40px; height:40px; border-radius:10px; background:#eff6ff; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#2563eb' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='4' width='18' height='18' rx='2' ry='2'/><line x1='16' y1='2' x2='16' y2='6'/><line x1='8' y1='2' x2='8' y2='6'/><line x1='3' y1='10' x2='21' y2='10'/></svg>
                            </div>
                            <div>
                                <div style="font-weight:700; font-size:15px; color:#1e293b; line-height:1.3;">${escapeHtml(dateHeader)}</div>
                                <div style="font-size:13px; color:#64748b; margin-top:2px;">Exam Date</div>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; font-size:14px; color:#374151; background:#f8fafc; border-radius:8px; padding:10px 12px; margin-bottom:14px;">
                            <svg xmlns='http://www.w3.org/2000/svg' width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='#6366f1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'/><polyline points='12 6 12 12 16 14'/></svg>
                            <span style="font-weight:600;">${escapeHtml(startTimeFormatted)} – ${escapeHtml(endTimeFormatted)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; padding:4px 10px; border-radius:20px; background:${isFull ? '#f1f5f9' : '#eff6ff'}; color:${isFull ? '#94a3b8' : '#1d4ed8'};">
                                <span style="width:6px; height:6px; border-radius:50%; background:${isFull ? '#94a3b8' : '#2563eb'}; display:inline-block;"></span>
                                ${isFull ? 'Fully Booked' : s.seats_remaining + ' seats left'}
                            </span>
                            <button 
                                class="btn-book-slot" 
                                data-slot-id="${s.exam_slot_id}" 
                                data-assessment-id="${assessmentId}"
                                ${isFull ? 'disabled' : ''}
                                style="display:inline-flex; align-items:center; gap:7px; background:${isFull ? '#e2e8f0' : '#2563eb'}; color:${isFull ? '#94a3b8' : '#fff'}; border:none; padding:9px 18px; border-radius:9px; font-weight:700; font-size:13px; cursor:${isFull ? 'not-allowed' : 'pointer'}; transition:background 0.2s;"
                            >
                                <svg xmlns='http://www.w3.org/2000/svg' width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='4' width='18' height='18' rx='2' ry='2'/><line x1='16' y1='2' x2='16' y2='6'/><line x1='8' y1='2' x2='8' y2='6'/><line x1='3' y1='10' x2='21' y2='10'/><line x1='12' y1='14' x2='12' y2='18'/><line x1='10' y1='16' x2='14' y2='16'/></svg>
                                ${isFull ? 'Unavailable' : 'Book This Slot'}
                            </button>
                        </div>
                    </div>`;
                }).join('')}
            </div>`;

        slotsListEl.querySelectorAll(".btn-book-slot").forEach(btn => {
            btn.addEventListener("click", () => handleBookSlotClick(btn));
        });
        if (window.lucide && typeof window.lucide.createIcons === "function") {
            window.lucide.createIcons();
        }

    } catch (err) {
        slotsListEl.innerHTML = `<p style="color:#dc2626;">Error: ${escapeHtml(err.message)}</p>`;
    }
}

async function handleBookSlotClick(btn) {
    const slotId = btn.dataset.slotId;
    const assessmentId = btn.dataset.assessmentId;
    const noticeContainer = document.getElementById("notice-container");

    const parsedSlotId = Number(slotId);
    if (!slotId || isNaN(parsedSlotId) || !Number.isInteger(parsedSlotId) || parsedSlotId <= 0) {
        if (noticeContainer) {
            noticeContainer.innerHTML = `
                <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                    ❌ ${escapeHtml("Invalid slot selected")}
                </div>`;
        }
        return;
    }

    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = "Booking...";

    try {
        const token = await getCsrfToken();
        const response = await fetch("api/slots/book.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-CSRF-Token": token
            },
            body: JSON.stringify({
                assessment_id: Number(assessmentId),
                exam_slot_id: Number(slotId)
            })
        });

        const payload = await response.json();

        if (!response.ok || payload.status !== "success" || !payload.data) {
            throw new Error(payload.message || "Failed to book slot.");
        }

        const bookingData = payload.data;
        const attemptId = bookingData.attempt_id;

        if (attemptId) {
            localStorage.setItem("ib_attempt_id", attemptId);
        }

        if (noticeContainer) {
            noticeContainer.innerHTML = `
                <div class="notice notice-success" style="background:#e9f8f0; border:1px solid #c3edd7; color:#127249; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                    🎉 <strong>Slot Booked Successfully!</strong> Your exam attempt ID is #${escapeHtml(attemptId)}.
                    <a href="exam.html" class="btn btn-ib-primary btn-sm" style="margin-left:12px; background:#18a56a; color:#fff; padding:6px 12px; border-radius:6px; text-decoration:none; display:inline-block;">Go to Exam Page →</a>
                </div>`;
        }

        await loadAvailableSlots(assessmentId);

        document.querySelectorAll(".btn-book-slot").forEach(b => {
            b.disabled = true;
            b.textContent = "Already Booked";
            b.style.opacity = "0.6";
            b.style.cursor = "not-allowed";
        });

    } catch (err) {
        btn.disabled = false;
        btn.textContent = originalText;

        if (noticeContainer) {
            noticeContainer.innerHTML = `
                <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">
                    ❌ ${escapeHtml(err.message || "Booking failed.")}
                </div>`;
        }
    }
}

function escapeHtml(str) {
    if (!str) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}


async function loadPreferences(assessmentId) {

    const slotsListEl = document.getElementById("slots-list");

    if (!slotsListEl) return;

    const noticeContainer = document.getElementById("notice-container");



    try {

        const response = await fetch(`api/slots/preference.php?assessment_id=${encodeURIComponent(assessmentId)}`, {

            method: "GET",

            headers: { "Accept": "application/json" }

        });



        const payload = await response.json();

        if (!response.ok || payload.status !== "success") {

            throw new Error(payload.message || "Failed to fetch preference options.");

        }



        const data = payload.data;

        const currentPref = data.current_preference || {};

        const options = data.options || [];



        if (options.length === 0) {

            slotsListEl.innerHTML = `<p style="color:#60728b;">No available dates found for selection at this time.</p>`;

            return;

        }



        let html = `

            <div style="margin-bottom:20px; background:#f0f9ff; border:1px solid #bae6fd; padding:15px; border-radius:8px; color:#0369a1;">

                <strong>Batch Selection Mode:</strong> Please select your preferred exam date. Once 100 candidates choose the same date, your batch will be formed automatically!

            </div>

        `;

        

        if (currentPref.preferred_date && currentPref.preferred_time_slot) {

            const formattedTime = formatHHMM(currentPref.preferred_time_slot);

            html += `

                <div style="margin-bottom:24px; padding:16px; border:1px solid #10b981; border-radius:8px; background:#ecfdf5;">

                    <h3 style="margin:0 0 8px; color:#047857; font-size:16px;">Your Current Preference</h3>

                    <p style="margin:0; color:#065f46;"><strong>Date:</strong> ${escapeHtml(currentPref.preferred_date)} <br><strong>Time:</strong> ${escapeHtml(formattedTime)}</p>

                    <p style="margin:8px 0 0; font-size:13px; color:#047857;">Waiting for other candidates to select this slot...</p>

                </div>

                <h3 style="font-size:16px; margin-bottom:12px;">Change Preference</h3>

            `;

        }



        html += `<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:16px;">`;

        

        options.forEach(opt => {

            const dayLabel = getWeekdayLabel(opt.date);

            const dateHeader = dayLabel ? `${dayLabel} (${opt.date})` : opt.date;

            const timeParts = opt.time_slot.split('-');

            const startTimeFormatted = formatHHMM(timeParts[0]);

            const endTimeFormatted = formatHHMM(timeParts[1]);

            

            const isCurrent = currentPref.preferred_date === opt.date && currentPref.preferred_time_slot === opt.time_slot;

            

            html += `

            <div style="border:1px solid ${isCurrent ? '#10b981' : '#e5ebf2'}; border-radius:10px; padding:18px; background:${isCurrent ? '#ecfdf5' : '#ffffff'}; box-shadow:0 2px 8px rgba(0,0,0,0.03);">

                <div style="font-weight:700; font-size:16px; color:#17243a; margin-bottom:6px;">

                    ${escapeHtml(dateHeader)}

                </div>

                <div style="font-size:14px; color:#4b5563; margin-bottom:10px;">

                    ��� ${escapeHtml(startTimeFormatted)} ��   ${escapeHtml(endTimeFormatted)}

                </div>

                <div style="display:flex; justify-content:flex-end; margin-top:12px;">

                    ${isCurrent ? 

                        `<span style="color:#10b981; font-weight:700; display:flex; align-items:center;">�S&  Selected</span>` : 

                        `<button 

                            class="btn-set-preference" 

                            data-date="${opt.date}" 

                            data-time="${opt.time_slot}"

                            data-assessment-id="${assessmentId}"

                            style="background:#2563eb; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:700; cursor:pointer;"

                        >

                            Choose This Slot

                        </button>`

                    }

                </div>

            </div>`;

        });

        

        html += `</div>`;

        slotsListEl.innerHTML = html;



        slotsListEl.querySelectorAll(".btn-set-preference").forEach(btn => {

            btn.addEventListener("click", () => handleSetPreferenceClick(btn));

        });



    } catch (err) {

        slotsListEl.innerHTML = `<p style="color:#dc2626;">Error: ${escapeHtml(err.message)}</p>`;

    }

}



async function handleSetPreferenceClick(btn) {

    const assessmentId = btn.dataset.assessmentId;

    const date = btn.dataset.date;

    const time = btn.dataset.time;

    const noticeContainer = document.getElementById("notice-container");



    btn.disabled = true;

    btn.textContent = "Saving...";



    try {

        const token = await getCsrfToken();

        const response = await fetch("api/slots/preference.php", {

            method: "POST",

            headers: {

                "Content-Type": "application/json",

                "Accept": "application/json",

                "X-CSRF-Token": token

            },

            body: JSON.stringify({

                assessment_id: Number(assessmentId),

                preferred_date: date,

                preferred_time_slot: time

            })

        });



        const payload = await response.json();



        if (!response.ok || payload.status !== "success") {

            throw new Error(payload.message || "Failed to save preference.");

        }



        if (noticeContainer) {

            noticeContainer.innerHTML = `

                <div class="notice notice-success" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:14px 18px; border-radius:8px; margin-bottom:18px;">

                    �S&  Preference saved! You will be allocated to a batch once 100 candidates choose this date.

                </div>`;

        }



        await loadPreferences(assessmentId);



    } catch (err) {

        btn.disabled = false;

        btn.textContent = "Choose This Slot";

        if (noticeContainer) {

            noticeContainer.innerHTML = `

                <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:14px 18px; border-radius:8px; margin-bottom:18px;">

                    ��R ${escapeHtml(err.message)}

                </div>`;

        }

    }

}

