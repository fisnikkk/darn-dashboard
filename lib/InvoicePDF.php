<?php
/**
 * DARN Dashboard - Invoice PDF Generator
 * Generates invoices matching the Android Admin App format using FPDF.
 */
require_once __DIR__ . '/FPDF/fpdf.php';

class InvoicePDF extends FPDF {

    // Company info (seller)
    const SELLER_NAME = 'DARN GROUP L.L.C';
    const SELLER_ADDRESS = 'Bulevardi Deshmoret e Kombit Nr 62 6/1, Prishtine';
    const SELLER_FISKAL = '811577248';
    const SELLER_PHONE = '046199240';
    const SELLER_BIZNESI = '811577248';
    const SELLER_EMAIL = 'sales@darngroup.com';
    const BANK_NAME = 'TEB BANK';
    const BANK_IBAN = 'KOSOVEXK05 2020000196568247';
    const VAT_RATE = 0.18;

    private $invoiceNumber;
    private $dateFrom;
    private $dateTo;
    private $clientName;
    private $clientBusiness;
    private $clientAddress;
    private $clientFiskal;
    private $clientPhone;
    private $clientEmail;
    private $rows;
    private $totalAmount;
    private $totalDelivered;
    private $totalReturned;

    public function __construct(
        $invoiceNumber, $dateFrom, $dateTo,
        $clientName, $clientBusiness, $clientAddress,
        $clientFiskal, $clientPhone, $clientEmail,
        $rows
    ) {
        parent::__construct('P', 'mm', 'A4');
        $this->invoiceNumber = $invoiceNumber;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->clientName = $clientName;
        $this->clientBusiness = $clientBusiness ?: $clientName;
        $this->clientAddress = $clientAddress ?: '';
        $this->clientFiskal = $clientFiskal ?: '';
        $this->clientPhone = $clientPhone ?: '';
        $this->clientEmail = $clientEmail ?: '';
        $this->rows = $rows;

        // Calculate totals
        $this->totalAmount = 0;
        $this->totalDelivered = 0;
        $this->totalReturned = 0;
        foreach ($rows as $r) {
            $this->totalAmount += floatval($r['pagesa'] ?? 0);
            $this->totalDelivered += intval($r['sasia'] ?? 0);
            $this->totalReturned += intval($r['boca_te_kthyera'] ?? 0);
        }
    }

    public function generate() {
        $this->SetAutoPageBreak(true, 25);
        $this->SetMargins(10, 10, 10);
        $this->AddPage();

        $this->renderHeader();
        $this->renderSeparator();
        $this->renderTitle();
        $this->renderParties();
        $this->renderInvoiceDetails();
        $this->renderTransactionTable();
        $this->renderTotals();
        $this->renderFooter();
    }

    public function getFilename() {
        $month = date('M-Y', strtotime($this->dateTo));
        $biz = preg_replace('/[^a-zA-Z0-9 ]/', '', $this->clientBusiness);
        return "Fatura nr {$this->invoiceNumber} - {$month} - {$biz}.pdf";
    }

