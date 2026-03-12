<?php
/**
 * DARN Dashboard - Invoice PDF Generator
 * Matches the Android Admin App invoice layout exactly.
 * Uses FPDF with image support (logos, QR code, signature/stamp).
 */
require_once __DIR__ . '/FPDF/fpdf.php';

class InvoicePDF extends FPDF {

    // Company info (seller) — hardcoded same as Android app
    const SELLER_NAME = 'DARN GROUP L.L.C';
    const SELLER_ADDRESS = "Prishtine, Bulevardi Deshmoret e Kombit Nr 62 6/1";
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
    private $imgDir;
    private $cylinderCount;
    private $formattedInvoiceNumber;

    public function __construct(
        $invoiceNumber, $dateFrom, $dateTo,
        $clientName, $clientBusiness, $clientAddress,
        $clientFiskal, $clientPhone, $clientEmail,
        $rows, $cylinderCount = 0
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
        $this->imgDir = __DIR__ . '/../assets/images/';
        $this->cylinderCount = intval($cylinderCount);

        // Format invoice number as "134-02-2026" (number-month-year from dateTo)
        $monthNum = date('m', strtotime($dateTo));
        $year = date('Y', strtotime($dateTo));
        $this->formattedInvoiceNumber = $invoiceNumber . '-' . $monthNum . '-' . $year;

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
        $this->renderInvoiceInfo();
        $this->renderInvoiceDetails();
        $this->renderTransactionTable();
        $this->renderNotes();
        $this->renderFooter();
        $this->renderSignature();
    }

    public function getFilename() {
        $month = date('M-Y', strtotime($this->dateTo));
        $biz = preg_replace('/[^a-zA-Z0-9 ]/', '', $this->clientBusiness);
        return "Fatura nr {$this->formattedInvoiceNumber} - {$month} - {$biz}.pdf";
    }

    // ─── Header: 3 logos + Total amount ───
    private function renderHeader() {
        $startY = $this->GetY();

        // DARN logo (left)
        $img = $this->imgDir . 'darn_pdf_icon.png';
        if (file_exists($img)) {
            $this->Image($img, 10, $startY + 2, 22, 16);
        }

        // Hexagon logo (middle-left)
        $img2 = $this->imgDir . 'hexagone_icon.png';
        if (file_exists($img2)) {
            $this->Image($img2, 38, $startY + 2, 22, 16);
        }

        // QR code (middle)
        $img3 = $this->imgDir . 'qr_code.png';
        if (file_exists($img3)) {
            $this->Image($img3, 66, $startY + 2, 16, 16);
        }

        // Total label + amount (right side)
        $this->SetFont('Helvetica', '', 14);
        $this->SetTextColor(128, 128, 128);
        $this->SetXY(110, $startY + 3);
        $this->Cell(40, 8, 'Total :', 0, 0, 'R');

        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(30, 8, number_format($this->totalAmount, 2, '.', ','), 0, 0, 'R');

        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(10, 8, 'EURO', 0, 1, 'L');

        $this->SetTextColor(0, 0, 0);
        $this->SetY($startY + 22);
    }

    // ─── Gray separator line ───
    private function renderSeparator() {
        $this->SetFillColor(207, 208, 210);
        $this->Cell(0, 0.8, '', 0, 1, 'C', true);
        $this->Ln(2);
    }

    // ─── Title: FATURE ───
    private function renderTitle() {
        $this->SetFont('Helvetica', 'B', 16);
        $this->Cell(0, 8, 'Fature', 0, 1, 'C');
        $this->Ln(1);
    }

    // ─── Seller and Buyer info with gray backgrounds ───
    private function renderParties() {
        $this->SetFont('Helvetica', 'B', 8);
        $startY = $this->GetY();

        // Seller line (gray bg)
        $this->SetFillColor(207, 208, 210);
        $this->Cell(95, 5, 'Subjekti Shites : ' . self::SELLER_NAME, 0, 0, 'L', true);
        // Buyer line (gray bg)
        $this->Cell(95, 5, 'Subjekti Bleres : ' . $this->clientBusiness, 0, 1, 'L', true);

        // Address lines (no bg)
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(95, 4, 'Adresa : ' . self::SELLER_ADDRESS, 0, 0, 'L');
        $this->Cell(95, 4, 'Adresa : ' . $this->clientAddress, 0, 1, 'L');

        $this->Ln(2);
    }

