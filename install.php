<?php
require_once 'config.php';

$pdo = db();

// Drop or create tables
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'Staff',
    active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference_no TEXT NOT NULL UNIQUE,
    client_name TEXT,
    client_account TEXT,
    chn_number TEXT,
    account_officer TEXT,
    document_type TEXT NOT NULL,
    stock_symbol TEXT,
    units INTEGER DEFAULT 0,
    description TEXT,
    priority TEXT NOT NULL DEFAULT 'Normal',
    submission_date TEXT NOT NULL,
    due_date TEXT,
    status TEXT NOT NULL DEFAULT 'Received',
    
    -- Approval Workflow (3+ Levels)
    approval_status TEXT NOT NULL DEFAULT 'Pending Level 1',
    level1_approved INTEGER DEFAULT 0,
    level1_user_id INTEGER,
    level1_user_name TEXT,
    level1_date DATETIME,
    level1_notes TEXT,
    
    level2_approved INTEGER DEFAULT 0,
    level2_user_id INTEGER,
    level2_user_name TEXT,
    level2_date DATETIME,
    level2_notes TEXT,
    
    level3_approved INTEGER DEFAULT 0,
    level3_user_id INTEGER,
    level3_user_name TEXT,
    level3_date DATETIME,
    level3_notes TEXT,
    
    queried_at_level INTEGER DEFAULT 0,
    query_reason TEXT,
    
    -- Registrar & Collection Details
    registrar_name TEXT,
    collection_status TEXT NOT NULL DEFAULT 'Pending Collection',
    collector_name TEXT,
    collector_phone TEXT,
    collected_date TEXT,
    dispatch_waybill_no TEXT,
    collection_method TEXT,
    collection_proof_name TEXT,
    collection_proof_path TEXT,
    collection_notes TEXT,
    
    -- General Files & Remarks
    file_name TEXT,
    file_path TEXT,
    remarks TEXT,
    created_by INTEGER,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS approval_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    level INTEGER NOT NULL,
    level_name TEXT NOT NULL,
    action TEXT NOT NULL,
    user_id INTEGER,
    user_name TEXT,
    user_role TEXT,
    comments TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    old_status TEXT,
    new_status TEXT NOT NULL,
    comment TEXT,
    changed_by INTEGER,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY(changed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    document_id INTEGER,
    action TEXT NOT NULL,
    details TEXT,
    ip_address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS account_officers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    officer_code TEXT,
    email TEXT,
    phone TEXT,
    department TEXT,
    active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Seed default users for all approval tiers
$default_users = [
    [
        'name' => 'System Administrator',
        'email' => 'admin@regency.com',
        'password' => 'Admin@12345',
        'role' => 'Administrator'
    ],
    [
        'name' => 'Fatokun Oluwaseun (Operations Staff)',
        'email' => 'staff@regency.com',
        'password' => 'Staff@12345',
        'role' => 'Staff'
    ],
    [
        'name' => 'Adeola Johnson (Supervisor - L1 Approver)',
        'email' => 'supervisor@regency.com',
        'password' => 'Supervisor@12345',
        'role' => 'Supervisor'
    ],
    [
        'name' => 'Babatunde Alabi (Operations Manager - L2 Approver)',
        'email' => 'opsmanager@regency.com',
        'password' => 'Manager@12345',
        'role' => 'Operations Manager'
    ],
    [
        'name' => 'Chioma Okonkwo (Compliance Head - L3 Approver)',
        'email' => 'compliance@regency.com',
        'password' => 'Compliance@12345',
        'role' => 'Compliance Officer'
    ],
    [
        'name' => 'Default Admin (Legacy)',
        'email' => 'admin@localhost',
        'password' => 'Admin@12345',
        'role' => 'Administrator'
    ]
];

$stmt_find = $pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?)");
$stmt_insert = $pdo->prepare("INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)");
$stmt_update = $pdo->prepare("UPDATE users SET password=?, role=?, active=1 WHERE id=?");

