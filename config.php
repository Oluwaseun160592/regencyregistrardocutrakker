<?php
// Regency Assets Management Ltd - Registrar Document Tracker Configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_NAME', 'Registrar Document Tracker');
define('COMPANY_NAME', 'Regency Assets Management Ltd');
define('COMPANY_SUBTITLE', 'Trading License Holder of NGX LTD | Dealing Member of NASD');
define('DB_FILE', __DIR__ . '/data/registrar.sqlite');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_UPLOAD', 15 * 1024 * 1024); // 15MB

if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // Optional: Supabase / PostgreSQL connection via DATABASE_URL
        // Example: postgres://postgres.yourproject:password@aws-0-eu-central-1.pooler.supabase.com:6543/postgres
        $pg_url = getenv('DATABASE_URL') ?: (defined('DATABASE_URL') ? DATABASE_URL : null);
        if ($pg_url) {
            $parts = parse_url($pg_url);
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;sslmode=require",
                $parts['host'],
                $parts['port'] ?? 5432,
                ltrim($parts['path'], '/')
            );
            $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } else {
            // Default: Standalone zero-configuration SQLite
            $pdo = new PDO('sqlite:' . DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("PRAGMA foreign_keys = ON");
            auto_migrate_schema($pdo);
        }
    }
    return $pdo;
}

function auto_migrate_schema(PDO $pdo): void {
    // Ensure core tables exist (users, documents, status_history, audit_logs)
    $has_users = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!$has_users) {
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
            file_name TEXT,
            file_path TEXT,
            remarks TEXT,
            created_by INTEGER,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            old_status TEXT,
            new_status TEXT NOT NULL,
            comment TEXT,
            changed_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
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
        ");

        $default_users = [
            ['System Administrator', 'admin@regency.com', 'Admin@12345', 'Administrator'],
            ['Fatokun Oluwaseun (Operations Staff)', 'staff@regency.com', 'Staff@12345', 'Staff'],
            ['Adeola Johnson (Supervisor - L1 Approver)', 'supervisor@regency.com', 'Supervisor@12345', 'Supervisor'],
            ['Babatunde Alabi (Operations Manager - L2 Approver)', 'opsmanager@regency.com', 'Manager@12345', 'Operations Manager'],
            ['Chioma Okonkwo (Compliance Head - L3 Approver)', 'compliance@regency.com', 'Compliance@12345', 'Compliance Officer'],
            ['Default Admin (Legacy)', 'admin@localhost', 'Admin@12345', 'Administrator']
        ];
        $st_u = $pdo->prepare("INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)");
        foreach ($default_users as $du) {
            $st_u->execute([$du[0], $du[1], password_hash($du[2], PASSWORD_DEFAULT), $du[3]]);
        }
    }

    // Check if documents table exists
    $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='documents'")->fetchColumn();
    if ($check) {
        $cols = $pdo->query("PRAGMA table_info(documents)")->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_column($cols, 'name');

        $migrations = [
            'chn_number' => "ALTER TABLE documents ADD COLUMN chn_number TEXT",
            'account_officer' => "ALTER TABLE documents ADD COLUMN account_officer TEXT",
            'stock_symbol' => "ALTER TABLE documents ADD COLUMN stock_symbol TEXT",
            'units' => "ALTER TABLE documents ADD COLUMN units INTEGER DEFAULT 0",
            'approval_status' => "ALTER TABLE documents ADD COLUMN approval_status TEXT NOT NULL DEFAULT 'Pending Level 1'",
            
            // Level 1 Approval (Supervisor / Reviewer)
            'level1_approved' => "ALTER TABLE documents ADD COLUMN level1_approved INTEGER DEFAULT 0",
            'level1_user_id' => "ALTER TABLE documents ADD COLUMN level1_user_id INTEGER",
            'level1_user_name' => "ALTER TABLE documents ADD COLUMN level1_user_name TEXT",
            'level1_date' => "ALTER TABLE documents ADD COLUMN level1_date DATETIME",
            'level1_notes' => "ALTER TABLE documents ADD COLUMN level1_notes TEXT",
            
            // Level 2 Approval (Operations Manager)
            'level2_approved' => "ALTER TABLE documents ADD COLUMN level2_approved INTEGER DEFAULT 0",
            'level2_user_id' => "ALTER TABLE documents ADD COLUMN level2_user_id INTEGER",
            'level2_user_name' => "ALTER TABLE documents ADD COLUMN level2_user_name TEXT",
            'level2_date' => "ALTER TABLE documents ADD COLUMN level2_date DATETIME",
            'level2_notes' => "ALTER TABLE documents ADD COLUMN level2_notes TEXT",
            
            // Level 3 Approval (Compliance / Final Sign-off)
            'level3_approved' => "ALTER TABLE documents ADD COLUMN level3_approved INTEGER DEFAULT 0",
            'level3_user_id' => "ALTER TABLE documents ADD COLUMN level3_user_id INTEGER",
            'level3_user_name' => "ALTER TABLE documents ADD COLUMN level3_user_name TEXT",
            'level3_date' => "ALTER TABLE documents ADD COLUMN level3_date DATETIME",
            'level3_notes' => "ALTER TABLE documents ADD COLUMN level3_notes TEXT",
            
            // Registrar Collection Details
            'collection_status' => "ALTER TABLE documents ADD COLUMN collection_status TEXT NOT NULL DEFAULT 'Pending Collection'",
            'collector_name' => "ALTER TABLE documents ADD COLUMN collector_name TEXT",
            'collector_phone' => "ALTER TABLE documents ADD COLUMN collector_phone TEXT",
            'collected_date' => "ALTER TABLE documents ADD COLUMN collected_date TEXT",
            'dispatch_waybill_no' => "ALTER TABLE documents ADD COLUMN dispatch_waybill_no TEXT",
            'collection_method' => "ALTER TABLE documents ADD COLUMN collection_method TEXT",
            'collection_proof_name' => "ALTER TABLE documents ADD COLUMN collection_proof_name TEXT",
            'collection_proof_path' => "ALTER TABLE documents ADD COLUMN collection_proof_path TEXT",
            'collection_notes' => "ALTER TABLE documents ADD COLUMN collection_notes TEXT",
            'queried_at_level' => "ALTER TABLE documents ADD COLUMN queried_at_level INTEGER DEFAULT 0",
            'query_reason' => "ALTER TABLE documents ADD COLUMN query_reason TEXT"
        ];

        foreach ($migrations as $col => $sql) {
            if (!in_array($col, $existing, true)) {
                try {
                    $pdo->exec($sql);
                } catch (Exception $e) {
                    // Ignore column exists
                }
            }
        }
    }

    // Ensure approval_logs table exists
    $pdo->exec("
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
    ");

    // Ensure account_officers table exists
    $pdo->exec("
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

    // Seed default account officers if table is empty
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
}

function h($v): string { 
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); 
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid security token. Please go back and try again.');
    }
}

