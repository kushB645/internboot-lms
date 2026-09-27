/**
 * InternBoot - Exam Status Handler (M6 Integration)
 * Connects public/exam.html to backend API:
 * - GET api/exam/exam_status.php
 */
document.addEventListener("DOMContentLoaded", async () => {
    await initExamStatusModule();
});

async function initExamStatusModule() {
    const container = document.getElementById("exam-details-body");
    if (!container) return;

    const attemptId = localStorage.getItem("ib_attempt_id");

    if (!attemptId || isNaN(Number(attemptId)) || Number(attemptId) <= 0) {
        container.innerHTML = `
            <div class="notice notice-info" style="background:#edf4ff; border:1px solid #d4e4ff; color:#1c52b8; padding:24px; border-radius:10px; text-align:center;">
                <h2 style="margin:0 0 10px; color:#17243a; font-size:20px;">No Slot Booked Yet</h2>
                <p style="margin:0 0 16px; color:#4b5563;">You haven't booked an exam slot yet. Please select an available slot from your assigned batch.</p>
                <a href="batches-slots.html" class="btn btn-ib-primary" style="background:#2563eb; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none; display:inline-block; font-weight:700;">Go to Batches &amp; Slots →</a>
            </div>`;
        return;
    }

    try {
        const response = await fetch(`api/exam/exam_status.php?attempt_id=${encodeURIComponent(attemptId)}`, {
            method: "GET",
            headers: { "Accept": "application/json" }
        });

        const payload = await response.json();

        if (!response.ok || payload.status !== "success" || !payload.data) {
            container.innerHTML = `
                <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:20px; border-radius:10px; text-align:center;">
                    <h3 style="margin:0 0 10px; font-size:18px;">Attempt Access Error</h3>
                    <p style="margin:0 0 16px;">${escapeHtml(payload.message || "Attempt not found or access denied.")}</p>
                    <a href="batches-slots.html" class="btn btn-ib-primary" style="background:#2563eb; color:#fff; padding:8px 16px; border-radius:6px; text-decoration:none; display:inline-block; font-weight:700;">Book a New Slot →</a>
                </div>`;
            return;
        }

        const data = payload.data;
        const status = data.status;
        const remainingSec = Number(data.remaining_seconds) || 0;

        // 1. Exam in progress & active time remaining -> Resume Exam
        if (status === "in_progress" && data.start_time && remainingSec > 0) {
            const minutes = Math.floor(remainingSec / 60);
            const seconds = remainingSec % 60;
            const formattedTimer = `${minutes}m ${seconds}s`;

            container.innerHTML = `
                <div class="grid4" style="margin-bottom:20px;">
                    <div class="card stat"><label>Attempt ID</label><strong>#${data.attempt_id}</strong></div>
                    <div class="card stat"><label>Status</label><strong class="blue">In Progress</strong></div>
                    <div class="card stat"><label>Time Remaining</label><strong style="color:#2563eb;">${formattedTimer}</strong></div>
                    <div class="card stat"><label>Answered</label><strong>${data.answered_count} / ${data.total_questions}</strong></div>
                </div>
                <section class="card card-pad" style="text-align:center; padding:35px 20px;">
                    <h2 class="section-title" style="font-size:22px; margin-bottom:10px;">Exam Session Active</h2>
                    <p style="color:#4b5563; margin-bottom:24px;">Your exam timer is currently running. You can resume your assessment anytime before the timer expires.</p>
                    <a href="take-exam.php?attempt_id=${data.attempt_id}" class="btn btn-ib-primary" style="background:#2563eb; color:#fff; padding:12px 28px; border-radius:8px; font-size:16px; font-weight:700; text-decoration:none; display:inline-block;">Resume Exam →</a>
                </section>`;
            return;
        }

        // 2. Exam submitted or expired -> Show final status, no start button, link to results
        if (status === "submitted" || status === "expired") {
            const isSubmitted = status === "submitted";
            container.innerHTML = `
                <div class="grid4" style="margin-bottom:20px;">
                    <div class="card stat"><label>Attempt ID</label><strong>#${data.attempt_id}</strong></div>
                    <div class="card stat"><label>Final Status</label><strong class="${isSubmitted ? 'green' : 'gray'}">${isSubmitted ? 'Submitted' : 'Expired'}</strong></div>
                    <div class="card stat"><label>Questions Answered</label><strong>${data.answered_count} / ${data.total_questions}</strong></div>
                    <div class="card stat"><label>Completed At</label><strong style="font-size:16px;">${escapeHtml(data.submitted_at || "Completed")}</strong></div>
                </div>
                <section class="card card-pad" style="text-align:center; padding:35px 20px;">
                    <h2 class="section-title" style="font-size:22px; margin-bottom:10px;">${isSubmitted ? 'Assessment Completed' : 'Assessment Expired'}</h2>
                    <p style="color:#4b5563; margin-bottom:24px;">${isSubmitted ? 'Your answers have been submitted for evaluation.' : 'Your exam session ended.'} You can view your evaluated score and certificate level on the Results page.</p>
                    <a href="results.html" class="btn btn-ib-primary" style="background:#18a56a; color:#fff; padding:12px 28px; border-radius:8px; font-size:16px; font-weight:700; text-decoration:none; display:inline-block;">View Results →</a>
                </section>`;
            return;
        }

        // 3. Attempt exists but not started -> Decide between "Ready to Start" and "Exam Window Locked" from can_start and gate_message
        if (data.can_start) {
            container.innerHTML = `
                <div class="grid4" style="margin-bottom:20px;">
                    <div class="card stat"><label>Attempt ID</label><strong>#${data.attempt_id}</strong></div>
                    <div class="card stat"><label>Status</label><strong class="blue">Ready to Start</strong></div>
                    <div class="card stat"><label>Questions</label><strong>${data.total_questions}</strong></div>
                    <div class="card stat"><label>Access</label><strong class="green">Slot Active</strong></div>
                </div>
                <section class="card card-pad" style="text-align:center; padding:35px 20px;">
                    <div style="font-size:48px; margin-bottom:10px;">📝</div>
                    <h2 class="section-title" style="font-size:22px; margin-bottom:10px;">Your Exam Slot is Now Open</h2>
                    <p style="color:#4b5563; margin-bottom:24px;">Click below to launch the assessment engine. Once started, your assessment timer will run continuously.</p>
                    <a href="take-exam.php?attempt_id=${data.attempt_id}" class="btn btn-ib-primary" style="background:#2563eb; color:#fff; padding:12px 32px; border-radius:8px; font-size:16px; font-weight:700; text-decoration:none; display:inline-block;">Start Exam →</a>
                </section>`;
        } else {
            const gateMessage = data.gate_message || "Your exam slot is not open yet.";
            container.innerHTML = `
                <div class="grid4" style="margin-bottom:20px;">
                    <div class="card stat"><label>Attempt ID</label><strong>#${data.attempt_id}</strong></div>
                    <div class="card stat"><label>Status</label><strong style="color:#b77900;">Scheduled</strong></div>
                    <div class="card stat"><label>Questions</label><strong>${data.total_questions}</strong></div>
                    <div class="card stat"><label>Access</label><strong style="color:#66768a;">Locked</strong></div>
                </div>
                <section class="card card-pad" style="text-align:center; padding:35px 20px;">
                    <div style="font-size:42px; margin-bottom:10px;">🔒</div>
                    <h2 class="section-title" style="font-size:22px; margin-bottom:10px;">Exam Window Locked</h2>
                    <div class="notice" style="background:#fff7df; border:1px solid #fce8ad; color:#b77900; padding:14px 18px; border-radius:8px; margin:0 auto 24px; max-width:550px;">
                        <strong>${escapeHtml(gateMessage)}</strong>
                    </div>
                    <button class="btn btn-secondary" disabled style="background:#e5e7eb; color:#9ca3af; padding:12px 28px; border-radius:8px; font-size:16px; font-weight:700; border:none; cursor:not-allowed;">
                        Exam Not Open Yet
                    </button>
                </section>`;
        }

    } catch (err) {
        console.error("Exam status error:", err);
        container.innerHTML = `
            <div class="notice notice-error" style="background:#fdf2f2; border:1px solid #f8cdcd; color:#b91c1c; padding:20px; border-radius:10px; text-align:center;">
                <p style="margin:0;">${escapeHtml(err.message || "Failed to load exam status.")}</p>
            </div>`;
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