foreach ($default_users as $u) {
    $stmt_find->execute([$u['email']]);
    $existing_id = $stmt_find->fetchColumn();
    $hash = password_hash($u['password'], PASSWORD_DEFAULT);
    if (!$existing_id) {
        $stmt_insert->execute([
            $u['name'],
            strtolower($u['email']),
            $hash,
            $u['role']
        ]);
    } else {
        $stmt_update->execute([
            $hash,
            $u['role'],
            $existing_id
        ]);
    }
}

// Seed demo documents if table empty
$doc_count = $pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
if ($doc_count == 0) {
    $staff_id = $pdo->query("SELECT id FROM users WHERE email='staff@regency.com'")->fetchColumn() ?: 1;
    $sup_id = $pdo->query("SELECT id FROM users WHERE email='supervisor@regency.com'")->fetchColumn() ?: 1;
    $mgr_id = $pdo->query("SELECT id FROM users WHERE email='opsmanager@regency.com'")->fetchColumn() ?: 1;
    $comp_id = $pdo->query("SELECT id FROM users WHERE email='compliance@regency.com'")->fetchColumn() ?: 1;

    // Doc 1: Fully Approved & Collected by Registrar
    $sql1 = "INSERT INTO documents (
        reference_no, client_name, client_account, chn_number, account_officer, document_type, stock_symbol, units,
        description, priority, submission_date, due_date, status, approval_status,
        level1_approved, level1_user_id, level1_user_name, level1_date, level1_notes,
        level2_approved, level2_user_id, level2_user_name, level2_date, level2_notes,
        level3_approved, level3_user_id, level3_user_name, level3_date, level3_notes,
        registrar_name, collection_status, collector_name, collector_phone, collected_date, dispatch_waybill_no, collection_method, collection_notes,
        created_by
    ) VALUES (
        'RAML/REG/2026/001', 'ALHAJI SULAIMON IBRAHIM', 'CSCS-9018442', 'C189024LA', 'Fatokun Oluwaseun', 'Dematerialization', 'ZENITHBANK', 50000,
        'Dematerialization of 50,000 units of Zenith Bank Plc share certificates. Verified with CSCS portal.', 'Urgent',
        date('now', '-4 days'), date('now', '+3 days'), 'In Progress', 'Fully Approved',
        1, ?, 'Adeola Johnson (Supervisor)', datetime('now', '-3 days'), 'Verified client signatures and original certificate numbers. All KYC complete.',
        1, ?, 'Babatunde Alabi (Ops Mgr)', datetime('now', '-2 days'), 'Approved for registrar transmittal. Stock symbol and account verified.',
        1, ?, 'Chioma Okonkwo (Compliance)', datetime('now', '-2 days'), 'Compliance cleared. Approved for physical dispatch to registrar.',
        'Veritas Registrars Limited', 'Dispatched / Collected', 'Kazeem Oladipo (Registrar Liaison)', '+234 803 234 5678',
        datetime('now', '-1 days'), 'VR-DISP-89210', 'Registrar Liaison Officer Pick-up', 'Handed over in sealed registrar envelope with transmittal slip copy.',
        ?
    )";
    $pdo->prepare($sql1)->execute([$sup_id, $mgr_id, $comp_id, $staff_id]);
    $doc1_id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO approval_logs(document_id, level, level_name, action, user_id, user_name, user_role, comments) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$doc1_id, 1, 'Level 1: Supervisor Review', 'Approved', $sup_id, 'Adeola Johnson', 'Supervisor', 'Signatures and certificate verified.']);
    $pdo->prepare("INSERT INTO approval_logs(document_id, level, level_name, action, user_id, user_name, user_role, comments) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$doc1_id, 2, 'Level 2: Operations Approval', 'Approved', $mgr_id, 'Babatunde Alabi', 'Operations Manager', 'Operational approval granted.']);
    $pdo->prepare("INSERT INTO approval_logs(document_id, level, level_name, action, user_id, user_name, user_role, comments) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$doc1_id, 3, 'Level 3: Compliance Final Clearance', 'Approved', $comp_id, 'Chioma Okonkwo', 'Compliance Officer', 'Final compliance sign-off.']);

    // Doc 2: Pending Level 2 (Level 1 Approved)
    $sql2 = "INSERT INTO documents (
        reference_no, client_name, client_account, chn_number, account_officer, document_type, stock_symbol, units,
        description, priority, submission_date, due_date, status, approval_status,
        level1_approved, level1_user_id, level1_user_name, level1_date, level1_notes,
        registrar_name, collection_status, created_by
    ) VALUES (
        'RAML/REG/2026/002', 'MRS. FOLAKE ADEMOLA', 'CSCS-7718290', 'C992144LA', 'Adekunle Niyi', 'Intermember Transfer', 'MTNN', 12500,
        'Intermember transfer of 12,500 units of MTN Nigeria from CardinalStone Securities to Regency Assets.', 'High',
        date('now', '-2 days'), date('now', '+5 days'), 'In Review', 'Pending Level 2',
        1, ?, 'Adeola Johnson (Supervisor)', datetime('now', '-1 days'), 'Intermember transfer form vetted and matched with client instruction letter.',
        'Africa Prudential Plc', 'Pending Collection', ?
    )";
    $pdo->prepare($sql2)->execute([$sup_id, $staff_id]);
    $doc2_id = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO approval_logs(document_id, level, level_name, action, user_id, user_name, user_role, comments) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$doc2_id, 1, 'Level 1: Supervisor Review', 'Approved', $sup_id, 'Adeola Johnson', 'Supervisor', 'Level 1 verified. Forwarded to Ops Manager.']);

    // Doc 3: Just submitted, Pending Level 1
    $sql3 = "INSERT INTO documents (
        reference_no, client_name, client_account, chn_number, account_officer, document_type, stock_symbol, units,
        description, priority, submission_date, due_date, status, approval_status,
        registrar_name, collection_status, created_by
    ) VALUES (
        'RAML/REG/2026/003', 'DR. CHUKWUDI EZEKIEL', 'CSCS-5544120', 'C334511LA', 'Subuola Williams', 'Transmission', 'GTCO', 80000,
        'Transmission of 80,000 units of GTCO shares to Estate of Late Chief Ezekiel. Letters of Administration attached.', 'Normal',
        date('now'), date('now', '+7 days'), 'Received', 'Pending Level 1',
        'GTL Registrars & Data Solutions Limited', 'Pending Collection', ?
    )";
    $pdo->prepare($sql3)->execute([$staff_id]);
}

