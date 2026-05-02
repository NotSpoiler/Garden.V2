-- ============================================================
-- Community Garden & Allotment Manager — Database Schema
-- Plain MySQL, no framework
-- ============================================================

CREATE DATABASE IF NOT EXISTS community_garden CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE community_garden;

-- ============================================================
-- 1. MEMBERSHIP TIERS
-- Defines Bronze/Silver/Gold/Platinum tiers
-- ============================================================
CREATE TABLE membership_tiers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(20) NOT NULL,        -- Bronze, Silver, Gold, Platinum
    min_points  INT NOT NULL DEFAULT 0,      -- minimum community points needed
    min_months  INT NOT NULL DEFAULT 0,      -- minimum rental months needed
    discount_pct DECIMAL(5,2) DEFAULT 0.00,  -- rental fee discount %
    priority_boost INT DEFAULT 0            -- added to waitlist priority score
);

INSERT INTO membership_tiers (name, min_points, min_months, discount_pct, priority_boost) VALUES
('Bronze',   0,   0,  0.00, 0),
('Silver',  50,   3,  5.00, 5),
('Gold',   150,   9, 10.00, 10),
('Platinum',300,  18, 15.00, 20);

-- ============================================================
-- 2. USERS
-- Core user table. Roles: admin, warden, plot_owner, member, guest
-- ============================================================
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(120) NOT NULL,
    email           VARCHAR(120) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','warden','plot_owner','member','guest') DEFAULT 'member',
    membership_tier_id INT DEFAULT 1,        -- FK to membership_tiers
    community_points INT DEFAULT 0,
    karma_points    INT DEFAULT 0,
    seed_credits    INT DEFAULT 0,
    rental_months   INT DEFAULT 0,           -- accumulated months as plot owner
    gate_code       VARCHAR(10) DEFAULT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (membership_tier_id) REFERENCES membership_tiers(id)
);

-- Default admin account (password: Admin@1234)
INSERT INTO users (full_name, email, password_hash, role, membership_tier_id)
VALUES ('System Admin', 'admin@garden.local',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'admin', 4);

