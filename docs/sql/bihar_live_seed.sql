-- =============================================================================
-- Bihar project + roles + 9 users (live DB seed)
-- Idempotent-ish: safe to re-run (skips existing emails/roles/project code).
--
-- BEFORE RUN:
--   1. Backup the database.
--   2. Confirm company code TIWANA exists (adjust SELECT below if needed).
--   3. Temporary password for ALL new users: ChangeMe@Bihar2026!
--      Force password reset after first login.
--   4. Placeholder emails (*@users.tiwana.test) stay unverified and must not
--      receive real mail.
--
-- AFTER RUN (recommended on app server):
--   php artisan bihar:bootstrap-workflow
--   (syncs approval workflows + playbooks if this SQL section is skipped)
-- =============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 0) Resolve company
-- ---------------------------------------------------------------------------
SET @company_id := (
  SELECT id FROM companies
  WHERE deleted_at IS NULL
    AND (code = 'TIWANA' OR name LIKE '%Tiwana%')
  ORDER BY CASE WHEN code = 'TIWANA' THEN 0 ELSE 1 END
  LIMIT 1
);

SELECT IF(@company_id IS NULL, 'ERROR: TIWANA company not found — abort', CONCAT('OK company_id=', @company_id)) AS company_check;
-- If company_check says ERROR, ROLLBACK; and stop.

SET @now := NOW();
SET @guard := 'web';
SET @model_type := 'user';
-- bcrypt for: ChangeMe@Bihar2026!
SET @temp_password := '$2y$10$8Ncy8R1qs.AVpMTMfq/11.ZqO.Y24Y05DVyWRRn0ahzp4iXZPfRd6';

-- ---------------------------------------------------------------------------
-- 1) Roles (create missing only). Existing Site Engineer / PM / etc. reused.
-- ---------------------------------------------------------------------------
INSERT INTO roles (team_id, name, guard_name, description, is_system, created_at, updated_at)
SELECT @company_id, v.name, @guard, v.description, 1, @now, @now
FROM (
  SELECT 'BOQ Prep' AS name, 'BOQ preparation, rate build-up and drawing uploads.' AS description
  UNION ALL SELECT 'Civil Lead', 'Head-office civil reviewer (BOQ, subcontract bills, quality and drawings).'
  UNION ALL SELECT 'Management', 'Executive oversight with final approval authority (mirror of Director).'
  UNION ALL SELECT 'Store Incharge', 'Site store receipts, issues and gate verification (mirror of Store Manager).'
  UNION ALL SELECT 'Site Engineer', 'Daily site work on assigned projects.'
  UNION ALL SELECT 'Project Manager', 'Runs assigned projects: planning, site, approvals.'
  UNION ALL SELECT 'Purchase Manager', 'Procurement from material request to purchase order.'
  UNION ALL SELECT 'Accountant', 'Expenses, bills, payments and finance reports.'
) v
WHERE NOT EXISTS (
  SELECT 1 FROM roles r
  WHERE r.team_id = @company_id AND r.name = v.name AND r.guard_name = @guard
);

-- Copy permissions from sibling system roles (only when target role has none yet)
-- Management ← Director
INSERT INTO role_has_permissions (permission_id, role_id)
SELECT rhp.permission_id, dest.id
FROM roles src
JOIN role_has_permissions rhp ON rhp.role_id = src.id
JOIN roles dest ON dest.team_id = src.team_id AND dest.name = 'Management' AND dest.guard_name = @guard
WHERE src.team_id = @company_id AND src.name = 'Director' AND src.guard_name = @guard
  AND NOT EXISTS (SELECT 1 FROM role_has_permissions x WHERE x.role_id = dest.id AND x.permission_id = rhp.permission_id);

-- Store Incharge ← Store Manager
INSERT INTO role_has_permissions (permission_id, role_id)
SELECT rhp.permission_id, dest.id
FROM roles src
JOIN role_has_permissions rhp ON rhp.role_id = src.id
JOIN roles dest ON dest.team_id = src.team_id AND dest.name = 'Store Incharge' AND dest.guard_name = @guard
WHERE src.team_id = @company_id AND src.name = 'Store Manager' AND src.guard_name = @guard
  AND NOT EXISTS (SELECT 1 FROM role_has_permissions x WHERE x.role_id = dest.id AND x.permission_id = rhp.permission_id);

