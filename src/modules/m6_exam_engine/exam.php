<?php
if (file_exists(dirname(__DIR__, 2) . '/core/bootstrap.php')) {
    require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../../src/core/bootstrap.php';
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <title>InternBoot Level Assessment</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            background: #f4f6f9;
            color: #1f2937;
        }

        /* =========================
           HEADER
        ========================= */

        .exam-header {
            position: sticky;
            top: 0;
            z-index: 100;

            height: 70px;

            background: #ffffff;

            border-bottom: 1px solid #e5e7eb;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 28px;
        }

        .brand {
            font-size: 21px;
            font-weight: 700;
            color: #111827;
        }

        .timer-container {
            display: flex;
            align-items: center;
            gap: 8px;

            font-weight: 700;
        }

        .timer {
            min-width: 95px;

            text-align: center;

            padding: 9px 14px;

            border-radius: 8px;

            background: #111827;
            color: #ffffff;

            font-size: 18px;
            letter-spacing: 1px;
        }

        .timer.warning {
            background: #b45309;
        }

        .timer.danger {
            background: #dc2626;
        }

        /* =========================
           MAIN LAYOUT
        ========================= */

        .page {
            max-width: 1400px;

            margin: 0 auto;

            padding: 25px;

            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                330px;

            gap: 24px;

            align-items: start;
        }

        /* =========================
           QUESTION SECTION
        ========================= */

        .question-card {
            background: #ffffff;

            border-radius: 14px;

            padding: 30px;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .question-meta {
            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 22px;
        }

        .question-number {
            font-size: 16px;

            font-weight: 700;

            color: #2563eb;
        }

        .difficulty {
            font-size: 13px;

            padding: 5px 10px;

            border-radius: 20px;

            background: #eef2ff;

            color: #4338ca;

            text-transform: capitalize;
        }

        .question-text {
            font-size: 21px;

            line-height: 1.6;

            font-weight: 600;

            margin-bottom: 28px;
        }

        /* =========================
           OPTIONS
        ========================= */

        .options {
            display: flex;

            flex-direction: column;

            gap: 13px;
        }

        .option {
            position: relative;

            display: flex;

            align-items: flex-start;

            gap: 12px;

            padding: 16px;

            border: 1px solid #d1d5db;

            border-radius: 10px;

            cursor: pointer;

            transition:
                background 0.15s,
                border 0.15s;
        }

        .option:hover {
            background: #f8fafc;
        }

        .option.selected {
            border-color: #2563eb;

            background: #eff6ff;
        }

        .option input {
            margin-top: 4px;

            accent-color: #2563eb;
        }

        .option-text {
            line-height: 1.5;
        }

        /* =========================
           NAVIGATION
        ========================= */

        .navigation {
            display: flex;

            justify-content: space-between;

            gap: 12px;

            margin-top: 25px;
        }

        button {
            border: none;

            border-radius: 8px;

            padding: 12px 20px;

            font-size: 14px;

            font-weight: 700;

            cursor: pointer;

            transition:
                opacity 0.15s,
                transform 0.1s;
        }

        button:active {
            transform: scale(0.98);
        }

        button:disabled {
            opacity: 0.45;

            cursor: not-allowed;
        }

        .btn-secondary {
            background: #e5e7eb;

            color: #111827;
        }

        .btn-primary {
            background: #2563eb;

            color: #ffffff;
        }

        .btn-submit {
            background: #dc2626;

            color: #ffffff;
        }

        /* =========================
           QUESTION PALETTE
        ========================= */

        .navigator {
            position: sticky;

            top: 95px;

            background: #ffffff;

            border-radius: 14px;

            padding: 22px;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .navigator-title {
            font-size: 17px;

            font-weight: 700;

            margin-bottom: 15px;
        }

        .navigator-info {
            font-size: 13px;

            color: #6b7280;

            margin-bottom: 18px;
        }

        .question-grid {
            display: grid;

            grid-template-columns:
                repeat(5, 1fr);

            gap: 8px;
        }

        .question-btn {
            height: 42px;

            padding: 0;

            border-radius: 7px;

            background: #f3f4f6;

            border: 1px solid #d1d5db;

            color: #374151;

            font-size: 13px;
        }

        .question-btn:hover {
            background: #e5e7eb;
        }

        .question-btn.current {
            background: #2563eb;

            border-color: #2563eb;

            color: #ffffff;
        }

        .question-btn.answered {
            background: #dcfce7;

            border-color: #86efac;

            color: #166534;
        }

        .question-btn.answered.current {
            background: #2563eb;

            border-color: #2563eb;

            color: #ffffff;
        }

        /* =========================
           LEGEND
        ========================= */

        .legend {
            display: flex;

            flex-direction: column;

            gap: 9px;

            margin-top: 20px;

            padding-top: 18px;

            border-top: 1px solid #e5e7eb;

            font-size: 12px;

            color: #4b5563;
        }

        .legend-item {
            display: flex;

            align-items: center;

            gap: 8px;
        }

        .legend-box {
            width: 14px;

            height: 14px;

            border-radius: 3px;

            border: 1px solid #d1d5db;
        }

        .legend-current {
            background: #2563eb;

            border-color: #2563eb;
        }

        .legend-answered {
            background: #dcfce7;

            border-color: #86efac;
        }

        .legend-unanswered {
            background: #f3f4f6;
        }

        /* =========================
           SUBMIT AREA
        ========================= */

        .submit-area {
            margin-top: 20px;

            padding-top: 18px;

            border-top: 1px solid #e5e7eb;
        }

        .submit-area button {
            width: 100%;
        }

        /* =========================
           LOADING
        ========================= */

        .loading {
            max-width: 500px;

            margin: 100px auto;

            text-align: center;

            color: #6b7280;

            font-size: 17px;
        }

        /* =========================
           INSTRUCTIONS
        ========================= */

        .instructions {
            max-width: 680px;
            margin: 40px auto;
            padding: 32px 36px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }

        .instructions h2 {
            margin: 0 0 6px;
            font-size: 22px;
            color: #111827;
        }

        .instructions .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin: 0 0 24px;
        }

        .instr-list {
            list-style: none;
            padding: 0;
            margin: 0 0 24px;
        }

        .instr-list li {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
            line-height: 1.55;
            color: #374151;
        }

        .instr-list li:last-child {
            border-bottom: none;
        }

        .instr-icon {
            flex-shrink: 0;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .instr-meta {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .instr-meta-pill {
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 13px;
            font-weight: 600;
        }

        .instr-warn {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            color: #92400e;
            margin-bottom: 22px;
        }

        .instr-ack {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #374151;
            cursor: pointer;
        }

        .instr-ack input[type=checkbox] {
            margin-top: 2px;
            width: 17px;
            height: 17px;
            flex-shrink: 0;
            cursor: pointer;
        }

        #instrStartBtn {
            width: 100%;
            padding: 13px;
            font-size: 16px;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s, opacity 0.2s;
        }

        #instrStartBtn:disabled {
            background: #d1d5db;
            color: #9ca3af;
            cursor: not-allowed;
        }

        #instrStartBtn:not(:disabled) {
            background: #2563eb;
            color: #ffffff;
        }

        #instrStartBtn:not(:disabled):hover {
            background: #1d4ed8;
        }

        @media (max-width: 600px) {
            .instructions {
                margin: 16px;
                padding: 22px 18px;
            }
        }

        /* =========================
           ERROR
        ========================= */

        .error {
            max-width: 600px;

            margin: 100px auto;

            padding: 25px;

            background: #fef2f2;

            border: 1px solid #fecaca;

            border-radius: 10px;

            color: #b91c1c;

            text-align: center;
        }

        /* =========================
           SAVE INDICATOR
        ========================= */

        .save-status {
            margin-top: 15px;

            font-size: 13px;

            color: #6b7280;

            min-height: 20px;
        }

        .save-status.success {
            color: #15803d;
        }

        .save-status.error {
            margin: 15px 0 0;

            padding: 0;

            border: none;

            background: transparent;

            text-align: left;

            color: #dc2626;
        }

        /* =========================
           RESPONSIVE
        ========================= */

        @media (max-width: 900px) {

            .page {
                grid-template-columns: 1fr;
            }

            .navigator {
                position: static;

                order: 2;
            }

            .question-grid {
                grid-template-columns:
                    repeat(10, 1fr);
            }

        }

        @media (max-width: 600px) {

            .exam-header {
                padding: 0 15px;

                height: 62px;
            }

            .brand {
                font-size: 17px;
            }

            .timer {
                font-size: 15px;

                min-width: 78px;

                padding: 8px 9px;
            }

            .page {
                padding: 12px;
            }

            .question-card {
                padding: 20px;
            }

            .question-text {
                font-size: 18px;
            }

            .navigation {
                flex-wrap: wrap;
            }

            .navigation button {
                flex: 1;
            }

            .question-grid {
                grid-template-columns:
                    repeat(5, 1fr);
            }

        }

    </style>

