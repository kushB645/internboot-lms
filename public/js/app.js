// M4 Dashboard - Railway MySQL API Integration
// Values available from the API are dynamic. Static descriptive UI text remains in dashboard.html.

document.addEventListener("DOMContentLoaded", async () => {
    try {
        const response = await fetch("api/dashboard.php", {
            method: "GET",
            headers: { "Accept": "application/json" }
        });

        if (response.status === 401) {
            handleUnauthenticated("Session expired or authentication required. Please <a href='login.php' style='color:#1652d6;text-decoration:underline;font-weight:700;'>log in as candidate</a> to access your dashboard.");
            return;
        }

        const payload = await response.json();

        const isSuccess = payload.status === "success";
        if (!response.ok || !isSuccess || !payload.data) {
            throw new Error(payload.message || "Unable to load dashboard data.");
        }

        const source = payload.data;

        if (source.exam && source.exam.attempt_id) { localStorage.setItem('ib_attempt_id', source.exam.attempt_id); }
        fillSection("candidate", source.candidate);
        fillSection("payment", source.payment);
        fillSection("enrollment", source.enrollment);
        fillSection("batch", source.batch);
        fillSection("exam", source.exam);
        fillSection("result", source.result);
        fillSection("certificate", source.certificate);
        fillSection("placement", source.placement);

        updateAvatar(source.candidate?.name);
        updateStatusCards(source);
        updateLearningJourney(source);
        renderCertificateState(source);
        renderProfileState(source);
        renderEnrollmentState(source);
        renderResultsState(source);
if (source.demo_mode) {
            let alertBox = document.getElementById('demo-mode-alert');
            if (!alertBox) {
                const contentDiv = document.querySelector('.content');
                if (contentDiv) {
                    alertBox = document.createElement('div');
                    alertBox.id = 'demo-mode-alert';
                    alertBox.className = 'alert alert-warning text-center fw-bold mb-4';
                    alertBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i> TEST MODE: Demo Mode Active. Payment data shown is simulated.';
                    contentDiv.insertBefore(alertBox, contentDiv.firstChild);
                }
            }
        }
    } catch (error) {
        console.error("Dashboard API Error:", error);

        const errorBox = document.querySelector("[data-api-error]");
        if (errorBox) {
            errorBox.textContent = "Unable to load dashboard data. Please try again.";
            errorBox.style.display = "block";
        }
    }
});

function handleUnauthenticated(message) {
    updateAvatar("—");
    document.querySelectorAll('[data-candidate="name"]').forEach((el) => {
        el.textContent = "Unauthenticated";
    });

    let alertBox = document.getElementById("dashboard-alert");
    if (!alertBox) {
        const content = document.querySelector(".content");
        if (content) {
            alertBox = document.createElement("div");
            alertBox.id = "dashboard-alert";
            alertBox.style.cssText = "margin-bottom: 20px; padding: 14px 18px; border: 1px solid #ef4444; background: #fef2f2; color: #991b1b; border-radius: 8px; font-size: 14px; line-height: 1.5;";
            content.insertBefore(alertBox, content.firstChild);
        }
    }
    if (alertBox) {
        alertBox.innerHTML = message || "Session expired or authentication required. Please <a href='login.php' style='color:#1652d6;text-decoration:underline;font-weight:700;'>log in as candidate</a> to access your dashboard.";
        alertBox.style.display = "block";
    }
}

function fillSection(attribute, data) {
    if (!data) return;

    document.querySelectorAll(`[data-${attribute}]`).forEach((el) => {
        const key = el.dataset[attribute];

        if (data[key] !== undefined && data[key] !== null) {
            el.textContent = data[key];
        }
    });
}

function updateAvatar(name) {
    const avatar = document.querySelector('[data-candidate="initials"]');
    if (!avatar || !name || name === "—") return;

    const initials = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join("");

    avatar.textContent = initials || "--";
}

function updateStatusCards(source) {
    document.querySelectorAll("[data-status]").forEach((el) => {
        const parts = el.dataset.status.split(".");
        if (parts.length === 2 && source[parts[0]] && source[parts[0]][parts[1]] !== undefined) {
            el.textContent = source[parts[0]][parts[1]];
        }
    });
}

function updateLearningJourney(source) {
    const statuses = {
        payment: source.payment?.status,
        enrollment: source.enrollment?.status,
        batch: source.batch?.status,
        exam: source.exam?.status,
        result: source.result?.status,
        certificate: source.certificate?.status,
        placement: source.placement?.applicable
            ? (source.placement.statusLabel || source.placement.status || 'Eligible')
            : 'Not Applicable'
    };

    Object.entries(statuses).forEach(([key, status]) => {
        const badge = document.querySelector(`[data-journey="${key}"]`);
        const step = document.querySelector(`[data-step="${key}"]`);
        if (!badge) return;

        badge.textContent = journeyLabel(key, status);
        badge.className = `badge ${journeyClass(status)}`;

        if (step) {
            step.classList.toggle("done", isCompleted(key, status));
        }
    });
}