-- BOQ Prep ← Billing Engineer (+ drawings already usually on billing; OK if subset)
INSERT INTO role_has_permissions (permission_id, role_id)
SELECT rhp.permission_id, dest.id
FROM roles src
JOIN role_has_permissions rhp ON rhp.role_id = src.id
JOIN roles dest ON dest.team_id = src.team_id AND dest.name = 'BOQ Prep' AND dest.guard_name = @guard
WHERE src.team_id = @company_id AND src.name = 'Billing Engineer' AND src.guard_name = @guard
  AND NOT EXISTS (SELECT 1 FROM role_has_permissions x WHERE x.role_id = dest.id AND x.permission_id = rhp.permission_id);

-- Civil Lead ← copy from Site Engineer as a base, then add view/approve-related if present on Director subset via name match below
INSERT INTO role_has_permissions (permission_id, role_id)
SELECT rhp.permission_id, dest.id
FROM roles src
JOIN role_has_permissions rhp ON rhp.role_id = src.id
JOIN permissions p ON p.id = rhp.permission_id
JOIN roles dest ON dest.team_id = src.team_id AND dest.name = 'Civil Lead' AND dest.guard_name = @guard
WHERE src.team_id = @company_id AND src.name = 'Director' AND src.guard_name = @guard
  AND p.name IN (
    'dashboard.view','approvals.view','projects.view',
    'boq.view','boq.view_material_requirements','boq.view_bbs','boq.view_reconciliation',
    'subcontract.view','subcontract.certify_bill','vendor_bills.view','quality.view','drawings.view','reports.view'
  )
  AND NOT EXISTS (SELECT 1 FROM role_has_permissions x WHERE x.role_id = dest.id AND x.permission_id = rhp.permission_id);

-- Also grant masters.*.view style if Director has them via wildcard expansion already stored as concrete names:
INSERT INTO role_has_permissions (permission_id, role_id)
SELECT rhp.permission_id, dest.id
FROM roles src
JOIN role_has_permissions rhp ON rhp.role_id = src.id
JOIN permissions p ON p.id = rhp.permission_id
JOIN roles dest ON dest.team_id = src.team_id AND dest.name = 'Civil Lead' AND dest.guard_name = @guard
WHERE src.team_id = @company_id AND src.name = 'Site Engineer' AND src.guard_name = @guard
  AND (p.name LIKE 'masters.%.view' OR p.name = 'crm.clients.view')
  AND NOT EXISTS (SELECT 1 FROM role_has_permissions x WHERE x.role_id = dest.id AND x.permission_id = rhp.permission_id);

SET @role_boq_prep := (SELECT id FROM roles WHERE team_id=@company_id AND name='BOQ Prep' AND guard_name=@guard LIMIT 1);
SET @role_civil_lead := (SELECT id FROM roles WHERE team_id=@company_id AND name='Civil Lead' AND guard_name=@guard LIMIT 1);
SET @role_site_eng := (SELECT id FROM roles WHERE team_id=@company_id AND name='Site Engineer' AND guard_name=@guard LIMIT 1);
SET @role_pm := (SELECT id FROM roles WHERE team_id=@company_id AND name='Project Manager' AND guard_name=@guard LIMIT 1);
SET @role_mgmt := (SELECT id FROM roles WHERE team_id=@company_id AND name='Management' AND guard_name=@guard LIMIT 1);
SET @role_acct := (SELECT id FROM roles WHERE team_id=@company_id AND name='Accountant' AND guard_name=@guard LIMIT 1);
SET @role_purchase := (SELECT id FROM roles WHERE team_id=@company_id AND name='Purchase Manager' AND guard_name=@guard LIMIT 1);
SET @role_store_in := (SELECT id FROM roles WHERE team_id=@company_id AND name='Store Incharge' AND guard_name=@guard LIMIT 1);