// Seed default account officers
$ao_count = $pdo->query("SELECT COUNT(*) FROM account_officers")->fetchColumn();
if ($ao_count == 0) {
    $default_aos = [
        ['Fatokun Oluwaseun', 'AO-001', 'seun.fatokun@regency.com', '+234 802 345 6789', 'Equities & Wealth Mgmt'],
        ['Babatunde Alabi', 'AO-002', 'b.alabi@regency.com', '+234 803 112 2334', 'Institutional Equities'],
        ['Adeola Johnson', 'AO-003', 'a.johnson@regency.com', '+234 805 998 8776', 'Private Clients & Retail'],
        ['Chioma Okonkwo', 'AO-004', 'c.okonkwo@regency.com', '+234 807 445 5667', 'Corporate & Advisory'],
        ['Ibrahim Musa', 'AO-005', 'i.musa@regency.com', '+234 809 332 2110', 'Northern Regional Desk']
    ];
    $stmt_ao = $pdo->prepare("INSERT INTO account_officers (name, officer_code, email, phone, department) VALUES (?,?,?,?,?)");
    foreach ($default_aos as $ao) {
        $stmt_ao->execute($ao);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation Complete - <?= h(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #0b1a30; color: #333; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card { border-radius: 14px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
        .header-box { background: linear-gradient(135deg, #0b2545 0%, #134074 100%); color: white; border-radius: 14px 14px 0 0; padding: 28px; }
        .role-badge { font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 6px; }
    </style>
</head>
<body class="py-5">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card overflow-hidden">
                <div class="header-box text-center">
                    <h2 class="mb-1 text-white"><?= h(COMPANY_NAME) ?></h2>
                    <p class="mb-0 text-white-50">Registrar Document Tracker with 3-Level Approvals & Collection Tracking</p>
                </div>
                <div class="card-body p-4 p-md-5 bg-white">
                    <div class="alert alert-success d-flex align-items-center mb-4">
                        <i class="bi bi-check-circle-fill fs-3 me-3 text-success"></i>
                        <div>
                            <h5 class="alert-heading mb-1">Database & Workflow Initialized Successfully!</h5>
                            <p class="mb-0 small">The multi-tier document control tables, approval pipeline, and Nigerian registrar tracking directory are ready.</p>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-3"><i class="bi bi-shield-lock me-2 text-primary"></i>Pre-Configured Role Logins for Testing</h5>
                    <p class="text-muted small">You can log in with any of the accounts below to test different approval permissions and document workflows:</p>

                    <div class="table-responsive mb-4">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Role / Tier</th>
                                    <th>Approval Responsibility</th>
                                    <th>Email</th>
                                    <th>Password</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="badge bg-secondary role-badge">Staff (Initiator)</span></td>
                                    <td>Creates new document submissions, attaches scanned files, resolves queries, and records registrar collection hand-overs.</td>
                                    <td><code>staff@regency.com</code></td>
                                    <td><code>Staff@12345</code></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-warning text-dark role-badge">Level 1: Supervisor</span></td>
                                    <td>Performs initial verification, signature vetting, CSCS/client verification, approves L1 or issues query.</td>
                                    <td><code>supervisor@regency.com</code></td>
                                    <td><code>Supervisor@12345</code></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-info text-dark role-badge">Level 2: Ops Manager</span></td>
                                    <td>Operations clearance, validates broker/registrar alignment, units & stock validation, approves L2.</td>
                                    <td><code>opsmanager@regency.com</code></td>
                                    <td><code>Manager@12345</code></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-primary role-badge">Level 3: Compliance</span></td>
                                    <td>Regulatory sign-off, SEC/NGX compliance check, grants final clearance for registrar dispatch.</td>
                                    <td><code>compliance@regency.com</code></td>
                                    <td><code>Compliance@12345</code></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-dark role-badge">Administrator</span></td>
                                    <td>Full administrative access, user creation, role assignment, system audit trails, and document override.</td>
                                    <td><code>admin@regency.com</code></td>
                                    <td><code>Admin@12345</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card bg-light border p-3 mb-4">
                        <h6 class="fw-bold mb-2"><i class="bi bi-truck me-2 text-primary"></i>Registrar Operations & Account Officer Features Included:</h6>
                        <ul class="mb-0 small text-muted">
                            <li><strong>Account Officer Assignment:</strong> Directly records and tracks the assigned Account Officer / Relationship Manager for every client and document.</li>
                            <li><strong>Comprehensive Nigerian Registrars:</strong> Full active directory of all capital market registrars in Nigeria with major listed equities and office locations.</li>
                            <li><strong>Liaison / Dispatch Officer Details:</strong> Captures name, phone number, collection date, dispatch waybill / acknowledgment slip number.</li>
                            <li><strong>Proof of Collection:</strong> Upload stamped acknowledgment slips or photo receipts directly to each record.</li>
                            <li><strong>Formal Routing Slip:</strong> Generates and prints official company Transmittal & Routing Slips ready for physical registrar hand-overs.</li>
                        </ul>
                    </div>

                    <div class="text-center pt-2">
                        <a href="index.php" class="btn btn-primary btn-lg px-5 shadow-sm">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Launch Application
                        </a>
                    </div>
                </div>
                <div class="card-footer bg-light text-center py-3 text-muted small">
                    For production security, delete or rename <code>install.php</code> after testing your logins.
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