-- ============================================================
-- 3. PLOTS
-- Each plot has boundaries, soil tier, compliance status
-- ============================================================
CREATE TABLE plots (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    plot_code       VARCHAR(20) NOT NULL UNIQUE,  -- e.g. "A-01", "B-12"
    area_sqm        DECIMAL(8,2) NOT NULL,
    dimensions      VARCHAR(60),                  -- e.g. "5m x 4m"
    sunlight        ENUM('full_sun','partial_shade','full_shade') DEFAULT 'full_sun',
    soil_tier       ENUM('premium_raised','standard_raised','ground') DEFAULT 'ground',
    status          ENUM('available','occupied','maintenance') DEFAULT 'available',
    compliance_status ENUM('compliant','warning','non_compliant') DEFAULT 'compliant',
    grid_x          INT DEFAULT 0,               -- position on visual map grid
    grid_y          INT DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 4. LEASES
-- Time-bound plot rental agreements
-- ============================================================
CREATE TABLE leases (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    plot_id         INT NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    rental_fee      DECIMAL(10,2) NOT NULL,
    status          ENUM('active','expired','terminated') DEFAULT 'active',
    payment_status  ENUM('paid','pending','overdue') DEFAULT 'pending',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (plot_id) REFERENCES plots(id)
);

-- ============================================================
-- 5. SOIL HEALTH RECORDS
-- State-machine per plot: each entry is a timestamped event
-- ============================================================
CREATE TABLE soil_health_records (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    plot_id         INT NOT NULL,
    user_id         INT NOT NULL,
    event_type      ENUM('fertilizer','ph_reading','crop_rotation','other') NOT NULL,
    fertilizer_type VARCHAR(80),
    fertilizer_qty  DECIMAL(6,2),
    ph_level        DECIMAL(4,2),
    crop_name       VARCHAR(80),
    notes           TEXT,
    recorded_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plot_id) REFERENCES plots(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 6. WAITLIST
-- Priority-ordered queue for plot assignment
-- ============================================================
CREATE TABLE waitlist (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    priority_score  INT DEFAULT 0,
    status          ENUM('waiting','notified','assigned','cancelled') DEFAULT 'waiting',
    joined_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 7. INFECTION REPORTS
-- Pest/disease reports with neighbor-alert logic
-- ============================================================
CREATE TABLE infection_reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    plot_id         INT NOT NULL,
    user_id         INT NOT NULL,
    pest_type       VARCHAR(100) NOT NULL,
    severity        ENUM('low','medium','high') DEFAULT 'medium',
    is_transmissible TINYINT(1) DEFAULT 0,
    notes           TEXT,
    reported_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plot_id) REFERENCES plots(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 8. COMPLIANCE INSPECTIONS
-- Garden Warden inspection records per plot
-- ============================================================
CREATE TABLE compliance_inspections (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    plot_id         INT NOT NULL,
    warden_id       INT NOT NULL,
    notes           TEXT,
    photo_path      VARCHAR(255),
    result_status   ENUM('compliant','warning','non_compliant') NOT NULL,
    inspected_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plot_id) REFERENCES plots(id),
    FOREIGN KEY (warden_id) REFERENCES users(id)
);

-- ============================================================
-- 9. TOOLS
-- Tool inventory with state-machine status
-- ============================================================
CREATE TABLE tools (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    status          ENUM('available','checked_out','in_repair','decommissioned') DEFAULT 'available',
    usage_hours     DECIMAL(8,2) DEFAULT 0,
    maintenance_threshold DECIMAL(8,2) DEFAULT 100,
    cleaning_status ENUM('clean','uncleaned') DEFAULT 'clean',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 10. TOOL CHECKOUTS
-- Records every borrow session (usage hours accumulate here)
-- ============================================================
CREATE TABLE tool_checkouts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tool_id         INT NOT NULL,
    user_id         INT NOT NULL,
    checkout_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    due_date        DATE NOT NULL,
    return_date     DATETIME DEFAULT NULL,
    session_hours   DECIMAL(6,2) DEFAULT 0,
    status          ENUM('active','returned','overdue') DEFAULT 'active',
    FOREIGN KEY (tool_id) REFERENCES tools(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 11. TOOL RESERVATIONS
-- Future time-slot bookings for shared tools
-- ============================================================
CREATE TABLE tool_reservations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tool_id         INT NOT NULL,
    user_id         INT NOT NULL,
    reservation_date DATE NOT NULL,
    time_slot       VARCHAR(20) NOT NULL,   -- e.g. "09:00-11:00"
    status          ENUM('confirmed','cancelled') DEFAULT 'confirmed',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tool_id) REFERENCES tools(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 12. DAMAGE REPORTS
-- Links to tool + last checkout user
-- ============================================================
CREATE TABLE damage_reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tool_id         INT NOT NULL,
    reported_by     INT NOT NULL,
    description     TEXT NOT NULL,
    classification  ENUM('pending','natural_wear','negligence') DEFAULT 'pending',
    repair_fee      DECIMAL(8,2) DEFAULT 0,
    resolved        TINYINT(1) DEFAULT 0,
    reported_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tool_id) REFERENCES tools(id),
    FOREIGN KEY (reported_by) REFERENCES users(id)
);

-- ============================================================
-- 13. CONSUMABLE ITEMS
-- Fertilizer, mulch, etc. with reorder alerts
-- ============================================================
CREATE TABLE consumable_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    unit            VARCHAR(20) DEFAULT 'kg',
    stock_level     DECIMAL(10,2) DEFAULT 0,
    reorder_threshold DECIMAL(10,2) DEFAULT 10,
    last_updated    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 14. SEED BATCHES
-- Community seed bank with viability tracking
-- ============================================================
CREATE TABLE seed_batches (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    seed_type       VARCHAR(100) NOT NULL,
    variety         VARCHAR(100),
    quantity        INT DEFAULT 0,
    storage_date    DATE NOT NULL,
    expiry_date     DATE NOT NULL,
    status          ENUM('valid','flagged','unusable') DEFAULT 'valid',
    donated_by      INT,
    FOREIGN KEY (donated_by) REFERENCES users(id)
);

-- ============================================================
-- 15. COMMUNAL TASKS
-- Garden tasks with difficulty scores (points awarded on completion)
-- ============================================================
CREATE TABLE communal_tasks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    description     TEXT,
    difficulty_score INT DEFAULT 1,
    is_active       TINYINT(1) DEFAULT 1,
    created_by      INT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ============================================================
-- 16. TASK COMPLETIONS
-- Association class: member + task + points awarded
-- ============================================================
CREATE TABLE task_completions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    task_id         INT NOT NULL,
    user_id         INT NOT NULL,
    points_awarded  INT DEFAULT 0,
    verified        TINYINT(1) DEFAULT 0,
    completed_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES communal_tasks(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 17. SHIFTS
-- Workday shifts with role-based slot limits
-- ============================================================
CREATE TABLE shifts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    shift_date      DATE NOT NULL,
    role_type       ENUM('heavy','light') NOT NULL,
    available_slots INT DEFAULT 5,
    filled_slots    INT DEFAULT 0,
    status          ENUM('open','full','cancelled') DEFAULT 'open',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 18. SHIFT REGISTRATIONS
-- Who signed up for which shift
-- ============================================================
CREATE TABLE shift_registrations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    shift_id        INT NOT NULL,
    user_id         INT NOT NULL,
    registered_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reg (shift_id, user_id),
    FOREIGN KEY (shift_id) REFERENCES shifts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 19. SHIFT SWAP REQUESTS
-- Two-party swap with accept/reject flow
-- ============================================================
CREATE TABLE shift_swap_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    shift_id        INT NOT NULL,
    requester_id    INT NOT NULL,
    target_id       INT NOT NULL,
    status          ENUM('pending','accepted','rejected') DEFAULT 'pending',
    requested_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME DEFAULT NULL,
    FOREIGN KEY (shift_id) REFERENCES shifts(id),
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (target_id) REFERENCES users(id)
);

-- ============================================================
-- 20. SERVICE HOUR LOGS
-- Monthly compliance tracking
-- ============================================================
CREATE TABLE service_hour_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    hours_logged    DECIMAL(5,2) NOT NULL,
    log_date        DATE NOT NULL,
    description     VARCHAR(255),
    compliance_month VARCHAR(7),             -- e.g. "2026-04"
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 21. VOTING PROPOSALS
-- Community fund allocation votes
-- ============================================================
CREATE TABLE voting_proposals (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    description     TEXT,
    created_by      INT NOT NULL,
    deadline        DATETIME NOT NULL,
    status          ENUM('active','closed') DEFAULT 'active',
    winner_flag     TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ============================================================
-- 22. VOTES
-- One vote per user per proposal enforced by UNIQUE KEY
-- ============================================================
CREATE TABLE votes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id     INT NOT NULL,
    user_id         INT NOT NULL,
    voted_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_vote (proposal_id, user_id),
    FOREIGN KEY (proposal_id) REFERENCES voting_proposals(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 23. FLASH TRADE POSTS
-- Perishable produce with expiry timer
-- ============================================================
CREATE TABLE flash_trade_posts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    posted_by       INT NOT NULL,
    produce_type    VARCHAR(100) NOT NULL,
    quantity        DECIMAL(8,2) NOT NULL,
    unit            VARCHAR(20) DEFAULT 'kg',
    pickup_location VARCHAR(200),
    expires_at      DATETIME NOT NULL,
    status          ENUM('active','claimed','expired') DEFAULT 'active',
    claimed_by      INT DEFAULT NULL,
    claimed_at      DATETIME DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id),
    FOREIGN KEY (claimed_by) REFERENCES users(id)
);

-- ============================================================
-- 24. MARKETPLACE LISTINGS
-- General produce listings with allergen flag
-- ============================================================
CREATE TABLE marketplace_listings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT NOT NULL,
    produce_type    VARCHAR(100) NOT NULL,
    quantity        DECIMAL(8,2) NOT NULL,
    unit            VARCHAR(20) DEFAULT 'kg',
    price_type      ENUM('gift','trade','sale') DEFAULT 'gift',
    allergen_warning TINYINT(1) DEFAULT 0,
    allergen_notes  VARCHAR(200),
    avg_quality_score DECIMAL(3,1) DEFAULT 0,
    status          ENUM('available','sold','expired') DEFAULT 'available',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id)
);

