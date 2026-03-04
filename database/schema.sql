-- ============================================================
-- DARN GROUP LPG Dashboard - Database Schema
-- Mirrors the Excel: Databaza_drive_09_02_2026_final__Nita.xlsm
-- ============================================================

CREATE DATABASE IF NOT EXISTS darn_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE darn_dashboard;

-- ============================================================
-- 1. DISTRIBUIMI (Distribution/Deliveries) - Core table
-- Excel: Distribuimi sheet, ~39,000 rows
-- Columns B-Z from row 6 onwards
-- ============================================================
CREATE TABLE distribuimi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_nr INT,                              -- Col B: Row number
    klienti VARCHAR(255),                    -- Col C: Client name
    data DATE,                               -- Col D: Date
    sasia INT DEFAULT 0,                     -- Col E: Quantity (cylinders delivered)
    boca_te_kthyera INT DEFAULT 0,           -- Col F: Cylinders returned
    -- Col G (Boca tek biznesi) = CALCULATED: running sum of sasia - kthyera per client
    -- Col H (Boca total ne terren) = CALCULATED: running sum of all sasia - all kthyera
    litra DECIMAL(10,2) DEFAULT 0,           -- Col I: Liters per cylinder
    cmimi DECIMAL(10,4) DEFAULT 0,           -- Col J: Price per liter
    pagesa DECIMAL(12,2) DEFAULT 0,          -- Col K: Payment amount (stored from Excel, originally sasia * litra * cmimi)
    menyra_e_pageses VARCHAR(100),           -- Col L: Payment method
    fatura_e_derguar VARCHAR(100),           -- Col M: Invoice sent status
    data_e_fletepageses DATE NULL,           -- Col N: Payment slip date
    koment TEXT,                             -- Col O: Comment
    -- Col V (Month) = CALCULATED: DATE_FORMAT(data, '%b%Y')
    litrat_total DECIMAL(10,2) DEFAULT 0,    -- Col X: Litrat total (stored from Excel, originally sasia * litra)
    litrat_e_konvertuara DECIMAL(10,2) NULL, -- Col Z: Litrat e konvertuara (may differ from litrat_total)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_klienti (klienti),
    INDEX idx_data (data),
    INDEX idx_menyra (menyra_e_pageses),
    INDEX idx_klienti_data (klienti, data)
) ENGINE=InnoDB;

-- ============================================================
-- 2. SHPENZIMET (Expenses)
-- Excel: Shpenzimet sheet, ~2,542 rows
-- ============================================================
CREATE TABLE shpenzimet (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data_e_pageses DATE,                     -- Col C/A: Payment date
    shuma DECIMAL(12,2),                     -- Col D/B: Amount
    arsyetimi VARCHAR(255),                  -- Col E/C: Reason/Category
    lloji_i_pageses VARCHAR(100),            -- Col F/D: Payment type (Cash/Banke)
    lloji_i_transaksionit VARCHAR(100),      -- Col G/E: Transaction type (Shpenzim/Pagesa per plin/etc)
    pershkrim_i_detajuar TEXT,               -- Col H/F: Detailed description
    nafta_ne_litra DECIMAL(10,2) NULL,       -- Col I: Fuel in liters
    numri_i_fatures VARCHAR(100),            -- Col J: Invoice number
    fatura_e_rregullte VARCHAR(50) NULL,     -- Col M: Regular invoice flag
    data_e_fatures DATE NULL,                -- Invoice date
    shuma_fatures DECIMAL(12,2) NULL,        -- Invoice amount
    lloji_fatures VARCHAR(100) NULL,         -- Invoice type
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_data (data_e_pageses),
    INDEX idx_lloji_trans (lloji_i_transaksionit),
    INDEX idx_lloji_pag (lloji_i_pageses)
) ENGINE=InnoDB;