    // --- Header: Total amount ---
    private function renderHeader() {
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(130, 15, 'DARN GROUP L.L.C', 0, 0, 'L');
        $this->SetFont('Helvetica', '', 12);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(25, 15, 'Total:', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(25, 15, number_format($this->totalAmount, 2, '.', ','), 0, 0, 'R');
        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(10, 15, 'EUR', 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    // --- Gray separator line ---
    private function renderSeparator() {
        $this->SetDrawColor(207, 208, 210);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(3);
    }

    // --- Title: FATURE ---
    private function renderTitle() {
        $this->SetFont('Helvetica', 'B', 16);
        $this->Cell(0, 10, 'Fature', 0, 1, 'C');
        $this->Ln(2);
    }

    // --- Seller and Buyer info side by side ---
    private function renderParties() {
        $this->SetFont('Helvetica', 'B', 8);
        $startY = $this->GetY();

        // Left column: Seller
        $this->Cell(95, 5, 'Subjekti Shites: ' . self::SELLER_NAME, 0, 1, 'L');
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(95, 4, 'Adresa: ' . self::SELLER_ADDRESS, 0, 1, 'L');
        $sellerEndY = $this->GetY();

        // Right column: Buyer
        $this->SetY($startY);
        $this->SetX(105);
        $this->SetFont('Helvetica', 'B', 8);
        $this->Cell(95, 5, 'Subjekti Bleres: ' . $this->clientBusiness, 0, 1, 'L');
        $this->SetX(105);
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(95, 4, 'Adresa: ' . $this->clientAddress, 0, 1, 'L');

        $this->SetY(max($sellerEndY, $this->GetY()) + 2);

        // Detail rows: Seller | Buyer
        $details = [
            ['Nr. Fiskal:', self::SELLER_FISKAL, 'Nr. Fiskal:', $this->clientFiskal],
            ['Tel:', self::SELLER_PHONE, 'Tel:', $this->clientPhone],
            ['Nr.Biznesi:', self::SELLER_BIZNESI, 'Nr.Biznesi:', '-'],
            ['e-Mail:', self::SELLER_EMAIL, 'e-Mail:', $this->clientEmail],
        ];

        $this->SetFont('Helvetica', '', 7);
        foreach ($details as $d) {
            $y = $this->GetY();
            $this->Cell(20, 4, $d[0], 0, 0, 'L');
            $this->Cell(75, 4, $d[1], 0, 0, 'L');
            $this->Cell(20, 4, $d[2], 0, 0, 'L');
            $this->Cell(75, 4, $d[3], 0, 1, 'L');
        }
        $this->Ln(3);
    }

    // --- Invoice number and date range ---
    private function renderInvoiceDetails() {
        $this->renderSeparator();
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(95, 6, 'FATURA NR: ' . $this->invoiceNumber, 0, 0, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(95, 6, 'Data - Ora e dokumentit: ' . $this->dateFrom . ' deri ' . $this->dateTo, 0, 1, 'R');
        $this->Ln(2);
    }

    // --- Transaction table ---
    private function renderTransactionTable() {
        // Column widths (total = 190)
        $w = [25, 45, 18, 18, 20, 20, 16, 28];
        $headers = ['Data', 'Pershkrimi', 'Dhene', 'Kthyer', 'Sasia', 'Cmimi', 'Ulje%', 'Vlera'];

        // Header row
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(44, 62, 80);
        $this->SetTextColor(255, 255, 255);
        for ($i = 0; $i < count($headers); $i++) {
            $this->Cell($w[$i], 6, $headers[$i], 1, 0, 'C', true);
        }
        $this->Ln();

        // Data rows
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(0, 0, 0);
        $fill = false;
        $totalVlera = 0;
        $totalSasia = 0;
        $totalDhene = 0;
        $totalKthyer = 0;

        foreach ($this->rows as $row) {
            if ($fill) {
                $this->SetFillColor(240, 240, 240);
            }

            $date = date('Y-m-d', strtotime($row['data']));
            $desc = 'GAS I LENGET (L)';
            $dhene = intval($row['sasia'] ?? 0);
            $kthyer = intval($row['boca_te_kthyera'] ?? 0);
            $volume = floatval($row['litra'] ?? 0);
            $price = floatval($row['cmimi'] ?? 0);
            $vlera = floatval($row['pagesa'] ?? 0);

            $totalDhene += $dhene;
            $totalKthyer += $kthyer;
            $totalSasia += $volume;
            $totalVlera += $vlera;

            // Check for page break
            if ($this->GetY() + 5 > $this->PageBreakTrigger) {
                $this->AddPage();
                // Reprint header
                $this->SetFont('Helvetica', 'B', 7);
                $this->SetFillColor(44, 62, 80);
                $this->SetTextColor(255, 255, 255);
                for ($i = 0; $i < count($headers); $i++) {
                    $this->Cell($w[$i], 6, $headers[$i], 1, 0, 'C', true);
                }
                $this->Ln();
                $this->SetFont('Helvetica', '', 7);
                $this->SetTextColor(0, 0, 0);
            }

            $this->Cell($w[0], 5, $date, 'LR', 0, 'C', $fill);
            $this->Cell($w[1], 5, $this->truncate($desc, $w[1]), 'LR', 0, 'L', $fill);
            $this->Cell($w[2], 5, $dhene, 'LR', 0, 'C', $fill);
            $this->Cell($w[3], 5, $kthyer, 'LR', 0, 'C', $fill);
            $this->Cell($w[4], 5, number_format($volume, 1), 'LR', 0, 'C', $fill);
            $this->Cell($w[5], 5, number_format($price, 2), 'LR', 0, 'C', $fill);
            $this->Cell($w[6], 5, '0', 'LR', 0, 'C', $fill);
            $this->Cell($w[7], 5, number_format($vlera, 2), 'LR', 0, 'R', $fill);
            $this->Ln();
            $fill = !$fill;
        }

        // Totals row
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(44, 62, 80);
        $this->SetTextColor(255, 255, 255);
        $this->Cell($w[0], 6, '', 1, 0, 'C', true);
        $this->Cell($w[1], 6, 'TOTALI', 1, 0, 'C', true);
        $this->Cell($w[2], 6, $totalDhene, 1, 0, 'C', true);
        $this->Cell($w[3], 6, $totalKthyer, 1, 0, 'C', true);
        $this->Cell($w[4], 6, number_format($totalSasia, 1), 1, 0, 'C', true);
        $this->Cell($w[5], 6, '', 1, 0, 'C', true);
        $this->Cell($w[6], 6, '', 1, 0, 'C', true);
        $this->Cell($w[7], 6, number_format($totalVlera, 2), 1, 0, 'R', true);
        $this->Ln(8);
        $this->SetTextColor(0, 0, 0);
    }

    // --- VAT breakdown and notes ---
    private function renderTotals() {
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(95, 5, 'Shenime:', 0, 0, 'L');

        // VAT calculation (total includes 18% VAT)
        $totalWithVat = $this->totalAmount;
        $totalWithoutVat = round($totalWithVat / (1 + self::VAT_RATE), 2);
        $vatAmount = round($totalWithVat - $totalWithoutVat, 2);

        $this->SetFont('Helvetica', '', 8);
        $this->Cell(50, 5, 'VLERA PA TVSH:', 0, 0, 'R');
        $this->SetFont('Helvetica', 'B', 8);
        $this->Cell(45, 5, number_format($totalWithoutVat, 2) . ' EUR', 0, 1, 'R');

        $this->Cell(95, 5, '', 0, 0, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(50, 5, 'TVSH 18%:', 0, 0, 'R');
        $this->SetFont('Helvetica', 'B', 8);
        $this->Cell(45, 5, number_format($vatAmount, 2) . ' EUR', 0, 1, 'R');

        $this->Cell(95, 5, '', 0, 0, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(50, 5, 'VLERA ME TVSH:', 0, 0, 'R');
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(45, 5, number_format($totalWithVat, 2) . ' EUR', 0, 1, 'R');

        $this->Ln(5);
    }

    // --- Footer: Bank details, payment terms, signature ---
    private function renderFooter() {
        $this->renderSeparator();
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(0, 4, self::BANK_NAME . ' ' . self::BANK_IBAN, 0, 1, 'L');
        $this->Ln(2);

        $this->SetFont('Helvetica', '', 6);
        $this->MultiCell(0, 3,
            'Te nderuar klient, Ju lusim qe pagesen ta kryeni brenda 7 diteve pune nga marrja e fatures. ' .
            'Ne te kunderten do te llogariten interesat ne shumen 0.1% ne dite.'
        );
        $this->Ln(5);

        // Signature area
        $this->SetFont('Helvetica', '', 8);
        $startY = $this->GetY();
        $this->Cell(95, 5, 'Leshoi ________________', 0, 0, 'L');
        $this->Cell(95, 5, 'Pranoi ________________', 0, 1, 'R');
    }

    private function truncate($text, $width) {
        $maxChars = intval($width / 1.8);
        if (strlen($text) > $maxChars) {
            return substr($text, 0, $maxChars - 2) . '..';
        }
        return $text;
    }
}
