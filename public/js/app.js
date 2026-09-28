// M4 Dashboard - Railway MySQL API Integration
// Values available from the API are dynamic. Static descriptive UI text remains in dashboard.html.

function initStudentResponsiveShell() {
    const sidebar = document.querySelector("body > div aside");
    const main = document.querySelector("body > div main");
    const header = main?.querySelector("header");

    if (!sidebar || !main || !header || sidebar.dataset.responsiveShellBound) return;
    sidebar.dataset.responsiveShellBound = "1";

    document.body.classList.add("overflow-x-hidden");
    sidebar.classList.add("-translate-x-full", "transition-transform", "duration-200", "lg:translate-x-0");
    main.classList.remove("ml-[293px]", "w-[calc(100%-293px)]");
    main.classList.add("ml-0", "w-full", "lg:ml-[293px]", "lg:w-[calc(100%-293px)]");
    header.classList.remove("left-[293px]");
    header.classList.add("left-0", "lg:left-[293px]", "px-4", "sm:px-6");
    main.querySelectorAll("table").forEach((table) => {
        table.classList.add("min-w-[640px]");
        table.parentElement?.classList.add("max-w-full", "overflow-x-auto");
    });

    const menuButton = document.createElement("button");
    menuButton.type = "button";
    menuButton.className = "inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 lg:hidden";
    menuButton.setAttribute("aria-label", "Open navigation");
    menuButton.setAttribute("aria-expanded", "false");
    menuButton.innerHTML = '<i data-lucide="menu" class="h-5 w-5"></i>';

    const headerTitle = header.firstElementChild;
    if (headerTitle) {
        headerTitle.classList.add("min-w-0");
        headerTitle.prepend(menuButton);
        headerTitle.classList.add("gap-2", "sm:gap-3");
    }

    const overlay = document.createElement("button");
    overlay.type = "button";
    overlay.className = "fixed inset-0 z-40 hidden bg-slate-900/40 lg:hidden";
    overlay.setAttribute("aria-label", "Close navigation");
    document.body.appendChild(overlay);

    const close = () => {
        sidebar.classList.add("-translate-x-full");
        overlay.classList.add("hidden");
        menuButton.setAttribute("aria-expanded", "false");
    };
    const open = () => {
        sidebar.classList.remove("-translate-x-full");
        overlay.classList.remove("hidden");
        menuButton.setAttribute("aria-expanded", "true");
    };

    menuButton.addEventListener("click", () => {
        if (sidebar.classList.contains("-translate-x-full")) open();
        else close();
    });
    overlay.addEventListener("click", close);
    sidebar.querySelectorAll("a").forEach((link) => link.addEventListener("click", close));
    window.addEventListener("resize", () => {
        if (window.innerWidth >= 1024) close();
    });

    if (window.lucide && typeof window.lucide.createIcons === "function") {
        window.lucide.createIcons();
    }
}

document.addEventListener("DOMContentLoaded", async () => {
    initStudentResponsiveShell();
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
        renderResultState(source);
    } catch (error) {
        console.error("Dashboard API Error:", error);

        const errorBox = document.querySelector("[data-api-error]");
        if (errorBox) {
            errorBox.textContent = "Unable to load dashboard data. Please try again.";
            errorBox.style.display = "block";
        } else {
            console.error("Critical Dashboard API Error: " + error.message);
        }
    }
});

