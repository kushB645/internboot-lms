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

function output_certificate_pdf(array $data): void
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

    if (stripos($levelNameStr, "Level {$levelNum}") !== false) {
        $levelDisplay = $levelNameStr;
    } else {
        $levelDisplay = "Level {$levelNum} - {$levelNameStr}";
    }
    $percentage = (string)($data['percentage'] ?? '0') . '%';
    $date = (string)($data['issue_date'] ?? date('Y-m-d'));

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

    $pageWidth = 842.0;
    $pageHeight = 595.0;

    // 1. Full-bleed background template image
    // Stretch to fill the entire A4-L page (x=0, y=0, w=842, h=595).
    // The template (1264×848, ratio 1.491) is slightly wider than A4-L (842×595, ratio 1.415).
    // Preserving the aspect ratio via height-fit would crop ~32px off each side of the ornate
    // border, clipping all four corner seals. Full-stretch introduces only a 5.1% horizontal
    // squeeze which is imperceptible on the curved ornamental artwork, so full-bleed wins.
    $templatePath = dirname(__DIR__, 3) . '/public/assets/certificate-template.jpg';
    if (file_exists($templatePath)) {
        $pdf->Image($templatePath, 0, 0, $pageWidth, $pageHeight, '', '', '', false, 300, '', false, false, 0);
    }

    // 2. Overlay dynamic text aligned with template layout
    //
    // The template is 1264x848 px rendered at full A4-L height (595 pt → scale ≈ 0.7018).
    // Pre-printed label bands (measured via pixel scan):
    //   "CANDIDATE NAME" label:  image Y ≈ 370-387  →  PDF Y ≈ 259-272 pt  (centre ~265)
    //   "DOMAIN" label:          image Y ≈ 464-477  →  PDF Y ≈ 326-335 pt  (centre ~330)
    //   "LEVEL" label:           image Y ≈ 550-566  →  PDF Y ≈ 386-397 pt  (centre ~391)
    //   Signature / date area:   image Y ≈ 700-730  →  PDF Y ≈ 491-512 pt  (centre ~502)
    // Dynamic text is centred over the full page width to stay aligned with the centred labels.

    // --- Candidate Name (overlays the "CANDIDATE NAME" placeholder) ---
    $fontSize = 26;
    $pdf->SetFont('freesans', 'B', $fontSize);
    // 2. Overlay dynamic text aligned with template layout
    // New Scorecard Template has underlines at:
    // NAME: X=271.3, Y=249.5 (width=426.8)
    // DOMAIN: X=271.3, Y=286.2
    // LEVEL: X=271.3, Y=322.8
    // Cert ID: X=208.9, Y=509.5 (width=119.2)

    $pdf->SetTextColor(10, 24, 60);

    // NAME
    $pdf->SetFont('freesans', 'B', 24);
    $pdf->SetXY(271.3, 249.5 - 26);
    $pdf->Cell(426.8, 24, $name, 0, 1, 'C');

    // DOMAIN
    $pdf->SetFont('freesans', 'B', 16);
    $pdf->SetXY(271.3, 286.2 - 20);
    $pdf->Cell(426.8, 20, $assessment, 0, 1, 'C');

    // LEVEL
    $pdf->SetFont('freesans', 'B', 14);
    $pdf->SetXY(271.3, 322.8 - 18);
    $pdf->Cell(426.8, 18, $levelDisplay, 0, 1, 'C');

    // CERTIFICATE ID
    $pdf->SetFont('freesans', 'B', 11);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->SetXY(208.9, 509.5 - 14);
    $pdf->Cell(119.2, 14, $cert, 0, 0, 'C');
    
    // QR code rendering has been completely removed as per user request


    // (Date is omitted visually as the new scorecard template does not have a Date field)

    $pdfContent = $pdf->Output('', 'S');
    $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($data['certificate_number'] ?? 'certificate')) . '.pdf';

    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
    }

    echo $pdfContent;
    exit;
}
