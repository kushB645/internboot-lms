<?php
require_once __DIR__ . '/service.php';

function m7_request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('Request body must contain valid JSON.');
    }
    return is_array($data) ? $data : [];
}

/**
 * Strip PII fields that staff must not see from a single record or a list.
 *
 * Fields removed for staff: email, phone, profile_details.
 * Admin receives the full, unfiltered row — this function is a no-op for admins.
 *
 * @param array  $rows   A single associative record OR a flat list of records.
 * @param bool   $isList True when $rows is an array-of-arrays (list endpoint).
 * @param mysqli $conn   DB connection (for resolve_admin_role()).
 * @return array         Filtered record(s).
 */
function m7_strip_pii_for_staff(array $rows, bool $isList, mysqli $conn): array
{
    if (resolve_admin_role($conn) === 'admin') {
        return $rows; // Admin sees everything — no change.
    }

    static $piiFields = ['email', 'phone', 'profile_details'];

    if ($isList) {
        return array_map(static function (array $row) use ($piiFields): array {
            return array_diff_key($row, array_flip($piiFields));
        }, $rows);
    }

    return array_diff_key($rows, array_flip($piiFields));
}

function m7_handle_request(mysqli $conn): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? 'dashboard';

    if ($method === 'GET' && $action === 'csrf') {
        send_json_response('success', 'Security token loaded.', ['token' => csrf_token()]);
    }

    require_admin_access($conn);

    if ($method === 'GET') {
        switch ($action) {
            case 'dashboard': send_json_response('success','Dashboard data loaded',m7_dashboard($conn));
            case 'ai-status': 
                require_once __DIR__ . '/../m1_ai_qbank/controller.php';
                handle_ai_status_request($conn);
                break;
            case 'candidates':
                $candidateList = m7_strip_pii_for_staff(m7_candidates($conn), true, $conn);
                send_json_response('success', 'Candidates loaded', ['candidates' => $candidateList]);
            case 'candidate':
                $id=require_positive_int($_GET['id']??null,'id');
                $data=m7_get_candidate($conn,$id);
                if(!$data) throw new InvalidArgumentException('Candidate not found.');
                $data = m7_strip_pii_for_staff($data, false, $conn);
                send_json_response('success', 'Candidate loaded', $data);
            case 'results':
                $resultList = m7_strip_pii_for_staff(m7_results($conn), true, $conn);
                send_json_response('success', 'Results loaded', ['results' => $resultList]);
            case 'pending-attempts':
                $attemptList = m7_strip_pii_for_staff(m7_pending_attempts($conn), true, $conn);
                send_json_response('success', 'Pending attempts loaded', ['attempts' => $attemptList]);
            case 'attempt':
                require_admin_only($conn);
                $id=require_positive_int($_GET['id']??null,'id');
                $data=get_attempt_detail($conn,$id);
                if(!$data) throw new InvalidArgumentException('Attempt not found.');
                send_json_response('success','Attempt loaded',$data);
            case 'certificates':
                $certList = m7_strip_pii_for_staff(m7_certificates($conn), true, $conn);
                send_json_response('success', 'Certificates loaded', ['certificates' => $certList]);
            case 'certificate-verify':
                $number=trim((string)($_GET['certificate_number']??''));
                if($number==='' || strlen($number)>100) throw new InvalidArgumentException('Enter a valid certificate number.');
                $certificate=verify_certificate($conn,$number);
                if(!$certificate) send_json_response('success','Certificate not found.',['verified'=>false]);
                send_json_response('success','Certificate verified.',['verified'=>true,'certificate'=>$certificate]);
            case 'placements': send_json_response('success','Placement records loaded',['placements'=>m7_placements($conn)]);
            case 'questions':
                $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
                send_json_response('success','Questions loaded',['questions'=>m7_questions($conn, $isAdmin)]);
            case 'batches': send_json_response('success','Batches loaded',m7_batches($conn));
            case 'question-banks': send_json_response('success','Question banks loaded',['question_banks'=>m7_question_banks($conn)]);
            case 'settings': send_json_response('success','Settings loaded',m7_settings($conn));
            case 'health':
                $required=['users','candidates','payments','assessments','batches','enrollments','exam_schedules','exam_slots','question_banks','questions','options','attempts','answers','results','levels','certificates','placement_records','admin_logs','settings'];
                $missing=[];
                $stmt=$conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
                foreach($required as $table){
                    $stmt->bind_param('s',$table); $stmt->execute();
                    if(!$stmt->get_result()->fetch_row()) $missing[]=$table;
                }
                $stmt->close();
                send_json_response('success','M7 health check completed',['database'=>'connected','missing_tables'=>$missing,'ready'=>count($missing)===0]);
            default: throw new InvalidArgumentException('Unknown admin action.');
        }
    }

    if($method!=='POST') send_json_response('error','Method not allowed.',null,405);
    require_csrf();

    if (in_array($action, [
        'setting', 'batch', 'slot', 'slot_delete', 'allocate',
        'evaluate', 'certificate', 'certificate-next', 'placement', 'question-status'
    ], true)) {
        require_admin_only($conn);
    }

    /* Avatar upload uses multipart/form-data; all other POST actions use JSON. */
    if ($action === 'avatar-upload') {
        if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
            throw new InvalidArgumentException('Please choose an image.');
        }
        $file = $_FILES['avatar'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The image upload failed.');
        }
        if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Image must be 2 MB or smaller.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $info = @getimagesize($tmp);
        if (!$info || empty($info['mime'])) throw new InvalidArgumentException('Please upload a valid image.');
        $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        $mime = strtolower((string)$info['mime']);
        if (!isset($allowed[$mime])) throw new InvalidArgumentException('Only PNG, JPG or WEBP images are allowed.');

        $adminUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($adminUserId <= 0) {
            $admin = q_one($conn, 'SELECT id FROM users WHERE role IN (\'admin\',\'staff\') AND is_active=1 ORDER BY id LIMIT 1');
            $adminUserId = $admin ? (int)$admin['id'] : 0;
        }
        if ($adminUserId <= 0) throw new InvalidArgumentException('Administrator account could not be resolved.');

        $uploadDir = dirname(__DIR__, 3) . '/public/uploads';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Upload directory could not be created.');
        }
        $filename = 'admin-avatar-' . $adminUserId . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
        $destination = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('Could not save the uploaded image.');

        $avatarUrl = '/uploads/' . $filename;
        update_setting($conn, 'admin_avatar_url', $avatarUrl);
        create_admin_log($conn, $adminUserId, 'avatar_update', json_encode(['avatar_url'=>$avatarUrl]));
        send_json_response('success','Profile photo updated successfully.', ['avatar_url'=>$avatarUrl]);
    }

    $body=m7_request_body();

    switch($action){
        case 'candidate-create':
            $name = trim((string)($body['full_name'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $phone = trim((string)($body['phone'] ?? ''));
            $data = create_admin_candidate($conn, $name, $email, $phone);
            create_admin_log($conn, $_SESSION['user_id'] ?? null, 'create_candidate', json_encode([
                'candidate_id' => $data['id'],
                'email' => $data['email']
            ]));
            send_json_response('success', 'Candidate created successfully.', $data, 201);

        case 'evaluate':
            $id=require_positive_int($body['attempt_id']??null,'attempt_id');
            $forceRegrade=(bool)($body['force_regrade']??false);
            // Certificate auto-issue is unconditional inside evaluate_attempt();
            // eligibility is checked there against min_certificate_level/percentage settings.
            $data=evaluate_attempt($conn,$id,true,$forceRegrade);
            create_admin_log($conn,$_SESSION['user_id']??null,'evaluate_attempt_request',json_encode(['attempt_id'=>$id,'force_regrade'=>$forceRegrade]));
            send_json_response('success','Attempt evaluated successfully.',$data);

        case 'certificate':
            $id=require_positive_int($body['result_id']??null,'result_id');
            $data=generate_certificate($conn,$id);
            create_admin_log($conn,$_SESSION['user_id']??null,'generate_certificate',json_encode(['result_id'=>$id,'certificate_id'=>$data['id']]));
            send_json_response('success','Certificate generated successfully.',$data);

        case 'certificate-next':
            $data=generate_next_certificate($conn);
            create_admin_log($conn,$_SESSION['user_id']??null,'generate_certificate',json_encode(['result_id'=>$data['result_id']??null,'certificate_id'=>$data['id']]));
            send_json_response('success','Certificate generated successfully.',$data);

        case 'placement':
            $id=require_positive_int($body['id']??null,'id');
            $status=trim((string)($body['status']??''));
            $allowed=['eligible','shortlisted','interviewing','placed','not_placed'];
            if(!in_array($status,$allowed,true)) throw new InvalidArgumentException('Invalid placement status.');
            update_placement($conn,$id,$status,isset($body['company_name'])?(string)$body['company_name']:null,isset($body['notes'])?(string)$body['notes']:null);
            create_admin_log($conn,$_SESSION['user_id']??null,'update_placement',json_encode(['placement_id'=>$id,'status'=>$status]));
            send_json_response('success','Placement record updated successfully.');

        case 'batch':
            $date=trim((string)($body['exam_date']??''));
            $capacity=require_positive_int($body['capacity']??null,'capacity');
            $name=trim((string)($body['batch_number']??('BATCH-'.date('Ymd-His'))));
            $assessmentId=!empty($body['assessment_id']) ? require_positive_int($body['assessment_id'],'assessment_id') : 0;
            $startTime = !empty($body['start_time']) ? trim((string)$body['start_time']) : null;
            $endTime = !empty($body['end_time']) ? trim((string)$body['end_time']) : null;
            $data=create_batch($conn,$name,$assessmentId,$date,$capacity,$startTime,$endTime);
            create_admin_log($conn,$_SESSION['user_id']??null,'create_batch',json_encode($data));
            send_json_response('success','Batch and exam slots created successfully.',$data,201);

        case 'slot':
            $batchId=require_positive_int($body['batch_id']??null,'batch_id');
            $start=trim((string)($body['start_time']??''));
            $end=trim((string)($body['end_time']??''));
            $capacity=require_positive_int($body['capacity']??null,'capacity');
            $data=create_exam_slot($conn,$batchId,$start,$end,$capacity);
            create_admin_log($conn,$_SESSION['user_id']??null,'create_slot',json_encode($data));
            send_json_response('success','Exam slot created successfully.',$data,201);

        case 'slot_delete':
            $slotId = require_positive_int($body['slot_id'] ?? null, 'slot_id');
            delete_exam_slot($conn, $slotId);
            create_admin_log($conn, $_SESSION['user_id'] ?? null, 'delete_slot', json_encode(['slot_id' => $slotId]));
            send_json_response('success', 'Exam slot deleted successfully.');

        case 'allocate':
            $enrollmentId=require_positive_int($body['enrollment_id']??null,'enrollment_id');
            $batchId=require_positive_int($body['batch_id']??null,'batch_id');
            $slotId=require_positive_int($body['slot_id']??null,'slot_id');
            $data=allocate_candidate_to_batch($conn,$enrollmentId,$batchId,$slotId);
            create_admin_log($conn,$_SESSION['user_id']??null,'allocate_candidate',json_encode($data));
            send_json_response('success','Candidate allocated successfully.',$data);

        case 'question-status':
            $id=require_positive_int($body['question_id']??null,'question_id');
            $status=trim((string)($body['status']??''));
            update_question_status($conn,$id,$status);
            create_admin_log($conn,$_SESSION['user_id']??null,'question_status',json_encode(['question_id'=>$id,'status'=>$status]));
            send_json_response('success','Question status updated successfully.');

        case 'setting':
            $key=trim((string)($body['key']??''));
            $value=trim((string)($body['value']??''));
            if($key==='' || strlen($key)>100) throw new InvalidArgumentException('Invalid setting key.');
            if(strlen($value)>255) throw new InvalidArgumentException('Setting value is too long.');

            // Server-side validation for known business-rule keys.
            // Rejects out-of-range values even if the client UI is bypassed.
            $knownBoolKeys = ['negative_marking_enabled', 'retake_allowed'];
            if (in_array($key, $knownBoolKeys, true)) {
                if ($value !== '0' && $value !== '1') {
                    throw new InvalidArgumentException("Setting '{$key}' must be '0' or '1'.");
                }
            } elseif ($key === 'batch_threshold') {
                if (!ctype_digit($value) || (int)$value < 1) {
                    throw new InvalidArgumentException("batch_threshold must be a positive integer (>= 1).");
                }
            } elseif ($key === 'exam_fee') {
                if (!is_numeric($value) || (float)$value <= 0) {
                    throw new InvalidArgumentException("exam_fee must be a positive number.");
                }
            } elseif ($key === 'min_certificate_level') {
                $lvl = (int)$value;
                if (!ctype_digit(ltrim($value, '0') ?: '0') || $lvl < 1 || $lvl > 5) {
                    throw new InvalidArgumentException("min_certificate_level must be an integer between 1 and 5.");
                }
            } elseif ($key === 'min_certificate_percentage') {
                if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100) {
                    throw new InvalidArgumentException("min_certificate_percentage must be between 0 and 100.");
                }
            } elseif ($key === 'negative_marking_value') {
                if (!is_numeric($value) || (float)$value < 0 || (float)$value > 1) {
                    throw new InvalidArgumentException("negative_marking_value must be between 0 and 1.");
                }
            }

            update_setting($conn,$key,$value);
            create_admin_log($conn,$_SESSION['user_id']??null,'update_setting',json_encode(['key'=>$key,'value'=>$value]));
            send_json_response('success','Setting saved successfully.');


        case 'profile':
            $name=trim((string)($body['full_name']??''));
            $email=trim((string)($body['email']??''));
            $phone=trim((string)($body['phone']??''));
            if($name==='') throw new InvalidArgumentException('Full name is required.');
            update_admin_profile($conn,$_SESSION['user_id']??null,$name,$email,$phone);
            send_json_response('success','Profile saved successfully.');

        case 'logout':
            require_once __DIR__ . '/../m3_auth/controller.php';
            handle_logout_request();


        default: throw new InvalidArgumentException('Unknown admin action.');
    }
}
