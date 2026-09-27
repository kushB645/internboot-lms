# M6 — Online Assessment Engine

## InternBoot Automated Level Assessment & Certification Platform

The M6 Online Assessment Engine provides the candidate-facing online assessment experience and manages the assessment attempt lifecycle.

> **M6 scope:** Assessment delivery and attempt lifecycle.  
> **M7 scope:** Evaluation, scoring, percentage calculation, and level assignment.

## 1. Module Responsibilities

- Start and resume assessment attempts
- Enforce a server-controlled assessment timer
- Deliver randomized MCQ questions
- Maintain stable question ordering for an attempt
- Save and restore candidate answers
- Perform periodic autosave
- Track assessment status
- Automatically expire attempts
- Submit and lock completed attempts
- Validate candidate/attempt ownership
- Validate question assignment
- Validate selected-option ownership
- Provide fullscreen assessment mode
- Provide browser-level anti-cheating deterrents

## 2. Technology Stack

| Technology | Usage |
|---|---|
| PHP | Backend/API |
| MySQL | Assessment and attempt persistence |
| HTML5 | Assessment interface |
| CSS3 | Responsive UI |
| JavaScript | Timer, navigation, autosave and browser interaction |
| Bootstrap/CSS | UI/responsive styling |
| PHP Sessions | Candidate session context |

## 3. Repository Structure

```text
src/modules/m6_exam_engine/
├── config/
│   └── database.php         # Backward-compatible bridge forwarding to src/core/bootstrap.php
├── data/
│   └── seed_questions.php   # Development question bank seeder
├── exam.php                 # Assessment UI (fullscreen mode & proctoring deterrents)
├── index.php                # Connectivity & bootstrap status check
└── README.md                # Module technical documentation

public/api/exam/
├── start_exam.php           # Initiates attempt & validates server-authoritative timer
├── get_questions.php        # Serves deterministic randomized MCQs (no answer keys)
├── save_answer.php          # Real-time answer persistence with anti-tampering checks
├── exam_status.php          # Heartbeat endpoint returning progress & remaining time
└── submit_exam.php          # Final submission & atomic attempt locking
```

### Shared Team Core Dependencies
M6 consumes the central project infrastructure:
- **`src/core/bootstrap.php`**: Loaded by all public API endpoints (`require_once __DIR__ . '/../../../src/core/bootstrap.php'`) for centralized session handling, error reporting, and utilities.
- **`db.php`**: Central database connection handler establishing MySQLi connection (`$conn`) to the shared Railway cloud database.
- **`.env`**: Central environment configuration defining `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`.

## 4. Assessment Configuration

| Configuration | Value |
|---|---|
| Assessment type | MCQ |
| Questions per attempt | 100 |
| Development question pool | 120 |
| Options per question | 4 |
| Correct options | 1 |
| Duration | 60 minutes |
| Negative marking | No |
| Retake | No |
| Randomization | Yes |
| Autosave interval | 30 seconds |
| Maximum browser violations | 3 |

Duration and question count are read from the assessment configuration where applicable.

## 5. Assessment Lifecycle

```text
Eligible Candidate
       |
       v
   Start Attempt
       |
       v
Server assigns start/end time
       |
       v
Load randomized questions
       |
       v
Candidate answers questions
       |
       +----> Autosave
       |
       +----> Navigation
       |
       +----> Server timer validation
       |
       v
Submit Attempt
       |
       v
Attempt Locked
       |
       v
Evaluation Pending
       |
       v
       M7
```

## 6. Attempt Management

Each attempt is associated with:

- Candidate
- Assessment
- Exam slot
- Start time
- End time
- Status
- Submission time

Supported states:

```text
in_progress
submitted
expired
```

Submitted or expired attempts cannot be resumed.

## 7. Server-Side Timer

The server is authoritative for assessment timing.

When an attempt has no start time, M6 records the server-side start time and calculates the deadline:

```text
start_time = server current time
end_time   = start_time + assessment duration
```

The browser countdown is only a display mechanism.

Every relevant API independently validates the server-side deadline.

When the deadline is reached:

```text
attempt status -> expired
```

Further answer saving or submission is rejected.

## 8. Question Randomization

The development pool contains 120 approved questions and each assessment attempt receives 100.

M6 uses deterministic ordering based on the attempt ID:

```sql
ORDER BY MD5(CONCAT(?, ':', q.id))
LIMIT ?
```

This provides:

- Different question selection/order between attempts
- Stable ordering for the same attempt
- Consistent restoration after refresh
- No additional `attempt_questions` table

### Important assumption

The approved question pool should remain unchanged during active attempts. Changing the approved pool during an active attempt can change the recomputed set.

For production, approved question banks should therefore be frozen for active assessment runs.

## 9. Question Security

The browser receives question and option information, but does **not** receive:

```text
options.is_correct
```

Correct-answer information remains server-side.

## 10. Answer Saving

For every save request M6 validates:

1. Candidate owns the attempt
2. Attempt is `in_progress`
3. Assessment has not expired
4. Question belongs to the attempt's assigned set
5. Selected option belongs to the submitted question

Answers are persisted in the `answers` table.

M6 does not calculate whether an answer is correct. Evaluation belongs to M7.

## 11. Answer Persistence and Autosave

Previously saved answers are returned when questions are loaded so the frontend can restore the selected options.

The frontend performs periodic autosave every:

```text
30 seconds
```

Autosave uses:

```text
public/api/exam/save_answer.php
```

Autosave stops after submission.

## 12. Assessment Status

The status endpoint returns the server-side state of an attempt, including:

- Attempt ID
- Status
- Start time
- End time
- Submission time
- Remaining time
- Answered question count
- Total question count

Remaining time is recalculated from the server-side deadline.

## 13. Submission

Submission is handled by:

```text
public/api/exam/submit_exam.php
```

The endpoint:

1. Validates candidate ownership
2. Checks attempt status
3. Checks server-side expiry
4. Atomically changes:

```text
in_progress -> submitted
```

5. Records submission time
6. Returns the submission state

After successful submission:

- Timer stops
- Autosave stops
- Answer controls are disabled
- Attempt cannot be submitted again
- Attempt cannot be resumed

M6 does not calculate the final score.

## 14. Automatic Expiry

When an API request arrives after the assessment deadline, M6 transitions the attempt to:

```text
expired
```

and rejects further assessment operations.

The browser countdown is not trusted for enforcement.

## 15. Fullscreen Mode

The candidate first sees an assessment-ready screen.

The assessment starts after selecting:

```text
Enter Fullscreen & Start Assessment
```

The browser then requests fullscreen mode before displaying the exam interface.

Fullscreen is a usability/integrity measure and depends on browser capabilities and permissions.

## 16. Anti-Cheating Deterrents

M6 implements browser-level deterrents.

### Window monitoring

The frontend detects leaving the assessment using:

```text
visibilitychange
```

### Focus monitoring

The frontend also listens for:

```text
window.blur
```

### Violation threshold

Current threshold:

```text
3 violations
```

After the third detected visibility violation, the assessment is automatically submitted.

### Restricted actions

The assessment interface prevents:

- Right-click/context menu
- Copy
- Cut
- Paste
- Ctrl+C
- Ctrl+V
- Ctrl+X
- Ctrl+U
- Ctrl+A
- F12
- Common developer-tools keyboard shortcuts

These are browser-level deterrents only. Server-side validation remains the primary security control.

## 17. API Endpoints

### Start Exam

```text
public/api/exam/start_exam.php
```

Starts or resumes an attempt and enforces server-side timing and ownership.

### Get Questions

```text
public/api/exam/get_questions.php
```

Loads the randomized question set and previously saved answers without exposing correct-answer information.

### Save Answer

```text
public/api/exam/save_answer.php
```

Validates and persists a selected option.

### Exam Status

```text
public/api/exam/exam_status.php
```

Returns the current server-side attempt state and remaining time.

### Submit Exam

```text
public/api/exam/submit_exam.php
```

Submits and locks the attempt. Final scoring is outside M6.

## 18. Session Requirements

M6 expects an authenticated candidate session containing:

```php
$_SESSION['candidate_id']
```

Candidate ownership is verified against the database for protected attempt operations.

Conceptually:

```sql
WHERE a.id = ?
AND a.candidate_id = ?
```

Changing `attempt_id` in the request must not allow access to another candidate's attempt.

## 19. Database Dependencies

M6 uses the existing project schema, including:

```text
assessments
candidates
enrollments
batches
exam_schedules
exam_slots
question_banks
questions
options
attempts
answers
```

No database schema change is required by M6.

## 20. Security Principles

M6 follows these principles:

- Never trust client-side timing
- Never trust client-side question assignment
- Never trust client-side option ownership
- Never expose answer keys to the browser
- Verify candidate ownership server-side
- Lock submitted attempts
- Keep scoring separate from assessment delivery

## 21. M7 Integration Boundary

M6 ends at successful assessment submission.

M7 is responsible for:

- Reading submitted answers
- Determining correct/incorrect answers
- Calculating score
- Calculating percentage
- Assigning the candidate level
- Persisting the result

Flow:

```text
M6
Candidate answers
      |
      v
Answers saved
      |
      v
Assessment submitted
      |
      v
M7
Evaluation
      |
      v
Score / Percentage / Level
      |
      v
Result
```

## 22. Development Seed Data

The development fixture is provided by:

```text
data/seed_questions.php
```

Current fixture:

```text
120 questions
480 options
60 medium questions
60 hard questions
4 options/question
1 correct option/question
Approved MCQ questions
```

The seed data is intended for local development/testing and is not final production assessment content.

## 23. Module Execution & Environment Configuration

The M6 assessment engine operates within the team's repository architecture and utilizes the central project configuration via `src/core/bootstrap.php` and `db.php`:

### Central Cloud Database (`.env`)
Database parameters are maintained in the root `.env` configuration:
```env
DB_HOST=tokaido.proxy.rlwy.net
DB_PORT=18068
DB_USER=root
DB_PASSWORD=YOUR_RAILWAY_PASSWORD
DB_NAME=railway
APP_ENV=development
```

### Accessing M6 in the Integrated Repository
When serving the project from the repository root:

- **Health & Diagnostic Check**:
  ```text
  GET /src/modules/m6_exam_engine/index.php
  ```
  Verifies that database connectivity, session bootstrap, and table accessibility are healthy.

- **Candidate Assessment Interface**:
  ```text
  GET /src/modules/m6_exam_engine/exam.php?attempt_id=<ATTEMPT_ID>
  ```
  Renders the candidate-facing assessment experience in fullscreen mode with client-side deterrents and autosave. (Requires an active authenticated candidate session initialized upstream by M1/M5).

- **Public Assessment API Endpoints**:
  - `POST /public/api/exam/start_exam.php?attempt_id=<ATTEMPT_ID>`
  - `GET  /public/api/exam/get_questions.php?attempt_id=<ATTEMPT_ID>`
  - `POST /public/api/exam/save_answer.php`
  - `GET  /public/api/exam/exam_status.php?attempt_id=<ATTEMPT_ID>`
  - `POST /public/api/exam/submit_exam.php`

## 24. Testing Completed

### API validation

- PHP syntax checks
- Candidate session validation
- Attempt ownership validation
- Attempt status validation
- Server-side expiry validation
- Question assignment validation
- Option ownership validation

### Assessment behavior

- Attempt start
- Timer initialization
- Question loading
- Randomized question assignment
- Answer selection
- Navigation
- Answer persistence
- Refresh restoration
- Periodic autosave
- Assessment submission
- Post-submission locking

### Security behavior

- Invalid attempt access
- Invalid question submission
- Invalid option submission
- Correct-answer information not exposed
- Copy/cut/paste restrictions
- Context-menu restriction
- Keyboard shortcut restrictions
- Fullscreen flow
- Visibility-change detection
- Focus-loss detection
- Automatic submission after repeated violations

### Expiry

Server-side expiry was tested independently of the browser countdown.

## 25. Known Limitations

### Browser anti-cheating is not absolute

Client-side restrictions can be bypassed. Server-side authorization, timing, question validation and attempt locking are the primary security controls.

### No proctoring

M6 does not implement webcam monitoring, screen recording, biometric verification or advanced proctoring.

### Retake disabled

Retake functionality is currently disabled.

### Question-pool mutation

The randomized set depends on a stable approved question pool during active attempts.

### Scoring is external

Final scoring and level assignment belong to M7.

## 26. Production Integration Checklist

Current Status:

1. [x] Use the project's shared authentication/session bootstrap (`src/core/bootstrap.php`).
2. [x] Use the project's shared database configuration (`db.php` + `.env` Railway parameters).
3. [x] Do not expose development database credentials (`.env` protected via `.gitignore`).
4. [ ] Remove or disable development seed scripts if not required in production.
5. [ ] Use HTTPS in production deployment.
6. [x] Freeze approved question banks during active assessments.
7. [x] Route the candidate-facing assessment page through the main application.
8. [x] Verify M7 can consume submitted attempts and answers (`evaluation_pending = true`).
9. [x] Verify production timezone configuration (`Asia/Kolkata` set in `bootstrap.php`).
10. [x] Perform a final integrated end-to-end test (26/26 automated assessment lifecycle and security checks verified).

## 27. Responsibility Boundary

### M6 Owns

```text
Assessment UI
Assessment timer
Attempt lifecycle
Question delivery
Question randomization
Answer saving
Autosave
Answer restoration
Assessment status
Expiry
Submission
Attempt locking
Browser-level assessment deterrents
```

### M7 Owns

```text
Evaluation
Scoring
Percentage calculation
Level assignment
Result persistence
```

### M6 Does Not Own

```text
Payment processing
Slot booking
Candidate authentication
Final scoring
Level calculation
Certificate generation
Placement processing
Admin analytics
```

## 28. Handover Summary

M6 provides the online assessment lifecycle from attempt initialization through final submission.

The implementation was developed independently against the agreed database/session contracts and tested using a local development environment.

The M6 implementation does not require changes to the shared database schema and is designed to integrate with the project's authentication, slot-booking and evaluation modules.

## 29. Status
 
**Implementation:** Complete & Integrated with Shared Core
 
**Testing:** 26/26 Automated Lifecycle, Timer & Security Checks Passed
 
**Git branch:** `m6-assessment-engine`
 
**Commit:** `fix(m6): switch from local database to centralized bootstrap.php`
 
**Pull Request:** `#15` (Merged into `main` by Yash Mishra)
 
**Repository status:** Active on `main` branch
 
**Module:** M6 — Online Assessment Engine
 
**Project:** InternBoot Automated Level Assessment & Certification Platform