-- ============================================================
-- 25. QUALITY RATINGS
-- Only verified transaction members can rate
-- ============================================================
CREATE TABLE quality_ratings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    listing_id      INT NOT NULL,
    rated_by        INT NOT NULL,
    score           TINYINT NOT NULL CHECK (score BETWEEN 1 AND 5),
    rated_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_rating (listing_id, rated_by),
    FOREIGN KEY (listing_id) REFERENCES marketplace_listings(id),
    FOREIGN KEY (rated_by) REFERENCES users(id)
);

-- ============================================================
-- 26. DONATIONS
-- Gift-economy produce donations (no exchange required)
-- ============================================================
CREATE TABLE donations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    donor_id        INT NOT NULL,
    produce_type    VARCHAR(100) NOT NULL,
    quantity        DECIMAL(8,2) NOT NULL,
    unit            VARCHAR(20) DEFAULT 'kg',
    karma_awarded   INT DEFAULT 0,
    donated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id) REFERENCES users(id)
);

-- ============================================================
-- 27. ADVICE POSTS (P2P Advice Exchange)
-- ============================================================
CREATE TABLE advice_posts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    posted_by       INT NOT NULL,
    question        TEXT NOT NULL,
    best_answer_id  INT DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id)
);

-- ============================================================
-- 28. ADVICE ANSWERS
-- Seed credits awarded when selected as best
-- ============================================================
CREATE TABLE advice_answers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    post_id         INT NOT NULL,
    answered_by     INT NOT NULL,
    content         TEXT NOT NULL,
    credits_awarded INT DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES advice_posts(id),
    FOREIGN KEY (answered_by) REFERENCES users(id)
);

