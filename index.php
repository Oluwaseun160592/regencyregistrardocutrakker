<?php
// Regency Assets Management Ltd - Registrar Document Tracker with 3-Level Approvals & Collection Tracking
require_once 'config.php';

$page = $_GET['page'] ?? 'dashboard';

// -------------------------------------------------------------
// Authentication: Login & Logout
// -------------------------------------------------------------
if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $email = strtolower(trim($_POST['email'] ?? ''));
        $raw_pass = (string)($_POST['password'] ?? '');
        $trimmed_pass = trim($raw_pass);

        $stmt = db()->prepare("SELECT * FROM users WHERE LOWER(email)=LOWER(?) AND active=1");
        $stmt->execute([$email]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        $authenticated = false;
        if ($u) {
            if (password_verify($trimmed_pass, $u['password']) || password_verify($raw_pass, $u['password'])) {
                $authenticated = true;
            } elseif ($trimmed_pass === $u['password'] || $raw_pass === $u['password']) {
                $authenticated = true;
                // Auto-upgrade plaintext to secure hash
                db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($trimmed_pass, PASSWORD_DEFAULT), $u['id']]);
            }
        }

        if ($authenticated) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['user_name'] = $u['name'];
            $_SESSION['user_email'] = $u['email'];
            $_SESSION['user_role'] = $u['role'];
            audit('Login', null, 'User signed in as ' . $u['role']);
            header('Location: index.php');
            exit;
        }
        flash('danger', 'Invalid email or password. Please check your credentials.');
    }
    render_header('Login', false);
    ?>
    <div class="row justify-content-center align-items-center" style="min-height: 85vh;">
        <div class="col-md-7 col-lg-5">
            <div class="card shadow-lg border-0" style="border-radius: 16px; overflow: hidden;">
                <div class="p-4 text-center text-white" style="background: linear-gradient(135deg, #0b2545 0%, #134074 100%);">
                    <h4 class="mb-1 fw-bold"><?= h(COMPANY_NAME) ?></h4>
                    <p class="small text-white-50 mb-0"><?= h(COMPANY_SUBTITLE) ?></p>
                </div>
                <div class="card-body p-4 p-md-5 bg-white">
                    <div class="d-flex align-items-center mb-4">
                        <div class="badge bg-primary-subtle text-primary p-2 rounded-3 me-3">
                            <i class="bi bi-shield-check fs-4"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold">Sign In to Document Portal</h5>
                            <small class="text-muted">3-Tier Approvals & Registrar Dispatch Control</small>
                        </div>
                    </div>

                    <form method="post" action="index.php?page=login">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">EMAIL ADDRESS</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                                <input class="form-control border-start-0 ps-0" type="email" id="login_email" name="email" placeholder="name@regency.com" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-semibold text-muted">PASSWORD</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
                                <input class="form-control border-start-0 ps-0" type="password" id="login_password" name="password" placeholder="••••••••" required>
                            </div>
                        </div>
                        <button class="btn btn-primary w-100 py-2 fw-semibold shadow-sm" type="submit">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Sign In to Portal
                        </button>
                    </form>

                    <div class="mt-4 pt-3 border-top">
                        <label class="form-label small fw-bold text-muted mb-2"><i class="bi bi-lightning-charge text-warning me-1"></i> Quick Demo Logins (Click to fill):</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="quickFill('staff@regency.com', 'Staff@12345')">Staff (Initiator)</button>
                            <button type="button" class="btn btn-sm btn-outline-warning text-dark" onclick="quickFill('supervisor@regency.com', 'Supervisor@12345')">L1: Supervisor</button>
                            <button type="button" class="btn btn-sm btn-outline-info text-dark" onclick="quickFill('opsmanager@regency.com', 'Manager@12345')">L2: Ops Mgr</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="quickFill('compliance@regency.com', 'Compliance@12345')">L3: Compliance</button>
                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="quickFill('admin@regency.com', 'Admin@12345')">Admin</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center mt-3 text-muted small">
                &copy; <?= date('Y') ?> <?= h(COMPANY_NAME) ?>. All rights reserved.
            </div>
        </div>
    </div>
    <script>
    function quickFill(email, pass) {
        document.getElementById('login_email').value = email;
        document.getElementById('login_password').value = pass;
    }
    </script>
    <?php
    render_footer();
    exit;
}