</head>

<body>

<header class="exam-header">

    <div class="brand">
        InternBoot Level Assessment
    </div>

    <div style="display:flex; align-items:center; gap:20px;">
        <?php if (!empty($_SESSION['full_name'])): ?>
            <div style="font-size:14px; color:#4b5563;">
                Candidate: <strong style="color:#111827;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>
            </div>
        <?php endif; ?>

        <div class="timer-container">
            <span>Time Left</span>
            <span
                id="timer"
                class="timer"
            >
                --:--
            </span>
        </div>
    </div>

</header>


<div
    id="loading"
    class="loading"
>
    <div>Loading assessment...</div>
</div>


<!-- =============================================
     ASSESSMENT INSTRUCTIONS GATE
     Shown to fresh-start candidates only.
     Hidden on resume (start_time already set).
============================================== -->
<div id="instructions" class="instructions" style="display:none;">
    <h2>📋 Assessment Instructions</h2>
    <p class="subtitle">Please read carefully before starting. Your timer begins only after you click <strong>Start Exam</strong>.</p>

    <div class="instr-meta">
        <span class="instr-meta-pill" id="instrQuestions">— Questions</span>
        <span class="instr-meta-pill" id="instrDuration">— Minutes</span>
        <span class="instr-meta-pill">Online · MCQ</span>
    </div>

    <ul class="instr-list">
        <li>
            <span class="instr-icon">🧭</span>
            <span><strong>Free navigation:</strong> You can move between questions in any order using Previous / Next buttons or the Question Navigator panel. Your position is saved as you go.</span>
        </li>
        <li>
            <span class="instr-icon">💾</span>
            <span><strong>Auto-save:</strong> Every answer you select is saved automatically in real time. You do not need to click a separate save button.</span>
        </li>
        <li>
            <span class="instr-icon">⏱️</span>
            <span><strong>Timer:</strong> The countdown timer starts the moment you click <strong>Start Exam</strong> below. It runs continuously and cannot be paused.</span>
        </li>
        <li>
            <span class="instr-icon">🔒</span>
            <span><strong>Single attempt:</strong> This is a one-time attempt. You cannot restart or retake once the exam begins.</span>
        </li>
        <li>
            <span class="instr-icon">🖥️</span>
            <span><strong>Stay on screen:</strong> Switching tabs or moving focus away from the exam window is tracked as a violation. Exceeding <strong id="instrMaxViolations">3</strong> violations will automatically submit your attempt.</span>
        </li>
    </ul>

    <div class="instr-warn">
        ⚠️ <strong>Important:</strong> Once you click <em>Start Exam</em>, your assessment timer begins and cannot be paused. Ensure you have a stable internet connection and a quiet environment before proceeding.
    </div>

    <label class="instr-ack">
        <input type="checkbox" id="instrAck">
        <span>I have read and understood the instructions above, and I am ready to begin my assessment.</span>
    </label>

    <button id="instrStartBtn" disabled>Start Exam →</button>
