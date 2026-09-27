/**
 * InternBoot M4 - Payment Module Integration
 * Connects public/payment.html to public/api/payment/payment.php
 * Handles token-based demo payment flow:
 * 1. GET api/payment/payment.php?action=details
 * 2. POST api/payment/payment.php?action=create (short-lived in-memory token)
 * 3. POST api/payment/payment.php?action=verify (server-side token verification & enrollment)
 */

document.addEventListener("DOMContentLoaded", () => {
    initPaymentModule();
});

// In-memory state (scoped to page lifecycle, never persisted to localStorage)
let currentAssessmentId = null;
let currentCandidateId = null;
let currentFeeAmount = 2999;
let isPaymentCompleted = false;
let isProcessing = false;
let devCandidateParam = null;
let devAssessmentParam = null;

async function initPaymentModule() {
    const urlParams = new URLSearchParams(window.location.search);
    devCandidateParam = urlParams.get("candidate_id");
    devAssessmentParam = urlParams.get("assessment_id");

    const payButton = document.getElementById("btn-pay");
    if (payButton) {
        payButton.addEventListener("click", handlePaymentSubmit);
    }

    await loadPaymentDetails();
}

/**
 * Fetch and render details from api/payment/payment.php?action=details
 */
async function loadPaymentDetails() {
    clearAlert();

    const queryParams = new URLSearchParams();
    queryParams.set("action", "details");

    // Only pass candidate_id if present in URL for dev/demo testing fallback;
    // Otherwise rely purely on server-side session resolution
    if (devCandidateParam) {
        queryParams.set("candidate_id", devCandidateParam);
    }
    if (devAssessmentParam) {
        queryParams.set("assessment_id", devAssessmentParam);
    }

    try {
        const response = await fetch(`api/payment/payment.php?${queryParams.toString()}`, {
            method: "GET",
            headers: {
                "Accept": "application/json"
            }
        });

        if (response.status === 401) {
            handleUnauthenticated("Session expired or authentication required. Please <a href='login.php' style='color:#1652d6;text-decoration:underline;font-weight:700;'>log in as candidate</a> to access payments.");
            return;
        }

        const payload = await response.json();

        if (!response.ok || payload.status !== "success" || !payload.data) {
            showAlert("error", payload.message || "Unable to load payment details. Please refresh the page.");
            return;
        }

        const data = payload.data;
        renderDetails(data);

    } catch (error) {
        console.error("Payment details fetch error:", error);
        showAlert("error", "Network or server connection error. Unable to load payment information.");
    }
}

/**
 * Populate UI with retrieved payment, assessment, candidate, and enrollment data
 */
function renderDetails(data) {
    const { candidate, assessment, fee, payment, enrollment } = data;

    // 1. Candidate Info & Avatar
    if (candidate) {
        currentCandidateId = candidate.id;
        setText('[data-candidate="name"]', candidate.name || "Candidate");
        updateAvatar(candidate.name);
    }

    // 2. Assessment Info
    if (assessment) {
        currentAssessmentId = assessment.id;
        setText("#assessment-title", assessment.title || "Assessment");
        setText('[data-assessment="title"]', assessment.title || "Assessment");

        const durationText = assessment.duration ? `${assessment.duration} mins` : "60 mins";
        const questionsText = assessment.questions ? `${assessment.questions} Questions` : "50 Questions";
        setText("#assessment-meta", `Duration: ${durationText} | Questions: ${questionsText}`);
    }

    // 3. Fee
    currentFeeAmount = (fee !== undefined && fee !== null) ? Number(fee) : 2999;
    const formattedFee = formatCurrency(currentFeeAmount);

    setText('[data-payment="totalFee"]', formattedFee);
    setText("#assessment-fee-display", formattedFee);

    // 4. Payment & Enrollment Status
    if (payment && payment.status === "success") {
        isPaymentCompleted = true;
        applyPaymentSuccessState(payment, enrollment);
    } else if (payment && payment.status === "pending") {
        isPaymentCompleted = false;
        applyPaymentPendingState(payment, enrollment);
    } else {
        isPaymentCompleted = false;
        applyPaymentUnpaidState(enrollment);
    }
}

/**
 * Update UI when payment is successfully verified
 */
function applyPaymentSuccessState(payment, enrollment) {
    const paidAmount = payment.amount ? formatCurrency(payment.amount) : formatCurrency(currentFeeAmount);

    setText('[data-payment="paidAmount"]', paidAmount);
    setText('[data-payment="status"]', "Paid");
    setText('[data-payment="transactionId"]', payment.reference_number || "—");
    setText('[data-payment="paymentId"]', payment.id ? `IB-PAY-${payment.id}` : "—");
    setText('[data-payment="paymentDate"]', formatDate(payment.payment_date || payment.created_at));
    setText('[data-payment="method"]', "Demo Gateway (Verified)");

    // Badge styling
    setStatusBadge("Paid", "green");
    setStatCardStatus("Paid", "green");

    // Enrollment badge
    const enrollmentStatus = enrollment?.eligibility_status || "eligible";
    setEnrollmentBadge(enrollmentStatus === "eligible" ? "Eligible" : enrollmentStatus, "green");

    // Action button replacement
    const actionContainer = document.getElementById("payment-action-box");
    if (actionContainer) {
        actionContainer.innerHTML = `
            <span class="badge green" style="padding: 10px 18px; font-size: 14px; font-weight: 700;">
                ✓ Payment Verified & Eligible
            </span>
            <a href="batches-slots.html" class="btn btn-ib-primary" style="margin-left: 8px;">
                Select Batch Slot &rarr;
            </a>
        `;
    }
}