if ($page === 'logout') {
    audit('Logout', null, 'User signed out');
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

require_login();
$currentUser = current_user();

// -------------------------------------------------------------
// POST Handler: Save Document (Create or Edit)
// -------------------------------------------------------------
if ($page === 'save_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $reference = trim($_POST['reference_no'] ?? '');
    $doc_type = trim($_POST['document_type'] ?? '');
    $sub_date = $_POST['submission_date'] ?? date('Y-m-d');

    if (!$reference || !$doc_type || !$sub_date) {
        flash('danger', 'Reference number, Document Type, and Submission Date are required.');
        header('Location: index.php?page=document_form' . ($id ? '&id=' . $id : ''));
        exit;
    }

    $client_name = trim($_POST['client_name'] ?? '');
    $client_account = trim($_POST['client_account'] ?? '');
    $chn_number = trim($_POST['chn_number'] ?? '');
    $account_officer = trim($_POST['account_officer'] ?? '');
    $stock_symbol = strtoupper(trim($_POST['stock_symbol'] ?? ''));
    $units = (int)($_POST['units'] ?? 0);
    $priority = $_POST['priority'] ?? 'Normal';
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $description = trim($_POST['description'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $registrar_name = trim($_POST['registrar_name'] ?? '');
    if ($registrar_name === 'Other' && !empty($_POST['registrar_name_custom'])) {
        $registrar_name = trim($_POST['registrar_name_custom']);
    }

    // Handle file upload
    $fileName = null;
    $filePath = null;
    if (!empty($_FILES['document_file']['name']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['document_file']['size'] > MAX_UPLOAD) {
            flash('danger', 'Uploaded file exceeds the maximum 15MB size limit.');
            header('Location: index.php?page=document_form' . ($id ? '&id=' . $id : ''));
            exit;
        }
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        $ext = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            flash('danger', 'Unsupported file type. Please upload PDF, Word, Excel, or Image.');
            header('Location: index.php?page=document_form' . ($id ? '&id=' . $id : ''));
            exit;
        }
        $safe = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        move_uploaded_file($_FILES['document_file']['tmp_name'], UPLOAD_DIR . $safe);
        $fileName = $_FILES['document_file']['name'];
        $filePath = 'uploads/' . $safe;
    }

    if ($id) {
        // Update existing document
        $stmt_old = db()->prepare("SELECT * FROM documents WHERE id=?");
        $stmt_old->execute([$id]);
        $before = $stmt_old->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            flash('danger', 'Document record not found.');
            header('Location: index.php?page=documents');
            exit;
        }

        $sql = "UPDATE documents SET reference_no=?, client_name=?, client_account=?, chn_number=?, account_officer=?,
                document_type=?, stock_symbol=?, units=?, description=?, priority=?, submission_date=?,
                due_date=?, registrar_name=?, remarks=?, updated_at=CURRENT_TIMESTAMP";
        $params = [$reference, $client_name, $client_account, $chn_number, $account_officer, $doc_type, $stock_symbol,
                   $units, $description, $priority, $sub_date, $due_date, $registrar_name, $remarks];

        if ($fileName) {
            if (!empty($before['file_path']) && file_exists(__DIR__ . '/' . $before['file_path'])) {
                @unlink(__DIR__ . '/' . $before['file_path']);
            }
            $sql .= ", file_name=?, file_path=?";
            $params[] = $fileName;
            $params[] = $filePath;
        }
        $sql .= " WHERE id=?";
        $params[] = $id;

        db()->prepare($sql)->execute($params);
        audit('Updated document', $id, 'Reference: ' . $reference . ' updated by ' . $currentUser['name']);
        flash('success', 'Document updated successfully.');
    } else {
        // Create new document
        $sql = "INSERT INTO documents (
            reference_no, client_name, client_account, chn_number, account_officer, document_type, stock_symbol, units,
            description, priority, submission_date, due_date, registrar_name, remarks,
            file_name, file_path, status, approval_status, collection_status, created_by
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Received','Pending Level 1','Pending Collection',?)";

        $stmt = db()->prepare($sql);
        $stmt->execute([
            $reference, $client_name, $client_account, $chn_number, $account_officer, $doc_type, $stock_symbol, $units,
            $description, $priority, $sub_date, $due_date, $registrar_name, $remarks,
            $fileName, $filePath, $currentUser['id']
        ]);
        $id = (int)db()->lastInsertId();

        db()->prepare("INSERT INTO status_history (document_id, new_status, comment, changed_by) VALUES (?,?,?,?)")
            ->execute([$id, 'Received', 'Initial document registration. Queued for Level 1 Supervisor review.', $currentUser['id']]);

        log_approval_step($id, 0, 'Document Initiation', 'Submitted', 'Document registered and queued for Level 1 Supervisor review.');
        audit('Registered document', $id, 'Reference: ' . $reference . ' submitted by ' . $currentUser['name']);
        flash('success', 'Document registered successfully and queued for Level 1 Review.');
    }

    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// POST Handler: Multi-Level Approval Action
// -------------------------------------------------------------
if ($page === 'approve_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $level = (int)($_POST['level'] ?? 0);
    $notes = trim($_POST['approval_notes'] ?? '');

    $doc = db()->prepare("SELECT * FROM documents WHERE id=?");
    $doc->execute([$id]);
    $d = $doc->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        flash('danger', 'Document not found.');
        header('Location: index.php?page=documents');
        exit;
    }

    if ($level === 1) {
        if (!can_approve_level1($currentUser)) {
            flash('danger', 'Unauthorized: You do not have permission to execute Level 1 Supervisor Approval.');
            header('Location: index.php?page=view&id=' . $id);
            exit;
        }
        $sql = "UPDATE documents SET level1_approved=1, level1_user_id=?, level1_user_name=?, level1_date=CURRENT_TIMESTAMP,
                level1_notes=?, approval_status='Pending Level 2', status='In Review', queried_at_level=0, query_reason=NULL,
                updated_at=CURRENT_TIMESTAMP WHERE id=?";
        db()->prepare($sql)->execute([$currentUser['id'], $currentUser['name'] . ' (' . $currentUser['role'] . ')', $notes, $id]);

        log_approval_step($id, 1, 'Level 1: Supervisor Review', 'Approved', $notes ?: 'Approved Level 1');
        db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
            ->execute([$id, $d['status'], 'In Review', 'Level 1 Supervisor approval completed. Moved to Level 2 Operations clearance.', $currentUser['id']]);
        audit('Level 1 Approved', $id, 'Approved by ' . $currentUser['name']);
        flash('success', 'Level 1 Supervisor approval completed successfully. Forwarded to Level 2 Operations Manager.');
    }
    elseif ($level === 2) {
        if (!can_approve_level2($currentUser)) {
            flash('danger', 'Unauthorized: You do not have permission to execute Level 2 Operations Manager Approval.');
            header('Location: index.php?page=view&id=' . $id);
            exit;
        }
        if ($d['level1_approved'] != 1) {
            flash('warning', 'Level 1 Supervisor approval must be completed before Level 2 can be granted.');
            header('Location: index.php?page=view&id=' . $id);
            exit;
        }
        $sql = "UPDATE documents SET level2_approved=1, level2_user_id=?, level2_user_name=?, level2_date=CURRENT_TIMESTAMP,
                level2_notes=?, approval_status='Pending Level 3', status='In Review', queried_at_level=0, query_reason=NULL,
                updated_at=CURRENT_TIMESTAMP WHERE id=?";
        db()->prepare($sql)->execute([$currentUser['id'], $currentUser['name'] . ' (' . $currentUser['role'] . ')', $notes, $id]);

        log_approval_step($id, 2, 'Level 2: Operations Approval', 'Approved', $notes ?: 'Approved Level 2');
        db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
            ->execute([$id, $d['status'], 'In Review', 'Level 2 Operations approval completed. Forwarded to Level 3 Compliance.', $currentUser['id']]);
        audit('Level 2 Approved', $id, 'Approved by ' . $currentUser['name']);
        flash('success', 'Level 2 Operations approval granted. Forwarded to Level 3 Compliance / Final Clearance.');
    }
    elseif ($level === 3) {
        if (!can_approve_level3($currentUser)) {
            flash('danger', 'Unauthorized: You do not have permission to execute Level 3 Compliance Final Approval.');
            header('Location: index.php?page=view&id=' . $id);
            exit;
        }
        if ($d['level2_approved'] != 1) {
            flash('warning', 'Level 2 Operations approval must be completed before Level 3 final clearance.');
            header('Location: index.php?page=view&id=' . $id);
            exit;
        }
        $sql = "UPDATE documents SET level3_approved=1, level3_user_id=?, level3_user_name=?, level3_date=CURRENT_TIMESTAMP,
                level3_notes=?, approval_status='Fully Approved', status='Approved', queried_at_level=0, query_reason=NULL,
                updated_at=CURRENT_TIMESTAMP WHERE id=?";
        db()->prepare($sql)->execute([$currentUser['id'], $currentUser['name'] . ' (' . $currentUser['role'] . ')', $notes, $id]);

        log_approval_step($id, 3, 'Level 3: Compliance Final Clearance', 'Approved', $notes ?: 'Final compliance sign-off granted.');
        db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
            ->execute([$id, $d['status'], 'Approved', 'All 3 approval levels completed. Document cleared for physical registrar dispatch/collection.', $currentUser['id']]);
        audit('Level 3 Approved', $id, 'Final sign-off by ' . $currentUser['name']);
        flash('success', 'Level 3 Final Compliance Clearance Granted! Document is now FULLY APPROVED and cleared for registrar dispatch.');
    }

    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// POST Handler: Query Document (at any approval stage)
// -------------------------------------------------------------
if ($page === 'query_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $level = (int)($_POST['level'] ?? 1);
    $reason = trim($_POST['query_reason'] ?? '');

    $doc = db()->prepare("SELECT * FROM documents WHERE id=?");
    $doc->execute([$id]);
    $d = $doc->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        flash('danger', 'Document not found.');
        header('Location: index.php?page=documents');
        exit;
    }

    if ($level === 1 && !can_approve_level1($currentUser)) {
        flash('danger', 'Unauthorized to issue query for Level 1.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }
    if ($level === 2 && !can_approve_level2($currentUser)) {
        flash('danger', 'Unauthorized to issue query for Level 2.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }
    if ($level === 3 && !can_approve_level3($currentUser)) {
        flash('danger', 'Unauthorized to issue query for Level 3.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }

    if (!$reason) {
        flash('danger', 'Please provide a clear reason or description for the query.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }

    $sql = "UPDATE documents SET approval_status='Queried', status='Queried', queried_at_level=?, query_reason=?, updated_at=CURRENT_TIMESTAMP WHERE id=?";
    db()->prepare($sql)->execute([$level, $reason, $id]);

    log_approval_step($id, $level, 'Level ' . $level . ' Review', 'Queried', $reason);
    db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
        ->execute([$id, $d['status'], 'Queried', 'Document queried at Level ' . $level . ': ' . $reason, $currentUser['id']]);
    audit('Queried Document', $id, 'Queried at Level ' . $level . ' by ' . $currentUser['name']);
    flash('warning', 'Document has been marked as QUERIED. The initiator has been notified to amend the details.');

    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// POST Handler: Reject Document (at any approval stage)
// -------------------------------------------------------------
if ($page === 'reject_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $level = (int)($_POST['level'] ?? 1);
    $reason = trim($_POST['rejection_reason'] ?? '');

    $doc = db()->prepare("SELECT * FROM documents WHERE id=?");
    $doc->execute([$id]);
    $d = $doc->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        flash('danger', 'Document not found.');
        header('Location: index.php?page=documents');
        exit;
    }

    if ($level === 1 && !can_approve_level1($currentUser)) {
        flash('danger', 'Unauthorized to reject document at Level 1.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }
    if ($level === 2 && !can_approve_level2($currentUser)) {
        flash('danger', 'Unauthorized to reject document at Level 2.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }
    if ($level === 3 && !can_approve_level3($currentUser)) {
        flash('danger', 'Unauthorized to reject document at Level 3.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }

    if (!$reason) {
        flash('danger', 'Please provide a clear reason for rejecting this document.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }

    $rejection_note = 'REJECTED at Level ' . $level . ' by ' . $currentUser['name'] . ': ' . $reason;
    $sql = "UPDATE documents SET approval_status='Rejected', status='Rejected', remarks=?, updated_at=CURRENT_TIMESTAMP WHERE id=?";
    db()->prepare($sql)->execute([$rejection_note, $id]);

    log_approval_step($id, $level, 'Level ' . $level . ' Review', 'Rejected', $reason);
    db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
        ->execute([$id, $d['status'], 'Rejected', $rejection_note, $currentUser['id']]);
    audit('Rejected Document', $id, $rejection_note);
    flash('danger', 'Document has been formally REJECTED.');

    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// POST Handler: Resolve Query & Re-Submit
// -------------------------------------------------------------
if ($page === 'resolve_query' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $response_notes = trim($_POST['resolution_notes'] ?? '');

    $doc = db()->prepare("SELECT * FROM documents WHERE id=?");
    $doc->execute([$id]);
    $d = $doc->fetch(PDO::FETCH_ASSOC);

    if ($d) {
        // Return to the level where it was queried, or Level 1
        $target_level = $d['queried_at_level'] ?: 1;
        $new_approval_status = 'Pending Level ' . $target_level;

        $sql = "UPDATE documents SET approval_status=?, status='In Review', query_reason=NULL, queried_at_level=0, updated_at=CURRENT_TIMESTAMP WHERE id=?";
        db()->prepare($sql)->execute([$new_approval_status, $id]);

        log_approval_step($id, $target_level, 'Query Resolution', 'Re-submitted', 'Query addressed by ' . $currentUser['name'] . ': ' . $response_notes);
        db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
            ->execute([$id, 'Queried', 'In Review', 'Query addressed and re-submitted for Level ' . $target_level . ' review.', $currentUser['id']]);
        audit('Resolved Query', $id, 'Re-submitted by ' . $currentUser['name']);
        flash('success', 'Query resolution submitted successfully. Document re-queued for approval.');
    }
    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// POST Handler: Record Registrar Collection & Dispatch
// -------------------------------------------------------------
if ($page === 'record_collection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);

    $doc = db()->prepare("SELECT * FROM documents WHERE id=?");
    $doc->execute([$id]);
    $d = $doc->fetch(PDO::FETCH_ASSOC);
    if (!$d) {
        flash('danger', 'Document not found.');
        header('Location: index.php?page=documents');
        exit;
    }

    $registrar_name = trim($_POST['registrar_name'] ?? '');
    if ($registrar_name === 'Other' && !empty($_POST['registrar_name_custom'])) {
        $registrar_name = trim($_POST['registrar_name_custom']);
    }

    $collector_name = trim($_POST['collector_name'] ?? '');
    $collector_phone = trim($_POST['collector_phone'] ?? '');
    $collected_date = $_POST['collected_date'] ?? date('Y-m-d H:i');
    $waybill = trim($_POST['dispatch_waybill_no'] ?? '');
    $method = trim($_POST['collection_method'] ?? '');
    $notes = trim($_POST['collection_notes'] ?? '');

    if (!$collector_name || !$registrar_name) {
        flash('danger', 'Registrar Name and Collecting Officer / Courier Name are required.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }

    // Stamped proof of collection upload
    $proofName = null;
    $proofPath = null;
    if (!empty($_FILES['collection_proof']['name']) && $_FILES['collection_proof']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['collection_proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            // Unlink old proof if exists
            if (!empty($d['collection_proof_path']) && file_exists(__DIR__ . '/' . $d['collection_proof_path'])) {
                @unlink(__DIR__ . '/' . $d['collection_proof_path']);
            }
            $safe = 'ack_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            move_uploaded_file($_FILES['collection_proof']['tmp_name'], UPLOAD_DIR . $safe);
            $proofName = $_FILES['collection_proof']['name'];
            $proofPath = 'uploads/' . $safe;
        }
    }

    $sql = "UPDATE documents SET registrar_name=?, collector_name=?, collector_phone=?, collected_date=?,
            dispatch_waybill_no=?, collection_method=?, collection_notes=?, collection_status='Dispatched / Collected',
            status='In Progress', updated_at=CURRENT_TIMESTAMP";
    $params = [$registrar_name, $collector_name, $collector_phone, $collected_date, $waybill, $method, $notes];

    if ($proofName) {
        $sql .= ", collection_proof_name=?, collection_proof_path=?";
        $params[] = $proofName;
        $params[] = $proofPath;
    }
    $sql .= " WHERE id=?";
    $params[] = $id;

    db()->prepare($sql)->execute($params);

    $audit_details = "Collected by {$collector_name} ({$registrar_name}), Waybill: {$waybill}";
    log_approval_step($id, 4, 'Registrar Dispatch & Collection', 'Collected', $audit_details);
    db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
        ->execute([$id, $d['status'], 'In Progress', $audit_details, $currentUser['id']]);
    audit('Recorded Registrar Collection', $id, $audit_details);

    flash('success', 'Registrar Collection & Dispatch details recorded successfully.');
    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// POST Handler: Update Registrar Processing Status
// -------------------------------------------------------------
if ($page === 'update_registrar_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $new_coll_status = trim($_POST['collection_status'] ?? '');
    $notes = trim($_POST['status_notes'] ?? '');

    $doc = db()->prepare("SELECT * FROM documents WHERE id=?");
    $doc->execute([$id]);
    $d = $doc->fetch(PDO::FETCH_ASSOC);

    if ($d && $new_coll_status) {
        $new_doc_status = $d['status'];
        if ($new_coll_status === 'Completed by Registrar') {
            $new_doc_status = 'Completed';
        }

        $sql = "UPDATE documents SET collection_status=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?";
        db()->prepare($sql)->execute([$new_coll_status, $new_doc_status, $id]);

        $log_comment = "Registrar status updated to {$new_coll_status}. " . ($notes ? "Notes: {$notes}" : '');
        db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
            ->execute([$id, $d['status'], $new_doc_status, $log_comment, $currentUser['id']]);
        audit('Registrar Status Update', $id, $log_comment);
        flash('success', 'Registrar processing status updated.');
    }
    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// POST Handler: Delete Document
// -------------------------------------------------------------
if ($page === 'delete_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!can_delete_document($currentUser)) {
        flash('danger', 'You do not have permission to delete document records.');
        header('Location: index.php?page=documents');
        exit;
    }
    $id = (int)$_POST['id'];
    $s = db()->prepare("SELECT * FROM documents WHERE id=?");
    $s->execute([$id]);
    $d = $s->fetch(PDO::FETCH_ASSOC);
    if ($d) {
        if (!empty($d['file_path']) && file_exists(__DIR__ . '/' . $d['file_path'])) {
            @unlink(__DIR__ . '/' . $d['file_path']);
        }
        if (!empty($d['collection_proof_path']) && file_exists(__DIR__ . '/' . $d['collection_proof_path'])) {
            @unlink(__DIR__ . '/' . $d['collection_proof_path']);
        }
        db()->prepare("DELETE FROM documents WHERE id=?")->execute([$id]);
        audit('Deleted document', null, 'Deleted reference: ' . $d['reference_no']);
        flash('success', 'Document deleted successfully.');
    }
    header('Location: index.php?page=documents');
    exit;
}

// -------------------------------------------------------------
// POST Handler: Reassign Account Officer
// -------------------------------------------------------------
if ($page === 'reassign_account_officer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!can_reassign_account_officer($currentUser)) {
        flash('danger', 'Unauthorized: You do not have permission to reassign account officers.');
        header('Location: index.php?page=documents');
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $new_ao = trim($_POST['account_officer'] ?? '');
    $reason = trim($_POST['reassignment_reason'] ?? '');

    $doc = db()->prepare("SELECT * FROM documents WHERE id=?");
    $doc->execute([$id]);
    $d = $doc->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        flash('danger', 'Document not found.');
        header('Location: index.php?page=documents');
        exit;
    }

    if (!$new_ao) {
        flash('danger', 'Please select an Account Officer to assign.');
        header('Location: index.php?page=view&id=' . $id);
        exit;
    }

    $old_ao = $d['account_officer'] ?: 'Unassigned';
    $sql = "UPDATE documents SET account_officer=?, updated_at=CURRENT_TIMESTAMP WHERE id=?";
    db()->prepare($sql)->execute([$new_ao, $id]);

    $note = "Account Officer reassigned from '{$old_ao}' to '{$new_ao}' by {$currentUser['name']}." . ($reason ? " Reason: {$reason}" : '');
    db()->prepare("INSERT INTO status_history (document_id, old_status, new_status, comment, changed_by) VALUES (?,?,?,?,?)")
        ->execute([$id, $d['status'], $d['status'], $note, $currentUser['id']]);
    audit('Reassigned Account Officer', $id, $note);
    flash('success', "Document successfully reassigned to Account Officer: {$new_ao}");

    header('Location: index.php?page=view&id=' . $id);
    exit;
}

// -------------------------------------------------------------
// Export CSV
// -------------------------------------------------------------
if ($page === 'export') {
    $rows = db()->query("
        SELECT 
            reference_no, client_name, client_account, chn_number, account_officer, document_type, stock_symbol, units,
            priority, submission_date, due_date, status, approval_status,
            level1_user_name, level1_date,
            level2_user_name, level2_date,
            level3_user_name, level3_date,
            registrar_name, collection_status, collector_name, collector_phone, collected_date, dispatch_waybill_no, collection_method
        FROM documents 
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=regency_registrar_documents_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
    }
    fclose($out);
    exit;
}

// -------------------------------------------------------------
// Printable Routing & Transmittal Slip
// -------------------------------------------------------------
if ($page === 'print_slip') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT d.*, u.name creator_name FROM documents d LEFT JOIN users u ON u.id=d.created_by WHERE d.id=?");
    $stmt->execute([$id]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$d) {
        exit('Document not found.');
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Transmittal Slip - <?= h($d['reference_no']) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-size: 13px; color: #111; background: #fff; font-family: "Segoe UI", Arial, sans-serif; }
            .print-container { max-width: 820px; margin: 20px auto; padding: 25px; border: 1px solid #ccc; }
            .header-logo { max-height: 50px; }
            .title-box { border-bottom: 2px solid #0b2545; padding-bottom: 12px; margin-bottom: 18px; }
            .table-sm td, .table-sm th { padding: 6px 10px; }
            .sig-box { border: 1px solid #999; border-radius: 6px; padding: 10px; min-height: 125px; }
            @media print {
                .no-print { display: none !important; }
                .print-container { border: none; margin: 0; padding: 0; width: 100%; }
            }
        </style>
    </head>
    <body>
    <div class="print-container">
        <div class="no-print mb-3 text-end">
            <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Print Routing Slip</button>
            <a href="index.php?page=view&id=<?= $d['id'] ?>" class="btn btn-outline-secondary btn-sm">Back to Portal</a>
        </div>

        <div class="title-box d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-bold" style="color:#0b2545; font-size: 18px;"><?= h(COMPANY_NAME) ?></div>
                <div class="small text-muted"><?= h(COMPANY_SUBTITLE) ?></div>
            </div>
            <div class="text-end">
                <div class="badge bg-dark fs-6 px-3 py-2"><?= h($d['reference_no']) ?></div>
                <div class="small text-muted mt-1">Generated: <?= date('d M Y, H:i') ?></div>
            </div>
        </div>

        <h5 class="text-center fw-bold text-uppercase mb-3" style="letter-spacing: 1px; color:#0b2545;">
            Registrar Document Transmittal & Multi-Tier Approval Slip
        </h5>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <table class="table table-bordered table-sm">
                    <tr><th class="bg-light" width="40%">Client Name:</th><td class="fw-bold"><?= h($d['client_name']) ?></td></tr>
                    <tr><th class="bg-light">CSCS Account No:</th><td><?= h($d['client_account']) ?></td></tr>
                    <tr><th class="bg-light">CHN Number:</th><td><?= h($d['chn_number'] ?: 'N/A') ?></td></tr>
                    <tr><th class="bg-light">Account Officer:</th><td class="fw-bold text-dark"><?= h($d['account_officer'] ?: 'Unassigned') ?></td></tr>
                    <tr><th class="bg-light">Document Type:</th><td class="fw-bold text-primary"><?= h($d['document_type']) ?></td></tr>
                </table>
            </div>
            <div class="col-6">
                <table class="table table-bordered table-sm">
                    <tr><th class="bg-light" width="45%">Security / Stock:</th><td class="fw-bold"><?= h($d['stock_symbol'] ?: 'N/A') ?></td></tr>
                    <tr><th class="bg-light">Units Involved:</th><td><?= number_format($d['units'] ?? 0) ?></td></tr>
                    <tr><th class="bg-light">Submission Date:</th><td><?= h($d['submission_date']) ?></td></tr>
                    <tr><th class="bg-light">Target Registrar:</th><td class="fw-bold"><?= h($d['registrar_name']) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body p-2">
                <div class="small fw-bold text-muted">DESCRIPTION & TRANSACTION DETAILS:</div>
                <div><?= nl2br(h($d['description'] ?: 'None specified.')) ?></div>
            </div>
        </div>

        <h6 class="fw-bold text-uppercase mt-4 mb-2" style="color:#0b2545;">Internal 3-Tier Multi-Level Approval Sign-Off</h6>
        <div class="row g-2 mb-4">
            <div class="col-4">
                <div class="sig-box">
                    <div class="fw-bold small text-primary mb-1">LEVEL 1: SUPERVISOR REVIEW</div>
                    <div>Status: <?= $d['level1_approved'] ? '<span class="text-success fw-bold">✓ APPROVED</span>' : '<span class="text-muted">Pending</span>' ?></div>
                    <div class="small text-muted">Officer: <?= h($d['level1_user_name'] ?: 'Pending Review') ?></div>
                    <div class="small text-muted">Date: <?= h($d['level1_date'] ?: '-') ?></div>
                    <div class="small text-muted fst-italic mt-1">Notes: <?= h($d['level1_notes'] ?: '-') ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="sig-box">
                    <div class="fw-bold small text-info mb-1">LEVEL 2: OPERATIONS APPROVAL</div>
                    <div>Status: <?= $d['level2_approved'] ? '<span class="text-success fw-bold">✓ APPROVED</span>' : '<span class="text-muted">Pending</span>' ?></div>
                    <div class="small text-muted">Officer: <?= h($d['level2_user_name'] ?: 'Pending Clearance') ?></div>
                    <div class="small text-muted">Date: <?= h($d['level2_date'] ?: '-') ?></div>
                    <div class="small text-muted fst-italic mt-1">Notes: <?= h($d['level2_notes'] ?: '-') ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="sig-box">
                    <div class="fw-bold small text-dark mb-1">LEVEL 3: COMPLIANCE FINAL SIGN-OFF</div>
                    <div>Status: <?= $d['level3_approved'] ? '<span class="text-success fw-bold">✓ APPROVED</span>' : '<span class="text-muted">Pending</span>' ?></div>
                    <div class="small text-muted">Officer: <?= h($d['level3_user_name'] ?: 'Pending Sign-off') ?></div>
                    <div class="small text-muted">Date: <?= h($d['level3_date'] ?: '-') ?></div>
                    <div class="small text-muted fst-italic mt-1">Notes: <?= h($d['level3_notes'] ?: '-') ?></div>
                </div>
            </div>
        </div>

        <h6 class="fw-bold text-uppercase mt-3 mb-2" style="color:#0b2545;">Registrar Collection & Physical Acknowledgment</h6>
        <div class="card border p-3">
            <div class="row g-3">
                <div class="col-6">
                    <div class="small text-muted">Collecting Registrar Entity:</div>
                    <div class="fw-bold"><?= h($d['registrar_name']) ?></div>
                    <div class="small text-muted mt-2">Registrar Liaison Officer / Collector:</div>
                    <div class="fw-bold"><?= h($d['collector_name'] ?: '_____________________________________') ?></div>
                    <div class="small text-muted mt-2">Collector Contact Phone:</div>
                    <div><?= h($d['collector_phone'] ?: '________________________') ?></div>
                </div>
                <div class="col-6">
                    <div class="small text-muted">Dispatch / Acknowledgment Slip No:</div>
                    <div class="fw-bold"><?= h($d['dispatch_waybill_no'] ?: '_____________________________________') ?></div>
                    <div class="small text-muted mt-2">Collection Date & Time:</div>
                    <div><?= h($d['collected_date'] ?: '________________________') ?></div>
                    <div class="small text-muted mt-3">Registrar Stamp & Signature:</div>
                    <div style="border-bottom: 1px dashed #666; height: 35px;"></div>
                </div>
            </div>
        </div>

        <div class="mt-4 pt-3 border-top text-center text-muted small">
            Regency Assets Management Ltd &bull; Compliance & Internal Operations Control Document &bull; Confidential
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------
// Header & Footer Layout Helper
// -------------------------------------------------------------
function render_header($title, $auth = true) {
    $flash = get_flash();
    $u = current_user();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> - <?= h(COMPANY_NAME) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            :root {
                --regency-navy: #0b2545;
                --regency-blue: #134074;
                --regency-light: #eef4f8;
            }
            body {
                background: #f4f6fa;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                color: #2b303a;
            }
            .sidebar {
                min-height: 100vh;
                background: linear-gradient(180deg, #091e38 0%, #0d284d 100%);
                color: #fff;
                box-shadow: 2px 0 10px rgba(0,0,0,0.08);
            }
            .sidebar .nav-link {
                color: #c4d4e6;
                padding: 10px 14px;
                border-radius: 8px;
                font-size: 13.5px;
                font-weight: 500;
                margin-bottom: 4px;
                display: flex;
                align-items: center;
                transition: all 0.2s ease;
                text-decoration: none;
            }
            .sidebar .nav-link i { font-size: 16px; margin-right: 10px; width: 20px; }
            .sidebar .nav-link:hover, .sidebar .nav-link.active {
                background: rgba(255,255,255,0.12);
                color: #ffffff;
            }
            .sidebar .sidebar-heading {
                font-size: 10.5px;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #8da4be;
                font-weight: 700;
                margin-top: 18px;
                margin-bottom: 8px;
                padding-left: 12px;
            }
            .topbar {
                background: #ffffff;
                border-bottom: 1px solid #e2e8f0;
                padding: 14px 28px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            }
            .content-area { padding: 28px; }
            .card {
                border: 1px solid #e5eaf2;
                border-radius: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            }
            .badge-soft-success { background: #d1e7dd; color: #0f5132; }
            .badge-soft-warning { background: #fff3cd; color: #664d03; }
            .badge-soft-danger  { background: #f8d7da; color: #842029; }
            .badge-soft-primary { background: #cfe2ff; color: #084298; }
            .badge-soft-info    { background: #cff4fc; color: #055160; }
            .badge-soft-secondary { background: #e2e3e5; color: #41464b; }
            .stepper-box {
                display: flex;
                align-items: center;
                justify-content: space-between;
                position: relative;
                margin: 20px 0;
            }
            .stepper-step {
                text-align: center;
                position: relative;
                z-index: 2;
                flex: 1;
            }
            .stepper-circle {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #e9ecef;
                color: #6c757d;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 8px auto;
                font-weight: bold;
                border: 2px solid #dee2e6;
            }
            .stepper-step.completed .stepper-circle {
                background: #198754;
                color: #fff;
                border-color: #198754;
            }
            .stepper-step.active .stepper-circle {
                background: #0d6efd;
                color: #fff;
                border-color: #0d6efd;
                box-shadow: 0 0 0 4px rgba(13,110,253,0.25);
            }
            .stepper-step.queried .stepper-circle {
                background: #dc3545;
                color: #fff;
                border-color: #dc3545;
            }
        </style>
    </head>
    <body>
    <?php if ($auth): ?>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar -->
            <aside class="col-md-3 col-lg-2 sidebar p-3 d-flex flex-column">
                <div class="mb-4 text-center pb-2 border-bottom border-secondary">
                    <div class="fw-bold text-white" style="font-size: 14px; letter-spacing: 0.5px;"><?= h(COMPANY_NAME) ?></div>
                    <div style="font-size: 10px; color: #8da4be; margin-top: 4px; line-height: 1.3;"><?= h(COMPANY_SUBTITLE) ?></div>
                </div>

                <div class="sidebar-heading">NAVIGATION</div>
                <nav class="nav flex-column">
                    <a class="nav-link <?= (($_GET['page']??'dashboard')==='dashboard'?'active':'') ?>" href="index.php">
                        <i class="bi bi-grid-1x2"></i> Dashboard
                    </a>
                    <a class="nav-link <?= (($_GET['page']??'')==='documents'?'active':'') ?>" href="index.php?page=documents">
                        <i class="bi bi-folder2-open"></i> All Documents
                    </a>
                    <a class="nav-link <?= (($_GET['page']??'')==='document_form'?'active':'') ?>" href="index.php?page=document_form">
                        <i class="bi bi-file-earmark-plus"></i> New Submission
                    </a>
                </nav>

                <div class="sidebar-heading">WORKFLOW QUEUES</div>
                <nav class="nav flex-column">
                    <a class="nav-link" href="index.php?page=documents&filter=pending_l1">
                        <i class="bi bi-1-circle"></i> Level 1 (Supervisor)
                    </a>
                    <a class="nav-link" href="index.php?page=documents&filter=pending_l2">
                        <i class="bi bi-2-circle"></i> Level 2 (Ops Manager)
                    </a>
                    <a class="nav-link" href="index.php?page=documents&filter=pending_l3">
                        <i class="bi bi-3-circle"></i> Level 3 (Compliance)
                    </a>
                    <a class="nav-link" href="index.php?page=documents&filter=collection">
                        <i class="bi bi-truck"></i> Registrar Dispatch
                    </a>
                    <a class="nav-link" href="index.php?page=documents&status=Queried">
                        <i class="bi bi-exclamation-triangle"></i> Queried Items
                    </a>
                </nav>

                <div class="sidebar-heading">DIRECTORIES</div>
                <nav class="nav flex-column">
                    <a class="nav-link <?= (($_GET['page']??'')==='registrars'?'active':'') ?>" href="index.php?page=registrars">
                        <i class="bi bi-building"></i> Nigerian Registrars
                    </a>
                </nav>

                <div class="sidebar-heading">MANAGEMENT</div>
                <nav class="nav flex-column">
                    <a class="nav-link <?= (($_GET['page']??'')==='reports'?'active':'') ?>" href="index.php?page=reports">
                        <i class="bi bi-graph-up-arrow"></i> Reports & Audits
                    </a>
                    <?php if (can_manage_account_officers($u)): ?>
                    <a class="nav-link <?= (($_GET['page']??'')==='account_officers'?'active':'') ?>" href="index.php?page=account_officers">
                        <i class="bi bi-person-badge"></i> Account Officers
                    </a>
                    <?php endif; ?>
                    <?php if (can_manage_users($u)): ?>
                    <a class="nav-link <?= (($_GET['page']??'')==='users'?'active':'') ?>" href="index.php?page=users">
                        <i class="bi bi-people"></i> Users & Roles
                    </a>
                    <?php endif; ?>
                </nav>

                <div class="mt-auto pt-4 border-top border-secondary">
                    <div class="d-flex align-items-center mb-2 px-2 text-white">
                        <i class="bi bi-person-circle fs-4 me-2 text-info"></i>
                        <div class="overflow-hidden">
                            <div class="small fw-bold text-truncate"><?= h($u['name']) ?></div>
                            <span class="badge bg-secondary" style="font-size:10px;"><?= h($u['role']) ?></span>
                        </div>
                    </div>
                    <a class="btn btn-outline-light btn-sm w-100 mt-2" href="index.php?page=logout">
                        <i class="bi bi-box-arrow-right me-1"></i> Sign Out
                    </a>
                </div>
            </aside>

            <!-- Main Content Area -->
            <main class="col-md-9 col-lg-10 p-0">
                <div class="topbar d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bold" style="color: #0b2545;"><?= h($title) ?></h5>
                        <small class="text-muted">Regency Document Control & Registrar Tracking</small>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <a href="index.php?page=document_form" class="btn btn-primary btn-sm px-3 shadow-sm">
                            <i class="bi bi-plus-circle me-1"></i> New Document
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-light btn-sm border dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-person-badge me-1"></i> <?= h($u['name']) ?> (<?= h($u['role']) ?>)
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li class="dropdown-header">Signed in as <b><?= h($u['role']) ?></b></li>
                                <li><a class="dropdown-item" href="index.php?page=documents&mine=1"><i class="bi bi-list-check me-2"></i>My Submissions</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="index.php?page=logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="content-area">
                    <?php if ($flash): ?>
                    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
                        <i class="bi bi-info-circle me-2"></i> <?= h($flash['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
    <?php else: ?>
    <div class="container py-4">
        <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
    <?php endif;
}

function render_footer() {
    echo '</div></main></div></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body></html>';
}

// -------------------------------------------------------------
// Router: Switch Pages
// -------------------------------------------------------------
switch ($page) {

// =============================================================
// PAGE: Dashboard
// =============================================================
case 'dashboard':
    $pdo = db();
    $total = $pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
    $p_l1 = $pdo->query("SELECT COUNT(*) FROM documents WHERE approval_status='Pending Level 1'")->fetchColumn();
    $p_l2 = $pdo->query("SELECT COUNT(*) FROM documents WHERE approval_status='Pending Level 2'")->fetchColumn();
    $p_l3 = $pdo->query("SELECT COUNT(*) FROM documents WHERE approval_status='Pending Level 3'")->fetchColumn();
    $fully_appr = $pdo->query("SELECT COUNT(*) FROM documents WHERE approval_status='Fully Approved'")->fetchColumn();
    $dispatched = $pdo->query("SELECT COUNT(*) FROM documents WHERE collection_status IN ('Dispatched / Collected','Acknowledged by Registrar','Processing at Registrar')")->fetchColumn();
    $queried = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='Queried' OR approval_status='Queried'")->fetchColumn();
    $completed = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='Completed' OR collection_status='Completed by Registrar'")->fetchColumn();
    $overdue = $pdo->query("SELECT COUNT(*) FROM documents WHERE due_date IS NOT NULL AND due_date < date('now') AND status NOT IN ('Completed','Rejected')")->fetchColumn();

    // Check how many items need current user's approval
    $my_pending = 0;
    if ($currentUser['role'] === 'Supervisor') {
        $my_pending = $p_l1;
    } elseif ($currentUser['role'] === 'Operations Manager') {
        $my_pending = $p_l2;
    } elseif ($currentUser['role'] === 'Compliance Officer') {
        $my_pending = $p_l3;
    } elseif ($currentUser['role'] === 'Administrator') {
        $my_pending = $p_l1 + $p_l2 + $p_l3;
    }

    $recent = $pdo->query("
        SELECT d.*, u.name creator_name 
        FROM documents d 
        LEFT JOIN users u ON u.id=d.created_by 
        ORDER BY d.id DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    render_header('Document Control Dashboard');
    ?>
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <h3 class="fw-bold mb-1" style="color:#0b2545;">Document Approval & Registrar Control</h3>
            <p class="text-muted mb-0">Multi-tier approval workflow and registrar collection tracking for Regency Assets Management Ltd.</p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <a href="index.php?page=document_form" class="btn btn-primary shadow-sm"><i class="bi bi-plus-circle me-1"></i> Register New Document</a>
        </div>
    </div>

    <?php if ($my_pending > 0): ?>
    <div class="alert alert-warning border-warning d-flex align-items-center justify-content-between p-3 mb-4 shadow-sm" style="border-radius: 10px;">
        <div class="d-flex align-items-center">
            <i class="bi bi-bell-fill fs-3 text-warning me-3"></i>
            <div>
                <h6 class="mb-0 fw-bold">Action Required by You (<?= h($currentUser['role']) ?>)</h6>
                <div class="small">There are <b><?= $my_pending ?></b> document(s) waiting for your approval sign-off.</div>
            </div>
        </div>
        <a href="index.php?page=documents&filter=my_approvals" class="btn btn-warning btn-sm fw-bold">Review Pending Approvals &rarr;</a>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm border-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold">TOTAL SUBMISSIONS</div>
                            <h2 class="fw-bold mb-0 text-dark"><?= $total ?></h2>
                        </div>
                        <div class="badge bg-primary-subtle text-primary p-3 rounded-circle"><i class="bi bi-folder2 fs-4"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents&filter=pending_l1" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm border-warning">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-warning small fw-semibold">LEVEL 1 (SUPERVISOR)</div>
                            <h2 class="fw-bold mb-0 text-warning"><?= $p_l1 ?></h2>
                        </div>
                        <div class="badge bg-warning-subtle text-warning p-3 rounded-circle"><i class="bi bi-1-circle fs-4"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents&filter=pending_l2" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm border-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-info small fw-semibold">LEVEL 2 (OPS MGR)</div>
                            <h2 class="fw-bold mb-0 text-info"><?= $p_l2 ?></h2>
                        </div>
                        <div class="badge bg-info-subtle text-info p-3 rounded-circle"><i class="bi bi-2-circle fs-4"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents&filter=pending_l3" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm border-primary">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-primary small fw-semibold">LEVEL 3 (COMPLIANCE)</div>
                            <h2 class="fw-bold mb-0 text-primary"><?= $p_l3 ?></h2>
                        </div>
                        <div class="badge bg-primary-subtle text-primary p-3 rounded-circle"><i class="bi bi-3-circle fs-4"></i></div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Secondary KPI row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents&approval_status=Fully+Approved" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm">
                    <div class="small text-muted fw-semibold">FULLY APPROVED</div>
                    <h3 class="fw-bold text-success mb-0"><?= $fully_appr ?></h3>
                    <div class="small text-muted">Ready for dispatch</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents&filter=dispatched" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm">
                    <div class="small text-muted fw-semibold">DISPATCHED / COLLECTED</div>
                    <h3 class="fw-bold text-primary mb-0"><?= $dispatched ?></h3>
                    <div class="small text-muted">With registrar</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents&status=Queried" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm">
                    <div class="small text-muted fw-semibold">QUERIED</div>
                    <h3 class="fw-bold text-danger mb-0"><?= $queried ?></h3>
                    <div class="small text-muted">Pending resolution</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="index.php?page=documents&filter=overdue" class="text-decoration-none">
                <div class="card p-3 h-100 shadow-sm">
                    <div class="small text-muted fw-semibold">OVERDUE ITEMS</div>
                    <h3 class="fw-bold text-danger mb-0"><?= $overdue ?></h3>
                    <div class="small text-muted">Exceeded SLA target</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent Submissions Table -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2 text-primary"></i> Recent Document Submissions</h5>
            <a href="index.php?page=documents" class="btn btn-sm btn-outline-primary">View All Documents &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Reference</th>
                        <th>Type & Stock</th>
                        <th>Client & Account Officer</th>
                        <th>Approval Level</th>
                        <th>Registrar & Collector</th>
                        <th>Submitted / Due</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted">No documents submitted yet.</td></tr>
                    <?php else: foreach ($recent as $r): 
                        $is_late = $r['due_date'] && $r['due_date'] < date('Y-m-d') && !in_array($r['status'], ['Completed', 'Rejected']);
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?page=view&id=<?= $r['id'] ?>" class="fw-bold text-decoration-none">
                                <?= h($r['reference_no']) ?>
                            </a>
                            <div><span class="badge bg-secondary-subtle text-secondary" style="font-size:10px;"><?= h($r['priority']) ?></span></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= h($r['document_type']) ?></div>
                            <small class="text-muted"><?= h($r['stock_symbol'] ?: '-') ?> (<?= number_format($r['units'] ?? 0) ?> units)</small>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= h($r['client_name']) ?></div>
                            <small class="text-muted d-block"><?= h($r['client_account']) ?></small>
                            <?php if (!empty($r['account_officer'])): ?>
                            <small class="text-primary"><i class="bi bi-person-badge"></i> <?= h($r['account_officer']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= approval_badge($r['approval_status']) ?></td>
                        <td>
                            <div class="fw-semibold text-truncate" style="max-width: 170px;"><?= h($r['registrar_name']) ?></div>
                            <small class="text-muted">
                                <?= $r['collector_name'] ? '<i class="bi bi-person-check"></i> ' . h($r['collector_name']) : '<span class="text-warning">Awaiting Collection</span>' ?>
                            </small>
                        </td>
                        <td>
                            <div><?= h($r['submission_date']) ?></div>
                            <small class="<?= $is_late ? 'text-danger fw-bold' : 'text-muted' ?>">
                                Due: <?= h($r['due_date'] ?: '-') ?> <?= $is_late ? '<i class="bi bi-alarm-fill"></i>' : '' ?>
                            </small>
                        </td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td>
                            <a href="index.php?page=view&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    render_footer();
    break;

// =============================================================
// PAGE: All Documents (Register & Search)
// =============================================================
case 'documents':
    $search = trim($_GET['q'] ?? '');
    $status = $_GET['status'] ?? '';
    $approval_filter = $_GET['approval_status'] ?? '';
    $registrar_filter = $_GET['registrar'] ?? '';
    $ao_filter = $_GET['account_officer'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $filter_shortcut = $_GET['filter'] ?? '';

    $sql = "SELECT d.*, u.name creator_name FROM documents d LEFT JOIN users u ON u.id=d.created_by WHERE 1=1";
    $params = [];

    if ($search) {
        $sql .= " AND (d.reference_no LIKE ? OR d.client_name LIKE ? OR d.client_account LIKE ? OR d.chn_number LIKE ? OR d.account_officer LIKE ? OR d.stock_symbol LIKE ? OR d.collector_name LIKE ? OR d.dispatch_waybill_no LIKE ?)";
        $term = "%{$search}%";
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term, $term, $term]);
    }

    if ($status) {
        $sql .= " AND d.status=?";
        $params[] = $status;
    }

    if ($approval_filter) {
        $sql .= " AND d.approval_status=?";
        $params[] = $approval_filter;
    }

    if ($registrar_filter) {
        $sql .= " AND d.registrar_name=?";
        $params[] = $registrar_filter;
    }

    if ($ao_filter) {
        $sql .= " AND d.account_officer=?";
        $params[] = $ao_filter;
    }

    if ($type_filter) {
        $sql .= " AND d.document_type=?";
        $params[] = $type_filter;
    }

    // Filter shortcuts
    if ($filter_shortcut === 'pending_l1') {
        $sql .= " AND d.approval_status='Pending Level 1'";
    } elseif ($filter_shortcut === 'pending_l2') {
        $sql .= " AND d.approval_status='Pending Level 2'";
    } elseif ($filter_shortcut === 'pending_l3') {
        $sql .= " AND d.approval_status='Pending Level 3'";
    } elseif ($filter_shortcut === 'collection') {
        $sql .= " AND d.approval_status='Fully Approved' AND d.collection_status='Pending Collection'";
    } elseif ($filter_shortcut === 'my_approvals') {
        if ($currentUser['role'] === 'Supervisor') {
            $sql .= " AND d.approval_status='Pending Level 1'";
        } elseif ($currentUser['role'] === 'Operations Manager') {
            $sql .= " AND d.approval_status='Pending Level 2'";
        } elseif ($currentUser['role'] === 'Compliance Officer') {
            $sql .= " AND d.approval_status='Pending Level 3'";
        } elseif ($currentUser['role'] === 'Administrator') {
            $sql .= " AND d.approval_status IN ('Pending Level 1', 'Pending Level 2', 'Pending Level 3')";
        }
    } elseif ($filter_shortcut === 'overdue') {
        $sql .= " AND d.due_date IS NOT NULL AND d.due_date < date('now') AND d.status NOT IN ('Completed', 'Rejected')";
    } elseif ($filter_shortcut === 'dispatched') {
        $sql .= " AND d.collection_status IN ('Dispatched / Collected', 'Acknowledged by Registrar', 'Processing at Registrar')";
    }

    if (!empty($_GET['mine'])) {
        $sql .= " AND d.created_by=?";
        $params[] = $currentUser['id'];
    }

    $sql .= " ORDER BY d.id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $registrars = db()->query("SELECT DISTINCT registrar_name FROM documents WHERE registrar_name IS NOT NULL AND registrar_name != '' ORDER BY registrar_name")->fetchAll(PDO::FETCH_COLUMN);
    $types = db()->query("SELECT DISTINCT document_type FROM documents ORDER BY document_type")->fetchAll(PDO::FETCH_COLUMN);
    $account_officers = db()->query("SELECT DISTINCT account_officer FROM documents WHERE account_officer IS NOT NULL AND account_officer != '' ORDER BY account_officer")->fetchAll(PDO::FETCH_COLUMN);

    render_header('Document Register');
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="fw-bold mb-1" style="color:#0b2545;">Document Register</h3>
            <p class="text-muted mb-0">Search, filter, and monitor all multi-level document approvals and registrar collections.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php?page=export" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV</a>
            <a href="index.php?page=document_form" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New Submission</a>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card p-3 mb-4 bg-white shadow-sm">
        <form method="get" action="index.php">
            <input type="hidden" name="page" value="documents">
            <div class="row g-2">
                <div class="col-md-3">
                    <input type="text" name="q" class="form-control" placeholder="Search reference, client, CSCS, officer" value="<?= h($search) ?>">
                </div>
                <div class="col-md-2">
                    <select name="approval_status" class="form-select">
                        <option value="">All Approval Stages</option>
                        <option value="Pending Level 1" <?= $approval_filter==='Pending Level 1'?'selected':'' ?>>Pending Level 1 (Supervisor)</option>
                        <option value="Pending Level 2" <?= $approval_filter==='Pending Level 2'?'selected':'' ?>>Pending Level 2 (Ops Mgr)</option>
                        <option value="Pending Level 3" <?= $approval_filter==='Pending Level 3'?'selected':'' ?>>Pending Level 3 (Compliance)</option>
                        <option value="Fully Approved" <?= $approval_filter==='Fully Approved'?'selected':'' ?>>Fully Approved</option>
                        <option value="Queried" <?= $approval_filter==='Queried'?'selected':'' ?>>Queried</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="">All Lifecycle Statuses</option>
                        <?php foreach (['Received','In Review','Approved','In Progress','Queried','Completed','Rejected'] as $s): ?>
                        <option value="<?= h($s) ?>" <?= $status===$s?'selected':'' ?>><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="registrar" class="form-select">
                        <option value="">All Registrars</option>
                        <?php foreach ($registrars as $r): ?>
                        <option value="<?= h($r) ?>" <?= $registrar_filter===$r?'selected':'' ?>><?= h($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="account_officer" class="form-select">
                        <option value="">All Account Officers</option>
                        <?php foreach ($account_officers as $ao): ?>
                        <option value="<?= h($ao) ?>" <?= $ao_filter===$ao?'selected':'' ?>><?= h($ao) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-dark w-100" title="Filter"><i class="bi bi-funnel"></i></button>
                    <a href="index.php?page=documents" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Document Table -->
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Reference</th>
                        <th>Document & Stock</th>
                        <th>Client & Account Officer</th>
                        <th>Approval Level</th>
                        <th>Registrar Collection</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No documents found matching the criteria.</td></tr>
                    <?php else: foreach ($documents as $d): 
                        $is_late = $d['due_date'] && $d['due_date'] < date('Y-m-d') && !in_array($d['status'], ['Completed', 'Rejected']);
                    ?>
                    <tr class="<?= $is_late ? 'table-warning' : '' ?>">
                        <td>
                            <a href="index.php?page=view&id=<?= $d['id'] ?>" class="fw-bold text-decoration-none">
                                <?= h($d['reference_no']) ?>
                            </a>
                            <div><span class="badge bg-secondary-subtle text-secondary" style="font-size:10px;"><?= h($d['priority']) ?></span></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= h($d['document_type']) ?></div>
                            <small class="text-muted"><?= h($d['stock_symbol'] ?: 'N/A') ?> (<?= number_format($d['units'] ?? 0) ?> units)</small>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= h($d['client_name']) ?></div>
                            <small class="text-muted d-block">CSCS: <?= h($d['client_account']) ?> | CHN: <?= h($d['chn_number'] ?: '-') ?></small>
                            <span class="badge bg-light text-dark border mt-1" style="font-size: 11px;">
                                <i class="bi bi-person-badge text-primary me-1"></i><?= h($d['account_officer'] ?: 'Unassigned') ?>
                            </span>
                        </td>
                        <td>
                            <?= approval_badge($d['approval_status']) ?>
                            <?php if ($d['approval_status'] === 'Queried'): ?>
                            <div class="small text-danger fw-bold"><i class="bi bi-exclamation-circle"></i> Level <?= (int)$d['queried_at_level'] ?> Query</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= h($d['registrar_name']) ?></div>
                            <div><?= collection_badge($d['collection_status']) ?></div>
                            <?php if ($d['collector_name']): ?>
                            <small class="text-muted"><i class="bi bi-person"></i> <?= h($d['collector_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="d-block text-muted">Sub: <?= h($d['submission_date']) ?></small>
                            <small class="<?= $is_late ? 'text-danger fw-bold' : 'text-muted' ?>">
                                Due: <?= h($d['due_date'] ?: '-') ?> <?= $is_late ? '<span class="badge bg-danger">OVERDUE</span>' : '' ?>
                            </small>
                        </td>
                        <td><?= status_badge($d['status']) ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=view&id=<?= $d['id'] ?>" class="btn btn-outline-primary" title="View Details">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="index.php?page=print_slip&id=<?= $d['id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Print Routing Slip">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <a href="index.php?page=document_form&id=<?= $d['id'] ?>" class="btn btn-outline-dark" title="Edit Document">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    render_footer();
    break;

// =============================================================
// PAGE: Document Form (New / Edit)
// =============================================================
case 'document_form':
    $id = (int)($_GET['id'] ?? 0);
    $d = null;
    if ($id) {
        $stmt = db()->prepare("SELECT * FROM documents WHERE id=?");
        $stmt->execute([$id]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            flash('danger', 'Document not found.');
            header('Location: index.php?page=documents');
            exit;
        }
    }

    $auto_ref = 'RAML/REG/' . date('Y') . '/' . strtoupper(bin2hex(random_bytes(3)));
    $v = function($field, $default = '') use ($d) {
        return h($d[$field] ?? $default);
    };

    $all_registrars = get_nigerian_registrars();
    $all_active_officers = get_active_account_officers();
    $preselected_registrar = $_GET['registrar'] ?? $d['registrar_name'] ?? '';

    $active_officers = get_active_account_officers();

    render_header($id ? 'Edit Document' : 'Register New Document');
    ?>
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0 fw-bold" style="color:#0b2545;">
                            <?= $id ? 'Edit Document Submission' : 'Register New Document for Registrar Tracking' ?>
                        </h4>
                        <small class="text-muted">Enter client, CSCS, and security details. Document will enter the 3-tier approval pipeline.</small>
                    </div>
                    <a href="index.php?page=documents" class="btn btn-outline-secondary btn-sm">Cancel & Return</a>
                </div>
                <div class="card-body p-4 p-md-5">
                    <form method="post" action="index.php?page=save_document" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">

                        <h5 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="bi bi-file-earmark-text me-2"></i>1. Document & Transaction Information</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">REFERENCE NUMBER *</label>
                                <input type="text" name="reference_no" class="form-control" value="<?= $v('reference_no', $auto_ref) ?>" required>
                                <div class="form-text">Official tracking reference identifier.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">DOCUMENT TYPE *</label>
                                <select name="document_type" class="form-select" required>
                                    <?php 
                                    $types = [
                                        'Dematerialization',
                                        'Intermember Transfer',
                                        'Transfer of Shares',
                                        'Transmission to Estate',
                                        'Rematerialization',
                                        'Dividend Mandate (E-Dividend)',
                                        'Change of Address / Signature',
                                        'Consolidation of Accounts',
                                        'Outstanding Share Certificate',
                                        'CSCS / Registrar Reconciliation',
                                        'Verification of Certificate',
                                        'Other'
                                    ];
                                    foreach ($types as $t): ?>
                                    <option value="<?= h($t) ?>" <?= $v('document_type') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">PRIORITY LEVEL</label>
                                <select name="priority" class="form-select">
                                    <option value="Normal" <?= $v('priority','Normal')==='Normal'?'selected':'' ?>>Normal</option>
                                    <option value="High" <?= $v('priority')==='High'?'selected':'' ?>>High Priority</option>
                                    <option value="Urgent" <?= $v('priority')==='Urgent'?'selected':'' ?>>Urgent (Express)</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">SECURITY / STOCK SYMBOL</label>
                                <input type="text" name="stock_symbol" class="form-control" placeholder="e.g. ZENITHBANK, MTNN, GTCO, ACCESSCORP" value="<?= $v('stock_symbol') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">NUMBER OF UNITS</label>
                                <input type="number" name="units" class="form-control" placeholder="e.g. 25000" value="<?= $v('units', '0') ?>">
                            </div>
                        </div>

                        <h5 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="bi bi-person-vcard me-2"></i>2. Client & Account Officer Details</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">CLIENT FULL NAME *</label>
                                <input type="text" name="client_name" class="form-control" placeholder="e.g. Alhaji Sulaimon Ibrahim" value="<?= $v('client_name') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">CSCS ACCOUNT NUMBER *</label>
                                <input type="text" name="client_account" class="form-control" placeholder="e.g. CSCS-9018442" value="<?= $v('client_account') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">CHN NUMBER (CLEARING HOUSE NO)</label>
                                <input type="text" name="chn_number" class="form-control" placeholder="e.g. C189024LA" value="<?= $v('chn_number') ?>">
                            </div>
                            <div class="col-md-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-semibold small mb-0">ACCOUNT OFFICER *</label>
                                    <?php if (can_manage_account_officers($currentUser)): ?>
                                    <a href="index.php?page=account_officers" target="_blank" class="small text-decoration-none text-primary" style="font-size: 11px;" title="Manage Account Officers">
                                        <i class="bi bi-gear-fill me-1"></i>Manage
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-person-badge text-primary"></i></span>
                                    <select name="account_officer" class="form-select" required>
                                        <option value="">-- Select Account Officer --</option>
                                        <?php foreach ($active_officers as $ao): ?>
                                        <option value="<?= h($ao['name']) ?>" <?= $v('account_officer') === $ao['name'] ? 'selected' : '' ?>>
                                            <?= h($ao['name']) ?> <?= !empty($ao['department']) ? '('.h($ao['department']).')' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <?php if ($v('account_officer') && !in_array($v('account_officer'), array_column($active_officers, 'name'), true)): ?>
                                        <option value="<?= h($v('account_officer')) ?>" selected><?= h($v('account_officer')) ?> (Currently Assigned)</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="form-text" style="font-size: 11px;">Assigned relationship manager / broker.</div>
                            </div>
                        </div>

                        <h5 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="bi bi-building me-2"></i>3. Target Registrar & Due Dates</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">TARGET REGISTRAR *</label>
                                <select name="registrar_name" id="registrar_select" class="form-select" onchange="toggleCustomRegistrar(this.value)" required>
                                    <option value="">-- Select Main Registrar --</option>
                                    <?php foreach ($all_registrars as $reg): ?>
                                    <option value="<?= h($reg) ?>" <?= ($v('registrar_name') === $reg || $preselected_registrar === $reg) ? 'selected' : '' ?>><?= h($reg) ?></option>
                                    <?php endforeach; ?>
                                    <option value="Other" <?= !in_array($v('registrar_name'), $all_registrars) && $v('registrar_name') ? 'selected' : '' ?>>Other (Specify)</option>
                                </select>
                                <div id="custom_registrar_div" class="mt-2 <?= !in_array($v('registrar_name'), $all_registrars) && $v('registrar_name') ? '' : 'd-none' ?>">
                                    <input type="text" name="registrar_name_custom" class="form-control" placeholder="Enter custom registrar name" value="<?= $v('registrar_name') ?>">
                                </div>
                                <div class="form-text">
                                    Need to verify a registrar? <a href="index.php?page=registrars" target="_blank"><i class="bi bi-box-arrow-up-right"></i> View Nigerian Registrars Directory</a>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">SUBMISSION DATE *</label>
                                <input type="date" name="submission_date" class="form-control" value="<?= $v('submission_date', date('Y-m-d')) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">EXPECTED DUE DATE</label>
                                <input type="date" name="due_date" class="form-control" value="<?= $v('due_date', date('Y-m-d', strtotime('+7 days'))) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold small">TRANSACTION DESCRIPTION / PARTICULARS</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Provide complete particulars of the transaction, certificate numbers, or transfer reason..."><?= $v('description') ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold small">INTERNAL REMARKS / CLIENT INSTRUCTIONS</label>
                                <textarea name="remarks" class="form-control" rows="2" placeholder="Any special client instructions, phone contact, or handling notes..."><?= $v('remarks') ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold small">SUPPORTING DOCUMENT UPLOAD</label>
                                <input type="file" name="document_file" class="form-control">
                                <div class="form-text">Attach scanned submission form, certificate copy, or client instruction letter (PDF, Word, Excel, Images up to 15MB).</div>
                                <?php if ($d && $d['file_path']): ?>
                                <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center">
                                    <i class="bi bi-paperclip fs-5 text-primary me-2"></i>
                                    <span class="small me-3">Current File: <b><?= h($d['file_name']) ?></b></span>
                                    <a href="<?= h($d['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Open File</a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                            <a href="index.php?page=documents" class="btn btn-light px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary px-5 shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> <?= $id ? 'Save Changes' : 'Submit for Level 1 Approval' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
    function toggleCustomRegistrar(val) {
        var div = document.getElementById('custom_registrar_div');
        if (val === 'Other') {
            div.classList.remove('d-none');
        } else {
            div.classList.add('d-none');
        }
    }
    </script>
    <?php
    render_footer();
    break;

// =============================================================
// PAGE: Document View (Approval Pipeline & Collection Tracking)
// =============================================================
case 'view':
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT d.*, u.name creator_name, u.role creator_role FROM documents d LEFT JOIN users u ON u.id=d.created_by WHERE d.id=?");
    $stmt->execute([$id]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        flash('danger', 'Document not found.');
        header('Location: index.php?page=documents');
        exit;
    }

    $appr_logs = db()->prepare("SELECT * FROM approval_logs WHERE document_id=? ORDER BY id DESC");
    $appr_logs->execute([$id]);
    $logs = $appr_logs->fetchAll(PDO::FETCH_ASSOC);

    $hist_stmt = db()->prepare("SELECT h.*, u.name user_name FROM status_history h LEFT JOIN users u ON u.id=h.changed_by WHERE document_id=? ORDER BY h.id DESC");
    $hist_stmt->execute([$id]);
    $history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

    $all_registrars = get_nigerian_registrars();
    $all_active_officers = get_active_account_officers();

    render_header('Document Details - ' . $d['reference_no']);
    ?>
    <!-- Top Action Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h3 class="fw-bold mb-0" style="color:#0b2545;"><?= h($d['reference_no']) ?></h3>
                <span class="badge bg-secondary"><?= h($d['priority']) ?></span>
                <?= approval_badge($d['approval_status']) ?>
                <?= status_badge($d['status']) ?>
            </div>
            <div class="text-muted small">
                Registered on <b><?= h($d['submission_date']) ?></b> by <b><?= h($d['creator_name'] ?: 'Staff') ?></b>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0">
            <a href="index.php?page=print_slip&id=<?= $id ?>" target="_blank" class="btn btn-outline-dark shadow-sm">
                <i class="bi bi-printer me-1"></i> Print Routing Slip
            </a>
            <a href="index.php?page=document_form&id=<?= $id ?>" class="btn btn-outline-primary shadow-sm">
                <i class="bi bi-pencil me-1"></i> Edit Document
            </a>
            <?php if (can_delete_document($currentUser)): ?>
            <form method="post" action="index.php?page=delete_document" onsubmit="return confirm('Are you sure you want to permanently delete this document?');" class="d-inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-outline-danger shadow-sm"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Multi-Level Approval Stepper Visual Progress -->
    <div class="card shadow-sm p-4 mb-4 bg-white">
        <h6 class="fw-bold text-uppercase text-muted mb-3" style="letter-spacing: 0.5px;">
            <i class="bi bi-diagram-3 me-2 text-primary"></i> Multi-Tier Document Approval & Registrar Lifecycle
        </h6>
        <div class="stepper-box">
            <!-- Step 1: Submission -->
            <div class="stepper-step completed">
                <div class="stepper-circle"><i class="bi bi-check-lg"></i></div>
                <div class="fw-bold small">Initiated</div>
                <div class="text-muted" style="font-size: 11px;"><?= h($d['submission_date']) ?></div>
            </div>

            <!-- Step 2: Level 1 -->
            <div class="stepper-step <?= $d['level1_approved'] ? 'completed' : ($d['approval_status']==='Pending Level 1' ? 'active' : ($d['approval_status']==='Queried' && $d['queried_at_level']==1 ? 'queried' : '')) ?>">
                <div class="stepper-circle">
                    <?= $d['level1_approved'] ? '<i class="bi bi-check-lg"></i>' : ($d['approval_status']==='Queried' && $d['queried_at_level']==1 ? '!' : '1') ?>
                </div>
                <div class="fw-bold small">Level 1: Supervisor</div>
                <div class="text-muted" style="font-size: 11px;">
                    <?= $d['level1_approved'] ? 'Approved' : ($d['approval_status']==='Queried' && $d['queried_at_level']==1 ? 'Queried' : 'Pending') ?>
                </div>
            </div>

            <!-- Step 3: Level 2 -->
            <div class="stepper-step <?= $d['level2_approved'] ? 'completed' : ($d['approval_status']==='Pending Level 2' ? 'active' : ($d['approval_status']==='Queried' && $d['queried_at_level']==2 ? 'queried' : '')) ?>">
                <div class="stepper-circle">
                    <?= $d['level2_approved'] ? '<i class="bi bi-check-lg"></i>' : ($d['approval_status']==='Queried' && $d['queried_at_level']==2 ? '!' : '2') ?>
                </div>
                <div class="fw-bold small">Level 2: Ops Manager</div>
                <div class="text-muted" style="font-size: 11px;">
                    <?= $d['level2_approved'] ? 'Approved' : ($d['approval_status']==='Queried' && $d['queried_at_level']==2 ? 'Queried' : 'Pending') ?>
                </div>
            </div>

            <!-- Step 4: Level 3 -->
            <div class="stepper-step <?= $d['level3_approved'] ? 'completed' : ($d['approval_status']==='Pending Level 3' ? 'active' : ($d['approval_status']==='Queried' && $d['queried_at_level']==3 ? 'queried' : '')) ?>">
                <div class="stepper-circle">
                    <?= $d['level3_approved'] ? '<i class="bi bi-check-lg"></i>' : ($d['approval_status']==='Queried' && $d['queried_at_level']==3 ? '!' : '3') ?>
                </div>
                <div class="fw-bold small">Level 3: Compliance</div>
                <div class="text-muted" style="font-size: 11px;">
                    <?= $d['level3_approved'] ? 'Approved' : ($d['approval_status']==='Queried' && $d['queried_at_level']==3 ? 'Queried' : 'Pending') ?>
                </div>
            </div>

            <!-- Step 5: Registrar Collection -->
            <div class="stepper-step <?= $d['collection_status'] !== 'Pending Collection' ? 'completed' : ($d['approval_status']==='Fully Approved' ? 'active' : '') ?>">
                <div class="stepper-circle"><i class="bi bi-truck"></i></div>
                <div class="fw-bold small">Registrar Dispatch</div>
                <div class="text-muted" style="font-size: 11px;"><?= h($d['collection_status']) ?></div>
            </div>

            <!-- Step 6: Complete -->
            <div class="stepper-step <?= in_array($d['status'], ['Completed']) || $d['collection_status']==='Completed by Registrar' ? 'completed' : '' ?>">
                <div class="stepper-circle"><i class="bi bi-flag-fill"></i></div>
                <div class="fw-bold small">Completed</div>
                <div class="text-muted" style="font-size: 11px;">CSCS Updated</div>
            </div>
        </div>
    </div>

    <!-- Alert Banner if Queried -->
    <?php if ($d['approval_status'] === 'Queried' || $d['status'] === 'Queried'): ?>
    <div class="alert alert-danger border-danger p-4 mb-4 shadow-sm" style="border-radius: 12px;">
        <div class="d-flex align-items-start">
            <i class="bi bi-exclamation-octagon-fill fs-2 me-3 text-danger"></i>
            <div class="flex-grow-1">
                <h5 class="fw-bold alert-heading mb-1">Document Queried at Level <?= (int)$d['queried_at_level'] ?></h5>
                <p class="mb-2"><b>Query Reason:</b> <?= nl2br(h($d['query_reason'])) ?></p>
                <div class="small text-muted mb-3">Please amend the required document particulars and submit your resolution note below to re-enter the approval pipeline.</div>
                
                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#resolveQueryModal">
                    <i class="bi bi-arrow-repeat me-1"></i> Resolve Query & Re-Submit for Approval
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Bar for Pending Approval -->
    <?php
    $can_act_level1 = ($d['approval_status'] === 'Pending Level 1' && can_approve_level1($currentUser));
    $can_act_level2 = ($d['approval_status'] === 'Pending Level 2' && can_approve_level2($currentUser));
    $can_act_level3 = ($d['approval_status'] === 'Pending Level 3' && can_approve_level3($currentUser));
    ?>

    <?php if ($can_act_level1 || $can_act_level2 || $can_act_level3): ?>
    <div class="card border-primary shadow-sm mb-4" style="border-left: 5px solid #0d6efd !important;">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold text-primary mb-1">
                    <i class="bi bi-award-fill me-2"></i>
                    Action Required: 
                    <?= $can_act_level1 ? 'Level 1: Supervisor Review' : ($can_act_level2 ? 'Level 2: Operations Clearance' : 'Level 3: Compliance Final Clearance') ?>
                </h5>
                <p class="text-muted mb-0 small">
                    You have authorized permission as <b><?= h($currentUser['role']) ?></b> to grant this approval sign-off or issue a query.
                </p>
            </div>
            <div class="d-flex gap-2 mt-3 mt-md-0">
                <button type="button" class="btn btn-success px-4" data-bs-toggle="modal" data-bs-target="#approveModal">
                    <i class="bi bi-check-circle me-1"></i> Approve
                </button>
                <button type="button" class="btn btn-warning px-3 text-dark" data-bs-toggle="modal" data-bs-target="#queryModal">
                    <i class="bi bi-question-circle me-1"></i> Issue Query
                </button>
                <button type="button" class="btn btn-outline-danger px-3" data-bs-toggle="modal" data-bs-target="#rejectModal">
                    <i class="bi bi-x-circle me-1"></i> Reject
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Grid: Document Data & Registrar Collection -->
    <div class="row g-4 mb-4">
        <!-- Left: Document & Client Information -->
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold" style="color:#0b2545;"><i class="bi bi-info-circle me-2 text-primary"></i> Document & Client Particulars</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <span class="text-muted small fw-semibold">CLIENT NAME</span>
                            <div class="fw-bold fs-6"><?= h($d['client_name']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small fw-semibold">CSCS ACCOUNT NO.</span>
                            <div class="fw-bold fs-6 text-primary"><?= h($d['client_account']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small fw-semibold">CHN NUMBER</span>
                            <div class="fw-semibold"><?= h($d['chn_number'] ?: 'N/A') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small fw-semibold">ACCOUNT OFFICER (RELATIONSHIP MGR)</span>
                                <?php if (can_reassign_account_officer($currentUser)): ?>
                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#reassignAoModal" title="Change Assigned Account Officer" style="font-size: 11px;">
                                    <i class="bi bi-pencil-square me-1"></i>Reassign
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="fw-bold fs-6 text-dark"><i class="bi bi-person-badge text-primary me-1"></i><?= h($d['account_officer'] ?: 'Unassigned') ?></div>
                            <?php 
                            $ao_details = get_account_officer_by_name($d['account_officer']);
                            if ($ao_details): 
                            ?>
                            <div class="text-muted" style="font-size: 11px;">
                                <span><i class="bi bi-briefcase me-1"></i><?= h($ao_details['department']) ?></span>
                                <?php if ($ao_details['phone']): ?> &bull; <span><i class="bi bi-telephone me-1"></i><?= h($ao_details['phone']) ?></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small fw-semibold">DOCUMENT TYPE</span>
                            <div class="fw-semibold"><?= h($d['document_type']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small fw-semibold">STOCK SYMBOL / UNITS</span>
                            <div class="fw-bold"><?= h($d['stock_symbol'] ?: 'N/A') ?> &bull; <?= number_format($d['units'] ?? 0) ?> units</div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small fw-semibold">SUBMISSION / DUE DATE</span>
                            <div>Sub: <?= h($d['submission_date']) ?> | Due: <b><?= h($d['due_date'] ?: '-') ?></b></div>
                        </div>

                        <div class="col-12"><hr class="my-2"></div>

                        <div class="col-12">
                            <span class="text-muted small fw-semibold">TRANSACTION DESCRIPTION</span>
                            <div class="p-3 bg-light rounded mt-1"><?= nl2br(h($d['description'] ?: 'None provided.')) ?></div>
                        </div>

                        <?php if ($d['remarks']): ?>
                        <div class="col-12">
                            <span class="text-muted small fw-semibold">INTERNAL REMARKS / CLIENT NOTES</span>
                            <div class="p-3 bg-light rounded mt-1"><?= nl2br(h($d['remarks'])) ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($d['file_path']): ?>
                        <div class="col-12">
                            <span class="text-muted small fw-semibold">ATTACHED SUPPORTING DOCUMENT</span>
                            <div class="mt-2 p-3 bg-light border rounded d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-file-earmark-pdf fs-3 text-danger me-3"></i>
                                    <div>
                                        <div class="fw-bold"><?= h($d['file_name']) ?></div>
                                        <small class="text-muted">Attached client & verification scan</small>
                                    </div>
                                </div>
                                <a href="<?= h($d['file_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-download me-1"></i> View / Download
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Registrar Collection & Dispatch Tracking Box -->
        <div class="col-lg-5">
            <div class="card shadow-sm h-100 border-primary">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h5 class="mb-0 fw-bold" style="color:#0b2545;">
                        <i class="bi bi-truck me-2 text-primary"></i> Registrar Collection Tracking
                    </h5>
                    <?= collection_badge($d['collection_status']) ?>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <span class="text-muted small fw-semibold">COLLECTING REGISTRAR ENTITY</span>
                        <div class="fw-bold fs-5" style="color:#0b2545;"><?= h($d['registrar_name']) ?></div>
                    </div>

                    <div class="p-3 bg-light rounded mb-3">
                        <div class="row g-2">
                            <div class="col-6">
                                <span class="text-muted small d-block">Liaison / Collector:</span>
                                <span class="fw-bold"><?= h($d['collector_name'] ?: 'Not Dispatched Yet') ?></span>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small d-block">Collector Phone:</span>
                                <span class="fw-semibold"><?= h($d['collector_phone'] ?: '-') ?></span>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small d-block">Collection Date:</span>
                                <span class="fw-semibold"><?= h($d['collected_date'] ?: '-') ?></span>
                            </div>
                            <div class="col-6">
                                <span class="text-muted small d-block">Waybill / Ack Slip:</span>
                                <span class="fw-bold text-primary"><?= h($d['dispatch_waybill_no'] ?: '-') ?></span>
                            </div>
                            <div class="col-12">
                                <span class="text-muted small d-block">Dispatch Method:</span>
                                <span class="fw-semibold"><?= h($d['collection_method'] ?: '-') ?></span>
                            </div>
                            <?php if ($d['collection_notes']): ?>
                            <div class="col-12">
                                <span class="text-muted small d-block">Hand-over Notes:</span>
                                <span class="small"><?= nl2br(h($d['collection_notes'])) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($d['collection_proof_path']): ?>
                    <div class="p-3 bg-white border rounded mb-3 d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-check fs-3 text-success me-2"></i>
                            <div>
                                <div class="fw-bold small">Stamped Proof of Collection</div>
                                <div class="text-muted" style="font-size:11px;"><?= h($d['collection_proof_name']) ?></div>
                            </div>
                        </div>
                        <a href="<?= h($d['collection_proof_path']) ?>" target="_blank" class="btn btn-sm btn-outline-success">View Proof</a>
                    </div>
                    <?php endif; ?>

                    <!-- Collection Action Buttons -->
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordCollectionModal">
                            <i class="bi bi-pencil-square me-1"></i> Record / Update Collection Details
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#updateRegistrarStatusModal">
                            <i class="bi bi-arrow-repeat me-1"></i> Update Registrar Processing Status
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3-Tier Approval History & Audit Trail -->
    <div class="row g-4 mb-4">
        <!-- 3-Tier Sign-off Records -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold" style="color:#0b2545;"><i class="bi bi-shield-check me-2 text-primary"></i> 3-Tier Sign-Off Signatures</h5>
                </div>
                <div class="card-body p-4">
                    <!-- Level 1 Box -->
                    <div class="p-3 mb-3 border rounded <?= $d['level1_approved'] ? 'border-success bg-success-subtle' : 'bg-light' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-primary">LEVEL 1: SUPERVISOR REVIEW</span>
                            <?= $d['level1_approved'] ? '<span class="badge bg-success">Approved ✓</span>' : '<span class="badge bg-warning text-dark">Pending</span>' ?>
                        </div>
                        <div class="small"><b>Officer:</b> <?= h($d['level1_user_name'] ?: 'Awaiting Reviewer Sign-off') ?></div>
                        <div class="small"><b>Timestamp:</b> <?= h($d['level1_date'] ?: '-') ?></div>
                        <div class="small text-muted mt-1"><b>Notes:</b> <?= h($d['level1_notes'] ?: '-') ?></div>
                    </div>

                    <!-- Level 2 Box -->
                    <div class="p-3 mb-3 border rounded <?= $d['level2_approved'] ? 'border-success bg-success-subtle' : 'bg-light' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-info">LEVEL 2: OPERATIONS CLEARANCE</span>
                            <?= $d['level2_approved'] ? '<span class="badge bg-success">Approved ✓</span>' : '<span class="badge bg-info text-dark">Pending</span>' ?>
                        </div>
                        <div class="small"><b>Officer:</b> <?= h($d['level2_user_name'] ?: 'Awaiting Ops Clearance') ?></div>
                        <div class="small"><b>Timestamp:</b> <?= h($d['level2_date'] ?: '-') ?></div>
                        <div class="small text-muted mt-1"><b>Notes:</b> <?= h($d['level2_notes'] ?: '-') ?></div>
                    </div>

                    <!-- Level 3 Box -->
                    <div class="p-3 border rounded <?= $d['level3_approved'] ? 'border-success bg-success-subtle' : 'bg-light' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark">LEVEL 3: COMPLIANCE FINAL CLEARANCE</span>
                            <?= $d['level3_approved'] ? '<span class="badge bg-success">Approved ✓</span>' : '<span class="badge bg-secondary">Pending</span>' ?>
                        </div>
                        <div class="small"><b>Officer:</b> <?= h($d['level3_user_name'] ?: 'Awaiting Compliance Sign-off') ?></div>
                        <div class="small"><b>Timestamp:</b> <?= h($d['level3_date'] ?: '-') ?></div>
                        <div class="small text-muted mt-1"><b>Notes:</b> <?= h($d['level3_notes'] ?: '-') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity & Audit Timeline -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold" style="color:#0b2545;"><i class="bi bi-clock-history me-2 text-primary"></i> Document Activity Timeline</h5>
                </div>
                <div class="card-body p-4" style="max-height: 400px; overflow-y: auto;">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($history as $h): ?>
                        <li class="list-group-item px-0 py-2">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold small text-primary"><?= h($h['new_status']) ?></span>
                                <small class="text-muted"><?= h($h['changed_at']) ?></small>
                            </div>
                            <div class="small"><?= h($h['comment']) ?></div>
                            <small class="text-muted">By: <?= h($h['user_name'] ?: 'System') ?></small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Approve Document -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="index.php?page=approve_document">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="level" value="<?= $can_act_level1 ? 1 : ($can_act_level2 ? 2 : 3) ?>">
                    
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i> Confirm Approval Sign-Off</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info">
                            You are approving <b>Level <?= $can_act_level1 ? '1 (Supervisor)' : ($can_act_level2 ? '2 (Operations Manager)' : '3 (Compliance Officer)') ?></b> for <b><?= h($d['reference_no']) ?></b>.
                        </div>
                        <label class="form-label fw-semibold small">APPROVAL NOTES / REMARKS</label>
                        <textarea name="approval_notes" class="form-control" rows="3" placeholder="Enter verification confirmation notes..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success px-4">Grant Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Query Document -->
    <div class="modal fade" id="queryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="index.php?page=query_document">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="level" value="<?= $can_act_level1 ? 1 : ($can_act_level2 ? 2 : 3) ?>">
                    
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i> Issue Query on Submission</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-warning small">
                            Issuing a query will pause the approval pipeline and return this document to the initiator for rectification.
                        </div>
                        <label class="form-label fw-semibold small">REASON FOR QUERY *</label>
                        <textarea name="query_reason" class="form-control" rows="4" placeholder="Detail the missing signature, signature mismatch, account discrepancy, or required KYC..." required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning px-4 text-dark">Issue Query</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Reject Document -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="index.php?page=reject_document">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="level" value="<?= $can_act_level1 ? 1 : ($can_act_level2 ? 2 : 3) ?>">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i> Reject Submission</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-danger small">
                            Rejecting this submission will formally terminate processing. This action is permanently audited.
                        </div>
                        <label class="form-label fw-semibold small">REJECTION REASON *</label>
                        <textarea name="rejection_reason" class="form-control" rows="4" placeholder="State reason for rejection (e.g. fraudulent certificates, signature mismatch, account frozen)..." required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger px-4">Confirm Rejection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Resolve Query -->
    <div class="modal fade" id="resolveQueryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="index.php?page=resolve_query">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i> Submit Query Resolution</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <label class="form-label fw-semibold small">RESOLUTION NOTES / EXPLANATION *</label>
                        <textarea name="resolution_notes" class="form-control" rows="4" placeholder="Explain what corrections have been made or attached..." required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger px-4">Re-Submit Document</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Record Registrar Collection -->
    <div class="modal fade" id="recordCollectionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="index.php?page=record_collection" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-truck me-2"></i> Record Registrar Collection & Hand-Over</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">COLLECTING REGISTRAR *</label>
                                <select name="registrar_name" id="modal_registrar_select" class="form-select" onchange="toggleModalCustomRegistrar(this.value)" required>
                                    <?php foreach ($all_registrars as $reg): ?>
                                    <option value="<?= h($reg) ?>" <?= $d['registrar_name'] === $reg ? 'selected' : '' ?>><?= h($reg) ?></option>
                                    <?php endforeach; ?>
                                    <option value="Other">Other (Specify below)</option>
                                </select>
                                <div id="modal_custom_registrar_div" class="mt-2 d-none">
                                    <input type="text" name="registrar_name_custom" id="modal_registrar_custom_input" class="form-control form-control-sm" placeholder="Enter custom registrar name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">REGISTRAR LIAISON / COLLECTOR NAME *</label>
                                <input type="text" name="collector_name" class="form-control" placeholder="e.g. Kazeem Oladipo (Registrar Rep)" value="<?= h($d['collector_name']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">COLLECTOR PHONE NUMBER</label>
                                <input type="text" name="collector_phone" class="form-control" placeholder="e.g. +234 803 123 4567" value="<?= h($d['collector_phone']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">COLLECTION / DISPATCH DATE & TIME</label>
                                <input type="text" name="collected_date" class="form-control" value="<?= h($d['collected_date'] ?: date('Y-m-d H:i')) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">DISPATCH / ACKNOWLEDGMENT WAYBILL NO.</label>
                                <input type="text" name="dispatch_waybill_no" class="form-control" placeholder="e.g. AP-WAY-99120" value="<?= h($d['dispatch_waybill_no']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">COLLECTION METHOD</label>
                                <select name="collection_method" class="form-select">
                                    <option value="Registrar Liaison Officer Pick-up" <?= $d['collection_method']==='Registrar Liaison Officer Pick-up'?'selected':'' ?>>Registrar Liaison Officer Pick-up</option>
                                    <option value="Internal Regency Dispatch Rider" <?= $d['collection_method']==='Internal Regency Dispatch Rider'?'selected':'' ?>>Internal Regency Dispatch Rider</option>
                                    <option value="Direct Walk-in to Registrar Office" <?= $d['collection_method']==='Direct Walk-in to Registrar Office'?'selected':'' ?>>Direct Walk-in to Registrar Office</option>
                                    <option value="Courier / DHL Express" <?= $d['collection_method']==='Courier / DHL Express'?'selected':'' ?>>Courier / DHL Express</option>
                                    <option value="Electronic / Registrar SEC Portal" <?= $d['collection_method']==='Electronic / Registrar SEC Portal'?'selected':'' ?>>Electronic / Registrar SEC Portal</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold small">UPLOAD STAMPED ACKNOWLEDGMENT SLIP (PROOF)</label>
                                <input type="file" name="collection_proof" class="form-control">
                                <div class="form-text">Attach photo or PDF scan of the signed/stamped registrar acknowledgment copy.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold small">COLLECTION / HAND-OVER NOTES</label>
                                <textarea name="collection_notes" class="form-control" rows="2" placeholder="Any details on batch envelope number, client copy retention, etc."><?= h($d['collection_notes']) ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save Collection Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    function toggleModalCustomRegistrar(val) {
        var div = document.getElementById('modal_custom_registrar_div');
        if (div) {
            if (val === 'Other') {
                div.classList.remove('d-none');
                var inp = document.getElementById('modal_registrar_custom_input');
                if (inp) inp.focus();
            } else {
                div.classList.add('d-none');
            }
        }
    }
    </script>

    <!-- MODAL: Update Registrar Processing Status -->
    <div class="modal fade" id="updateRegistrarStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="index.php?page=update_registrar_status">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i> Update Registrar Lifecycle Status</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <label class="form-label fw-semibold small">REGISTRAR PROCESSING STATUS</label>
                        <select name="collection_status" class="form-select mb-3">
                            <option value="Dispatched / Collected" <?= $d['collection_status']==='Dispatched / Collected'?'selected':'' ?>>Dispatched / Collected</option>
                            <option value="Acknowledged by Registrar" <?= $d['collection_status']==='Acknowledged by Registrar'?'selected':'' ?>>Acknowledged by Registrar</option>
                            <option value="Processing at Registrar" <?= $d['collection_status']==='Processing at Registrar'?'selected':'' ?>>Processing at Registrar</option>
                            <option value="Completed by Registrar" <?= $d['collection_status']==='Completed by Registrar'?'selected':'' ?>>Completed by Registrar (Final Settlement)</option>
                        </select>

                        <label class="form-label fw-semibold small">STATUS UPDATE NOTES</label>
                        <textarea name="status_notes" class="form-control" rows="3" placeholder="Registrar confirmation reference, certificate release number, or CSCS update date..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark px-4">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Reassign Account Officer -->
    <div class="modal fade" id="reassignAoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="index.php?page=reassign_account_officer">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i> Assign / Reassign Account Officer</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info small mb-3">
                            Select an active Account Officer to manage client communication and registrar follow-up for this document.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">CURRENTLY ASSIGNED OFFICER</label>
                            <div class="p-2 bg-light border rounded fw-bold text-dark">
                                <i class="bi bi-person-badge text-primary me-2"></i><?= h($d['account_officer'] ?: 'Unassigned') ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold small mb-0">NEW ACCOUNT OFFICER *</label>
                                <?php if (can_manage_account_officers($currentUser)): ?>
                                <a href="index.php?page=account_officers" target="_blank" class="small text-decoration-none" style="font-size: 11px;">
                                    <i class="bi bi-plus-circle me-1"></i>Create New Officer
                                </a>
                                <?php endif; ?>
                            </div>
                            <select name="account_officer" class="form-select" required>
                                <option value="">-- Choose Account Officer --</option>
                                <?php foreach ($all_active_officers as $ao): ?>
                                <option value="<?= h($ao['name']) ?>" <?= $d['account_officer'] === $ao['name'] ? 'selected' : '' ?>>
                                    <?= h($ao['name']) ?> <?= !empty($ao['department']) ? '('.h($ao['department']).')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label fw-semibold small">REASSIGNMENT REASON / REMARKS (OPTIONAL)</label>
                            <textarea name="reassignment_reason" class="form-control" rows="2" placeholder="e.g. Portfolio reassigned, staff handover, client requested officer change..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save Assignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    render_footer();
    break;

// =============================================================
// PAGE: Reports & Audits
// =============================================================
case 'reports':
    $audits = db()->query("
        SELECT a.*, u.name user_name, u.role user_role, d.reference_no 
        FROM audit_logs a 
        LEFT JOIN users u ON u.id=a.user_id 
        LEFT JOIN documents d ON d.id=a.document_id 
        ORDER BY a.id DESC LIMIT 150
    ")->fetchAll(PDO::FETCH_ASSOC);

    render_header('Reports & System Audit Trail');
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1" style="color:#0b2545;">Reports & Audit Trail</h3>
            <p class="text-muted mb-0">Complete chronological accountability log of every document creation, approval tier sign-off, and registrar collection.</p>
        </div>
        <a href="index.php?page=export" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Master Document CSV</a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>User & Role</th>
                        <th>Action</th>
                        <th>Document Reference</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audits)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No audit records found.</td></tr>
                    <?php else: foreach ($audits as $a): ?>
                    <tr>
                        <td class="small text-muted"><?= h($a['created_at']) ?></td>
                        <td>
                            <div class="fw-bold small"><?= h($a['user_name'] ?: 'System') ?></div>
                            <small class="text-muted"><?= h($a['user_role'] ?: '-') ?></small>
                        </td>
                        <td><span class="badge bg-secondary-subtle text-secondary"><?= h($a['action']) ?></span></td>
                        <td>
                            <?php if ($a['document_id']): ?>
                            <a href="index.php?page=view&id=<?= $a['document_id'] ?>" class="fw-bold text-decoration-none">
                                <?= h($a['reference_no'] ?: '#' . $a['document_id']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= h($a['details']) ?></td>
                        <td class="small text-muted"><?= h($a['ip_address'] ?: '-') ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    render_footer();
    break;

// =============================================================
// PAGE: Nigerian Registrars Directory
// =============================================================
case 'registrars':
    $directory = get_nigerian_registrars_directory();
    $q_reg = trim($_GET['q'] ?? '');

    if ($q_reg) {
        $directory = array_filter($directory, function($item) use ($q_reg) {
            $haystack = strtolower($item['name'] . ' ' . $item['alias'] . ' ' . $item['popular_equities'] . ' ' . $item['office_location']);
            return strpos($haystack, strtolower($q_reg)) !== false;
        });
    }

    render_header('Nigerian Registrars Directory');
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1" style="color:#0b2545;">Nigerian Registrars Directory</h3>
            <p class="text-muted mb-0">Official list of SEC-registered capital market registrars in Nigeria with office locations and major listed equities.</p>
        </div>
        <a href="index.php?page=document_form" class="btn btn-primary shadow-sm"><i class="bi bi-plus-circle me-1"></i> New Document Submission</a>
    </div>

    <!-- Search Bar -->
    <div class="card p-3 mb-4 bg-white shadow-sm">
        <form method="get" action="index.php">
            <input type="hidden" name="page" value="registrars">
            <div class="row g-2">
                <div class="col-md-9">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Search registrar name, alias, stock symbol (e.g. Zenith, GTCO, MTNN, First Bank), or location..." value="<?= h($q_reg) ?>">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100"><i class="bi bi-search me-1"></i> Search Directory</button>
                    <a href="index.php?page=registrars" class="btn btn-outline-secondary" title="Reset Search"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Registrars Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold text-dark"><i class="bi bi-building me-2 text-primary"></i> Active Registrars (<?= count($directory) ?> Total)</span>
            <span class="badge bg-primary-subtle text-primary">SEC Registered</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Official Registrar Name</th>
                        <th>Common Alias</th>
                        <th>Major Listed Equities / Registers</th>
                        <th>Operations Office Location</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($directory)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No registrars found matching "<?= h($q_reg) ?>".</td></tr>
                    <?php else: $i = 1; foreach ($directory as $reg): ?>
                    <tr>
                        <td class="text-muted fw-bold"><?= $i++ ?></td>
                        <td>
                            <div class="fw-bold fs-6 text-primary"><?= h($reg['name']) ?></div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border"><?= h($reg['alias']) ?></span>
                        </td>
                        <td>
                            <div class="small fw-semibold text-dark"><?= h($reg['popular_equities']) ?></div>
                        </td>
                        <td>
                            <div class="small text-muted"><i class="bi bi-geo-alt me-1 text-danger"></i><?= h($reg['office_location']) ?></div>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?page=document_form&registrar=<?= urlencode($reg['name']) ?>" class="btn btn-outline-primary" title="Create submission for this registrar">
                                    <i class="bi bi-plus-lg me-1"></i> Submit Doc
                                </a>
                                <a href="index.php?page=documents&registrar=<?= urlencode($reg['name']) ?>" class="btn btn-outline-secondary" title="View tracked documents">
                                    <i class="bi bi-folder2-open"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    render_footer();
    break;

// =============================================================
// PAGE: Account Officer Management (Admin Only)
// =============================================================
case 'account_officers':
    if (!can_manage_account_officers($currentUser)) {
        flash('danger', 'Unauthorized access: Administrator rights required to manage Account Officers.');
        header('Location: index.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? 'create';

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['officer_code'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $department = trim($_POST['department'] ?? '');

            if ($name) {
                try {
                    $stmt = db()->prepare("INSERT INTO account_officers (name, officer_code, email, phone, department, active) VALUES (?,?,?,?,?,1)");
                    $stmt->execute([$name, $code, $email, $phone, $department]);
                    audit('Created Account Officer', null, "Created Account Officer '{$name}' ({$department})");
                    flash('success', "Account Officer '{$name}' created successfully.");
                } catch (Exception $e) {
                    flash('danger', 'Error creating Account Officer. Please try again.');
                }
            } else {
                flash('danger', 'Account Officer full name is required.');
            }
            header('Location: index.php?page=account_officers');
            exit;
        }

        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['officer_code'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $department = trim($_POST['department'] ?? '');

            if ($id && $name) {
                $curr = db()->prepare("SELECT name FROM account_officers WHERE id=?");
                $curr->execute([$id]);
                $old_name = $curr->fetchColumn();

                $stmt = db()->prepare("UPDATE account_officers SET name=?, officer_code=?, email=?, phone=?, department=? WHERE id=?");
                $stmt->execute([$name, $code, $email, $phone, $department, $id]);

                // Synchronize existing documents if name changed
                if ($old_name && $old_name !== $name) {
                    db()->prepare("UPDATE documents SET account_officer=? WHERE account_officer=?")->execute([$name, $old_name]);
                }

                audit('Updated Account Officer', null, "Updated Account Officer ID {$id}: {$name}");
                flash('success', "Account Officer '{$name}' updated successfully.");
            }
            header('Location: index.php?page=account_officers');
            exit;
        }

        if ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $ao = db()->prepare("SELECT * FROM account_officers WHERE id=?");
            $ao->execute([$id]);
            $officer = $ao->fetch(PDO::FETCH_ASSOC);

            if ($officer) {
                $new_status = $officer['active'] ? 0 : 1;
                db()->prepare("UPDATE account_officers SET active=? WHERE id=?")->execute([$new_status, $id]);
                audit('Toggled Account Officer Status', null, "Account Officer '{$officer['name']}' set to " . ($new_status ? 'Active' : 'Inactive'));
                flash('success', "Account Officer '{$officer['name']}' " . ($new_status ? 'activated' : 'deactivated') . " successfully.");
            }
            header('Location: index.php?page=account_officers');
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $ao = db()->prepare("SELECT * FROM account_officers WHERE id=?");
            $ao->execute([$id]);
            $officer = $ao->fetch(PDO::FETCH_ASSOC);

            if ($officer) {
                $stmt_cnt = db()->prepare("SELECT COUNT(*) FROM documents WHERE account_officer=?");
                $stmt_cnt->execute([$officer['name']]);
                $doc_count = (int)$stmt_cnt->fetchColumn();

                $reassign_to = trim($_POST['reassign_to'] ?? '');

                if ($doc_count > 0 && !empty($reassign_to)) {
                    // Reassign assigned documents to chosen officer before deleting
                    db()->prepare("UPDATE documents SET account_officer=? WHERE account_officer=?")->execute([$reassign_to, $officer['name']]);
                    db()->prepare("DELETE FROM account_officers WHERE id=?")->execute([$id]);
                    audit('Deleted Account Officer', null, "Deleted '{$officer['name']}', {$doc_count} documents reassigned to '{$reassign_to}'");
                    flash('success', "Account Officer '{$officer['name']}' deleted. All {$doc_count} document(s) have been reassigned to '{$reassign_to}'.");
                } elseif ($doc_count > 0 && empty($_POST['confirm_force_delete'])) {
                    flash('warning', "Cannot delete '{$officer['name']}': {$doc_count} document(s) are currently assigned. Please choose an officer to reassign those documents to in the delete confirmation modal, or deactivate this officer.");
                } else {
                    // Safe delete
                    db()->prepare("DELETE FROM account_officers WHERE id=?")->execute([$id]);
                    audit('Deleted Account Officer', null, "Deleted Account Officer: {$officer['name']}");
                    flash('success', "Account Officer '{$officer['name']}' deleted successfully.");
                }
            }
            header('Location: index.php?page=account_officers');
            exit;
        }
    }

    $officers = get_all_account_officers();
    $active_officers = get_active_account_officers();

    render_header('Account Officers & Relationship Managers');
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1" style="color:#0b2545;">Account Officers & Relationship Managers</h3>
            <p class="text-muted mb-0">Manage registered Relationship Managers who originate documents and manage client portfolios.</p>
        </div>
        <a href="index.php?page=documents" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Document Register</a>
    </div>

    <div class="row g-4">
        <!-- Left: Account Officers List -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold" style="color:#0b2545;">
                        <i class="bi bi-person-badge me-2 text-primary"></i> Registered Officers (<?= count($officers) ?> Total)
                    </span>
                    <span class="badge bg-primary-subtle text-primary"><?= count($active_officers) ?> Active for Submissions</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name & Staff Code</th>
                                <th>Department / Desk</th>
                                <th>Contact Info</th>
                                <th class="text-center">Assigned Docs</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($officers)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No Account Officers created yet. Use the form on the right to add one.</td></tr>
                            <?php else: foreach ($officers as $ao): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark fs-6"><?= h($ao['name']) ?></div>
                                    <small class="text-muted"><i class="bi bi-upc-scan me-1"></i><?= h($ao['officer_code'] ?: 'No Code') ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= h($ao['department'] ?: 'General Equities') ?></span>
                                </td>
                                <td>
                                    <?php if ($ao['email']): ?>
                                    <div class="small"><i class="bi bi-envelope me-1 text-muted"></i><?= h($ao['email']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($ao['phone']): ?>
                                    <div class="small text-muted"><i class="bi bi-telephone me-1 text-muted"></i><?= h($ao['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($ao['doc_count'] > 0): ?>
                                    <a href="index.php?page=documents&account_officer=<?= urlencode($ao['name']) ?>" class="badge bg-primary text-decoration-none px-2 py-1" title="View documents assigned to this officer">
                                        <?= (int)$ao['doc_count'] ?> documents <i class="bi bi-arrow-right-short"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="badge bg-light text-muted border">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $ao['active'] 
                                        ? '<span class="badge bg-success-subtle text-success">Active</span>' 
                                        : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>' ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Edit Modal Trigger -->
                                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editAoModal<?= $ao['id'] ?>" title="Edit Details">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- Toggle Status -->
                                        <form method="post" action="index.php?page=account_officers" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= $ao['id'] ?>">
                                            <button type="submit" class="btn btn-outline-<?= $ao['active'] ? 'warning' : 'success' ?>" title="<?= $ao['active'] ? 'Deactivate (Hide from new submissions)' : 'Activate' ?>">
                                                <i class="bi bi-<?= $ao['active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Delete Modal Trigger -->
                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAoModal<?= $ao['id'] ?>" title="Delete Officer">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>

                                    <!-- MODAL: Edit Account Officer -->
                                    <div class="modal fade" id="editAoModal<?= $ao['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog text-start">
                                            <div class="modal-content">
                                                <form method="post" action="index.php?page=account_officers">
                                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="edit">
                                                    <input type="hidden" name="id" value="<?= $ao['id'] ?>">

                                                    <div class="modal-header bg-dark text-white">
                                                        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i> Edit Account Officer</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-semibold">FULL NAME *</label>
                                                            <input type="text" name="name" class="form-control" value="<?= h($ao['name']) ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-semibold">STAFF / OFFICER CODE</label>
                                                            <input type="text" name="officer_code" class="form-control" value="<?= h($ao['officer_code']) ?>" placeholder="e.g. AO-001">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-semibold">DEPARTMENT / BRANCH DESK</label>
                                                            <input type="text" name="department" class="form-control" value="<?= h($ao['department']) ?>" placeholder="e.g. Equities & Wealth Mgmt">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-semibold">EMAIL ADDRESS</label>
                                                            <input type="email" name="email" class="form-control" value="<?= h($ao['email']) ?>" placeholder="officer@regency.com">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-semibold">PHONE NUMBER</label>
                                                            <input type="text" name="phone" class="form-control" value="<?= h($ao['phone']) ?>" placeholder="e.g. +234 802 345 6789">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary px-4">Update Officer</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MODAL: Delete Account Officer -->
                                    <div class="modal fade" id="deleteAoModal<?= $ao['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog text-start">
                                            <div class="modal-content">
                                                <form method="post" action="index.php?page=account_officers">
                                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $ao['id'] ?>">

                                                    <div class="modal-header bg-danger text-white">
                                                        <h5 class="modal-title"><i class="bi bi-trash me-2"></i> Delete Account Officer</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <p class="mb-2">Are you sure you want to delete Account Officer: <b><?= h($ao['name']) ?></b>?</p>

                                                        <?php if ($ao['doc_count'] > 0): ?>
                                                        <div class="alert alert-warning small">
                                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                            <b>Attention:</b> This officer currently has <b><?= (int)$ao['doc_count'] ?></b> document(s) assigned. 
                                                            To protect document history, please choose an officer to reassign those documents to:
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-semibold">REASSIGN ASSIGNED DOCUMENTS TO *</label>
                                                            <select name="reassign_to" class="form-select" required>
                                                                <option value="">-- Choose New Officer --</option>
                                                                <?php foreach ($officers as $other_ao): if ($other_ao['id'] != $ao['id']): ?>
                                                                <option value="<?= h($other_ao['name']) ?>"><?= h($other_ao['name']) ?> (<?= h($other_ao['department']) ?>)</option>
                                                                <?php endif; endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <?php else: ?>
                                                        <div class="alert alert-secondary small">
                                                            This officer has 0 documents currently assigned. Deletion is safe and permanent.
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger px-4">Confirm Delete</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right: Create New Account Officer Form -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold" style="color:#0b2545;">
                        <i class="bi bi-person-plus me-2 text-primary"></i> Create Account Officer
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="post" action="index.php?page=account_officers">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">OFFICER FULL NAME *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Oluwaseun Fatokun" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">STAFF / OFFICER CODE</label>
                            <input type="text" name="officer_code" class="form-control" placeholder="e.g. AO-006">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">DEPARTMENT / BRANCH DESK</label>
                            <input type="text" name="department" class="form-control" placeholder="e.g. Equities & Wealth Mgmt">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">OFFICIAL EMAIL</label>
                            <input type="email" name="email" class="form-control" placeholder="name@regency.com">
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-semibold">PHONE NUMBER</label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. +234 802 123 4567">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 shadow-sm py-2">
                            <i class="bi bi-person-check me-1"></i> Save & Activate Officer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    render_footer();
    break;

// =============================================================
// PAGE: User Management (Admin Only)
// =============================================================
// =============================================================
// PAGE: User Management (Admin Only — Single & Multi-User Creation)
// =============================================================
case 'users':
    if (!can_manage_users($currentUser)) {
        flash('danger', 'Unauthorized access: Administrator rights required.');
        header('Location: index.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? 'create';

        if ($action === 'toggle_status') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id === (int)$currentUser['id']) {
                flash('danger', 'Security Restriction: You cannot deactivate your own administrative account.');
            } else {
                $u = db()->prepare("SELECT active, name FROM users WHERE id=?");
                $u->execute([$user_id]);
                $user_data = $u->fetch(PDO::FETCH_ASSOC);
                if ($user_data) {
                    $new_status = $user_data['active'] ? 0 : 1;
                    db()->prepare("UPDATE users SET active=? WHERE id=?")->execute([$new_status, $user_id]);
                    audit('Toggled User Status', null, 'User ' . $user_data['name'] . ' changed to ' . ($new_status ? 'Active' : 'Inactive'));
                    flash('success', 'User account ' . ($new_status ? 'activated' : 'deactivated') . ' successfully.');
                }
            }
            header('Location: index.php?page=users');
            exit;
        }

        if ($action === 'reset_password') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $new_pass = trim($_POST['new_password'] ?? '');
            if ($user_id && strlen($new_pass) >= 6) {
                db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new_pass, PASSWORD_DEFAULT), $user_id]);
                audit('Reset User Password', null, 'Password reset for user ID ' . $user_id);
                flash('success', 'User password updated successfully.');
            } else {
                flash('danger', 'Password must be at least 6 characters long.');
            }
            header('Location: index.php?page=users');
            exit;
        }

        if ($action === 'delete_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id === (int)$currentUser['id']) {
                flash('danger', 'Security Restriction: You cannot delete your own account.');
            } else {
                $u = db()->prepare("SELECT name, email FROM users WHERE id=?");
                $u->execute([$user_id]);
                $user_data = $u->fetch(PDO::FETCH_ASSOC);
                if ($user_data) {
                    db()->prepare("DELETE FROM users WHERE id=?")->execute([$user_id]);
                    audit('Deleted User', null, 'Deleted user ' . $user_data['name'] . ' (' . $user_data['email'] . ')');
                    flash('success', 'User ' . $user_data['name'] . ' has been deleted.');
                }
            }
            header('Location: index.php?page=users');
            exit;
        }

        // Multi-User Batch Creation
        if ($action === 'bulk_create') {
            $created = 0;
            $updated = 0;
            $errors = [];

            // 1. Process Grid Rows
            $rows = $_POST['users'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $i => $row) {
                    $name = trim($row['name'] ?? '');
                    $email = strtolower(trim($row['email'] ?? ''));
                    $pass = trim($row['password'] ?? '');
                    $role = trim($row['role'] ?? 'Staff');

                    if (empty($name) && empty($email)) continue;

                    if (!$name || !$email || !$pass) {
                        $errors[] = "Row #" . ($i + 1) . ": Name, email, and password are required.";
                        continue;
                    }

                    $st = db()->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?)");
                    $st->execute([$email]);
                    $existing_id = $st->fetchColumn();
                    $hash = password_hash($pass, PASSWORD_DEFAULT);

                    if ($existing_id) {
                        db()->prepare("UPDATE users SET name=?, password=?, role=?, active=1 WHERE id=?")
                            ->execute([$name, $hash, $role, $existing_id]);
                        $updated++;
                        audit('Updated User', null, "Batch updated user $email as $role");
                    } else {
                        db()->prepare("INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, 1)")
                            ->execute([$name, $email, $hash, $role]);
                        $created++;
                        audit('Created User', null, "Batch created user $email as $role");
                    }
                }
            }

            // 2. Process CSV / Plaintext Paste
            $bulk_text = trim($_POST['bulk_text'] ?? '');
            if (!empty($bulk_text)) {
                $lines = explode("\n", str_replace("\r", "", $bulk_text));
                foreach ($lines as $ln => $line) {
                    $line = trim($line);
                    if (empty($line) || str_starts_with($line, '#')) continue;

                    $parts = str_contains($line, "\t") ? explode("\t", $line) : explode(",", $line);
                    $name = trim($parts[0] ?? '');
                    $email = strtolower(trim($parts[1] ?? ''));
                    $pass = trim($parts[2] ?? '');
                    $role = trim($parts[3] ?? 'Staff');

                    $valid_roles = ['Staff', 'Supervisor', 'Operations Manager', 'Compliance Officer', 'Administrator'];
                    if (!in_array($role, $valid_roles, true)) {
                        $matched = 'Staff';
                        foreach ($valid_roles as $vr) {
                            if (stripos($role, $vr) !== false || stripos($vr, $role) !== false) {
                                $matched = $vr;
                                break;
                            }
                        }
                        $role = $matched;
                    }

                    if (!$name || !$email || !$pass) {
                        $errors[] = "Line " . ($ln + 1) . ": Expected format: Full Name, Email, Password, Role";
                        continue;
                    }

                    $st = db()->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?)");
                    $st->execute([$email]);
                    $existing_id = $st->fetchColumn();
                    $hash = password_hash($pass, PASSWORD_DEFAULT);

                    if ($existing_id) {
                        db()->prepare("UPDATE users SET name=?, password=?, role=?, active=1 WHERE id=?")
                            ->execute([$name, $hash, $role, $existing_id]);
                        $updated++;
                        audit('Updated User', null, "CSV updated user $email as $role");
                    } else {
                        db()->prepare("INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, 1)")
                            ->execute([$name, $email, $hash, $role]);
                        $created++;
                        audit('Created User', null, "CSV created user $email as $role");
                    }
                }
            }

            $results = [];
            if ($created > 0) $results[] = "Successfully created <b>$created</b> user account(s).";
            if ($updated > 0) $results[] = "Updated <b>$updated</b> existing user account(s).";
            if (!empty($results)) flash('success', implode(' ', $results));
            if (!empty($errors)) flash('warning', "Batch notices:<br>" . implode('<br>', array_slice($errors, 0, 5)));

            header('Location: index.php?page=users');
            exit;
        }

        // Single User Creation
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = trim($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'Staff';

        if ($name && $email && $password) {
            $chk = db()->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?)");
            $chk->execute([$email]);
            $existing_id = $chk->fetchColumn();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            if ($existing_id) {
                db()->prepare("UPDATE users SET name=?, password=?, role=?, active=1 WHERE id=?")
                    ->execute([$name, $hash, $role, $existing_id]);
                audit('Updated User', null, 'Updated user ' . $email . ' as ' . $role);
                flash('success', "User account for $email was already present and has been updated.");
            } else {
                db()->prepare("INSERT INTO users (name, email, password, role, active) VALUES (?,?,?,?,1)")
                    ->execute([$name, $email, $hash, $role]);
                audit('Created User', null, 'Created user ' . $email . ' as ' . $role);
                flash('success', "User account for $name ($email) created successfully.");
            }
        } else {
            flash('danger', 'Full name, email address, and temporary password are required.');
        }
        header('Location: index.php?page=users');
        exit;
    }

    $users = db()->query("SELECT * FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    render_header('User & Role Management');
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h4 class="mb-1 fw-bold" style="color:#0b2545;">
                <i class="bi bi-people-fill me-2 text-primary"></i> User & Access Control Management
            </h4>
            <p class="text-muted small mb-0">Create multiple users, configure approval roles, and manage permissions.</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0">
            <button type="button" class="btn btn-primary shadow-sm" onclick="showTab('batch-tab')">
                <i class="bi bi-person-plus-fill me-1"></i> Create Multiple Users
            </button>
            <button type="button" class="btn btn-outline-primary" onclick="showTab('single-tab')">
                <i class="bi bi-person-plus me-1"></i> Add Single User
            </button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-4" id="userManagementTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-semibold" id="directory-tab" data-bs-toggle="pill" data-bs-target="#tab-directory" type="button">
                <i class="bi bi-table me-1"></i> User Directory <span class="badge bg-light text-dark ms-1"><?= count($users) ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-semibold" id="batch-tab" data-bs-toggle="pill" data-bs-target="#tab-batch" type="button">
                <i class="bi bi-people-fill me-1 text-warning"></i> Batch / Multiple User Creator
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-semibold" id="single-tab" data-bs-toggle="pill" data-bs-target="#tab-single" type="button">
                <i class="bi bi-person-plus me-1"></i> Add Single User
            </button>
        </li>
    </ul>

    <div class="tab-content" id="userManagementTabsContent">
        <!-- TAB 1: User Directory -->
        <div class="tab-pane fade show active" id="tab-directory">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold" style="color:#0b2545;">
                        <i class="bi bi-shield-lock me-2 text-primary"></i> Registered System Users
                    </h5>
                    <span class="text-muted small">Total: <b><?= count($users) ?></b> active and inactive accounts</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Full Name</th>
                                <th>Email Address</th>
                                <th>Role / Approval Tier</th>
                                <th>Account Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $idx => $u): ?>
                            <tr>
                                <td class="text-muted small"><?= $idx + 1 ?></td>
                                <td class="fw-bold">
                                    <?= h($u['name']) ?>
                                    <?php if ($u['id'] == $currentUser['id']): ?>
                                        <span class="badge bg-secondary ms-1" style="font-size:10px;">You</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= h($u['email']) ?></code></td>
                                <td>
                                    <?php 
                                    $role_badges = [
                                        'Staff' => 'bg-secondary',
                                        'Supervisor' => 'bg-warning text-dark',
                                        'Operations Manager' => 'bg-info text-dark',
                                        'Compliance Officer' => 'bg-primary',
                                        'Administrator' => 'bg-dark'
                                    ];
                                    $b = $role_badges[$u['role']] ?? 'bg-secondary';
                                    ?>
                                    <span class="badge <?= $b ?>"><?= h($u['role']) ?></span>
                                </td>
                                <td>
                                    <?= $u['active'] ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-danger-subtle text-danger">Inactive</span>' ?>
                                </td>
                                <td class="small text-muted"><?= substr($u['created_at'] ?? '', 0, 10) ?></td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <!-- Toggle Status -->
                                        <?php if ($u['id'] != $currentUser['id']): ?>
                                        <form method="post" action="index.php?page=users" style="display:inline;" onsubmit="return confirm('Toggle active status for <?= h($u['name']) ?>?');">
                                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-outline-<?= $u['active'] ? 'warning' : 'success' ?> btn-sm" title="<?= $u['active'] ? 'Deactivate User' : 'Activate User' ?>">
                                                <i class="bi bi-<?= $u['active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <!-- Reset Password -->
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#resetUserModal<?= $u['id'] ?>" title="Reset Password">
                                            <i class="bi bi-key"></i>
                                        </button>

                                        <!-- Delete User -->
                                        <?php if ($u['id'] != $currentUser['id']): ?>
                                        <form method="post" action="index.php?page=users" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete <?= h($u['name']) ?> (<?= h($u['email']) ?>)?');">
                                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete User">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Modal: Reset Password -->
                                    <div class="modal fade" id="resetUserModal<?= $u['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-sm">
                                            <div class="modal-content text-start">
                                                <form method="post" action="index.php?page=users">
                                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="reset_password">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <div class="modal-header bg-dark text-white py-2">
                                                        <h6 class="modal-title mb-0">Reset Password</h6>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-3">
                                                        <div class="small text-muted mb-2">New password for <b><?= h($u['name']) ?></b>:</div>
                                                        <input type="password" name="new_password" class="form-control" placeholder="New password (min 6 chars)" minlength="6" required>
                                                    </div>
                                                    <div class="modal-footer py-2">
                                                        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-sm btn-dark">Update Password</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: Batch / Multiple User Creator -->
        <div class="tab-pane fade" id="tab-batch">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bold" style="color:#0b2545;">
                            <i class="bi bi-grid-3x3-gap-fill me-2 text-warning"></i> Multi-User Creation Grid
                        </h5>
                        <small class="text-muted">Enter multiple user accounts to create all of them in a single batch.</small>
                    </div>
                    <div class="d-flex gap-2 mt-2 mt-md-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="prefillStandardRoles()">
                            <i class="bi bi-magic me-1"></i> Pre-Fill 5 Standard Roles
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addMultiUserRow()">
                            <i class="bi bi-plus-circle me-1"></i> Add Another Row
                        </button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="post" action="index.php?page=users">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="bulk_create">

                        <div class="table-responsive mb-3">
                            <table class="table table-bordered align-middle" id="multiUserTable">
                                <thead class="table-light small fw-bold">
                                    <tr>
                                        <th style="width:25%;">FULL NAME *</th>
                                        <th style="width:30%;">EMAIL ADDRESS *</th>
                                        <th style="width:20%;">PASSWORD *</th>
                                        <th style="width:20%;">ROLE & TIER *</th>
                                        <th style="width:5%;" class="text-center">RM</th>
                                    </tr>
                                </thead>
                                <tbody id="multiUserBody">
                                    <!-- Dynamic rows inserted by JavaScript or default rows -->
                                    <tr>
                                        <td><input type="text" name="users[0][name]" class="form-control form-control-sm" placeholder="e.g. Babatunde Alabi" required></td>
                                        <td><input type="email" name="users[0][email]" class="form-control form-control-sm" placeholder="b.alabi@regency.com" required></td>
                                        <td><input type="text" name="users[0][password]" class="form-control form-control-sm" placeholder="Pass@12345" required></td>
                                        <td>
                                            <select name="users[0][role]" class="form-select form-select-sm">
                                                <option value="Staff">Staff (Initiator)</option>
                                                <option value="Supervisor">Supervisor (Level 1)</option>
                                                <option value="Operations Manager">Operations Manager (Level 2)</option>
                                                <option value="Compliance Officer">Compliance Officer (Level 3)</option>
                                                <option value="Administrator">Administrator</option>
                                            </select>
                                        </td>
                                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeMultiUserRow(this)"><i class="bi bi-x"></i></button></td>
                                    </tr>
                                    <tr>
                                        <td><input type="text" name="users[1][name]" class="form-control form-control-sm" placeholder="e.g. Adeola Johnson"></td>
                                        <td><input type="email" name="users[1][email]" class="form-control form-control-sm" placeholder="a.johnson@regency.com"></td>
                                        <td><input type="text" name="users[1][password]" class="form-control form-control-sm" placeholder="Pass@12345"></td>
                                        <td>
                                            <select name="users[1][role]" class="form-select form-select-sm">
                                                <option value="Supervisor" selected>Supervisor (Level 1)</option>
                                                <option value="Staff">Staff (Initiator)</option>
                                                <option value="Operations Manager">Operations Manager (Level 2)</option>
                                                <option value="Compliance Officer">Compliance Officer (Level 3)</option>
                                                <option value="Administrator">Administrator</option>
                                            </select>
                                        </td>
                                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeMultiUserRow(this)"><i class="bi bi-x"></i></button></td>
                                    </tr>
                                    <tr>
                                        <td><input type="text" name="users[2][name]" class="form-control form-control-sm" placeholder="e.g. Chioma Okonkwo"></td>
                                        <td><input type="email" name="users[2][email]" class="form-control form-control-sm" placeholder="c.okonkwo@regency.com"></td>
                                        <td><input type="text" name="users[2][password]" class="form-control form-control-sm" placeholder="Pass@12345"></td>
                                        <td>
                                            <select name="users[2][role]" class="form-select form-select-sm">
                                                <option value="Compliance Officer" selected>Compliance Officer (Level 3)</option>
                                                <option value="Staff">Staff (Initiator)</option>
                                                <option value="Supervisor">Supervisor (Level 1)</option>
                                                <option value="Operations Manager">Operations Manager (Level 2)</option>
                                                <option value="Administrator">Administrator</option>
                                            </select>
                                        </td>
                                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeMultiUserRow(this)"><i class="bi bi-x"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted">
                                <i class="bi bi-filetype-csv me-1"></i> OPTIONAL: Quick Paste (CSV or Tab-Delimited)
                            </label>
                            <textarea name="bulk_text" class="form-control font-monospace" rows="4" placeholder="Format per line: Full Name, Email, Password, Role&#10;e.g.&#10;David Adeleke, d.adeleke@regency.com, David@12345, Staff&#10;Funke Akindele, f.akindele@regency.com, Funke@12345, Supervisor"></textarea>
                            <small class="text-muted">You can fill the table above, paste lines here, or do both. Blank rows will be skipped safely.</small>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <button type="button" class="btn btn-outline-secondary" onclick="addMultiUserRow()">
                                <i class="bi bi-plus-lg me-1"></i> Add Another Row
                            </button>
                            <button type="submit" class="btn btn-primary px-4 py-2 shadow-sm">
                                <i class="bi bi-check2-all me-2"></i> Create All Multiple Users Now
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- TAB 3: Single User Form -->
        <div class="tab-pane fade" id="tab-single">
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0 fw-bold" style="color:#0b2545;">
                                <i class="bi bi-person-plus me-2 text-primary"></i> Add Single User
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="post" action="index.php?page=users">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="create">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">FULL NAME *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Babatunde Alabi" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">EMAIL ADDRESS *</label>
                                    <input type="email" name="email" class="form-control" placeholder="name@regency.com" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">TEMPORARY PASSWORD *</label>
                                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-semibold">ROLE & APPROVAL RESPONSIBILITY *</label>
                                    <select name="role" class="form-select" required>
                                        <option value="Staff">Staff (Initiator / Submissions)</option>
                                        <option value="Supervisor">Supervisor (Level 1 Reviewer)</option>
                                        <option value="Operations Manager">Operations Manager (Level 2 Approver)</option>
                                        <option value="Compliance Officer">Compliance Officer (Level 3 Approver)</option>
                                        <option value="Administrator">Administrator (Full Control)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 shadow-sm py-2">
                                    <i class="bi bi-person-check me-1"></i> Create Single User Account
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    let rowIndex = 3;

    function showTab(tabId) {
        const trigger = document.getElementById(tabId);
        if (trigger) {
            const tab = new bootstrap.Tab(trigger);
            tab.show();
        }
    }

    function addMultiUserRow() {
        const tbody = document.getElementById('multiUserBody');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="users[${rowIndex}][name]" class="form-control form-control-sm" placeholder="Full Name" required></td>
            <td><input type="email" name="users[${rowIndex}][email]" class="form-control form-control-sm" placeholder="email@regency.com" required></td>
            <td><input type="text" name="users[${rowIndex}][password]" class="form-control form-control-sm" placeholder="Password" required></td>
            <td>
                <select name="users[${rowIndex}][role]" class="form-select form-select-sm">
                    <option value="Staff">Staff (Initiator)</option>
                    <option value="Supervisor">Supervisor (Level 1)</option>
                    <option value="Operations Manager">Operations Manager (Level 2)</option>
                    <option value="Compliance Officer">Compliance Officer (Level 3)</option>
                    <option value="Administrator">Administrator</option>
                </select>
            </td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeMultiUserRow(this)"><i class="bi bi-x"></i></button></td>
        `;
        tbody.appendChild(tr);
        rowIndex++;
    }

    function removeMultiUserRow(btn) {
        const row = btn.closest('tr');
        const tbody = document.getElementById('multiUserBody');
        if (tbody.rows.length > 1) {
            row.remove();
        } else {
            alert('At least one row is required in the multi-user creator.');
        }
    }

    function prefillStandardRoles() {
        const tbody = document.getElementById('multiUserBody');
        tbody.innerHTML = '';
        const standards = [
            { name: 'Fatokun Oluwaseun (Operations Staff)', email: 'staff@regency.com', pass: 'Staff@12345', role: 'Staff' },
            { name: 'Adeola Johnson (Supervisor)', email: 'supervisor@regency.com', pass: 'Supervisor@12345', role: 'Supervisor' },
            { name: 'Babatunde Alabi (Operations Manager)', email: 'opsmanager@regency.com', pass: 'Manager@12345', role: 'Operations Manager' },
            { name: 'Chioma Okonkwo (Compliance Head)', email: 'compliance@regency.com', pass: 'Compliance@12345', role: 'Compliance Officer' },
            { name: 'System Administrator', email: 'admin@regency.com', pass: 'Admin@12345', role: 'Administrator' }
        ];

        standards.forEach((s, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input type="text" name="users[${idx}][name]" value="${s.name}" class="form-control form-control-sm" required></td>
                <td><input type="email" name="users[${idx}][email]" value="${s.email}" class="form-control form-control-sm" required></td>
                <td><input type="text" name="users[${idx}][password]" value="${s.pass}" class="form-control form-control-sm" required></td>
                <td>
                    <select name="users[${idx}][role]" class="form-select form-select-sm">
                        <option value="Staff" ${s.role === 'Staff' ? 'selected' : ''}>Staff (Initiator)</option>
                        <option value="Supervisor" ${s.role === 'Supervisor' ? 'selected' : ''}>Supervisor (Level 1)</option>
                        <option value="Operations Manager" ${s.role === 'Operations Manager' ? 'selected' : ''}>Operations Manager (Level 2)</option>
                        <option value="Compliance Officer" ${s.role === 'Compliance Officer' ? 'selected' : ''}>Compliance Officer (Level 3)</option>
                        <option value="Administrator" ${s.role === 'Administrator' ? 'selected' : ''}>Administrator</option>
                    </select>
                </td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeMultiUserRow(this)"><i class="bi bi-x"></i></button></td>
            `;
            tbody.appendChild(tr);
        });
        rowIndex = standards.length;
    }
    </script>
    <?php
    render_footer();
    break;

default:
    header('Location: index.php?page=dashboard');
    exit;
}
?>