</div>


<div
    id="error"
    class="error"
    style="display:none;"
></div>


<main
    id="exam"
    class="page"
    style="display:none;"
>

    <!-- =========================
         QUESTION
    ========================== -->

    <section>

        <div class="question-card">

            <div class="question-meta">

                <div
                    id="questionNumber"
                    class="question-number"
                >
                    Question 1 of 100
                </div>

                <div
                    id="difficulty"
                    class="difficulty"
                >
                    -
                </div>

            </div>


            <div
                id="questionText"
                class="question-text"
            ></div>


            <div
                id="options"
                class="options"
            ></div>


            <div
                id="saveStatus"
                class="save-status"
            ></div>


            <div class="navigation">

                <button
                    id="previousBtn"
                    class="btn-secondary"
                    onclick="previousQuestion()"
                >
                    ← Previous
                </button>


                <button
                    id="nextBtn"
                    class="btn-primary"
                    onclick="nextQuestion()"
                >
                    Next →
                </button>

            </div>

        </div>

    </section>


    <!-- =========================
         QUESTION NAVIGATOR
    ========================== -->

    <aside class="navigator">

        <div class="navigator-title">
            Question Navigator
        </div>

        <div
            id="navigatorInfo"
            class="navigator-info"
        >
            Answered: 0 / 100
        </div>


        <div
            id="questionGrid"
            class="question-grid"
        ></div>


        <div class="legend">

            <div class="legend-item">

                <span
                    class="legend-box legend-current"
                ></span>

                Current

            </div>


            <div class="legend-item">

                <span
                    class="legend-box legend-answered"
                ></span>

                Answered

            </div>


            <div class="legend-item">

                <span
                    class="legend-box legend-unanswered"
                ></span>

                Not Answered

            </div>

        </div>


        <div class="submit-area">

            <button
                class="btn-submit"
                onclick="submitExam(false)"
            >
                Submit Exam
            </button>

        </div>

    </aside>

