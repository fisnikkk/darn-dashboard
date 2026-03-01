# DARN Group LPG Dashboard

Web dashboard that mirrors the Excel workbook `Databaza_drive_09_02_2026_final__Nita.xlsm`.

## Setup Instructions

### 1. Requirements
- PHP 8.0+ with PDO MySQL extension
- MySQL 5.7+ or MariaDB 10.3+
- Python 3 with `openpyxl` (for data import)

### 2. Create the Database

```bash
mysql -u root -p < database/schema.sql
```

### 3. Import Excel Data

Copy the Excel file to the `scripts/` directory, then run:

```bash
cd scripts/
# Update DB_CONFIG in import_data.py with your MySQL credentials
# Update MASTER_EXCEL path to point to: Databaza_drive_09_02_2026_final__Nita.xlsm
pip install openpyxl mysql-connector-python
python import_data.py
```

### 4. Configure Database Connection

Edit `config/database.php` and update:
- `DB_HOST` (default: localhost)
- `DB_USER` (default: root)
- `DB_PASS` (your MySQL password)
- `DB_NAME` (default: darn_dashboard)

### 5. Start PHP Server

```bash
cd darn-dashboard/
php -S localhost:8080
```

Open: http://localhost:8080

## Dashboard Pages

| Page | Excel Sheet | Type | Features |
|------|-------------|------|----------|
| Pasqyra | Distribuimi rows 1-4 | Report | Summary aggregations, payment breakdown |
| Distribuimi | Distribuimi | Data + Calc | Editable, filters, calculated columns (boca, pagesa) |
| Shpenzimet | Shpenzimet | Input form | Date, number, dropdown fields |
| Plini Depo | Plini depo | Input form | Running balance (Gjendja) |
| Shitje Produkteve | Shitje produkteve | Input form | Client dropdown linked to real client list |
| Kontrata | Kontrata | Data + Calc | Days since last delivery, cylinder comparison |
| Gjendja Bankare | Gjendja bankare | Data | Highlight/mark rows as reconciled |
| Nxemëse | Nxemese1 | Input form | Stock per client mini-report |
| Borxhet | GJENDJA e borxheve | Report | Date filter, debt by payment type per client |
| Monthly Profit | Monthly profit | Report | P&L by month |
| Litrat | Litrat | Report | Monthly liters bought/sold/invoiced |
| Klientët | Klientet | Data | Master client list |
| Stoku Zyrtar | Stoku zyrtar | Data + Calc | Official product inventory, stock per code |
| Depo | Depo | Data + Calc | Product accessories stock vs sold |

## Key Features

- **Inline editing**: Double-click any editable cell to modify
- **Payment type dropdown**: Finance uses this to reconcile payments
- **Date filter on Borxhet**: Mirrors Excel cell M1 functionality
- **Highlight/reconcile**: Bank statement rows can be marked as verified
- **All data editable**: As required by Albulena

## Calculated Fields (SQL replaces Excel formulas)

- `Boca tek biznesi` = SUM(sasia) - SUM(kthyera) per client
- `Boca total ne terren` = SUM(all sasia) - SUM(all kthyera)
- `Pagesa` = sasia × litra × çmimi
- `Ditë pa marrë` = DATEDIFF(today, MAX(data per client))
- `Borxhet` = SUMIFS equivalent grouped by client + payment type
- `Monthly Profit` = Sales - Purchases - Expenses per month

## File Structure

```
darn-dashboard/
├── index.php              # Overview (Pasqyra)
├── config/
│   ├── database.php       # DB connection + helpers
│   └── layout.php         # Main layout template
├── pages/
│   ├── distribuimi.php    # Core delivery data
│   ├── shpenzimet.php     # Expenses
│   ├── plini_depo.php     # Gas purchases
│   ├── shitje_produkteve.php # Product sales
│   ├── kontrata.php       # Contracts
│   ├── gjendja_bankare.php # Bank statement
│   ├── nxemese.php        # Heaters
│   ├── borxhet.php        # Debt report
│   ├── monthly_profit.php # P&L report
│   ├── litrat.php         # Liters report
│   └── klientet.php       # Client list
├── api/
│   ├── update.php         # Inline edit endpoint
│   ├── insert.php         # Form insert endpoint
│   └── delete.php         # Row delete endpoint
├── assets/
│   ├── css/style.css      # Dashboard styles
│   └── js/app.js          # Inline edit, toast, forms
├── database/
│   └── schema.sql         # MySQL schema
└── scripts/
    └── import_data.py     # Excel → MySQL importer
```