    // ─── Fiscal, Phone, Business#, Email for both parties (4 columns) ───
    private function renderInvoiceInfo() {
        $this->SetFont('Helvetica', '', 7);

        // Row 1: Fiscal + Phone
        $this->Cell(47.5, 4, 'Nr. Fiskal : ' . self::SELLER_FISKAL, 0, 0, 'L');
        $this->Cell(47.5, 4, 'Tel : ' . self::SELLER_PHONE, 0, 0, 'L');
        $this->Cell(47.5, 4, 'Nr. Fiskal : ' . $this->clientFiskal, 0, 0, 'L');
        $this->Cell(47.5, 4, 'Tel : ' . $this->clientPhone, 0, 1, 'L');

        // Row 2: Business# + Email
        $this->Cell(47.5, 4, 'Nr.Bisnesi : ' . self::SELLER_BIZNESI, 0, 0, 'L');
        $this->Cell(47.5, 4, 'e-Mail : ' . self::SELLER_EMAIL, 0, 0, 'L');
        $this->Cell(47.5, 4, 'Nr.Bisnesi : - ', 0, 0, 'L');
        $this->Cell(47.5, 4, 'e-Mail : ' . $this->clientEmail, 0, 1, 'L');

        $this->Ln(3);
    }

    // ─── Invoice number and date range + column headers ───
    private function renderInvoiceDetails() {
        // Separator
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(1);

        $duration = $this->dateFrom . ' deri ' . $this->dateTo;
        $this->SetFont('Helvetica', 'B', 8);
        $this->Cell(95, 5, 'FATURA NR : ' . $this->formattedInvoiceNumber, 0, 0, 'L');
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(95, 5, 'Data - Ora e dokumentit : ' . $duration, 0, 1, 'R');

        // Separator
        $this->SetDrawColor(128, 128, 128);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(1);

        // Column headers with gray background
        $w = [25, 45, 18, 18, 20, 20, 16, 28];
        $headers = ['Date', iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Pershkrimi'), iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Dhene'), 'Kthyer', 'Sasia', 'Cmim', 'Ulje %', 'Vlera'];

        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(207, 208, 210);
        $this->SetTextColor(0, 0, 0);
        for ($i = 0; $i < count($headers); $i++) {
            $align = 'C';
            if ($i === 0 || $i === 1) $align = 'L';
            if ($i === 7) $align = 'R';
            $this->Cell($w[$i], 5, $headers[$i], 0, 0, $align, true);
        }
        $this->Ln();
    }

    // ─── Transaction data rows ───
    private function renderTransactionTable() {
        $w = [25, 45, 18, 18, 20, 20, 16, 28];

        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(0, 0, 0);

        foreach ($this->rows as $row) {
            // Check for page break
            if ($this->GetY() + 5 > $this->PageBreakTrigger) {
                $this->AddPage();
                // Reprint column headers
                $headers = ['Date', 'Pershkrimi', 'Dhene', 'Kthyer', 'Sasia', 'Cmim', 'Ulje %', 'Vlera'];
                $this->SetFont('Helvetica', 'B', 7);
                $this->SetFillColor(207, 208, 210);
                for ($i = 0; $i < count($headers); $i++) {
                    $align = 'C';
                    if ($i === 0 || $i === 1) $align = 'L';
                    if ($i === 7) $align = 'R';
                    $this->Cell($w[$i], 5, $headers[$i], 0, 0, $align, true);
                }
                $this->Ln();
                $this->SetFont('Helvetica', '', 7);
                $this->SetTextColor(0, 0, 0);
            }

            $date = date('Y-m-d', strtotime($row['data']));
            $desc = 'GAS I LENGET (L)';
            $dhene = intval($row['sasia'] ?? 0);
            $kthyer = intval($row['boca_te_kthyera'] ?? 0);
            $volume = floatval($row['litra'] ?? 0);
            $price = floatval($row['cmimi'] ?? 0);
            $vlera = floatval($row['pagesa'] ?? 0);

            $this->Cell($w[0], 4.5, $date, 0, 0, 'L');
            $this->Cell($w[1], 4.5, $desc, 0, 0, 'L');
            $this->Cell($w[2], 4.5, $dhene, 0, 0, 'R');
            $this->Cell($w[3], 4.5, $kthyer, 0, 0, 'R');
            $this->Cell($w[4], 4.5, number_format($volume, 1), 0, 0, 'C');
            $this->Cell($w[5], 4.5, number_format($price, 2), 0, 0, 'C');
            $this->Cell($w[6], 4.5, '0', 0, 0, 'R');
            $this->Cell($w[7], 4.5, number_format($vlera, 2), 0, 1, 'R');
        }
    }

    // ─── Notes separator ───
    private function renderNotes() {
        $this->Ln(1);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(1);

        $this->SetFont('Helvetica', '', 8);
        $this->Cell(0, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Shenime :'), 0, 1, 'L');
        $this->Ln(2);
    }

    // ─── Footer: Bank details (left) + VAT breakdown (right) ───
    private function renderFooter() {
        $startY = $this->GetY();

        // Check if we need a page break for footer content
        if ($startY + 50 > $this->PageBreakTrigger) {
            $this->AddPage();
            $startY = $this->GetY();
        }

        // LEFT: Bank details + payment terms
        $this->SetFont('Helvetica', '', 7);
        $this->SetDrawColor(128, 128, 128);

        $this->SetXY(10, $startY);
        $bankText = self::BANK_NAME . ' ' . self::BANK_IBAN;
        $this->Cell(100, 4, $bankText, 0, 1, 'L');

        $this->SetX(10);
        $this->SetFont('Helvetica', '', 6);
        $this->MultiCell(100, 3,
            iconv('UTF-8', 'ISO-8859-1//TRANSLIT',
                "Verejtje: Pagesa duhet te behet 7 dite nga data e pranimit te\n" .
                "fatures, ne te kundert aplikohet kamata ditore 0.1%"
            )
        );

        // RIGHT: VAT breakdown table
        $rightX = 130;
        $this->SetFont('Helvetica', '', 7);

        // MONEDHA1 | EURO
        $this->SetXY($rightX, $startY);
        $this->SetFillColor(207, 208, 210);
        $this->Cell(35, 4.5, 'MONEDHA1', 0, 0, 'R', true);
        $this->Cell(2, 4.5, '', 0, 0);
        $this->Cell(23, 4.5, 'EURO', 1, 1, 'R');

        // VLERA PA TVSH
        $totalWithVat = $this->totalAmount;
        $totalWithoutVat = round($totalWithVat / (1 + self::VAT_RATE), 2);
        $vatAmount = round($totalWithVat - $totalWithoutVat, 2);

        $this->SetX($rightX);
        $this->Cell(35, 4.5, 'VLERA PA TVSH', 0, 0, 'R', true);
        $this->Cell(2, 4.5, '', 0, 0);
        $this->Cell(23, 4.5, number_format($totalWithoutVat, 2), 1, 1, 'R');

        // TVSH 18%
        $this->SetX($rightX);
        $this->Cell(35, 4.5, 'TVSH 18 %', 0, 0, 'R', true);
        $this->Cell(2, 4.5, '', 0, 0);
        $this->Cell(23, 4.5, number_format($vatAmount, 2), 1, 1, 'R');

        // VLERA ME TVSH
        $this->SetX($rightX);
        $this->Cell(35, 4.5, 'VLERA ME TVSH', 0, 0, 'R', true);
        $this->Cell(2, 4.5, '', 0, 0);
        $this->Cell(23, 4.5, number_format($totalWithVat, 2), 1, 1, 'R');

        // ART. NE PERD. NE TOTAL - total cylinders client has in use
        $this->SetX(10);
        $this->SetFont('Helvetica', 'B', 7);
        $this->Cell(80, 5, 'ART. NE PERD. NE TOTAL :  ' . $this->cylinderCount, 0, 1, 'R');

        $this->Ln(5);
    }

    // ─── Signature: stamp image (left) + "Pranoi" line (right) ───
    private function renderSignature() {
        $startY = $this->GetY();

        // Check if we need a page break
        if ($startY + 40 > 280) {
            $this->AddPage();
            $startY = $this->GetY();
        }

        // Left: Signature + stamp image
        $img = $this->imgDir . 'sign_icon.png';
        if (file_exists($img)) {
            $this->Image($img, 10, $startY, 50, 33);
        }

        // Right: "Pranoi" line
        $this->SetFont('Helvetica', '', 8);
        $this->SetXY(120, $startY + 20);
        $this->Cell(70, 5, 'Pranoi   ______________________________', 0, 1, 'L');
    }
}