</main>


<script>

/* ==========================================
   CONFIGURATION & API RESOLVER
========================================== */

function getApiUrl(endpoint) {
    const path = window.location.pathname;
    const m6Idx = path.indexOf('/src/modules/m6_exam_engine');
    if (m6Idx !== -1) {
        const root = path.substring(0, m6Idx);
        return `${root}/public/api/exam/${endpoint}`;
    }
    const pubIdx = path.indexOf('/public');
    if (pubIdx !== -1) {
        const root = path.substring(0, pubIdx);
        return `${root}/public/api/exam/${endpoint}`;
    }
    return `api/exam/${endpoint}`;
}

const urlParams = new URLSearchParams(window.location.search);

const attemptId = Number(
    urlParams.get("attempt_id")
);

if (!attemptId || attemptId <= 0) {

    document.getElementById("loading").style.display = "none";

    showError("Invalid or missing exam attempt.");

    throw new Error("Invalid attempt ID");

}

/* ==========================================
   INSTRUCTIONS GATE — PRE-FLIGHT CHECK
   Runs immediately on page load (before
   start_exam.php is ever called).

   Uses exam_status.php (GET, read-only) to
   check if this attempt already has a
   start_time set:

   - start_time IS SET  → resume: skip
     instructions, go straight to exam.
   - start_time NOT SET → fresh start: show
     instructions screen; block until the
     candidate ticks the checkbox and clicks
     "Start Exam".

   NOTE: initializeExam() / start_exam.php
   are NOT called here.  They are only called
   from enterFullscreen(), which is now
   triggered exclusively by #instrStartBtn.
========================================== */

(async function initInstructionsGate() {

    try {
        const statusRes = await fetch(
            getApiUrl(`exam_status.php?attempt_id=${attemptId}`),
            { method: 'GET', headers: { Accept: 'application/json' } }
        );
        const statusRaw = await statusRes.json();
        const statusData = statusRaw.data || {};

        const isResuming = !!(statusData.start_time);

        if (isResuming) {
            /*
             * Timer already running — skip instructions and
             * go straight into the exam grid.
             */
            document.getElementById('loading').style.display = 'none';
            await enterFullscreen();
            return;
        }

        /*
         * Fresh start — show instructions.
         * Populate real metadata from the status response.
         */
        document.getElementById('loading').style.display = 'none';

        const instrEl = document.getElementById('instructions');
        instrEl.style.display = 'block';

        const qEl = document.getElementById('instrQuestions');
        const dEl = document.getElementById('instrDuration');
        const vEl = document.getElementById('instrMaxViolations');

        if (statusData.total_questions > 0) {
            qEl.textContent = statusData.total_questions + ' Questions';
        }
        if (statusData.duration_minutes > 0) {
            dEl.textContent = statusData.duration_minutes + ' Minutes';
        }

        /* Pull the violation threshold from the already-declared constant */
        if (vEl) vEl.textContent = MAX_VIOLATIONS;

        /* Checkbox gate */
        const ackBox = document.getElementById('instrAck');
        const startBtn = document.getElementById('instrStartBtn');

        ackBox.addEventListener('change', function () {
            startBtn.disabled = !this.checked;
        });

        startBtn.addEventListener('click', async function () {
            if (!ackBox.checked) return;
            startBtn.disabled = true;
            startBtn.textContent = 'Starting…';
            await enterFullscreen();
        });

    } catch (err) {
        /*
         * If the pre-flight check itself fails (network, auth, etc.)
         * fall back to showing the loading error state.
         */
        document.getElementById('loading').style.display = 'none';
        showError('Unable to load assessment. Please check your connection and try again.');
        console.error('Instructions gate pre-flight error:', err);
    }

})();



let questions = [];

let currentQuestionIndex = 0;

let remainingSeconds = 0;
let deadlineTimestamp = 0;

let timerInterval = null;
let statusSyncInterval = null;

let examSubmitted = false;
let violationCount = 0;
const MAX_VIOLATIONS = 3;

let autosaveInterval = null;

function setDeadlineToRemaining(seconds) {
    const sec = Math.max(0, Math.floor(Number(seconds) || 0));
    remainingSeconds = sec;
    deadlineTimestamp = Date.now() + (sec * 1000);
}


/*
 * Stores selected option for each question.
 *
 * Example:
 *
 * answerMap[1] = 4
 *
 * means question 1 has option 4 selected.
 */
const answerMap = {};

