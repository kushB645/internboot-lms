<?php

function q_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function q_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function q_value(mysqli $conn, string $sql, string $types = '', array $params = []): int|float|string|null
{
    $row = q_one($conn, $sql, $types, $params);
    return $row ? array_values($row)[0] : null;
}

function get_dashboard_stats(mysqli $conn): array
{
    $levelRows = q_all($conn, 'SELECT level_assigned, COUNT(*) AS total FROM results GROUP BY level_assigned ORDER BY level_assigned');

    $levelCounts = [];
    foreach ($levelRows as $row) {
        $levelCounts[(string)(int)$row['level_assigned']] = (int)$row['total'];
    }

    return [
        'total_registrations' => (int)q_value($conn, 'SELECT COUNT(*) FROM candidates'),
        'paid_candidates' => (int)q_value($conn, "SELECT COUNT(DISTINCT candidate_id) FROM payments WHERE status = 'success'"),
        'eligible_candidates' => (int)q_value($conn, "SELECT COUNT(*) FROM enrollments WHERE eligibility_status = 'eligible'"),
        'upcoming_batches' => (int)q_value($conn, "SELECT COUNT(DISTINCT b.id) FROM batches b JOIN exam_schedules s ON s.batch_id=b.id WHERE s.exam_date >= CURDATE() AND s.status='scheduled'"),
        'available_slots' => (int)q_value($conn, "SELECT COALESCE(SUM(seats_remaining),0) FROM exam_slots es JOIN exam_schedules s ON s.id=es.exam_schedule_id WHERE s.exam_date >= CURDATE() AND s.status='scheduled'"),
        'completed_assessments' => (int)q_value($conn, "SELECT COUNT(DISTINCT at.id) FROM attempts at LEFT JOIN results r ON r.attempt_id=at.id WHERE at.status='submitted' OR r.id IS NOT NULL"),
        'certificates' => (int)q_value($conn, 'SELECT COUNT(*) FROM certificates'),
        'level_counts' => $levelCounts,
        'recent_candidates' => get_recent_candidates($conn, 8),
        'upcoming_batch_rows' => get_upcoming_batches($conn, 8),
        'pending_attempt_count' => (int)q_value($conn, "SELECT COUNT(*) FROM attempts at LEFT JOIN results r ON r.attempt_id=at.id WHERE r.id IS NULL AND at.start_time IS NOT NULL AND (at.status IN ('submitted', 'expired') OR (at.status = 'in_progress' AND at.end_time IS NOT NULL AND at.end_time <= NOW()))")
    ];
}