/**
 * Update UI when a payment is in pending status
 */
function applyPaymentPendingState(payment, enrollment) {
    setText('[data-payment="paidAmount"]', "₹0");
    setText('[data-payment="status"]', "Pending");
    setText('[data-payment="transactionId"]', payment.reference_number || "—");
    setText('[data-payment="paymentId"]', payment.id ? `IB-PAY-${payment.id}` : "—");
    setText('[data-payment="paymentDate"]', "Verification Pending");
    setText('[data-payment="method"]', "Demo Payment Gateway");

    setStatusBadge("Pending", "yellow");
    setStatCardStatus("Pending", "blue");

    setEnrollmentBadge("Pending Payment", "gray");

    const payButton = document.getElementById("btn-pay");
    if (payButton) {
        payButton.disabled = false;
        payButton.innerHTML = `Complete Payment (${formatCurrency(currentFeeAmount)})`;
    }
}

/**
 * Update UI when no payment has been made
 */
function applyPaymentUnpaidState(enrollment) {
    setText('[data-payment="paidAmount"]', "₹0");
    setText('[data-payment="status"]', "Unpaid");
    setText('[data-payment="transactionId"]', "—");
    setText('[data-payment="paymentId"]', "—");
    setText('[data-payment="paymentDate"]', "—");
    setText('[data-payment="method"]', "Demo Payment Gateway");

    setStatusBadge("Unpaid", "gray");
    setStatCardStatus("Unpaid", "gray");

    const enrollmentStatus = enrollment?.eligibility_status || "Pending Payment";
    setEnrollmentBadge(enrollmentStatus, "gray");

    const payButton = document.getElementById("btn-pay");
    if (payButton) {
        payButton.disabled = false;
        payButton.innerHTML = `(Demo Payment) Pay Registration Fee (${formatCurrency(currentFeeAmount)})`;
    }
}

/**
 * Handle "Pay" button click:
 * 1. POST api/payment/payment.php?action=create
 * 2. Store { payment_id, token } in memory
 * 3. POST api/payment/payment.php?action=verify
 */
async function handlePaymentSubmit(event) {
    if (event) event.preventDefault();

    if (isProcessing) return;
    if (isPaymentCompleted) {
        showAlert("info", "Payment has already been completed for this assessment.");
        return;
    }

    isProcessing = true;
    clearAlert();
    setButtonLoading(true, "Initializing Payment...");

    try {
        // Step 1: Initialize Payment (action=create)
        const createPayload = {
            assessment_id: currentAssessmentId || 1
        };

        if (devCandidateParam) {
            createPayload.candidate_id = devCandidateParam;
        }

        const createResponse = await fetch("api/payment/payment.php?action=create", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify(createPayload)
        });

        if (createResponse.status === 401) {
            handleUnauthenticated("Session expired. Please <a href='login.php' style='color:#1652d6;text-decoration:underline;font-weight:700;'>log in again</a> to proceed.");
            setButtonLoading(false);
            isProcessing = false;
            return;
        }

        const createData = await createResponse.json();

        if (!createResponse.ok || createData.status !== "success" || !createData.data) {
            showAlert("error", createData.message || "Failed to initialize payment.");
            setButtonLoading(false);
            isProcessing = false;
            return;
        }

        // Handle case where candidate already paid
        if (createData.data.already_paid) {
            isPaymentCompleted = true;
            applyPaymentSuccessState(createData.data.payment, { eligibility_status: "eligible" });
            showAlert("info", "Already paid for this assessment. Enrollment is confirmed.");
            setButtonLoading(false);
            isProcessing = false;
            return;
        }

        // Store short-lived token in local in-memory variables (never localStorage)
        const paymentId = createData.data.payment_id;
        const verificationToken = createData.data.token;

        if (!paymentId || !verificationToken) {
            showAlert("error", "Server did not issue a valid verification token.");
            setButtonLoading(false);
            isProcessing = false;
            return;
        }

        // Step 2: Confirm / Verify Demo Payment (action=verify)
        setButtonLoading(true, "Verifying Demo Token...");

        const verifyPayload = {
            payment_id: paymentId,
            token: verificationToken
        };

        if (devCandidateParam) {
            verifyPayload.candidate_id = devCandidateParam;
        }

        const verifyResponse = await fetch("api/payment/payment.php?action=verify", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify(verifyPayload)
        });

        if (verifyResponse.status === 401) {
            handleUnauthenticated("Session expired during verification. Please log in again.");
            setButtonLoading(false);
            isProcessing = false;
            return;
        }

        const verifyData = await verifyResponse.json();

        if (!verifyResponse.ok || verifyData.status !== "success" || !verifyData.data) {
            showAlert("error", verifyData.message || "Payment verification failed. Please try again.");
            setButtonLoading(false);
            isProcessing = false;
            return;
        }

        // Step 3: Success handling
        isPaymentCompleted = true;
        const verifiedPayment = verifyData.data.payment || {
            id: paymentId,
            amount: currentFeeAmount,
            reference_number: createData.data.reference_number,
            payment_date: new Date().toISOString()
        };

        const verifiedEnrollment = {
            eligibility_status: verifyData.data.enrollment_status || "eligible"
        };

        applyPaymentSuccessState(verifiedPayment, verifiedEnrollment);
        showAlert(
            "success",
            "Demo payment verified. Your assessment registration and enrollment are now confirmed (test mode — no real payment was processed)."
        );

    } catch (error) {
        console.error("Payment flow error:", error);
        showAlert("error", "A network or server error occurred while processing payment. Please try again.");
        setButtonLoading(false);
    } finally {
        isProcessing = false;
    }
}