-- ============================================================
-- 29. COMPOST CONTRIBUTIONS
-- Members log green waste added to communal pile
-- ============================================================
CREATE TABLE compost_contributions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    waste_type      VARCHAR(80) NOT NULL,
    quantity_kg     DECIMAL(6,2) NOT NULL,
    contributed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 30. PENALTIES
-- Late return fines or community service hours
-- ============================================================
CREATE TABLE penalties (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    checkout_id     INT,
    days_overdue    INT DEFAULT 0,
    penalty_type    ENUM('fine','service_hours') DEFAULT 'fine',
    amount          DECIMAL(8,2) DEFAULT 0,  -- money OR hours
    paid            TINYINT(1) DEFAULT 0,
    issued_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (checkout_id) REFERENCES tool_checkouts(id)
);

-- ============================================================
-- 31. EMERGENCY BROADCASTS
-- Admin emergency alerts to all members
-- ============================================================
CREATE TABLE emergency_broadcasts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sent_by         INT NOT NULL,
    message         TEXT NOT NULL,
    affected_areas  VARCHAR(255),
    sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sent_by) REFERENCES users(id)
);

-- ============================================================
-- 32. NOTIFICATIONS
-- Central notification inbox per user
-- ============================================================
CREATE TABLE notifications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    type            VARCHAR(50) NOT NULL,    -- 'pest_alert','lease_reminder', etc.
    message         TEXT NOT NULL,
    is_read         TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 33. AUDIT LOG
-- Immutable record of all major system actions
-- ============================================================
CREATE TABLE audit_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT,
    action_type     VARCHAR(80) NOT NULL,
    affected_table  VARCHAR(60),
    affected_id     INT,
    description     TEXT,
    ip_address      VARCHAR(45),
    logged_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 34. ACCESS LOG
-- Gate entry/exit records
-- ============================================================
CREATE TABLE access_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT,
    gate_code_used  VARCHAR(10),
    access_granted  TINYINT(1) DEFAULT 0,
    attempted_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- 35. MEDIA LINKS
-- Instructional videos/PDFs linked to tools or seed batches
-- ============================================================
CREATE TABLE media_links (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    linked_type     ENUM('tool','seed') NOT NULL,
    linked_id       INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    url             VARCHAR(500) NOT NULL,
    media_type      ENUM('video','pdf') DEFAULT 'video',
    added_by        INT,
    added_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (added_by) REFERENCES users(id)
);

-- ============================================================
-- ALLERGEN REFERENCE TABLE
-- ============================================================
CREATE TABLE allergen_categories (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    produce_keyword VARCHAR(80) NOT NULL,
    category_name   VARCHAR(80) NOT NULL
);

INSERT INTO allergen_categories (produce_keyword, category_name) VALUES
('tomato','Nightshades'), ('potato','Nightshades'), ('pepper','Nightshades'),
('peanut','Legumes'), ('bean','Legumes'),
('walnut','Tree Nuts'), ('almond','Tree Nuts'),
('onion','Alliums'), ('garlic','Alliums'), ('leek','Alliums'),
('wheat','Gluten'), ('barley','Gluten');