function flash($type, $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php?page=login');
        exit;
    }
}

function current_user(): array {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? 'Guest',
        'email' => $_SESSION['user_email'] ?? '',
        'role' => $_SESSION['user_role'] ?? 'Staff'
    ];
}

// User role permission helpers
function can_approve_level1($user): bool {
    return in_array($user['role'], ['Supervisor', 'Operations Manager', 'Compliance Officer', 'Administrator'], true);
}

function can_approve_level2($user): bool {
    return in_array($user['role'], ['Operations Manager', 'Compliance Officer', 'Administrator'], true);
}

function can_approve_level3($user): bool {
    return in_array($user['role'], ['Compliance Officer', 'Administrator'], true);
}

function can_manage_users($user): bool {
    return in_array($user['role'], ['Administrator'], true);
}

function can_manage_account_officers($user): bool {
    return in_array($user['role'], ['Administrator'], true);
}

function can_reassign_account_officer($user): bool {
    return in_array($user['role'], ['Administrator', 'Supervisor', 'Operations Manager'], true);
}

function get_active_account_officers(): array {
    try {
        return db()->query("SELECT * FROM account_officers WHERE active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_all_account_officers(): array {
    try {
        return db()->query("
            SELECT ao.*, 
                   (SELECT COUNT(*) FROM documents d WHERE d.account_officer = ao.name) AS doc_count 
            FROM account_officers ao 
            ORDER BY ao.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function get_account_officer_by_name($name) {
    if (!$name) return null;
    try {
        $stmt = db()->prepare("SELECT * FROM account_officers WHERE name=? LIMIT 1");
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function can_delete_document($user): bool {
    return in_array($user['role'], ['Administrator', 'Operations Manager'], true);
}

function audit($action, $document_id = null, $details = ''): void {
    $u = current_user();
    $stmt = db()->prepare("INSERT INTO audit_logs(user_id, document_id, action, details, ip_address)
                           VALUES(?,?,?,?,?)");
    $stmt->execute([$u['id'], $document_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
}

function log_approval_step($doc_id, $level, $level_name, $action, $comments = ''): void {
    $u = current_user();
    $stmt = db()->prepare("INSERT INTO approval_logs(document_id, level, level_name, action, user_id, user_name, user_role, comments)
                           VALUES(?,?,?,?,?,?,?,?)");
    $stmt->execute([$doc_id, $level, $level_name, $action, $u['id'], $u['name'], $u['role'], $comments]);
}

function status_badge($status): string {
    $map = [
        'Received' => 'badge-soft-info',
        'Pending' => 'badge-soft-warning',
        'Submitted' => 'badge-soft-primary',
        'In Review' => 'badge-soft-warning',
        'Approved' => 'badge-soft-success',
        'In Progress' => 'badge-soft-primary',
        'Queried' => 'badge-soft-danger',
        'Completed' => 'badge-soft-success',
        'Rejected' => 'badge-soft-dark'
    ];
    $c = $map[$status] ?? 'badge-soft-secondary';
    return '<span class="badge '.$c.'">'.h($status).'</span>';
}

function approval_badge($approval_status): string {
    $map = [
        'Pending Level 1' => '<span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Pending L1 Review</span>',
        'Pending Level 2' => '<span class="badge bg-info text-dark"><i class="bi bi-clock"></i> Pending L2 Ops</span>',
        'Pending Level 3' => '<span class="badge bg-primary text-white"><i class="bi bi-clock"></i> Pending L3 Compliance</span>',
        'Fully Approved'  => '<span class="badge bg-success text-white"><i class="bi bi-check-circle"></i> Fully Approved</span>',
        'Queried'         => '<span class="badge bg-danger text-white"><i class="bi bi-exclamation-triangle"></i> Queried</span>',
        'Rejected'        => '<span class="badge bg-dark text-white"><i class="bi bi-x-circle"></i> Rejected</span>'
    ];
    return $map[$approval_status] ?? '<span class="badge bg-secondary">'.h($approval_status).'</span>';
}

function collection_badge($status): string {
    $map = [
        'Pending Collection' => '<span class="badge bg-light text-secondary border"><i class="bi bi-hourglass"></i> Pending Collection</span>',
        'Dispatched / Collected' => '<span class="badge bg-primary"><i class="bi bi-truck"></i> Dispatched / Collected</span>',
        'Acknowledged by Registrar' => '<span class="badge bg-info text-dark"><i class="bi bi-envelope-check"></i> Registrar Acknowledged</span>',
        'Processing at Registrar' => '<span class="badge bg-warning text-dark"><i class="bi bi-gear-wide-connected"></i> Processing at Registrar</span>',
        'Completed by Registrar' => '<span class="badge bg-success"><i class="bi bi-check2-all"></i> Registrar Completed</span>'
    ];
    return $map[$status] ?? '<span class="badge bg-secondary">'.h($status).'</span>';
}

function get_nigerian_registrars(): array {
    return [
        'Africa Prudential Plc',
        'Apel Capital Registrars Limited',
        'CardinalStone Registrars Limited',
        'Centurion Registrars Limited',
        'Cordros Registrars Limited',
        'Coronation Registrars Limited',
        'Datamax Registrars Limited',
        'Dynamic Portfolio Registrars Limited',
        'EDC Registrars Limited',
        'First City Registrars Limited (FCMB)',
        'First Registrars & Investor Services Limited',
        'Flour Mills of Nigeria Registrars',
        'Greenwich Registrars & Data Solutions Limited',
        'GTL Registrars & Data Solutions Limited',
        'Lighthouse Registrars Limited',
        'Mainstreet Bank Registrars Limited',
        'Meristem Registrars & Probate Services Limited',
        'PAC Registrars Limited',
        'Sterling Registrars Limited',
        'United Securities Limited',
        'Unity Registrars Limited',
        'Veritas Registrars Limited'
    ];
}

function get_nigerian_registrars_directory(): array {
    return [
        [
            'name' => 'Africa Prudential Plc',
            'alias' => 'Africa Pru',
            'popular_equities' => 'UBA, Transcorp Plc, Transcorp Hotels, United Capital, Africa Prudential, etc.',
            'office_location' => 'Palmgrove / Ilupeju, Lagos'
        ],
        [
            'name' => 'Apel Capital Registrars Limited',
            'alias' => 'Apel Registrars',
            'popular_equities' => 'Various corporate & mutual fund registers',
            'office_location' => 'Victoria Island, Lagos'
        ],
        [
            'name' => 'CardinalStone Registrars Limited',
            'alias' => 'CardinalStone',
            'popular_equities' => 'Fidelity Bank, Lafarge Africa (WAPCO), BUA Foods, etc.',
            'office_location' => 'Lagos Island, Lagos'
        ],
        [
            'name' => 'Centurion Registrars Limited',
            'alias' => 'Centurion',
            'popular_equities' => 'Various industrial & investment registers',
            'office_location' => 'Marina, Lagos'
        ],
        [
            'name' => 'Cordros Registrars Limited',
            'alias' => 'Cordros',
            'popular_equities' => 'Cordros funds & corporate equities',
            'office_location' => 'Falomo, Ikoyi, Lagos'
        ],
        [
            'name' => 'Coronation Registrars Limited',
            'alias' => 'Coronation',
            'popular_equities' => 'Access Holdings (Access Bank), Coronation funds & bonds',
            'office_location' => 'Aig-Imoukhuede House, VI, Lagos'
        ],
        [
            'name' => 'Datamax Registrars Limited',
            'alias' => 'Datamax',
            'popular_equities' => 'Guaranty Trust Bank (historical), corporate registers',
            'office_location' => 'Anthony Village, Lagos'
        ],
        [
            'name' => 'Dynamic Portfolio Registrars Limited',
            'alias' => 'Dynamic',
            'popular_equities' => 'Capital market registers & SME bonds',
            'office_location' => 'Ikeja, Lagos'
        ],
        [
            'name' => 'EDC Registrars Limited',
            'alias' => 'Ecobank Registrars / EDC',
            'popular_equities' => 'Ecobank Transnational Incorporated (ETI), Ecobank Nigeria',
            'office_location' => 'Victoria Island, Lagos'
        ],
        [
            'name' => 'First City Registrars Limited (FCMB)',
            'alias' => 'FCMB Registrars',
            'popular_equities' => 'FCMB Group Plc, C&I Leasing, etc.',
            'office_location' => 'Marina / Primrose Tower, Lagos'
        ],
        [
            'name' => 'First Registrars & Investor Services Limited',
            'alias' => 'First Registrars',
            'popular_equities' => 'First Bank (FBN Holdings), Nestle Nigeria, Julius Berger, TotalEnergies, etc.',
            'office_location' => 'Iganmu House, Abebe Village Rd, Lagos'
        ],
        [
            'name' => 'Flour Mills of Nigeria Registrars',
            'alias' => 'FMN Registrars',
            'popular_equities' => 'Flour Mills of Nigeria Plc, Northern Nigeria Flour Mills',
            'office_location' => 'Apapa, Lagos'
        ],
        [
            'name' => 'Greenwich Registrars & Data Solutions Limited',
            'alias' => 'Greenwich Registrars',
            'popular_equities' => 'Nascon Allied Industries, various bond issues',
            'office_location' => 'Victoria Island, Lagos'
        ],
        [
            'name' => 'GTL Registrars & Data Solutions Limited',
            'alias' => 'GT Registrars',
            'popular_equities' => 'GTCO (Guaranty Trust Holding Co), Oando Plc, Nigerian Breweries, etc.',
            'office_location' => 'Plot 2C, Gbagada Expressway, Lagos'
        ],
        [
            'name' => 'Lighthouse Registrars Limited',
            'alias' => 'Lighthouse',
            'popular_equities' => 'Industrial, financial & trading equities',
            'office_location' => 'Onikan, Lagos'
        ],
        [
            'name' => 'Mainstreet Bank Registrars Limited',
            'alias' => 'Mainstreet',
            'popular_equities' => 'Various commercial equities & state bonds',
            'office_location' => 'Marina, Lagos'
        ],
        [
            'name' => 'Meristem Registrars & Probate Services Limited',
            'alias' => 'Meristem Registrars',
            'popular_equities' => 'Berger Paints, Conoil, Neimeth, Vitafoam, Thomas Wyatt, etc.',
            'office_location' => '213 Herbert Macaulay Way, Yaba, Lagos'
        ],
        [
            'name' => 'PAC Registrars Limited',
            'alias' => 'PAC Registrars',
            'popular_equities' => 'PanAfrican Capital, state government bonds, corporate securities',
            'office_location' => 'Victoria Island, Lagos'
        ],
        [
            'name' => 'Sterling Registrars Limited',
            'alias' => 'Sterling',
            'popular_equities' => 'Sterling Financial Holdings, commercial bonds',
            'office_location' => 'Ilupeju, Lagos'
        ],
        [
            'name' => 'United Securities Limited',
            'alias' => 'United Securities / USL',
            'popular_equities' => 'MTN Nigeria Communications Plc, Dangote Cement, Dangote Sugar, NASD OTC, etc.',
            'office_location' => '9 Amodu Ojikutu St, VI, Lagos'
        ],
        [
            'name' => 'Unity Registrars Limited',
            'alias' => 'Unity Registrars',
            'popular_equities' => 'Unity Bank Plc, northern industrial registers',
            'office_location' => 'Victoria Island, Lagos'
        ],
        [
            'name' => 'Veritas Registrars Limited',
            'alias' => 'Zenith Registrars / Veritas',
            'popular_equities' => 'Zenith Bank Plc, Veritas Kapital, Quantum Luxury, etc.',
            'office_location' => 'Plot 89A, Ajose Adeogun St, VI, Lagos'
        ]
    ];
}