async function enterFullscreen() {

    try {
        if (document.documentElement.requestFullscreen) {
            await document.documentElement.requestFullscreen();
        } else if (document.documentElement.webkitRequestFullscreen) {
            await document.documentElement.webkitRequestFullscreen();
        }
    } catch (error) {
        console.warn(
            "Fullscreen request not granted, continuing in standard view:",
            error
        );
    }

    document.getElementById(
        "loading"
    ).style.display = "none";

    document.getElementById(
        "instructions"
    ).style.display = "none";

    document.getElementById(
        "exam"
    ).style.display = "grid";

    buildQuestionNavigator();

    await initializeExam();
}


/* ==========================================
   INITIALIZE EXAM
========================================== */

async function initializeExam() {

    try {

        /*
         * Step 1:
         * Start or resume attempt.
         */
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const startResponse = await fetch(
            getApiUrl(`start_exam.php`),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ attempt_id: attemptId })
            }
        );

        const startRaw = await startResponse.json();
        const startPayload = startRaw.data || startRaw;
        const isStartSuccess = startRaw.status === 'success' || startRaw.success === true || startPayload.success === true;

        if (!isStartSuccess) {
            showError(startRaw.message || "Failed to start assessment");
            return;
        }

        setDeadlineToRemaining(startPayload.remaining_seconds);

        /*
         * Step 2:
         * Load questions.
         */
        const questionResponse = await fetch(
            getApiUrl(`get_questions.php?attempt_id=${attemptId}`)
        );

        const questionRaw =
            await questionResponse.json();
        const questionPayload = questionRaw.data || questionRaw;
        const isQuestionSuccess = questionRaw.status === 'success' || questionRaw.success === true || questionPayload.success === true;

        if (!isQuestionSuccess) {
            showError(questionRaw.message || "Failed to load questions");
            return;
        }

        questions =
            questionPayload.questions || [];

        questions.forEach(question => {
            if (question.selected_option_id !== null) {
                answerMap[question.question_id] =
                    Number(question.selected_option_id);
            }
        });


        if (questions.length === 0) {

            showError(
                "No questions are currently published for this assessment. Please contact your administrator."
            );

            return;
        }


        /*
        * Step 3:
        * Start Exam UI
        */
        startTimer();
        renderQuestion();

    }

    catch (error) {

        console.error(error);

        showError(
            "Unable to load the assessment. Please check your network connection."
        );

    }

}


/* ==========================================
   TIMER
========================================== */

function startTimer() {

    stopTimer();

    if (deadlineTimestamp > 0) {
        remainingSeconds = Math.max(0, Math.round((deadlineTimestamp - Date.now()) / 1000));
    }
    updateTimerDisplay();

    if (remainingSeconds <= 0) {
        submitExam(true);
        return;
    }

    timerInterval = setInterval(async () => {

        if (examSubmitted) {
            return;
        }

        remainingSeconds = Math.max(0, Math.round((deadlineTimestamp - Date.now()) / 1000));

        if (remainingSeconds <= 0) {
            remainingSeconds = 0;
            stopTimer();
            updateTimerDisplay();
            await submitExam(true);
            return;
        }

        updateTimerDisplay();

    }, 1000);

    startPeriodicStatusSync();
    startPeriodicAutosave();

}

function stopTimer() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
    if (statusSyncInterval) {
        clearInterval(statusSyncInterval);
        statusSyncInterval = null;
    }
}

function startPeriodicStatusSync() {
    if (statusSyncInterval) {
        clearInterval(statusSyncInterval);
        statusSyncInterval = null;
    }

    statusSyncInterval = setInterval(async () => {
        if (examSubmitted) {
            return;
        }
        await syncExamStatus(true);
    }, 30000);
}

async function syncExamStatus(triggerSubmitIfExpired = true) {
    if (examSubmitted) {
        return null;
    }

    try {
        const response = await fetch(getApiUrl(`exam_status.php?attempt_id=${attemptId}`));
        if (!response.ok) {
            console.warn("Status sync HTTP error:", response.status);
            return null;
        }

        const raw = await response.json();
        const payload = raw.data || raw;
        const isSuccess = raw.status === 'success' || raw.success === true || payload.success === true;

        if (!isSuccess) {
            console.warn("Status sync unsuccessful:", raw.message);
            return null;
        }

        const serverStatus = payload.status;
        const serverRemaining = typeof payload.remaining_seconds === 'number'
            ? payload.remaining_seconds
            : parseInt(payload.remaining_seconds, 10);

        // Check if server considers attempt already expired or closed
        if (serverStatus !== 'in_progress' || (Number.isFinite(serverRemaining) && serverRemaining <= 0)) {
            console.warn("Server reports attempt is closed or expired:", serverStatus);
            remainingSeconds = 0;
            updateTimerDisplay();
            stopTimer();
            if (autosaveInterval) {
                clearInterval(autosaveInterval);
                autosaveInterval = null;
            }
            if (triggerSubmitIfExpired && !examSubmitted) {
                await submitExam(true);
            }
            return payload;
        }

        if (Number.isFinite(serverRemaining)) {
            setDeadlineToRemaining(serverRemaining);
            remainingSeconds = Math.max(0, Math.round((deadlineTimestamp - Date.now()) / 1000));
            updateTimerDisplay();
        }

        return payload;
    } catch (err) {
        console.warn("Status sync network error:", err);
        return null;
    }
}