-- ---------------------------------------------------------------------------
-- 2) Users (insert if email missing; never resets existing passwords)
-- ---------------------------------------------------------------------------
INSERT INTO users (name, email, mobile, email_verified_at, password, is_super_admin, is_active, current_company_id, created_at, updated_at)
SELECT v.name, v.email, v.mobile, v.verified_at, @temp_password, 0, 1, @company_id, @now, @now
FROM (
  SELECT 'Bhaveeth Singh' AS name, 'civil.drawings@tiwana.in' AS email, '8968033360' AS mobile, @now AS verified_at
  UNION ALL SELECT 'Maninder Singh', 'civil@tiwana.in', '9872121360', @now
  UNION ALL SELECT 'Kartik', 'kartik@users.tiwana.test', NULL, NULL
  UNION ALL SELECT 'Aman', 'aman@users.tiwana.test', NULL, NULL
  UNION ALL SELECT 'Sumit', 'unithead.bihar@tiwana.in', '9915638360', @now
  UNION ALL SELECT 'Satnam Singh', 'satnam.singh26360@gmail.com', '9878626360', @now
  UNION ALL SELECT 'Khushpreet Singh', 'tiwanafeed9@gmail.com', '9041045360', @now
  UNION ALL SELECT 'Jaipreet', 'store@tiwana.in', '9872598360', @now
  UNION ALL SELECT 'Savroop', 'savroop@users.tiwana.test', NULL, NULL
) v
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.email = v.email);

-- Soft-update profile fields for existing matched emails (does NOT change password)
UPDATE users u
JOIN (
  SELECT 'civil.drawings@tiwana.in' AS email, 'Bhaveeth Singh' AS name, '8968033360' AS mobile
  UNION ALL SELECT 'civil@tiwana.in', 'Maninder Singh', '9872121360'
  UNION ALL SELECT 'kartik@users.tiwana.test', 'Kartik', NULL
  UNION ALL SELECT 'aman@users.tiwana.test', 'Aman', NULL
  UNION ALL SELECT 'unithead.bihar@tiwana.in', 'Sumit', '9915638360'
  UNION ALL SELECT 'satnam.singh26360@gmail.com', 'Satnam Singh', '9878626360'
  UNION ALL SELECT 'tiwanafeed9@gmail.com', 'Khushpreet Singh', '9041045360'
  UNION ALL SELECT 'store@tiwana.in', 'Jaipreet', '9872598360'
  UNION ALL SELECT 'savroop@users.tiwana.test', 'Savroop', NULL
) v ON v.email = u.email
SET u.name = v.name,
    u.mobile = v.mobile,
    u.is_active = 1,
    u.current_company_id = COALESCE(u.current_company_id, @company_id),
    u.updated_at = @now;

SET @u_bhaveeth := (SELECT id FROM users WHERE email='civil.drawings@tiwana.in' LIMIT 1);
SET @u_maninder := (SELECT id FROM users WHERE email='civil@tiwana.in' LIMIT 1);
SET @u_kartik := (SELECT id FROM users WHERE email='kartik@users.tiwana.test' LIMIT 1);
SET @u_aman := (SELECT id FROM users WHERE email='aman@users.tiwana.test' LIMIT 1);
SET @u_sumit := (SELECT id FROM users WHERE email='unithead.bihar@tiwana.in' LIMIT 1);
SET @u_satnam := (SELECT id FROM users WHERE email='satnam.singh26360@gmail.com' LIMIT 1);
SET @u_khush := (SELECT id FROM users WHERE email='tiwanafeed9@gmail.com' LIMIT 1);
SET @u_jaipreet := (SELECT id FROM users WHERE email='store@tiwana.in' LIMIT 1);
SET @u_savroop := (SELECT id FROM users WHERE email='savroop@users.tiwana.test' LIMIT 1);

-- ---------------------------------------------------------------------------
-- 3) Company membership + Spatie role assignment
-- ---------------------------------------------------------------------------
INSERT INTO company_user (company_id, user_id, user_type, is_active, created_at, updated_at)
SELECT @company_id, v.user_id, v.user_type, 1, @now, @now
FROM (
  SELECT @u_bhaveeth AS user_id, 'head_office' AS user_type
  UNION ALL SELECT @u_maninder, 'head_office'
  UNION ALL SELECT @u_kartik, 'site'
  UNION ALL SELECT @u_aman, 'site'
  UNION ALL SELECT @u_sumit, 'site'
  UNION ALL SELECT @u_satnam, 'head_office'
  UNION ALL SELECT @u_khush, 'head_office'
  UNION ALL SELECT @u_jaipreet, 'head_office'
  UNION ALL SELECT @u_savroop, 'site'
) v
WHERE v.user_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM company_user cu
    WHERE cu.company_id = @company_id AND cu.user_id = v.user_id
  );

