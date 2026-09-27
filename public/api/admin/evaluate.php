<?php
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/controller.php';

try {
    m7_handle_request($conn);
} catch (InvalidArgumentException $e) {
    send_json_response('error', $e->getMessage(), null, 422);
} catch (mysqli_sql_exception $e) {
    error_log('InternBoot M7 DB error: ' . $e->getMessage());
    send_json_response('error', is_dev_env() ? $e->getMessage() : 'Database operation failed.', null, 500);
} catch (Throwable $e) {
    error_log('InternBoot M7 error: ' . $e->getMessage());
    send_json_response('error', is_dev_env() ? $e->getMessage() : 'An unexpected server error occurred.', null, 500);
}