function journeyLabel(key, status) {
    if (!status || status === "—") return "—";

    if (key === "payment") return status === "Paid" ? "Completed" : status;
    if (key === "enrollment") return status === "Enrolled" ? "Completed" : status;
    if (key === "batch") return status === "Assigned" ? "Completed" : status;
    if (key === "exam") return status === "Completed" ? "Completed" : status;
    if (key === "result") return status === "Completed" ? "Completed" : status;
    if (key === "certificate") return status === "Issued" ? "Completed" : status;
    if (key === "placement") return status;

    return status;
}

function journeyClass(status) {
    if (["Paid", "Enrolled", "Assigned", "Completed", "Issued", "Placed"].includes(status)) return "green";
    if (["Upcoming", "Scheduled", "In Progress", "Eligible", "Shortlisted", "Interviewing"].includes(status)) return "blue";
    if (["Not Applicable"].includes(status)) return "gray";
    return "gray";
}

function isCompleted(key, status) {
    return (
        (key === "payment" && status === "Paid") ||
        (key === "enrollment" && status === "Enrolled") ||
        (key === "batch" && status === "Assigned") ||
        (key === "exam" && status === "Completed") ||
        (key === "result" && status === "Completed") ||
        (key === "certificate" && status === "Issued") ||
        (key === "placement" && status === "Placed")
    );
}