UPDATE company_user cu
JOIN (
  SELECT @u_bhaveeth AS user_id, 'head_office' AS user_type
  UNION ALL SELECT @u_maninder, 'head_office'
  UNION ALL SELECT @u_kartik, 'site'
  UNION ALL SELECT @u_aman, 'site'
  UNION ALL SELECT @u_sumit, 'site'
  UNION ALL SELECT @u_satnam, 'head_office'
  UNION ALL SELECT @u_khush, 'head_office'
  UNION ALL SELECT @u_jaipreet, 'head_office'
  UNION ALL SELECT @u_savroop, 'site'
) v ON v.user_id = cu.user_id
SET cu.user_type = v.user_type, cu.is_active = 1, cu.updated_at = @now
WHERE cu.company_id = @company_id;

-- Assign company roles (replace prior Bihar role for these users on this team)
DELETE mhr FROM model_has_roles mhr
WHERE mhr.team_id = @company_id
  AND mhr.model_type = @model_type
  AND mhr.model_id IN (@u_bhaveeth,@u_maninder,@u_kartik,@u_aman,@u_sumit,@u_satnam,@u_khush,@u_jaipreet,@u_savroop);

INSERT INTO model_has_roles (role_id, model_type, model_id, team_id) VALUES
(@role_boq_prep, @model_type, @u_bhaveeth, @company_id),
(@role_civil_lead, @model_type, @u_maninder, @company_id),
(@role_site_eng, @model_type, @u_kartik, @company_id),
(@role_site_eng, @model_type, @u_aman, @company_id),
(@role_pm, @model_type, @u_sumit, @company_id),
(@role_mgmt, @model_type, @u_satnam, @company_id),
(@role_acct, @model_type, @u_khush, @company_id),
(@role_purchase, @model_type, @u_jaipreet, @company_id),
(@role_store_in, @model_type, @u_savroop, @company_id);

-- ---------------------------------------------------------------------------
-- 4) Project BHR_60TPH + site + team
-- ---------------------------------------------------------------------------
INSERT INTO projects (
  company_id, client_id, project_number, code, name, description, project_type,
  address, city, state_code, project_manager_id, contract_value, status,
  created_at, updated_at
)
SELECT
  @company_id, NULL, 'PRJ-BHR-60TPH', 'BHR_60TPH', 'Bihar', NULL, 'industrial',
  'Bihar', 'Bihar', '10', @u_sumit, 0.00, 'planning',
  @now, @now
WHERE NOT EXISTS (
  SELECT 1 FROM projects p
  WHERE p.company_id = @company_id AND p.code = 'BHR_60TPH' AND p.deleted_at IS NULL
);

SET @project_id := (
  SELECT id FROM projects
  WHERE company_id = @company_id AND code = 'BHR_60TPH' AND deleted_at IS NULL
  LIMIT 1
);

UPDATE projects
SET project_manager_id = @u_sumit, name = 'Bihar', city = 'Bihar', address = 'Bihar', state_code = '10', updated_at = @now
WHERE id = @project_id;

INSERT INTO sites (company_id, project_id, name, address, is_active, created_at, updated_at)
SELECT @company_id, @project_id, 'Bihar', 'Bihar', 1, @now, @now
WHERE @project_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM sites s
    WHERE s.project_id = @project_id AND s.name = 'Bihar' AND s.deleted_at IS NULL
  );