document.addEventListener("DOMContentLoaded", () => {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
        window.lucide.createIcons();
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
    const avatars = document.querySelectorAll('[data-candidate="initials"]');
    if (!avatars.length || !name || name === "—") return;

    const initials = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join("");

    avatars.forEach(avatar => avatar.textContent = initials || "--");
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
        (key === "result" && (status === "Completed" || status === "Available")) ||
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
    const certPreviewCard = document.getElementById("certificate-preview-card");
    const certMainContainer = document.getElementById("certificate-main-container");
    const certBadge = document.getElementById("certificate-status-badge");
    const dashCertDownload = document.getElementById("dashboard-certificate-download");

    if (source.certificate && source.certificate.status === "Issued" && source.result && source.result.id) {
        const downloadUrl = `api/admin/certificate_pdf.php?result_id=${source.result.id}&t=${Date.now()}`;
        
        if (certStateContainer) {
            certStateContainer.innerHTML = `
                <div class="mx-auto w-16 h-16 rounded-2xl bg-green-50 border border-green-100 flex items-center justify-center text-green-600 mb-5">
                    <i data-lucide="award" class="w-8 h-8"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-900 mb-2">Certificate Issued</h3>
                <p class="text-slate-500 mb-6 max-w-sm">Congratulations! Your certificate has been generated successfully and is ready.</p>
                <div class="flex flex-col sm:flex-row justify-center gap-3 w-full sm:w-auto">
                    <a href="${downloadUrl}" target="_blank" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-medium transition-colors shadow-sm focus:ring-2 focus:ring-blue-500/20">
                        <i data-lucide="download" class="w-4 h-4"></i> Download PDF
                    </a>
                    <a href="${downloadUrl}" target="_blank" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-medium transition-colors shadow-sm">
                        <i data-lucide="eye" class="w-4 h-4"></i> View Online
                    </a>
                </div>
            `;
            if (typeof lucide !== 'undefined' && lucide.createIcons) {
                lucide.createIcons();
            }
        }

        if (certBadge) {
            certBadge.textContent = "Issued";
            certBadge.className = "inline-flex items-center px-3 py-1.5 rounded-full bg-green-100 text-green-700 text-xs font-semibold";
        }

        if (certMainContainer) {
            certMainContainer.className = "grid grid-cols-1 xl:grid-cols-2 gap-5 mb-6";
        }

        if (certPreviewCard) {
            certPreviewCard.style.display = "block";
            
            document.querySelectorAll('[data-certificate="number"]').forEach(el => el.textContent = source.certificate.number || '—');
            document.querySelectorAll('[data-certificate="level"]').forEach(el => el.textContent = source.certificate.level || '—');
            document.querySelectorAll('[data-certificate="issue_date"]').forEach(el => el.textContent = source.certificate.issueDate || '—');
        }

        if (dashCertDownload) {
            dashCertDownload.innerHTML = `<a href="${downloadUrl}" target="_blank" style="color: #1652d6; font-weight: bold;">(Download)</a>`;
        }
    } else {
        if (certStateContainer) {
            certStateContainer.innerHTML = `
                <div class="mx-auto w-16 h-16 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 mb-5">
                    <i data-lucide="clock" class="w-8 h-8"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-900 mb-2">No Certificate Issued</h3>
                <p class="text-slate-500 max-w-sm">Your certificate will appear here after successful evaluation and level assignment.</p>
            `;
            if (typeof lucide !== 'undefined' && lucide.createIcons) {
                lucide.createIcons();
            }
        }

        if (certBadge) {
            certBadge.textContent = "Pending";
            certBadge.className = "inline-flex items-center px-3 py-1.5 rounded-full bg-slate-100 text-slate-600 text-xs font-semibold";
        }

        if (certMainContainer) {
            certMainContainer.className = "grid grid-cols-1 gap-5 mb-6";
        }

        if (certPreviewCard) {
            certPreviewCard.style.display = "none";
        }
        
        if (dashCertDownload) {
            dashCertDownload.innerHTML = "";
        }
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

    const certContent = document.getElementById("profile-certificate-content");
    if (certContent) {
        const cert = source.certificate;
        const result = source.result;
        const resultId = result?.id || cert?.result_id;
        const isIssued = cert && (cert.status === "Issued" || (cert.number && cert.number !== "Not issued" && cert.number !== "—"));

        if (isIssued && resultId) {
            const downloadUrl = `api/admin/certificate_pdf.php?result_id=${resultId}&t=${Date.now()}`;
            const certNumber = cert.number || cert.certificate_number || "—";
            const certLevel = cert.level || (result?.level && result.level !== "—" ? result.level : "—");
            const issueDate = cert.issueDate || cert.issue_date || "—";

            certContent.innerHTML = `
                <div class="row"><span class="label">Certificate Number</span><span class="value"><strong>${certNumber}</strong></span></div>
                <div class="row"><span class="label">Level</span><span class="value"><span class="badge blue">${certLevel}</span></span></div>
                <div class="row"><span class="label">Issue Date</span><span class="value">${issueDate}</span></div>
                <div class="row" style="align-items: center;"><span class="label">Certificate PDF</span><span class="value"><a href="${downloadUrl}" class="button" target="_blank" style="background: #1652d6; color: white; padding: 6px 14px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block; font-size: 13px;">Download Certificate</a></span></div>
            `;
        } else {
            certContent.innerHTML = `
                <div class="row"><span class="label">Status</span><span class="value"><span class="badge gray">Not issued yet</span></span></div>
            `;
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

function renderResultState(source) {
    const container = document.getElementById('result-state-container');
    if (!container) return;

    if (source.result && source.result.status === 'Available') {
        const badge = document.getElementById('result-status-badge');
        if (badge) {
            badge.className = 'inline-flex items-center gap-2 self-start sm:self-auto px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-600 text-xs font-semibold';
            badge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Available';
        }
        
        container.innerHTML = `
            <div class="mx-auto w-14 h-14 rounded-2xl bg-white border border-emerald-100 flex items-center justify-center text-emerald-500 mb-5">
                <i data-lucide="check-circle" class="w-7 h-7"></i>
            </div>
            <h2 class="text-xl font-semibold text-slate-900">Result Evaluated</h2>
            <p class="mt-2 text-sm text-slate-600 max-w-md mx-auto leading-6">
                Your assessment has been successfully evaluated. Your final score and assigned level are now available below.
            </p>
        `;
        container.className = 'rounded-2xl border border-emerald-100 bg-emerald-50 px-6 py-10 text-center';
        if (window.lucide) { window.lucide.createIcons(); }
    }
}