/**
 * Handle 401 unauthenticated response gracefully
 */
function handleUnauthenticated(message) {
    showAlert("error", message);
    const payButton = document.getElementById("btn-pay");
    if (payButton) {
        payButton.disabled = true;
        payButton.textContent = "Login Required";
        payButton.style.opacity = "0.6";
        payButton.style.cursor = "not-allowed";
    }
    updateAvatar("—");
    setText('[data-candidate="name"]', "Unauthenticated");
}

/**
 * Alert / Banner notifications
 */
function showAlert(type, messageHtml) {
    const alertBox = document.getElementById("payment-alert");
    const alertBody = document.getElementById("payment-alert-body");
    if (!alertBox || !alertBody) return;

    alertBox.style.display = "block";

    if (type === "success") {
        alertBox.style.borderColor = "#10b981";
        alertBox.style.background = "#f0fdf4";
        alertBox.style.color = "#065f46";
    } else if (type === "info") {
        alertBox.style.borderColor = "#2563eb";
        alertBox.style.background = "#eff6ff";
        alertBox.style.color = "#1e40af";
    } else {
        alertBox.style.borderColor = "#ef4444";
        alertBox.style.background = "#fef2f2";
        alertBox.style.color = "#991b1b";
    }

    alertBody.innerHTML = `<div style="font-size: 14px; line-height: 1.5;">${messageHtml}</div>`;
}

function clearAlert() {
    const alertBox = document.getElementById("payment-alert");
    if (alertBox) {
        alertBox.style.display = "none";
    }
}

/**
 * Pay button loading state
 */
function setButtonLoading(loading, message = "Processing...") {
    const btn = document.getElementById("btn-pay");
    if (!btn) return;

    if (loading) {
        btn.disabled = true;
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = `
            <span style="display:inline-block; animation: spin 1s linear infinite; margin-right: 8px;">⟳</span>
            ${message}
        `;
        btn.style.opacity = "0.75";
        btn.style.cursor = "wait";
    } else {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalText || `Pay Registration Fee (${formatCurrency(currentFeeAmount)})`;
        btn.style.opacity = "1";
        btn.style.cursor = "pointer";
    }
}

/**
 * Helpers for setting text and styling badges
 */
function setText(selector, value) {
    document.querySelectorAll(selector).forEach(el => {
        el.textContent = value;
    });
}

function updateAvatar(name) {
    const avatarEl = document.querySelector('.avatar') || document.querySelector('[data-candidate="initials"]');
    if (!avatarEl || !name || name === "—") return;

    const initials = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0].toUpperCase())
        .join("");

    avatarEl.textContent = initials || "CA";
}

function setStatusBadge(text, colorClass) {
    const badge = document.getElementById("payment-status-badge");
    if (badge) {
        badge.className = `badge ${colorClass}`;
        const span = badge.querySelector('[data-payment="status"]') || badge;
        span.textContent = text;
    }
}

function setStatCardStatus(text, colorClass) {
    const strong = document.getElementById("stat-payment-status");
    if (strong) {
        strong.className = colorClass;
        const span = strong.querySelector('[data-payment="status"]') || strong;
        span.textContent = text;
    }
}

function setEnrollmentBadge(text, colorClass) {
    const badge = document.getElementById("enrollment-badge");
    if (badge) {
        badge.className = `badge ${colorClass}`;
        const span = badge.querySelector('[data-enrollment="status"]') || badge;
        span.textContent = text;
    }
}

function formatCurrency(amount) {
    const num = Number(amount) || 0;
    return "₹" + num.toLocaleString("en-IN");
}

function formatDate(dateStr) {
    if (!dateStr || dateStr === "—") return "—";
    try {
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return dateStr;

        return date.toLocaleDateString("en-IN", {
            day: "2-digit",
            month: "short",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
            hour12: true
        });
    } catch {
        return dateStr;
    }
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