-- ============================================================
-- 3. PLINI DEPO (Gas Depot / Purchases)
-- Excel: Plini depo sheet, ~877 rows
-- ============================================================
CREATE TABLE plini_depo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nr_i_fatures VARCHAR(100),               -- Col A: Invoice number
    data DATE,                               -- Col B: Date
    kg DECIMAL(12,2),                        -- Col C: Kilograms
    sasia_ne_litra DECIMAL(12,2),            -- Col D: Quantity in liters (= kg * 1.95)
    cmimi DECIMAL(10,4),                     -- Col E: Price
    faturat_e_pranuara DECIMAL(12,2),        -- Col F: Invoices received (amount)
    dalje_pagesat_sipas_bankes DECIMAL(12,2) DEFAULT 0, -- Col G: Bank payments out
    menyra_e_pageses VARCHAR(100),           -- Col H: Payment method (Me fature/Pa fature)
    cash_banke VARCHAR(50),                  -- Col I: Cash/Bank
    furnitori VARCHAR(255),                  -- Col J: Supplier
    koment TEXT,                             -- Col K: Comment
    -- Col L (Gjendja) = CALCULATED: running balance of faturat - dalje
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_data (data),
    INDEX idx_menyra (menyra_e_pageses)
) ENGINE=InnoDB;

-- ============================================================
-- 4. SHITJE PRODUKTEVE (Product Sales)
-- Excel: Shitje produkteve prej 9 mar, ~983 rows
-- ============================================================
CREATE TABLE shitje_produkteve (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data DATE,                               -- Col A: Date
    cilindra_sasia INT DEFAULT 0,            -- Col B: Cylinder quantity
    produkti VARCHAR(255),                   -- Col C: Product name
    klienti VARCHAR(255),                    -- Col D: Client name
    adresa VARCHAR(255),                     -- Col E: Address
    qyteti VARCHAR(100),                     -- Col F: City
    cmimi DECIMAL(10,2),                     -- Col G: Price per unit
    totali DECIMAL(12,2),                    -- Col H: Total (= cmimi * sasia)
    menyra_pageses VARCHAR(100),             -- Col I: Payment method
    koment TEXT,                             -- Col J: Comment
    statusi_i_pageses VARCHAR(100),          -- Col K: Payment status
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_data (data),
    INDEX idx_klienti (klienti),
    INDEX idx_menyra (menyra_pageses)
) ENGINE=InnoDB;

-- ============================================================
-- 5. KONTRATA (Contracts)
-- Excel: Kontrata sheet, ~966 rows
-- ============================================================
CREATE TABLE kontrata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nr_i_kontrates INT NULL,                 -- Col A: Contract number
    data DATE NULL,                          -- Col B: Date
    biznesi VARCHAR(255),                    -- Col C: Business name
    name_from_database VARCHAR(255),         -- Col D: Name from database
    numri_ne_stok_sipas_kontrates INT DEFAULT 0, -- Col E: Stock qty per contract
    -- Col F (Sipas Distribuimit) = CALCULATED from distribuimi
    -- Col G (Comparison) = CALCULATED: E - F
    sipas_skenimit_pda TEXT NULL,             -- Col H: PDA scanner data
    bashkepunim VARCHAR(50),                 -- Col I: Cooperation status (po/jo)
    qyteti VARCHAR(100),                     -- Col J: City
    rruga VARCHAR(255),                      -- Col K: Street
    numri_unik VARCHAR(100),                 -- Col L: Unique number
    perfaqesuesi VARCHAR(255),               -- Col M: Representative
    nr_telefonit VARCHAR(100),               -- Col N: Phone number
    koment TEXT,                             -- Col O: Comment
    email VARCHAR(255),                      -- Col P: Email
    ne_grup_njoftues VARCHAR(50),            -- Col Q: In notification group
    kontrate_e_vjeter VARCHAR(100),          -- Col R: Old contract
    lloji_i_bocave VARCHAR(100),             -- Col S: Cylinder type
    -- Col T (Qe sa dite nuk ka marr) = CALCULATED: days since last delivery
    bocat_e_paguara VARCHAR(50),             -- Col U: Cylinders paid
    data_rregullatoret DATE NULL,            -- Col W: Regulator install date
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_biznesi (biznesi),
    INDEX idx_name_db (name_from_database)
) ENGINE=InnoDB;

