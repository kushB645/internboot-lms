<?php
/**
 * Professional PDF generator for certificates using TCPDF with full UTF-8 Unicode TrueType font support.
 */

if (!class_exists('TCPDF')) {
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
}

function pdf_escape(string $text): string
{
    // TCPDF handles UTF-8 natively; preserve Unicode characters (Devanagari, CJK, etc.)
    return trim($text);
}

function output_certificate_pdf(array $data, bool $inline = false): void
{
    global $conn;

    $name = pdf_escape((string)($data['candidate'] ?? 'Candidate Name'));
    $cert = pdf_escape((string)($data['certificate_number'] ?? 'IB-2026-000000'));
    $assessment = pdf_escape((string)($data['assessment'] ?? 'Assessment Test'));
    $levelNum = (int)($data['level'] ?? 1);
    
    $levelNameStr = '';
    if (!empty($data['level_name'])) {
        $levelNameStr = (string)$data['level_name'];
    } elseif (isset($conn) && $conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT level_name FROM levels WHERE level_number = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $levelNum);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) $levelNameStr = (string)$row['level_name'];
        }
    }
    if ($levelNameStr === '') {
        $levelNameStr = "Level {$levelNum}";
    }

    $levelDisplay = "Level {$levelNum} - {$levelNameStr}";

    // A4 Landscape: 842 pt x 595 pt
    $pdf = new TCPDF('L', 'pt', 'A4', true, 'UTF-8', false);

    $pdf->SetCreator('InternBoot Platform');
    $pdf->SetAuthor('InternBoot Assessment Engine');
    $pdf->SetTitle('Certificate of Achievement - ' . $name);
    $pdf->SetSubject('InternBoot Certificate of Achievement');

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddPage('L', 'A4');

    // Add Background Template
    $templatePath = dirname(__DIR__, 3) . '/public/assets/certificate-template.jpg';
    if (file_exists($templatePath)) {
        $pdf->Image($templatePath, 0, 0, 842, 595, 'JPG', '', '', false, 300, '', false, false, 0);
    } else {
        // Fallback to white background if template is missing
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, 842, 595, 'F');
    }

    $pdf->SetTextColor(0, 15, 60);

    // 1. Name
    $fontSize = 20;
    $pdf->SetFont('freesans', 'B', $fontSize);
    
    // Auto-shrink font if name is too wide
    $nameWidth = $pdf->GetStringWidth($name);
    while ($nameWidth > 400 && $fontSize > 12) {
        $fontSize--;
        $pdf->SetFont('freesans', 'B', $fontSize);
        $nameWidth = $pdf->GetStringWidth($name);
    }
    
    // Y approx 226, X approx 300
    $pdf->SetXY(300, 226);
    $pdf->Cell(450, 24, $name, 0, 1, 'L');

    // 2. Domain (Assessment Title)
    $pdf->SetFont('freesans', 'B', 16);
    $domainWidth = $pdf->GetStringWidth($assessment);
    $fontSizeDomain = 16;
    while ($domainWidth > 400 && $fontSizeDomain > 10) {
        $fontSizeDomain--;
        $pdf->SetFont('freesans', 'B', $fontSizeDomain);
        $domainWidth = $pdf->GetStringWidth($assessment);
    }
    
    // Y approx 273
    $pdf->SetXY(300, 273);
    $pdf->Cell(450, 20, $assessment, 0, 1, 'L');

    // 3. Level Achieved
    $pdf->SetFont('freesans', 'B', 16);
    // Y approx 318
    $pdf->SetXY(300, 318);
    $pdf->Cell(450, 20, $levelDisplay, 0, 1, 'L');

    // 4. Certificate ID
    $pdf->SetFont('freesans', 'B', 12);
    // Y approx 488, X approx 220
    $pdf->SetXY(220, 488);
    $pdf->Cell(200, 20, $cert, 0, 1, 'L');

    $pdfContent = $pdf->Output('', 'S');
    $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($data['certificate_number'] ?? 'certificate')) . '.pdf';

    if (!headers_sent()) {
        $disposition = $inline ? 'inline' : 'attachment';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
    }

    echo $pdfContent;
    exit;
}