function get_recent_candidates(mysqli $conn, int $limit = 10): array
{
    $limit = max(1, min($limit, 50));
    return q_all($conn, "SELECT c.id, c.full_name, u.email,
        COALESCE((SELECT p.status FROM payments p WHERE p.candidate_id=c.id ORDER BY p.id DESC LIMIT 1),'pending') payment_status,
        COALESCE(e.eligibility_status,'pending') enrollment_status,
        CASE WHEN EXISTS(SELECT 1 FROM results r JOIN attempts a ON a.id=r.attempt_id WHERE a.candidate_id=c.id)
             THEN 'completed'
             WHEN EXISTS(SELECT 1 FROM attempts a WHERE a.candidate_id=c.id AND a.status IN ('in_progress','submitted','expired')) THEN 'pending'
             ELSE 'not-started' END assessment_status
      FROM candidates c JOIN users u ON u.id=c.user_id
      LEFT JOIN enrollments e ON e.candidate_id=c.id
      ORDER BY c.created_at DESC LIMIT $limit");
}

function get_upcoming_batches(mysqli $conn, int $limit = 10): array
{
    $limit = max(1, min($limit, 50));
    return q_all($conn, "SELECT b.id, b.batch_number, a.title assessment_title,
        s.id schedule_id, s.exam_date, s.status schedule_status,
        COALESCE((SELECT COUNT(*) FROM enrollments e WHERE e.batch_id=b.id),0) candidate_count,
        COALESCE((SELECT SUM(es.seats_remaining) FROM exam_slots es WHERE es.exam_schedule_id=s.id),0) available_slots,
        COALESCE((SELECT SUM(es.capacity) FROM exam_slots es WHERE es.exam_schedule_id=s.id),0) total_capacity
      FROM batches b
      JOIN assessments a ON a.id=b.assessment_id
      JOIN exam_schedules s ON s.batch_id=b.id
      WHERE s.exam_date >= CURDATE() AND s.status='scheduled'
      ORDER BY s.exam_date ASC, b.id DESC LIMIT $limit");
}

function get_candidates(mysqli $conn): array
{
    return q_all($conn, "SELECT c.id, c.full_name, c.phone, u.email,
        COALESCE((SELECT p.status FROM payments p WHERE p.candidate_id=c.id ORDER BY p.id DESC LIMIT 1),'pending') payment_status,
        COALESCE((SELECT e.eligibility_status FROM enrollments e WHERE e.candidate_id=c.id ORDER BY e.id DESC LIMIT 1),'pending') enrollment_status,
        (SELECT a.title FROM enrollments e JOIN assessments a ON a.id=e.assessment_id WHERE e.candidate_id=c.id ORDER BY e.id DESC LIMIT 1) assessment_title,
        CASE
          WHEN EXISTS(SELECT 1 FROM results r JOIN attempts ax ON ax.id=r.attempt_id WHERE ax.candidate_id=c.id) THEN 'completed'
          WHEN EXISTS(SELECT 1 FROM attempts ax WHERE ax.candidate_id=c.id AND ax.status IN ('in_progress','submitted','expired')) THEN 'pending'
          ELSE 'not-started'
        END assessment_status,
        (SELECT r.level_assigned FROM results r JOIN attempts ax ON ax.id=r.attempt_id WHERE ax.candidate_id=c.id ORDER BY r.created_at DESC LIMIT 1) level_assigned
      FROM candidates c
      JOIN users u ON u.id=c.user_id
      ORDER BY c.created_at DESC");
}

function m7_get_candidate(mysqli $conn, int $candidateId): ?array
{
    return q_one($conn, "SELECT c.id,c.full_name,c.phone,c.profile_details,u.email,u.created_at,
        COALESCE((SELECT p.status FROM payments p WHERE p.candidate_id=c.id ORDER BY p.id DESC LIMIT 1),'pending') payment_status,
        COALESCE(e.eligibility_status,'pending') enrollment_status,
        a.title assessment_title
        FROM candidates c JOIN users u ON u.id=c.user_id
        LEFT JOIN enrollments e ON e.candidate_id=c.id
        LEFT JOIN assessments a ON a.id=e.assessment_id
        WHERE c.id=?", 'i', [$candidateId]);
}

function get_results(mysqli $conn): array
{
    return q_all($conn, "SELECT r.id, r.attempt_id, c.id candidate_id, c.full_name, u.email,
        a.title assessment_title, r.total_score, r.percentage, r.level_assigned,
        at.status attempt_status,
        CASE
          WHEN r.id IS NOT NULL THEN 'evaluated'
          WHEN at.status = 'in_progress' AND at.start_time IS NULL THEN 'not_started'
          WHEN at.status = 'in_progress' AND at.start_time IS NOT NULL THEN 'in_progress'
          WHEN at.status = 'submitted' THEN 'submitted'
          WHEN at.status = 'expired' THEN 'expired'
          ELSE at.status
        END AS display_status,
        r.created_at
      FROM results r
      JOIN attempts at ON at.id=r.attempt_id
      JOIN candidates c ON c.id=at.candidate_id
      JOIN users u ON u.id=c.user_id
      JOIN assessments a ON a.id=at.assessment_id
      ORDER BY r.created_at DESC");
}

function get_pending_attempts(mysqli $conn): array
{
    return q_all($conn, "SELECT at.id attempt_id, c.id candidate_id, c.full_name, u.email,
        a.title assessment_title, a.total_questions, at.status, at.start_time, at.end_time, at.created_at,
        CASE
          WHEN r.id IS NOT NULL THEN 'evaluated'
          WHEN at.status = 'in_progress' AND at.start_time IS NULL THEN 'not_started'
          WHEN at.status = 'in_progress' AND at.start_time IS NOT NULL THEN 'in_progress'
          WHEN at.status = 'submitted' THEN 'submitted'
          WHEN at.status = 'expired' THEN 'expired'
          ELSE at.status
        END AS display_status
      FROM attempts at
      JOIN candidates c ON c.id=at.candidate_id
      JOIN users u ON u.id=c.user_id
      JOIN assessments a ON a.id=at.assessment_id
      LEFT JOIN results r ON r.attempt_id=at.id
      WHERE r.id IS NULL
        AND at.start_time IS NOT NULL
        AND (at.status IN ('submitted', 'expired') OR (at.status = 'in_progress' AND at.end_time IS NOT NULL AND at.end_time <= NOW()))
      ORDER BY at.created_at DESC");
}

function get_attempt_detail(mysqli $conn, int $attemptId): ?array
{
    $attempt = q_one($conn, "SELECT at.id,at.candidate_id,at.assessment_id,at.exam_slot_id,at.status,
        at.start_time,at.end_time,at.submitted_at,c.full_name,u.email,a.title assessment_title,a.total_questions,
        CASE
          WHEN r.id IS NOT NULL THEN 'evaluated'
          WHEN at.status = 'in_progress' AND at.start_time IS NULL THEN 'not_started'
          WHEN at.status = 'in_progress' AND at.start_time IS NOT NULL THEN 'in_progress'
          WHEN at.status = 'submitted' THEN 'submitted'
          WHEN at.status = 'expired' THEN 'expired'
          ELSE at.status
        END AS display_status
        FROM attempts at
        JOIN candidates c ON c.id=at.candidate_id
        JOIN users u ON u.id=c.user_id
        JOIN assessments a ON a.id=at.assessment_id
        LEFT JOIN results r ON r.attempt_id=at.id
        WHERE at.id=?", 'i', [$attemptId]);
    if (!$attempt) return null;

    $attempt['answers'] = q_all($conn, "SELECT an.id answer_id, q.id question_id,q.question_text,
        an.selected_option_id,an.is_correct,
        (SELECT option_text FROM options WHERE id=an.selected_option_id) selected_option_text,
        (SELECT GROUP_CONCAT(CONCAT(o.id, ':', REPLACE(o.option_text, ':', ' ')) ORDER BY o.id SEPARATOR ' || ')
          FROM options o WHERE o.question_id=q.id) options
        FROM answers an JOIN questions q ON q.id=an.question_id
        WHERE an.attempt_id=? ORDER BY q.id", 'i', [$attemptId]);

    return $attempt;
}

function get_certificates(mysqli $conn): array
{
    return q_all($conn, "SELECT ce.id, ce.certificate_number, ce.candidate_id, c.full_name, u.email,
        ce.result_id, ce.level, ce.issue_date, r.percentage,
        'verified' AS certificate_status
      FROM certificates ce
      JOIN candidates c ON c.id=ce.candidate_id
      JOIN users u ON u.id=c.user_id
      JOIN results r ON r.id=ce.result_id
      ORDER BY ce.issue_date DESC, ce.id DESC");
}

function sync_placement_records(mysqli $conn): void
{
    $results=q_all($conn,"SELECT r.id result_id,at.candidate_id FROM results r JOIN attempts at ON at.id=r.attempt_id LEFT JOIN placement_records pr ON pr.result_id=r.id WHERE pr.id IS NULL AND r.level_assigned <= 2");
    if(!$results) return;
    $stmt=$conn->prepare("INSERT INTO placement_records(candidate_id,result_id,placement_status) VALUES(?,?,'eligible')");
    foreach($results as $row){
        $candidateId=(int)$row['candidate_id']; $resultId=(int)$row['result_id'];
        $stmt->bind_param('ii',$candidateId,$resultId); $stmt->execute();
    }
    $stmt->close();
}

function get_placement_records(mysqli $conn): array
{
    return q_all($conn, "SELECT pr.id, pr.candidate_id, c.full_name, c.phone, u.email, pr.result_id,
        r.percentage, r.level_assigned, pr.placement_status, pr.company_name, pr.notes, pr.updated_at
      FROM placement_records pr
      JOIN candidates c ON c.id=pr.candidate_id
      JOIN users u ON u.id=c.user_id
      LEFT JOIN results r ON r.id=pr.result_id
      ORDER BY pr.updated_at DESC");
}

function get_all_question_banks(mysqli $conn): array
{
    return q_all($conn, "SELECT qb.id, qb.name, a.title AS assessment_title 
        FROM question_banks qb 
        LEFT JOIN assessments a ON a.id = qb.assessment_id 
        ORDER BY qb.name ASC");
}

function get_questions(mysqli $conn, bool $isAdmin = false): array
{
    $correctOptionSql = $isAdmin 
        ? "(SELECT GROUP_CONCAT(CASE WHEN o.is_correct=1 THEN o.option_text END SEPARATOR ' | ') FROM options o WHERE o.question_id=q.id) correct_option"
        : "NULL as correct_option";

    return q_all($conn, "SELECT q.id, q.question_text, q.difficulty, q.approval_status,
        qb.id question_bank_id, qb.name question_bank, a.id assessment_id, a.title assessment_title,
        (SELECT COUNT(*) FROM options o WHERE o.question_id=q.id) option_count,
        $correctOptionSql
      FROM questions q
      JOIN question_banks qb ON qb.id=q.question_bank_id
      JOIN assessments a ON a.id=qb.assessment_id
      ORDER BY q.created_at DESC");
}

function get_batches(mysqli $conn): array
{
    $batches = q_all($conn, "SELECT b.id, b.batch_number, b.assessment_id, a.title assessment_title,
        s.id schedule_id, s.exam_date, s.status schedule_status,
        COALESCE((SELECT COUNT(*) FROM enrollments e WHERE e.batch_id=b.id),0) candidate_count,
        COALESCE((SELECT SUM(es.seats_remaining) FROM exam_slots es WHERE es.exam_schedule_id=s.id),0) available_slots,
        COALESCE((SELECT SUM(es.capacity) FROM exam_slots es WHERE es.exam_schedule_id=s.id),0) total_capacity
      FROM batches b
      JOIN assessments a ON a.id=b.assessment_id
      LEFT JOIN exam_schedules s ON s.batch_id=b.id
      ORDER BY s.exam_date IS NULL, s.exam_date ASC, b.id DESC");

    $slots = q_all($conn, "SELECT es.id slot_id, es.exam_schedule_id, b.id batch_id,b.batch_number,
        s.exam_date, es.start_time,es.end_time,es.capacity,es.seats_remaining,
        (es.capacity-es.seats_remaining) allocated
        FROM exam_slots es
        JOIN exam_schedules s ON s.id=es.exam_schedule_id
        JOIN batches b ON b.id=s.batch_id
        WHERE s.status <> 'cancelled'
        ORDER BY s.exam_date ASC, es.start_time ASC");

    $eligible = q_all($conn, "SELECT c.id candidate_id,c.full_name,u.email,e.id enrollment_id,
        e.assessment_id,e.eligibility_status,e.batch_id,
        (SELECT r.level_assigned FROM results r JOIN attempts ax ON ax.id=r.attempt_id WHERE ax.candidate_id=c.id ORDER BY r.created_at DESC LIMIT 1) level_assigned,
        COALESCE((SELECT p.status FROM payments p WHERE p.candidate_id=c.id ORDER BY p.id DESC LIMIT 1),'pending') payment_status
        FROM enrollments e
        JOIN candidates c ON c.id=e.candidate_id
        JOIN users u ON u.id=c.user_id
        WHERE e.eligibility_status='eligible' AND e.batch_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM attempts a2 WHERE a2.candidate_id = c.id AND a2.assessment_id = e.assessment_id)
        ORDER BY c.full_name ASC");

    $assessments = q_all($conn, "SELECT id,title,status,total_questions,duration_minutes
        FROM assessments WHERE status <> 'archived' ORDER BY status='active' DESC,id ASC");

    $pending_requests = q_all($conn, "SELECT 
        s.id as schedule_id,
        a.id as assessment_id,
        a.title AS assessment_title, 
        s.exam_date as preferred_date, 
        CONCAT(es.start_time, '-', es.end_time) as preferred_time_slot,
        es.capacity as capacity,
        s.is_closed,
        s.batch_alert_sent,
        (SELECT COUNT(*) FROM enrollments e WHERE e.provisional_schedule_id = s.id AND e.eligibility_status = 'eligible' AND e.batch_id IS NULL) as candidate_count
        FROM exam_schedules s
        JOIN batches b ON s.batch_id = b.id
        JOIN assessments a ON b.assessment_id = a.id
        JOIN exam_slots es ON es.exam_schedule_id = s.id
        WHERE s.status = 'provisional'
        ORDER BY s.exam_date ASC");

    return ['batches'=>$batches,'slots'=>$slots,'eligible_candidates'=>$eligible,'assessments'=>$assessments,'pending_requests'=>$pending_requests];
}

function get_settings(mysqli $conn): array
{
    $settings = q_all($conn, 'SELECT setting_key, setting_value, description FROM settings ORDER BY setting_key');
    $profile = null;
    $adminUserId = $_SESSION['user_id'] ?? null;

    if ($adminUserId) {
        $profile = q_one($conn, 'SELECT id,email FROM users WHERE id=? AND role IN (\'admin\',\'staff\') AND is_active=1', 'i', [(int)$adminUserId]);
    }
    if (!$profile) {
        $profile = q_one($conn, 'SELECT id,email FROM users WHERE role IN (\'admin\',\'staff\') AND is_active=1 ORDER BY id LIMIT 1');
    }

    $map=[];
    foreach($settings as $row) $map[$row['setting_key']]=$row['setting_value'];

    if ($profile) {
        $candidateProfile = q_one($conn, 'SELECT full_name,phone FROM candidates WHERE user_id=?', 'i', [(int)$profile['id']]);
        $profile['full_name'] = $map['admin_full_name'] ?? $candidateProfile['full_name'] ?? 'Admin';
        $profile['phone'] = $map['admin_phone'] ?? $candidateProfile['phone'] ?? '';
    } else {
        $profile = [
            'id'=>null,
            'email'=>$map['admin_email'] ?? 'admin@internboot.com',
            'full_name'=>$map['admin_full_name'] ?? 'Admin',
            'phone'=>$map['admin_phone'] ?? ''
        ];
    }
    $profile['avatar_url'] = $map['admin_avatar_url'] ?? '';
    if (strpos($profile['avatar_url'], '../public/') === 0) {
        $profile['avatar_url'] = substr($profile['avatar_url'], 9);
    }

    return ['settings'=>$settings,'profile'=>$profile];
}

function get_attempt_for_update(mysqli $conn, int $attemptId): ?array
{
    return q_one($conn, 'SELECT at.*, a.total_questions, a.title assessment_title FROM attempts at JOIN assessments a ON a.id=at.assessment_id WHERE at.id=? FOR UPDATE', 'i', [$attemptId]);
}

function get_answer_rows(mysqli $conn, int $attemptId): array
{
    return q_all($conn, "SELECT an.id answer_id, an.question_id, an.selected_option_id,
        CASE WHEN an.selected_option_id IS NOT NULL AND EXISTS(
          SELECT 1 FROM options o WHERE o.id=an.selected_option_id AND o.question_id=an.question_id AND o.is_correct=1
        ) THEN 1 ELSE 0 END AS is_correct,
        an.selected_option_id IS NOT NULL AS was_answered
      FROM answers an WHERE an.attempt_id=?", 'i', [$attemptId]);
}

function get_level_for_percentage(mysqli $conn, float $percentage): ?array
{
    return q_one($conn, 'SELECT level_number, level_name, min_percentage, max_percentage FROM levels WHERE ? BETWEEN min_percentage AND max_percentage ORDER BY level_number LIMIT 1', 'd', [$percentage]);
}

function save_answer_correctness(mysqli $conn, int $answerId, int $isCorrect): void
{
    $stmt=$conn->prepare('UPDATE answers SET is_correct=? WHERE id=?');
    $stmt->bind_param('ii',$isCorrect,$answerId); $stmt->execute(); $stmt->close();
}

function update_attempt_submitted(mysqli $conn, int $attemptId): void
{
    $stmt=$conn->prepare("UPDATE attempts SET status='submitted', submitted_at=NOW(), updated_at=NOW() WHERE id=?");
    $stmt->bind_param('i',$attemptId); $stmt->execute(); $stmt->close();
}

function mark_attempt_evaluated(mysqli $conn, int $attemptId): void
{
    $stmt = $conn->prepare("UPDATE attempts SET submitted_at = IF(submitted_at IS NULL, NOW(), submitted_at), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $attemptId);
    $stmt->execute();
    $stmt->close();
}

function upsert_result(mysqli $conn, int $attemptId, float $score, float $percentage, int $level): int
{
    $existing=q_one($conn,'SELECT id FROM results WHERE attempt_id=?','i',[$attemptId]);
    if($existing){
        $stmt=$conn->prepare('UPDATE results SET total_score=?, percentage=?, level_assigned=?, updated_at=NOW() WHERE id=?');
        $id=(int)$existing['id']; $stmt->bind_param('ddii',$score,$percentage,$level,$id); $stmt->execute(); $stmt->close(); return $id;
    }
    $stmt=$conn->prepare('INSERT INTO results(attempt_id,total_score,percentage,level_assigned) VALUES(?,?,?,?)');
    $stmt->bind_param('iddi',$attemptId,$score,$percentage,$level); $stmt->execute(); $id=$stmt->insert_id; $stmt->close(); return $id;
}

function upsert_certificate(mysqli $conn, int $candidateId, int $resultId, int $level): array
{
    $existing=q_one($conn,'SELECT id, certificate_number, issue_date FROM certificates WHERE result_id=?','i',[$resultId]);
    if($existing) return $existing;

    require_once __DIR__ . '/../m5_batch_slots/queries.php';
    $minCertLevel = (int)(get_setting_value('min_certificate_level', $conn) ?? 4);
    if ($level > $minCertLevel) {
        throw new InvalidArgumentException("Result level {$level} does not qualify for certificate issuance (minimum Level {$minCertLevel} required).");
    }

    $number='IB-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(5)));
    while(q_one($conn,'SELECT id FROM certificates WHERE certificate_number=?','s',[$number])){
        $number='IB-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(5)));
    }
    $stmt=$conn->prepare('INSERT INTO certificates(certificate_number,candidate_id,result_id,level,issue_date) VALUES(?,?,?,?,CURDATE())');
    $stmt->bind_param('siii',$number,$candidateId,$resultId,$level); $stmt->execute(); $id=$stmt->insert_id; $stmt->close();
    return ['id'=>$id,'certificate_number'=>$number,'issue_date'=>date('Y-m-d')];
}

function update_placement(mysqli $conn,int $id,string $status,?string $company,?string $notes): void
{
    $stmt=$conn->prepare('UPDATE placement_records SET placement_status=?, company_name=?, notes=?, updated_at=NOW() WHERE id=?');
    $stmt->bind_param('sssi',$status,$company,$notes,$id); $stmt->execute(); $stmt->close();
}

function update_question_status(mysqli $conn, int $questionId, string $status): void
{
    $allowed=['pending','approved','rejected'];
    if (!in_array($status,$allowed,true)) throw new InvalidArgumentException('Invalid question status.');
    $exists=q_one($conn,'SELECT id FROM questions WHERE id=?','i',[$questionId]);
    if(!$exists) throw new InvalidArgumentException('Question not found.');
    
    if ($status === 'rejected') {
        $stmt=$conn->prepare('DELETE FROM questions WHERE id=?');
        $stmt->bind_param('i',$questionId);
    } else {
        $stmt=$conn->prepare('UPDATE questions SET approval_status=?, updated_at=NOW() WHERE id=?');
        $stmt->bind_param('si',$status,$questionId);
    }
    $stmt->execute();
    $stmt->close();
}

function update_setting(mysqli $conn, string $key, string $value): void
{
    $stmt=$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");
    $stmt->bind_param('ss',$key,$value);
    $stmt->execute();
    $stmt->close();
}

function ensure_placement_record(mysqli $conn, int $candidateId, int $resultId): void
{
    $res=q_one($conn,'SELECT level_assigned FROM results WHERE id=?','i',[$resultId]);
    if(!$res || (int)$res['level_assigned'] > 2) return;
    $existing=q_one($conn,'SELECT id FROM placement_records WHERE result_id=?','i',[$resultId]);
    if($existing) return;
    $stmt=$conn->prepare("INSERT INTO placement_records(candidate_id,result_id,placement_status) VALUES(?,?,'eligible')");
    $stmt->bind_param('ii',$candidateId,$resultId);
    $stmt->execute();
    $stmt->close();
}

function verify_certificate(mysqli $conn, string $certificateNumber): ?array
{
    return q_one($conn,"SELECT ce.certificate_number,ce.issue_date,ce.level,
        c.full_name,u.email,a.title assessment_title,r.percentage
      FROM certificates ce
      JOIN candidates c ON c.id=ce.candidate_id
      JOIN users u ON u.id=c.user_id
      JOIN results r ON r.id=ce.result_id
      JOIN attempts at ON at.id=r.attempt_id
      JOIN assessments a ON a.id=at.assessment_id
      WHERE ce.certificate_number=? LIMIT 1",'s',[$certificateNumber]);
}

function create_batch(mysqli $conn, string $batchNumber, int $assessmentId, string $examDate, int $capacity, ?string $startTime = null, ?string $endTime = null): array
{
    $batchNumber=trim($batchNumber);
    if($batchNumber==='' || strlen($batchNumber)>50) throw new InvalidArgumentException('Batch name must be between 1 and 50 characters.');
    if($capacity<100) throw new InvalidArgumentException('Batch capacity must be at least 100 candidates.');

    $date=DateTime::createFromFormat('!Y-m-d',$examDate);
    $errors=DateTime::getLastErrors();
    if(!$date || ($errors!==false && ($errors['warning_count']||$errors['error_count'])) || $date->format('Y-m-d')!==$examDate){
        throw new InvalidArgumentException('Enter a valid exam date.');
    }
    if((int)$date->format('N')<6) throw new InvalidArgumentException('Exam date must be Saturday or Sunday.');
    if($date < new DateTime('today')) throw new InvalidArgumentException('Exam date cannot be in the past.');

    if($assessmentId>0){
        $assessment=q_one($conn,'SELECT id,title,duration_minutes,status FROM assessments WHERE id=? AND status<>"archived"','i',[$assessmentId]);
    }else{
        $assessment=q_one($conn,'SELECT id,title,duration_minutes,status FROM assessments WHERE status="active" ORDER BY id DESC LIMIT 1');
        if(!$assessment) $assessment=q_one($conn,'SELECT id,title,duration_minutes,status FROM assessments WHERE status<>"archived" ORDER BY id DESC LIMIT 1');
    }
    if(!$assessment) throw new InvalidArgumentException('No active assessment exists. Create or activate an assessment first.');
    $assessmentId=(int)$assessment['id'];

    $threshold=(int)(q_value($conn,"SELECT setting_value FROM settings WHERE setting_key='batch_threshold' LIMIT 1") ?? 100);
    $threshold=max(1,$threshold);
    if($capacity<$threshold) throw new InvalidArgumentException("Batch capacity must be at least {$threshold} candidates.");
    $eligibleCount=(int)(q_value($conn,"SELECT COUNT(*) FROM enrollments WHERE assessment_id=? AND eligibility_status='eligible' AND batch_id IS NULL",'i',[$assessmentId]) ?? 0);
    if($eligibleCount<$threshold) throw new InvalidArgumentException("At least {$threshold} eligible unallocated candidates are required to create a batch. Currently available: {$eligibleCount}.");

    $duplicate=q_one($conn,'SELECT id FROM batches WHERE batch_number=?','s',[$batchNumber]);
    if($duplicate) throw new InvalidArgumentException('A batch with this name already exists.');

    $duration=max(1,(int)$assessment['duration_minutes']);

    $conn->begin_transaction();
    try{
        $stmt=$conn->prepare('INSERT INTO batches(batch_number,assessment_id) VALUES(?,?)');
        $stmt->bind_param('si',$batchNumber,$assessmentId); $stmt->execute(); $batchId=$stmt->insert_id; $stmt->close();

        $stmt=$conn->prepare("INSERT INTO exam_schedules(batch_id,exam_date,status) VALUES(?,?,'scheduled')");
        $stmt->bind_param('is',$batchId,$examDate); $stmt->execute(); $scheduleId=$stmt->insert_id; $stmt->close();

        $start1 = $startTime ?? '10:00:00';
        $end1 = $endTime ?? (new DateTime('2000-01-01 ' . $start1))->modify("+{$duration} minutes")->format('H:i:s');

        $stmt=$conn->prepare('INSERT INTO exam_slots(exam_schedule_id,start_time,end_time,capacity,seats_remaining) VALUES(?,?,?,?,?)');
        $stmt->bind_param('issii',$scheduleId,$start1,$end1,$capacity,$capacity); $stmt->execute(); $slot1=$stmt->insert_id; $stmt->close();

        $conn->commit();
        return ['batch_id'=>$batchId,'batch_number'=>$batchNumber,'assessment_id'=>$assessmentId,'assessment_title'=>$assessment['title'],'schedule_id'=>$scheduleId,'exam_date'=>$examDate,'capacity'=>$capacity,'slots'=>[
            ['slot_id'=>$slot1,'start_time'=>$start1,'end_time'=>$end1,'capacity'=>$capacity]
        ]];
    }catch(Throwable $e){$conn->rollback();throw $e;}
}

function create_admin_candidate(mysqli $conn, string $name, string $email, string $phone): array
{
    $name = trim($name);
    $email = trim($email);
    $phone = trim($phone);

    if ($name === '' || strlen($name) > 150) throw new InvalidArgumentException('Full name is required and must be 150 characters or fewer.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) throw new InvalidArgumentException('Enter a valid email address.');
    if ($phone === '' || strlen($phone) > 20) throw new InvalidArgumentException('Enter a valid phone number.');

    if (q_one($conn, 'SELECT id FROM users WHERE email=? LIMIT 1', 's', [$email])) {
        throw new InvalidArgumentException('That email address is already registered.');
    }
    if (q_one($conn, 'SELECT id FROM candidates WHERE phone=? LIMIT 1', 's', [$phone])) {
        throw new InvalidArgumentException('That phone number is already registered.');
    }

    $temporaryPassword = 'IB-' . strtoupper(bin2hex(random_bytes(4)));
    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO users(email,password,role,is_active) VALUES(?,?,'candidate',1)");
        $stmt->bind_param('ss', $email, $passwordHash);
        $stmt->execute();
        $userId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare('INSERT INTO candidates(user_id,full_name,phone,profile_details) VALUES(?,?,?,NULL)');
        $stmt->bind_param('iss', $userId, $name, $phone);
        $stmt->execute();
        $candidateId = (int)$stmt->insert_id;
        $stmt->close();

        $conn->commit();
        return [
            'id' => $candidateId,
            'user_id' => $userId,
            'full_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'temporary_password' => $temporaryPassword
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function update_admin_profile(mysqli $conn, ?int $userId, string $name, string $email, string $phone): void
{
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if(strlen($name)>150 || strlen($phone)>20 || strlen($email)>255) throw new InvalidArgumentException('Profile field is too long.');

    $user = null;
    if ($userId) $user=q_one($conn,'SELECT id FROM users WHERE id=? AND role IN (\'admin\',\'staff\') AND is_active=1','i',[$userId]);
    if (!$user) $user=q_one($conn,'SELECT id FROM users WHERE role IN (\'admin\',\'staff\') AND is_active=1 ORDER BY id LIMIT 1');

    $conn->begin_transaction();
    try {
        if($user){
            $uid=(int)$user['id'];
            $other=q_one($conn,'SELECT id FROM users WHERE email=? AND id<>? LIMIT 1','si',[$email,$uid]);
            if($other) throw new InvalidArgumentException('That email address is already in use.');
            $stmt=$conn->prepare('UPDATE users SET email=?,updated_at=NOW() WHERE id=?');
            $stmt->bind_param('si',$email,$uid); $stmt->execute(); $stmt->close();

            $candidate=q_one($conn,'SELECT id FROM candidates WHERE user_id=?','i',[$uid]);
            if ($candidate) {
                $cid=(int)$candidate['id'];
                $stmt=$conn->prepare('UPDATE candidates SET full_name=?,phone=?,updated_at=NOW() WHERE id=?');
                $stmt->bind_param('ssi',$name,$phone,$cid); $stmt->execute(); $stmt->close();
            }
        }
        update_setting($conn,'admin_full_name',$name);
        update_setting($conn,'admin_email',$email);
        update_setting($conn,'admin_phone',$phone);
        $conn->commit();
    } catch(Throwable $e) { $conn->rollback(); throw $e; }
}

function allocate_candidate_to_batch(mysqli $conn,int $enrollmentId,int $batchId,int $slotId): array
{
    $conn->begin_transaction();
    try {
        $enrollment=q_one($conn,'SELECT id,candidate_id,assessment_id,eligibility_status,batch_id FROM enrollments WHERE id=? FOR UPDATE','i',[$enrollmentId]);
        if(!$enrollment) throw new InvalidArgumentException('Enrollment not found.');
        if($enrollment['eligibility_status']!=='eligible') throw new InvalidArgumentException('Candidate is not eligible.');
        if(!empty($enrollment['batch_id'])) throw new InvalidArgumentException('Candidate is already allocated.');

        require_once __DIR__ . '/../m5_batch_slots/queries.php';
        $retakeAllowed = get_setting_value('retake_allowed', $conn) === '1';
        $statusFilter = $retakeAllowed ? "'in_progress'" : "'in_progress','submitted'";
        $existingAttempt = q_one($conn, "SELECT id FROM attempts WHERE candidate_id=? AND assessment_id=? AND status IN ($statusFilter) LIMIT 1 FOR UPDATE", 'ii', [(int)$enrollment['candidate_id'], (int)$enrollment['assessment_id']]);
        if ($existingAttempt) {
            throw new InvalidArgumentException('Candidate already has a booked slot for this assessment.');
        }

        $slot=q_one($conn,'SELECT es.id,es.exam_schedule_id,es.seats_remaining,b.id batch_id,b.assessment_id,s.exam_date,s.status schedule_status
            FROM exam_slots es JOIN exam_schedules s ON s.id=es.exam_schedule_id JOIN batches b ON b.id=s.batch_id
            WHERE es.id=? FOR UPDATE','i',[$slotId]);
        if(!$slot || (int)$slot['batch_id']!==$batchId) throw new InvalidArgumentException('Invalid slot for this batch.');
        if((int)$slot['seats_remaining']<=0) throw new InvalidArgumentException('Selected slot is full.');
        if((int)$slot['assessment_id']!==(int)$enrollment['assessment_id']) throw new InvalidArgumentException('Candidate assessment does not match this batch.');
        if($slot['schedule_status']!=='scheduled') throw new InvalidArgumentException('This exam schedule is not open for allocation.');
        if($slot['exam_date'] < date('Y-m-d')) throw new InvalidArgumentException('Cannot allocate a candidate to a past exam date.');

        $stmt=$conn->prepare('UPDATE enrollments SET batch_id=?,updated_at=NOW() WHERE id=?');
        $stmt->bind_param('ii',$batchId,$enrollmentId); $stmt->execute(); $stmt->close();

        $stmt=$conn->prepare('UPDATE exam_slots SET seats_remaining=seats_remaining-1,updated_at=NOW() WHERE id=? AND seats_remaining>0');
        $stmt->bind_param('i',$slotId); $stmt->execute();
        if($stmt->affected_rows!==1) { $stmt->close(); throw new RuntimeException('Slot allocation failed.'); }
        $stmt->close();

        $stmt = $conn->prepare('INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, created_at) VALUES (?, ?, ?, "in_progress", NOW())');
        $candId = (int)$enrollment['candidate_id'];
        $assId = (int)$enrollment['assessment_id'];
        $stmt->bind_param('iii', $candId, $assId, $slotId);
        $stmt->execute();
        if ($conn->errno) {
            throw new mysqli_sql_exception($conn->error, $conn->errno);
        }
        $attemptId = (int)$stmt->insert_id;
        $stmt->close();

        $conn->commit();
        return ['enrollment_id'=>$enrollmentId,'batch_id'=>$batchId,'slot_id'=>$slotId,'attempt_id'=>$attemptId];
    } catch(Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function create_exam_slot(mysqli $conn,int $batchId,string $startTime,string $endTime,int $capacity): array
{
    if ($capacity < 1) throw new InvalidArgumentException('Slot capacity must be at least 1.');
    if (!preg_match('/^\d{2}:\d{2}$/',$startTime) || !preg_match('/^\d{2}:\d{2}$/',$endTime)) {
        throw new InvalidArgumentException('Time must use HH:MM format.');
    }
    $start=new DateTime('2000-01-01 '.$startTime.':00');
    $end=new DateTime('2000-01-01 '.$endTime.':00');
    if($end <= $start) throw new InvalidArgumentException('Slot end time must be after start time.');

    $schedule=q_one($conn,'SELECT id,exam_date,status FROM exam_schedules WHERE batch_id=? AND status<>"cancelled" ORDER BY id DESC LIMIT 1','i',[$batchId]);
    if(!$schedule) throw new InvalidArgumentException('Batch has no active exam schedule.');
    if($schedule['exam_date'] < date('Y-m-d')) throw new InvalidArgumentException('Cannot create a slot for a past exam date.');

    $overlap=q_one($conn,"SELECT id FROM exam_slots WHERE exam_schedule_id=? AND start_time<? AND end_time>? LIMIT 1",'iss',[(int)$schedule['id'],$endTime,$startTime]);
    if($overlap) throw new InvalidArgumentException('This slot overlaps an existing slot.');

    $stmt=$conn->prepare('INSERT INTO exam_slots(exam_schedule_id,start_time,end_time,capacity,seats_remaining) VALUES(?,?,?,?,?)');
    $scheduleId=(int)$schedule['id'];
    $stmt->bind_param('issii',$scheduleId,$startTime,$endTime,$capacity,$capacity);
    $stmt->execute();
    $id=$stmt->insert_id;
    $stmt->close();
    return ['slot_id'=>$id,'batch_id'=>$batchId,'exam_date'=>$schedule['exam_date'],'start_time'=>$startTime,'end_time'=>$endTime,'capacity'=>$capacity];
}

function delete_exam_slot(mysqli $conn, int $slotId): void
{
    $slot = q_one($conn, 'SELECT capacity, seats_remaining FROM exam_slots WHERE id = ?', 'i', [$slotId]);
    if (!$slot) throw new InvalidArgumentException('Exam slot not found.');
    
    if ((int)$slot['capacity'] !== (int)$slot['seats_remaining']) {
        throw new InvalidArgumentException('Cannot delete slot: candidates are currently allocated to it.');
    }
    
    $attempt = q_one($conn, 'SELECT id FROM attempts WHERE exam_slot_id = ? LIMIT 1', 'i', [$slotId]);
    if ($attempt) {
        throw new InvalidArgumentException('Cannot delete slot: candidates have already attempted the exam in this slot.');
    }
    
    try {
        $stmt = $conn->prepare('DELETE FROM exam_slots WHERE id = ?');
        $stmt->bind_param('i', $slotId);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1451) {
            throw new InvalidArgumentException('Cannot delete this slot because it is linked to existing candidate records.');
        }
        throw $e;
    }
}

function create_admin_log(mysqli $conn, ?int $userId, string $action, ?string $details = null): void
{
    $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null);
    $stmt = $conn->prepare(
        'INSERT INTO admin_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())'
    );
    if (!$stmt) {
        throw new Exception("Failed to prepare admin log insert query: " . (@$conn->error ?: 'query error'));
    }
    $stmt->bind_param('isss', $userId, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