-- Project memberships
INSERT INTO project_users (project_id, user_id, project_role, is_active, created_at, updated_at)
SELECT @project_id, v.user_id, v.project_role, 1, @now, @now
FROM (
  SELECT @u_bhaveeth AS user_id, 'billing' AS project_role
  UNION ALL SELECT @u_maninder, 'viewer'
  UNION ALL SELECT @u_kartik, 'engineer'
  UNION ALL SELECT @u_aman, 'engineer'
  UNION ALL SELECT @u_sumit, 'manager'
  UNION ALL SELECT @u_satnam, 'viewer'
  UNION ALL SELECT @u_khush, 'accounts'
  UNION ALL SELECT @u_jaipreet, 'purchase'
  UNION ALL SELECT @u_savroop, 'store'
) v
WHERE @project_id IS NOT NULL AND v.user_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM project_users pu
    WHERE pu.project_id = @project_id AND pu.user_id = v.user_id
  );

UPDATE project_users pu
JOIN (
  SELECT @u_bhaveeth AS user_id, 'billing' AS project_role
  UNION ALL SELECT @u_maninder, 'viewer'
  UNION ALL SELECT @u_kartik, 'engineer'
  UNION ALL SELECT @u_aman, 'engineer'
  UNION ALL SELECT @u_sumit, 'manager'
  UNION ALL SELECT @u_satnam, 'viewer'
  UNION ALL SELECT @u_khush, 'accounts'
  UNION ALL SELECT @u_jaipreet, 'purchase'
  UNION ALL SELECT @u_savroop, 'store'
) v ON v.user_id = pu.user_id
SET pu.project_role = v.project_role, pu.is_active = 1, pu.updated_at = @now
WHERE pu.project_id = @project_id;

-- Workflow category settings
INSERT INTO project_settings (project_id, `key`, `value`, created_at, updated_at)
SELECT @project_id, 'workflow_categories',
  JSON_ARRAY('Civil — Execution','Civil — Supply','Equipment — Supply','Gate Pass'),
  @now, @now
WHERE @project_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM project_settings ps WHERE ps.project_id=@project_id AND ps.`key`='workflow_categories');

UPDATE project_settings
SET `value` = JSON_ARRAY('Civil — Execution','Civil — Supply','Equipment — Supply','Gate Pass'), updated_at = @now
WHERE project_id = @project_id AND `key` = 'workflow_categories';

INSERT INTO project_settings (project_id, `key`, `value`, created_at, updated_at)
SELECT @project_id, 'shared_contacts',
  JSON_ARRAY(JSON_OBJECT('label','Kartik/Aman unverified','mobile','7009034629')),
  @now, @now
WHERE @project_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM project_settings ps WHERE ps.project_id=@project_id AND ps.`key`='shared_contacts');

UPDATE project_settings
SET `value` = JSON_ARRAY(JSON_OBJECT('label','Kartik/Aman unverified','mobile','7009034629')), updated_at = @now
WHERE project_id = @project_id AND `key` = 'shared_contacts';

-- ---------------------------------------------------------------------------
-- 5) Project-specific approval workflows (Bihar)
-- ---------------------------------------------------------------------------
-- Helper procedure-free upserts per document type

-- boq: Maninder → Satnam
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'boq', @project_id, 'Bihar BOQ approval', 1, @now, @now
WHERE @project_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='boq' AND w.project_id=@project_id);

SET @wf_boq := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='boq' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf_boq;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, user_id, mode, created_at, updated_at) VALUES
(@wf_boq, 1, 'Civil Lead review', 'user', @u_maninder, 'any', @now, @now),
(@wf_boq, 2, 'Management approval', 'user', @u_satnam, 'any', @now, @now);

-- work_order: Sumit → Satnam
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'work_order', @project_id, 'Bihar work order approval', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='work_order' AND w.project_id=@project_id);
SET @wf := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='work_order' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, user_id, mode, created_at, updated_at) VALUES
(@wf, 1, 'Project Manager', 'user', @u_sumit, 'any', @now, @now),
(@wf, 2, 'Management approval', 'user', @u_satnam, 'any', @now, @now);

-- subcontractor_bill: Maninder → Sumit → Satnam
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'subcontractor_bill', @project_id, 'Bihar subcontractor bill approval', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='subcontractor_bill' AND w.project_id=@project_id);
SET @wf := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='subcontractor_bill' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, user_id, mode, created_at, updated_at) VALUES
(@wf, 1, 'Civil Lead', 'user', @u_maninder, 'any', @now, @now),
(@wf, 2, 'Project Manager', 'user', @u_sumit, 'any', @now, @now),
(@wf, 3, 'Management approval', 'user', @u_satnam, 'any', @now, @now);

