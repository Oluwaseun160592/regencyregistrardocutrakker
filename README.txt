================================================================================
REGENCY ASSETS MANAGEMENT LTD
REGISTRAR DOCUMENT TRACKER (MULTI-TIER APPROVALS & REGISTRAR DISPATCH)
Trading License Holder of NGX LTD | Dealing Member of NASD
================================================================================

OVERVIEW
--------
This is an upgraded, production-grade Registrar Document Control & Approval Portal 
developed specifically for stockbroking and registrar operations at Regency Assets Management Ltd.

It expands the original tracker with:
1. 3+ Distinct Approval Levels with individual logins & permissions:
   - Level 1: Supervisor (Initial vetting, client signature & CSCS check)
   - Level 2: Operations Manager (Operational clearance & broker-registrar validation)
   - Level 3: Compliance Officer (Regulatory & executive sign-off for release)
   - Staff / Initiator: Registers submissions, attaches files, resolves queries
   - Administrator: Full system oversight, audit trails, and user management

2. Dedicated Registrar Collection & Dispatch Tracking:
   - Identifies the specific Registrar that collected/received each document
   - Records the Registrar Liaison Officer, Rider, or Representative name & phone number
   - Tracks collection/dispatch date and time
   - Records official Dispatch Waybill / Stamped Acknowledgment Slip reference number
   - Allows upload of stamped proof of collection / acknowledgment scan
   - Real-time Registrar processing lifecycle: Dispatched -> Acknowledged -> Processing -> Completed

3. Printable Formal Transmittal & Routing Slip:
   - Generates an official, printable document routing slip featuring Regency Assets Management Ltd 
     branding, reference numbers, client CSCS/CHN particulars, security units, the 3 approval 
     signatures with digital timestamps, and the registrar receiving acknowledgment block.

4. Query & Resolution System:
   - Supervisors, Operations Managers, or Compliance Officers can issue queries with specific reasons.
   - Submissions clearly alert staff to make amendments and re-submit for approval.

5. Dynamic Account Officer / Relationship Manager Management:
   - Admin can CREATE new Account Officers with Name, Staff ID/Code, Email, Phone, and Department/Desk.
   - Admin can EDIT, ACTIVATE/DEACTIVATE, and DELETE Account Officers.
   - Safe Document Protection: If an Account Officer has documents assigned, the deletion modal allows 
     reassigning their existing documents to another officer with 1-click.
   - Document Submissions strictly populate from active Account Officers.
   - Document Assignment & Reassignment: Authorized users (Admin, Supervisor, Operations Manager) can 
     reassign the Account Officer directly from any submitted document details view with an audited reason note.
   - Filters and CSV reports track submissions by assigned Account Officer.

6. Comprehensive Nigerian Registrars Directory:
   - Full built-in directory of all 22 active SEC-registered capital market registrars in Nigeria.
   - Includes official company names, popular aliases (e.g. Zenith Registrars for Veritas, GT Registrars for GTL),
     major listed company registers handled (Zenith Bank, GTCO, MTNN, First Bank, Dangote, UBA, etc.),
     and office locations in Lagos/Nigeria.
   - One-click submission creation directly from the directory.

7. Live Deployment & Supabase Compatibility:
   - Zero-config SQLite (default): Instant upload to cPanel / Apache / InfinityFree.
   - Supabase PostgreSQL: Included `supabase_schema.sql` allows hosting the database on Supabase 
     and connecting simply by setting `DATABASE_URL` in config or environment.
   - Docker support: Included `Dockerfile` for 1-click deployment to Render, Railway, or Fly.io.


PRE-CONFIGURED USER ACCOUNTS
----------------------------
Role / Tier                   Email                     Password
--------------------------------------------------------------------------------
Administrator                 admin@regency.com         Admin@12345
Staff (Initiator)             staff@regency.com         Staff@12345
Level 1: Supervisor           supervisor@regency.com    Supervisor@12345
Level 2: Operations Manager   opsmanager@regency.com    Manager@12345
Level 3: Compliance Officer   compliance@regency.com    Compliance@12345
Legacy Admin Fallback         admin@localhost           Admin@12345


HOSTING & SERVER REQUIREMENTS
-----------------------------
- PHP 8.0, 8.1, 8.2, or 8.3
- PDO SQLite extension enabled (standard in all cPanel, shared hosts, Apache, and XAMPP)
- Write permissions on data/ and uploads/ folders
- Apache / Nginx web server


INSTALLATION INSTRUCTIONS
-------------------------
1. Upload all contents of this folder or the .zip archive to your web hosting directory 
   (e.g., public_html/registrar/ or htdocs/registrar/).
2. Open your web browser and navigate to:
   https://YOUR-DOMAIN/registrar/install.php
3. The installer will automatically create all database tables, configure approval logs, 
   and seed the pre-configured role test accounts and sample documents.
4. Click "Launch Application" to sign in.
5. For live production security, delete or rename install.php after testing.


FILE & DIRECTORY STRUCTURE
--------------------------
├── config.php                 Application configuration, roles, database helpers & permissions
├── index.php                  Main application controller, views, approval stepper & modals
├── install.php                Database migration, table initialization & demo account seeder
├── .htaccess                  Web server security rules (prevents direct data access)
├── README.txt                 Documentation & user guide
├── assets/
│   └── logo.png               Regency Assets Management Ltd logo
├── data/
│   ├── .htaccess              Blocks public access to SQLite database
│   └── registrar.sqlite       SQLite database (auto-generated)
└── uploads/
    ├── .htaccess              Prevents script execution in upload directory
    └── [uploaded files]       Client document scans & stamped registrar receipts


WORKFLOW WALKTHROUGH
--------------------
1. REGISTRATION (Staff):
   - Staff logs in (`staff@regency.com`).
   - Clicks "+ New Document", enters client name, CSCS account, CHN number, security symbol, 
     units, selects target registrar (e.g. Africa Prudential, First Registrars, Meristem), 
     sets priority, and uploads supporting PDF/image.
   - Document status is set to "Pending Level 1 Review".

2. LEVEL 1 APPROVAL (Supervisor):
   - Supervisor logs in (`supervisor@regency.com`).
   - Document appears in their "Pending L1 Review" queue.
   - Supervisor inspects documents and signatures, and clicks "Grant Approval" (or "Issue Query").
   - Document transitions to "Pending Level 2 Ops Clearance".

3. LEVEL 2 APPROVAL (Operations Manager):
   - Operations Manager logs in (`opsmanager@regency.com`).
   - Verifies broker accounts, unit balance, and registrar routing, and clicks "Grant Approval".
   - Document transitions to "Pending Level 3 Compliance Clearance".

4. LEVEL 3 FINAL APPROVAL (Compliance Officer):
   - Compliance Officer logs in (`compliance@regency.com`).
   - Conducts regulatory/SEC clearance and grants final sign-off.
   - Document becomes "Fully Approved" and ready for registrar dispatch.

5. REGISTRAR COLLECTION & DISPATCH:
   - When the physical registrar liaison officer or rider arrives, staff/operations clicks 
     "Record / Update Registrar Collection Details".
   - Enters collector's name (e.g. Kazeem Oladipo), phone number, date/time, waybill/slip number, 
     and uploads a photo/scan of the stamped acknowledgment slip.
   - Staff can also print the official "Document Transmittal & Routing Slip" for physical dispatch.

6. COMPLETION:
   - Once the registrar processes the transmission/demat/dividend and updates CSCS, 
     the registrar status is marked "Completed by Registrar".