document.addEventListener(
    "contextmenu",
    function (event) {
        event.preventDefault();
    }
);


document.addEventListener(
    "copy",
    function (event) {
        event.preventDefault();
    }
);


document.addEventListener(
    "cut",
    function (event) {
        event.preventDefault();
    }
);


document.addEventListener(
    "paste",
    function (event) {
        event.preventDefault();
    }
);

document.addEventListener(
    "keydown",
    function (event) {

        const key =
            event.key.toLowerCase();

        if (
            (event.ctrlKey &&
                ["c", "v", "x", "u", "a"].includes(key))
            ||
            event.key === "F12"
            ||
            (event.ctrlKey &&
                event.shiftKey &&
                ["i", "j"].includes(key))
        ) {

            event.preventDefault();

        }

    }
);


document.addEventListener(
    "visibilitychange",
    async function () {

        if (examSubmitted) {
            return;
        }

        if (document.visibilityState === "hidden") {

            violationCount++;
            try {
                const response = await fetch(getApiUrl("log_violation.php"), {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": getCsrfToken() },
                    body: JSON.stringify({ attempt_id: attemptData.attempt_id })
                });
                const resData = await response.json();
                if (resData.data && resData.data.violations) {
                    violationCount = parseInt(resData.data.violations);
                }
            } catch(e) {}

            if (violationCount >= MAX_VIOLATIONS) {

                alert(
                    "Maximum assessment violations reached.\n\n" +
                    "Your assessment will now be submitted."
                );

                submitExam(true);

                return;
            }

            alert(
                `Warning: You left the assessment window.\n\n` +
                `Violation ${violationCount}/${MAX_VIOLATIONS}\n\n` +
                `Please remain on the assessment screen.`
            );

        }

    }
);

window.addEventListener(
    "blur",
    function () {

        if (examSubmitted) {
            return;
        }

        console.warn(
            "Assessment window lost focus."
        );

    }
);

function startPeriodicAutosave() {

    if (autosaveInterval) {
        clearInterval(autosaveInterval);
    }

    autosaveInterval = setInterval(
        async () => {

            if (examSubmitted) {
                return;
            }

            await syncCurrentAnswers();

        },
        30000
    );
}

async function syncCurrentAnswers() {
    if (dirtyAnswers.size === 0) return;
    const entries = Array.from(dirtyAnswers).map(qid => [qid, answerMap[qid] || 0]);

    for (const [questionId, optionId] of entries) {
        if (examSubmitted) return;
        try {
            const response = await fetch(getApiUrl("save_answer.php"), {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": getCsrfToken()
                },
                body: JSON.stringify({
                    attempt_id: attemptData.attempt_id,
                    question_id: questionId,
                    selected_option_id: optionId
                })
            });
            if (!response.ok) {
                if (response.status === 403) {
                    examSubmitted = true;
                    window.location.href = "/dashboard.html";
                }
                console.error("Autosave failed for question", questionId);
            } else {
                dirtyAnswers.delete(questionId);
            }
        } catch (err) {
            console.error("Autosave network error", err);
        }
    }
}