function bindCandidateLogout() {
    document.querySelectorAll("a.logout, a[href*='logout.php']").forEach((link) => {
        if (link.dataset.logoutBound) return;
        link.dataset.logoutBound = "1";
        link.addEventListener("click", async (e) => {
            e.preventDefault();
            try {
                let token = null;
                try {
                    const csrfRes = await fetch("api/auth/csrf.php", {
                        credentials: "same-origin",
                        headers: { Accept: "application/json" }
                    });
                    const csrfData = await csrfRes.json();
                    token = csrfData.data?.token || null;
                } catch {}
                if (!token) {
                    const m7Res = await fetch("/api/admin/evaluate.php?action=csrf", {
                        credentials: "same-origin",
                        headers: { Accept: "application/json" }
                    });
                    const m7Data = await m7Res.json();
                    token = m7Data.data?.token || null;
                }
                await fetch("api/auth/logout.php", {
                    method: "POST",
                    headers: {
                        "Accept": "application/json",
                        "X-CSRF-Token": token || ""
                    }
                });
            } catch (err) {
                console.error("Logout failed:", err);
            } finally {
                window.location.href = "/login.php";
            }
        });
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bindCandidateLogout);
} else {
    bindCandidateLogout();
}

function renderCertificateState(source) {
    const certStateContainer = document.getElementById("certificate-state-container");
    const certPreviewContainer = document.getElementById("certificate-preview-container");
    const dashCertDownload = document.getElementById("dashboard-certificate-download");

    if (source.certificate && source.certificate.status === "Issued" && source.result && source.result.id) {
        const downloadUrl = `api/admin/certificate_pdf.php?result_id=${source.result.id}`;
        
        if (certStateContainer) {
            certStateContainer.innerHTML = `
                <div class='empty' style='padding: 40px;'>
                    <div style='font-size:42px'>🎓</div>
                    <h2 style='color:#17243a; margin-top: 10px;'>Certificate Issued</h2>
                    <p style='margin-bottom: 20px;'>Congratulations! Your certificate is ready to download.</p>
                    <a href="${downloadUrl}" class="button" target="_blank" style="background: #1652d6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">Download Certificate (PDF)</a>
                </div>
            `;
        }

        if (certPreviewContainer) {
            certPreviewContainer.style.display = "flex";
            const badge = document.getElementById("certificate-status-badge");
            if (badge) {
                badge.textContent = "Issued";
                badge.className = "badge green";
            }
        }

        if (dashCertDownload) {
            dashCertDownload.innerHTML = `<a href="${downloadUrl}" target="_blank" style="color: #1652d6; font-weight: bold;">(Download)</a>`;
        }
    } else {
        if (certStateContainer) {
            certStateContainer.innerHTML = `
                <div class='empty'>
                    <div style='font-size:42px'>🏅</div>
                    <h2 style='color:#17243a'>No Certificate Issued</h2>
                    <p>Your certificate will appear here after successful evaluation and level assignment.</p>
                </div>
            `;
        }
        if (certPreviewContainer) {
            certPreviewContainer.style.display = "none";
        }
        if (dashCertDownload) {
            dashCertDownload.innerHTML = "";
        }
    }
}


function renderResultsState(source) {
    if (!source || location.pathname.indexOf('results.html') === -1) return;
    
    const resultSection = document.querySelector('.card-pad .empty');
    const resultTitle = document.querySelector('.card-pad .section-title');
    if (!resultSection) return;

    if (source.result && source.result.evaluation === 'Evaluated') {
        resultSection.innerHTML = `<div style='font-size:42px; margin-bottom:10px;'>??</div><h2 style='color:#17243a'>Result Evaluated Successfully</h2><p>Your assessment has been fully evaluated. See your detailed score and level below.</p>`;
        resultSection.style.padding = '30px';
        if (resultTitle) resultTitle.textContent = 'Final Assessment Result';
    }
}

function renderProfileState(source) {
    if (!source) return;

    const levelBadge = document.getElementById("profile-level-badge") || document.querySelector("[data-result='level_assigned']");
    if (levelBadge) {
        const assignedLevel = source.result?.level_assigned ||
            (source.result?.level && source.result.level !== "—" ? source.result.level : null) ||
            source.candidate?.level_assigned ||
            (source.candidate?.level && source.candidate.level !== "—" ? source.candidate.level : null);

        if (assignedLevel) {
            levelBadge.textContent = assignedLevel;
            levelBadge.className = "badge blue";
        } else {
            levelBadge.textContent = "Not assigned yet";
            levelBadge.className = "badge gray";
        }
    }

    const profileBadge = document.getElementById("profile-status-badge") || document.querySelector("[data-candidate='profileStatus']");
    if (profileBadge) {
        const pStatus = source.candidate?.profileStatus;
        if (pStatus === "Verified") {
            profileBadge.textContent = "Verified";
            profileBadge.className = "badge green";
        } else if (pStatus) {
            profileBadge.textContent = pStatus;
            profileBadge.className = "badge gray";
        } else {
            profileBadge.textContent = "Basic Profile";
            profileBadge.className = "badge gray";
        }
    }
}

function renderEnrollmentState(source) {
    if (!source) return;

    const hero = document.getElementById("enrollment-hero");
    const statusBadge = document.getElementById("enrollment-status-badge");
    const nextStep = document.getElementById("enrollment-next-step");

    if (!hero && !statusBadge) return;

    const heroTitle = document.getElementById("enrollment-hero-title") || hero?.querySelector("h2");
    const heroDesc = document.getElementById("enrollment-hero-desc") || hero?.querySelector("p");

    const enrStatus = source.enrollment?.status;
    const payStatus = source.payment?.status;
    const hasEnrollmentId = source.enrollment?.id && source.enrollment.id !== "—";

    // 1. Confirmed (payment verified + enrollment created/active)
    if (enrStatus === "Enrolled" || enrStatus === "Confirmed" || enrStatus === "Active" || (hasEnrollmentId && enrStatus !== "Pending" && enrStatus !== "Not Enrolled")) {
        if (heroTitle) heroTitle.textContent = "Enrollment Confirmed";
        if (heroDesc) heroDesc.textContent = "Your payment has been verified and your enrollment has been created successfully.";
        if (statusBadge) {
            statusBadge.textContent = enrStatus && enrStatus !== "—" ? enrStatus : "Enrolled";
            statusBadge.className = "badge green";
        }
        if (nextStep) {
            nextStep.innerHTML = "Your next step is batch and slot assignment. You will see the details here once they are assigned.";
        }
    }
    // 2. Pending (payment done, enrollment processing)
    else if (enrStatus === "Pending" || (payStatus === "Paid" && !hasEnrollmentId)) {
        if (heroTitle) heroTitle.textContent = "Enrollment Pending";
        if (heroDesc) heroDesc.textContent = "Your payment has been received and your enrollment is currently being processed.";
        if (statusBadge) {
            statusBadge.textContent = "Pending";
            statusBadge.className = "badge yellow";
        }
        if (nextStep) {
            nextStep.innerHTML = "Your enrollment is pending verification. Please check back shortly once your batch is allocated.";
        }
    }
    // 3. Failed (payment not verified / failed)
    else if (payStatus === "Failed" || enrStatus === "Failed") {
        if (heroTitle) heroTitle.textContent = "Payment Failed";
        if (heroDesc) heroDesc.textContent = "Your payment could not be verified. Please complete your payment to proceed with enrollment.";
        if (statusBadge) {
            statusBadge.textContent = "Failed";
            statusBadge.className = "badge gray";
        }
        if (nextStep) {
            nextStep.innerHTML = 'Please visit <a href="payment.html" style="color:#1652d6;font-weight:700;">Payments</a> to retry your payment and complete your enrollment.';
        }
    }
    // 4. Not Started / Not Enrolled
    else {
        if (heroTitle) heroTitle.textContent = "Enrollment Not Started";
        if (heroDesc) heroDesc.textContent = "Please complete your payment to verify and confirm your enrollment.";
        if (statusBadge) {
            statusBadge.textContent = enrStatus && enrStatus !== "—" ? enrStatus : "Not Enrolled";
            statusBadge.className = "badge gray";
        }
        if (nextStep) {
            nextStep.innerHTML = 'Please visit <a href="payment.html" style="color:#1652d6;font-weight:700;">Payments</a> to pay the registration fee and start your enrollment.';
        }
    }
}