-- ============================================================
-- 6. GJENDJA BANKARE (Bank Statement)
-- Excel: Gjendja bankare sheet, ~1,838 rows
-- ============================================================
CREATE TABLE gjendja_bankare (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data DATE,                               -- Col A: Date
    data_valutes DATE NULL,                  -- Col B: Value date
    ora TIME NULL,                           -- Col C: Time
    shpjegim TEXT,                           -- Col D: Description
    valuta VARCHAR(10) DEFAULT 'EUR',        -- Col E: Currency
    debia DECIMAL(12,2) DEFAULT 0,           -- Col F: Debit (outgoing)
    kredi DECIMAL(12,2) DEFAULT 0,           -- Col G: Credit (incoming)
    bilanci DECIMAL(12,2) DEFAULT 0,         -- Col H: Balance
    deftesa VARCHAR(100),                    -- Col I: Receipt number
    lloji VARCHAR(100),                      -- Col J: Type/Category
    e_kontrolluar BOOLEAN DEFAULT FALSE,     -- For highlight/reconciliation marking
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_data (data),
    INDEX idx_lloji (lloji)
) ENGINE=InnoDB;

-- ============================================================
-- 7. NXEMESE (Heaters)
-- Excel: Nxemese1 sheet
-- ============================================================
CREATE TABLE nxemese (
    id INT AUTO_INCREMENT PRIMARY KEY,
    klienti VARCHAR(255),                    -- Col C: Client
    data DATE,                               -- Col D: Date
    te_dhena INT DEFAULT 0,                  -- Col E: Given out
    te_marra INT DEFAULT 0,                  -- Col F: Taken back
    -- Col G (Ne stok) = CALCULATED: running sum per client
    -- Col H (Total ne terren) = CALCULATED: running total
    lloji_i_nxemjes VARCHAR(100),            -- Col I: Heater type
    koment TEXT,                             -- Col J: Comment
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_klienti (klienti),
    INDEX idx_data (data)
) ENGINE=InnoDB;

-- ============================================================
-- 8. KLIENTET (Clients Master List)
-- Excel: Klientet sheet
-- ============================================================
CREATE TABLE klientet (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emri VARCHAR(255),                       -- Col B: Client name
    bashkepunim VARCHAR(100),                -- Col C: Cooperation status
    data_e_kontrates DATE NULL,              -- Col D: Contract date
    stoku INT DEFAULT 0,                     -- Col E: Stock
    koment TEXT,                             -- Col F: Comment
    kontakti VARCHAR(255),                   -- Col G: Contact person
    i_regjistruar_ne_emer VARCHAR(255),      -- Col H: Registered in name of
    numri_unik_identifikues VARCHAR(100),    -- Col I: Unique ID
    adresa VARCHAR(255),                     -- Col J: Address
    telefoni VARCHAR(100),                   -- Col K: Phone from ARBK
    telefoni_2 VARCHAR(100),                 -- Col L: Phone 2
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_emri (emri)
) ENGINE=InnoDB;

-- ============================================================
-- 9. STOKU ZYRTAR (Official Product Inventory)
-- Excel: Stoku zyrtar sheet, ~31 rows
-- ============================================================
CREATE TABLE stoku_zyrtar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data DATE NULL,                          -- Col B: Date
    kodi VARCHAR(100),                       -- Col C: Product code (KOD0000000029 etc.)
    kodi_2 VARCHAR(255),                     -- Col D: Secondary code / Destination
    pershkrimi VARCHAR(500),                 -- Col E: Description
    njesi VARCHAR(50),                       -- Col F: Unit (COPE etc.)
    sasia DECIMAL(12,2) DEFAULT 0,           -- Col G: Quantity (+incoming, -outgoing)
    cmimi DECIMAL(10,4) NULL,                -- Col H: Price per unit
    vlera DECIMAL(12,2) NULL,                -- Col I: Value (= sasia * cmimi)
    -- Col J (Stoku momental) = CALCULATED: SUM(sasia) per kodi
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kodi (kodi),
    INDEX idx_data (data)
) ENGINE=InnoDB;

-- ============================================================
-- 10. DEPO (Product Accessories Stock)
-- Excel: Depo sheet, ~21 rows
-- ============================================================
CREATE TABLE depo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data DATE NULL,                          -- Col B: Date
    produkti VARCHAR(255),                   -- Col C: Product name
    sasia INT DEFAULT 0,                     -- Col D: Quantity
    cmimi DECIMAL(10,2) NULL,                -- Col E: Price
    -- Col F (Total te shitura) = CALCULATED: SUMIF from shitje_produkteve
    -- Col G (Stoku aktual) = CALCULATED: sasia - total_sold + adjustments
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_produkti (produkti)
) ENGINE=InnoDB;

-- ============================================================
-- Views for calculated reports
-- ============================================================