function updateTimerDisplay() {

    const minutes =
        Math.floor(
            remainingSeconds / 60
        );


    const seconds =
        remainingSeconds % 60;


    const timer =
        document.getElementById("timer");


    timer.textContent =
        `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;


    /*
     * Timer warning states.
     */
    timer.classList.remove(
        "warning",
        "danger"
    );


    if (remainingSeconds <= 300) {

        timer.classList.add("danger");

    }

    else if (remainingSeconds <= 600) {

        timer.classList.add("warning");

    }

}


/* ==========================================
   QUESTION NAVIGATOR
========================================== */

function buildQuestionNavigator() {

    const grid =
        document.getElementById(
            "questionGrid"
        );


    grid.innerHTML = "";


    questions.forEach(
        (question, index) => {

            const button =
                document.createElement("button");


            button.className =
                "question-btn";


            button.textContent =
                index + 1;


            button.type =
                "button";


            button.addEventListener(
                "click",
                () => {

                    jumpToQuestion(index);

                }
            );


            grid.appendChild(button);

        }
    );


    updateQuestionNavigator();

}


function updateQuestionNavigator() {

    const buttons =
        document.querySelectorAll(
            ".question-btn"
        );


    buttons.forEach(
        (button, index) => {

            button.classList.remove(
                "current",
                "answered"
            );


            /*
             * Answered state.
             */
            if (
                answerMap[
                    questions[index].question_id
                ]
            ) {

                button.classList.add(
                    "answered"
                );

            }


            /*
             * Current question.
             */
            if (
                index === currentQuestionIndex
            ) {

                button.classList.add(
                    "current"
                );

            }

        }
    );


    updateNavigatorInfo();

}


function updateNavigatorInfo() {

    const answeredCount =
        Object.keys(answerMap).length;


    document.getElementById(
        "navigatorInfo"
    ).textContent =
        `Answered: ${answeredCount} / ${questions.length}`;

}


function jumpToQuestion(index) {

    if (
        index < 0 ||
        index >= questions.length
    ) {
        return;
    }


    currentQuestionIndex =
        index;


    renderQuestion();

}


/* ==========================================
   RENDER QUESTION
========================================== */

function renderQuestion() {

    const question =
        questions[currentQuestionIndex];


    if (!question) {
        return;
    }


    document.getElementById(
        "questionNumber"
    ).textContent =
        `Question ${currentQuestionIndex + 1} of ${questions.length}`;


    document.getElementById(
        "questionText"
    ).textContent =
        question.question_text;


    document.getElementById(
        "difficulty"
    ).textContent =
        question.difficulty || "MCQ";


    const optionsContainer =
        document.getElementById(
            "options"
        );


    optionsContainer.innerHTML = "";


    /*
     * Existing answer for this question.
     */
    const savedOptionId =
        answerMap[
            question.question_id
        ] || null;


    question.options.forEach(
        option => {

            const label =
                document.createElement(
                    "label"
                );


            label.className =
                "option";


            if (
                savedOptionId ===
                option.option_id
            ) {

                label.classList.add(
                    "selected"
                );

            }


            label.innerHTML = `

                <input
                    type="radio"
                    name="answer"
                    value="${option.option_id}"
                    ${savedOptionId === option.option_id ? "checked" : ""}
                >

                <span class="option-text">
                    ${escapeHtml(option.option_text)}
                </span>

            `;


            const radio =
                label.querySelector(
                    "input"
                );


            radio.addEventListener(
                "change",
                () => {

                    selectAnswer(
                        question.question_id,
                        option.option_id
                    );

                }
            );


            optionsContainer.appendChild(
                label
            );

        }
    );


    /*
     * Navigation buttons.
     */
    const previousBtn =
        document.getElementById(
            "previousBtn"
        );


    const nextBtn =
        document.getElementById(
            "nextBtn"
        );


    previousBtn.disabled =
        currentQuestionIndex === 0;


    nextBtn.disabled =
        currentQuestionIndex ===
        questions.length - 1;


    updateQuestionNavigator();


    document.getElementById(
        "saveStatus"
    ).textContent = "";

}


/* ==========================================
   ANSWER SELECTION
========================================== */

async function selectAnswer(
    questionId,
    optionId
) {

    if (examSubmitted) {
        return;
    }


    /*
     * Update local state immediately.
     *
     * This makes navigation instant.
     */
    answerMap[questionId] =
        optionId;


    /*
     * Update visual selected state.
     */
    document
        .querySelectorAll(".option")
        .forEach(option => {

            option.classList.remove(
                "selected"
            );

        });


    const selectedInput =
        document.querySelector(
            `input[value="${optionId}"]`
        );


    if (selectedInput) {

        selectedInput
            .closest(".option")
            .classList.add("selected");

    }


    updateQuestionNavigator();


    /*
     * Save answer to server.
     */
    await saveAnswer(
        questionId,
        optionId
    );

}


/* ==========================================
   SAVE ANSWER API
========================================== */

async function saveAnswer(
    questionId,
    optionId
) {

    const saveStatus =
        document.getElementById(
            "saveStatus"
        );


    saveStatus.className =
        "save-status";


    saveStatus.textContent =
        "Saving...";


    try {

        const response =
            await fetch(
                getApiUrl("save_answer.php"),
                {
                    method: "POST",

                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },

                    body: JSON.stringify({

                        attempt_id:
                            attemptId,

                        question_id:
                            questionId,

                        selected_option_id:
                            optionId

                    })
                }
            );


        const data =
            await response.json();
        const isSaveSuccess = data.status === 'success' || data.success === true;

        if (!isSaveSuccess) {

            saveStatus.className =
                "save-status error";


            saveStatus.textContent =
                data.message ||
                "Unable to save answer.";


            return;
        }


        saveStatus.className =
            "save-status success";


        saveStatus.textContent =
            "Answer saved";

    }

    catch (error) {

        console.error(error);


        saveStatus.className =
            "save-status error";


        saveStatus.textContent =
            "Connection error. Answer may not have been saved.";

    }

}


/* ==========================================
   NEXT QUESTION
========================================== */

function nextQuestion() {

    if (
        currentQuestionIndex <
        questions.length - 1
    ) {

        currentQuestionIndex++;

        renderQuestion();

    }

}


/* ==========================================
   PREVIOUS QUESTION
========================================== */

function previousQuestion() {

    if (
        currentQuestionIndex > 0
    ) {

        currentQuestionIndex--;

        renderQuestion();

    }

}


/* ==========================================
   SUBMIT EXAM
========================================== */

async function submitExam(
    autoSubmit = false
) {

    if (examSubmitted) {
        return;
    }


    /*
     * Manual submission confirmation.
     */
    if (!autoSubmit) {

        const unanswered =
            questions.length -
            Object.keys(answerMap).length;


        let message =
            "Are you sure you want to submit the exam?";


        if (unanswered > 0) {

            message +=
                `\n\nYou have ${unanswered} unanswered question(s).`;

        }


        const confirmed =
            confirm(message);


        if (!confirmed) {

            return;

        }

    }


    examSubmitted = true;

    stopTimer();
    if (autosaveInterval) {
        clearInterval(autosaveInterval);
        autosaveInterval = null;
    }

    try {

        const response =
            await fetch(
                getApiUrl("submit_exam.php"),
                {
                    method: "POST",

                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },

                    body: JSON.stringify({
                        attempt_id:
                            attemptId
                    })
                }
            );


        const data =
            await response.json();
        const isSubmitSuccess = data.status === 'success' || data.success === true;

        if (isSubmitSuccess) {

            alert(
                autoSubmit
                    ? "Time is over. Your exam has been submitted."
                    : "Exam submitted successfully."
            );


            /*
             * Disable exam controls.
             */
            document
                .querySelectorAll(
                    "button, input"
                )
                .forEach(element => {

                    element.disabled = true;

                });


            /*
             * Show completion message.
             */
            document.getElementById(
                "questionText"
            ).textContent =
                "Assessment submitted successfully.";


            document.getElementById(
                "options"
            ).innerHTML = "";


            document.getElementById(
                "saveStatus"
            ).textContent =
                "Your responses have been submitted for evaluation.";

        }

        else {

            await handleFailedSubmission(
                data.message ||
                "Unable to submit the exam."
            );

        }

    }

    catch (error) {

        console.error(error);

        await handleFailedSubmission(
            "Unable to submit the exam. Please check your connection."
        );

    }

}

async function handleFailedSubmission(errorMessage) {

    examSubmitted = false;

    alert(
        errorMessage +
        "\n\nResuming exam timer and autosave. You can continue answering or submit again."
    );

    // Refresh remaining time and attempt status from authoritative server endpoint
    const statusData = await syncExamStatus(false);

    if (
        statusData &&
        (statusData.status !== 'in_progress' ||
         (typeof statusData.remaining_seconds === 'number' && statusData.remaining_seconds <= 0))
    ) {
        remainingSeconds = 0;
        updateTimerDisplay();
        examSubmitted = true;
        alert("Your assessment time has expired.");

        document
            .querySelectorAll(
                "button, input"
            )
            .forEach(element => {
                element.disabled = true;
            });

        document.getElementById(
            "questionText"
        ).textContent =
            "Assessment has closed.";

        document.getElementById(
            "options"
        ).innerHTML = "";

        document.getElementById(
            "saveStatus"
        ).textContent =
            "Your attempt has expired and been submitted.";
        return;
    }

    const currentRemaining = Math.max(0, Math.round((deadlineTimestamp - Date.now()) / 1000));
    if (currentRemaining <= 0) {
        remainingSeconds = 0;
        updateTimerDisplay();
        await submitExam(true);
        return;
    }

    startTimer();
}


/* ==========================================
   HTML ESCAPING
========================================== */

function escapeHtml(value) {

    const div =
        document.createElement("div");


    div.textContent =
        value;


    return div.innerHTML;

}


/* ==========================================
   ERROR
========================================== */

function showError(message) {

    document.getElementById(
        "loading"
    ).style.display = "none";


    const error =
        document.getElementById(
            "error"
        );


    error.style.display =
        "block";


    error.textContent =
        message;

}


</script>

</body>

</html>