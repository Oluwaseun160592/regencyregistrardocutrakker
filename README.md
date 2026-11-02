# Regency Assets Management Ltd — Registrar Document Tracker (v2.0)

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/Oluwaseun160592/regencyregistrardocutrakker)

> **Trading License Holder of NGX LTD | Dealing Member of NASD**  
> Production-grade Registrar Document Control, Multi-Tier Approval & Dispatch Management Portal.

---

## 🚀 Live Online Deployment on Render

You can deploy and view this application live on the web in under 3 minutes using **Render.com**.

### Option A: 1-Click Blueprint Deploy (Recommended)

1. Click the button above or visit:  
   **[https://render.com/deploy?repo=https://github.com/Oluwaseun160592/regencyregistrardocutrakker](https://render.com/deploy?repo=https://github.com/Oluwaseun160592/regencyregistrardocutrakker)**
2. Sign in or sign up to [Render](https://render.com) (free).
3. Connect your GitHub account if prompted.
4. Render will detect `render.yaml` and configure the **Docker Web Service** automatically.
5. Click **Apply / Create Service**.
6. Render builds the Docker image and gives you a live public URL (e.g., `https://regency-registrar-tracker.onrender.com`).

---

### Option B: Manual Deploy on Render

1. Log in to [dashboard.render.com](https://dashboard.render.com).
2. Click **New +** > **Web Service**.
3. Select **Build and deploy from a Git repository** and connect:  
   `https://github.com/Oluwaseun160592/regencyregistrardocutrakker`
4. Set the following options:
   - **Name**: `regency-registrar-tracker` (or your preferred name)
   - **Language / Runtime**: `Docker`
   - **Region**: Any (e.g., *Oregon (US West)* or *Frankfurt (EU)*)
   - **Instance Type**: `Free`
5. Click **Create Web Service**.
6. When deployment finishes, open your live `.onrender.com` link!

---

## 🔐 Pre-Configured Test Accounts

The system automatically initializes core tables and seeds all 5 operational roles on first run:

| Role / Level | Email | Password | Primary Permissions |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin@regency.com` | `Admin@12345` | System oversight, user accounts, audit trails, Account Officer management |
| **Staff (Initiator)** | `staff@regency.com` | `Staff@12345` | Creates submissions, attaches client scans, responds to queries |
| **Level 1: Supervisor** | `supervisor@regency.com` | `Supervisor@12345` | Vets client signature & CSCS particulars; approves L1 or issues queries |
| **Level 2: Ops Manager** | `opsmanager@regency.com` | `Manager@12345` | Validates broker-registrar unit reconciliation; approves L2 |
| **Level 3: Compliance Officer** | `compliance@regency.com` | `Compliance@12345` | Regulatory sign-off, anti-fraud compliance, final release for dispatch |

*(Legacy fallback admin account: `admin@localhost` / `Admin@12345`)*

---

## ✨ Key System Features

1. **Strict 3-Tier Approval Workflow**:
   - Sequential progression: `Pending Level 1` $\rightarrow$ `Pending Level 2` $\rightarrow$ `Pending Level 3` $\rightarrow$ `Approved for Collection`.
   - Query & reject capabilities with auditable query reasons at every stage.
2. **Dedicated Registrar Dispatch & Collection Tracking**:
   - Logs the specific Registrar Liaison / Dispatch Officer name and phone number.
   - Records Dispatch Waybill / Stamped Acknowledgment Slip reference numbers.
   - Allows upload of stamped proof of collection / delivery receipts.
   - Lifecycle tracking: *Dispatched $\rightarrow$ Acknowledged $\rightarrow$ In Processing $\rightarrow$ Completed*.
3. **Formal Printable Transmittal & Routing Slips**:
   - Generates official company transmittal documents featuring Regency Assets Management Ltd branding, client details, CSCS/CHN particulars, digital timestamp approvals, and registrar receiving blocks.
4. **Dynamic Account Officer / Relationship Manager Registry**:
   - Manage Relationship Managers with Name, Staff ID/Code, Email, Phone, and Department.
   - Safe Document Protection: Reassign existing documents with 1-click if an officer changes.
5. **Multi-User Batch Creator & User Management**:
   - Both `admin@localhost` and `admin@regency.com` can create multiple users in a single batch.
   - Interactive multi-row dynamic grid with 1-click role pre-filling.
   - Direct CSV / Tab-delimited bulk paste import (`Name, Email, Password, Role`).
   - Account activation/deactivation, instant password reset, and user deletion.
6. **Comprehensive Directory of 22 Nigerian Registrars**:
   - Complete directory of SEC-registered capital market registrars (Africa Prudential, First Registrars, Meristem, Greenwich, EDC, CardinalStone, Veritas/Zenith, etc.) with listed equities and contact locations.
6. **Flexible Database Architecture**:
   - **Zero-config SQLite**: Built-in, instant setup with auto-migration.
   - **Supabase / PostgreSQL Ready**: Add `DATABASE_URL` environment variable to connect to Supabase or any managed PostgreSQL instance.

---

## 🐳 Running Locally with Docker

You can also run the application locally on your computer with Docker:

```bash
# Clone the repository
git clone https://github.com/Oluwaseun160592/regencyregistrardocutrakker.git
cd regencyregistrardocutrakker

# Build the Docker image
docker build -t regency-tracker .

# Run container on port 8080
docker run -d -p 8080:80 --name regency-portal regency-tracker
```

Open your browser and navigate to: **`http://localhost:8080`**

---

## 🌐 Supabase PostgreSQL Configuration (Optional)

To use a persistent Supabase database instead of SQLite:
1. Create a project on [Supabase](https://supabase.com).
2. Go to the SQL Editor and run the SQL script provided in `supabase_schema.sql`.
3. In your Render Dashboard (or local `.env`), add an Environment Variable:
   - **Key**: `DATABASE_URL`
   - **Value**: `postgres://postgres.[PROJECT-REF]:[PASSWORD]@aws-0-[REGION].pooler.supabase.com:6543/postgres`