-- BORXHET (Debts) - Mirrors GJENDJA e borxheve sheet
CREATE OR REPLACE VIEW v_borxhet AS
SELECT 
    klienti,
    SUM(CASE WHEN LOWER(menyra_e_pageses) = 'cash' THEN pagesa ELSE 0 END) AS cash,
    SUM(CASE WHEN LOWER(menyra_e_pageses) = 'bank' THEN pagesa ELSE 0 END) AS bank,
    SUM(CASE WHEN LOWER(menyra_e_pageses) = 'po (fature te rregullte) banke' THEN pagesa ELSE 0 END) AS fature_banke,
    SUM(CASE WHEN LOWER(menyra_e_pageses) = 'po (fature te rregullte) cash' THEN pagesa ELSE 0 END) AS fature_cash,
    SUM(CASE WHEN LOWER(menyra_e_pageses) = 'no payment' THEN pagesa ELSE 0 END) AS no_payment,
    SUM(CASE WHEN LOWER(menyra_e_pageses) = 'dhurate' THEN pagesa ELSE 0 END) AS dhurate,
    SUM(pagesa) AS total
FROM distribuimi
GROUP BY klienti
ORDER BY klienti;

-- BORXHET with date filter (parameterized in PHP)
-- Used for: GJENDJA e borxheve column I (Borxhi deri daten)

-- MONTHLY PROFIT - Mirrors Monthly profit sheet
CREATE OR REPLACE VIEW v_monthly_profit AS
SELECT 
    DATE_FORMAT(d.data, '%Y-%m') AS muaji,
    -- Sales from distribuimi
    COALESCE(SUM(d.pagesa), 0) AS shitjet,
    -- Gas purchases for that month
    (SELECT COALESCE(SUM(s.shuma), 0) FROM shpenzimet s 
     WHERE DATE_FORMAT(s.data_e_pageses, '%Y-%m') = DATE_FORMAT(d.data, '%Y-%m')
     AND LOWER(s.lloji_i_transaksionit) = 'pagesa per plin') AS blerje_plinit,
    -- Other expenses
    (SELECT COALESCE(SUM(s.shuma), 0) FROM shpenzimet s
     WHERE DATE_FORMAT(s.data_e_pageses, '%Y-%m') = DATE_FORMAT(d.data, '%Y-%m')
     AND LOWER(s.lloji_i_transaksionit) = 'shpenzim') AS shpenzimet_tjera
FROM distribuimi d
GROUP BY DATE_FORMAT(d.data, '%Y-%m')
ORDER BY muaji;

-- BOCA TEK BIZNESI per client (current cylinder count at each business)
CREATE OR REPLACE VIEW v_boca_tek_biznesi AS
SELECT 
    klienti,
    SUM(sasia) - SUM(boca_te_kthyera) AS boca_tek_biznesi
FROM distribuimi
GROUP BY klienti;

-- STOKU TOTAL NE TERREN (total cylinders in the field)
CREATE OR REPLACE VIEW v_stoku_total AS
SELECT 
    SUM(sasia) - SUM(boca_te_kthyera) AS boca_total_ne_terren
FROM distribuimi;

-- DITE PA MARR GAZ (days since last gas purchase per client)
CREATE OR REPLACE VIEW v_dite_pa_marr AS
SELECT 
    klienti,
    MAX(data) AS furnizimi_i_fundit,
    DATEDIFF(CURDATE(), MAX(data)) AS dite_pa_marr
FROM distribuimi
WHERE sasia > 0
GROUP BY klienti
ORDER BY dite_pa_marr DESC;

-- LITRAT monthly tracking
CREATE OR REPLACE VIEW v_litrat_mujore AS
SELECT
    DATE_FORMAT(data, '%Y-%m') AS muaji,
    -- Total liters bought (from plini_depo)
    (SELECT COALESCE(SUM(pd.sasia_ne_litra), 0) FROM plini_depo pd 
     WHERE DATE_FORMAT(pd.data, '%Y-%m') = DATE_FORMAT(d.data, '%Y-%m')) AS litra_te_blera,
    -- Total liters sold
    SUM(d.sasia * d.litra) AS litra_te_shitura,
    -- Cylinders distributed
    SUM(d.sasia) AS boca_te_shperndara,
    -- Total sales amount
    SUM(d.pagesa) AS shitjet_totale
FROM distribuimi d
GROUP BY DATE_FORMAT(d.data, '%Y-%m')
ORDER BY muaji;