-- material_request: Sumit
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'material_request', @project_id, 'Bihar material request approval', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='material_request' AND w.project_id=@project_id);
SET @wf := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='material_request' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, user_id, mode, created_at, updated_at) VALUES
(@wf, 1, 'Project Manager', 'user', @u_sumit, 'any', @now, @now);

-- purchase_order: Sumit (Jaipreet issues; PM approves)
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'purchase_order', @project_id, 'Bihar purchase order approval', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='purchase_order' AND w.project_id=@project_id);
SET @wf := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='purchase_order' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, user_id, mode, created_at, updated_at) VALUES
(@wf, 1, 'PM review (Jaipreet issues PO)', 'user', @u_sumit, 'any', @now, @now);

-- vendor_bill: Sumit → Satnam
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'vendor_bill', @project_id, 'Bihar vendor bill approval', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='vendor_bill' AND w.project_id=@project_id);
SET @wf := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='vendor_bill' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, user_id, mode, created_at, updated_at) VALUES
(@wf, 1, 'Project Manager', 'user', @u_sumit, 'any', @now, @now),
(@wf, 2, 'Management approval', 'user', @u_satnam, 'any', @now, @now);

-- grn: store project role
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'grn', @project_id, 'Bihar GRN approval', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='grn' AND w.project_id=@project_id);
SET @wf := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='grn' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, project_role, mode, created_at, updated_at) VALUES
(@wf, 1, 'Store (Savroop)', 'project_role', 'store', 'any', @now, @now);

-- gate_pass: Savroop → Sumit
INSERT INTO approval_workflows (company_id, document_type, project_id, name, is_active, created_at, updated_at)
SELECT @company_id, 'gate_pass', @project_id, 'Bihar gate pass approval', 1, @now, @now
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.company_id=@company_id AND w.document_type='gate_pass' AND w.project_id=@project_id);
SET @wf := (SELECT id FROM approval_workflows WHERE company_id=@company_id AND document_type='gate_pass' AND project_id=@project_id LIMIT 1);
DELETE FROM approval_steps WHERE approval_workflow_id = @wf;
INSERT INTO approval_steps (approval_workflow_id, level, name, approver_type, user_id, mode, created_at, updated_at) VALUES
(@wf, 1, 'Store review', 'user', @u_savroop, 'any', @now, @now),
(@wf, 2, 'Project Manager approval', 'user', @u_sumit, 'any', @now, @now);

-- ---------------------------------------------------------------------------
-- 6) Verification
-- ---------------------------------------------------------------------------
SELECT 'roles' AS kind, name, id FROM roles WHERE team_id=@company_id AND name IN (
  'BOQ Prep','Civil Lead','Management','Store Incharge','Site Engineer','Project Manager','Purchase Manager','Accountant'
) ORDER BY name;

SELECT u.id, u.name, u.email, u.mobile, u.email_verified_at IS NOT NULL AS verified,
       r.name AS role_name, cu.user_type
FROM users u
JOIN company_user cu ON cu.user_id=u.id AND cu.company_id=@company_id
LEFT JOIN model_has_roles mhr ON mhr.model_id=u.id AND mhr.model_type=@model_type AND mhr.team_id=@company_id
LEFT JOIN roles r ON r.id=mhr.role_id
WHERE u.email IN (
  'civil.drawings@tiwana.in','civil@tiwana.in','kartik@users.tiwana.test','aman@users.tiwana.test',
  'unithead.bihar@tiwana.in','satnam.singh26360@gmail.com','tiwanafeed9@gmail.com','store@tiwana.in','savroop@users.tiwana.test'
)
ORDER BY u.id;

SELECT id, code, name, project_manager_id, status FROM projects WHERE id=@project_id;
SELECT user_id, project_role, is_active FROM project_users WHERE project_id=@project_id ORDER BY id;
SELECT document_type, name, id FROM approval_workflows WHERE project_id=@project_id ORDER BY document_type;

COMMIT;

-- Temporary login password for newly inserted users only:
--   ChangeMe@Bihar2026!
-- Existing users keep their current password.
