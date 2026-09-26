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

    $levelDisplay = "Level {$levelNum} - {$levelNameStr}";
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

    // 1. Outer Border: Dark Blue (#2563eb -> 37, 99, 235), 2.5 pt
    $pdf->SetLineStyle(['width' => 2.5, 'color' => [37, 99, 235]]);
    $pdf->Rect(25, 25, 792, 545);

    // 2. Inner Border: Thin Slate Line (#94a3b8 -> 148, 163, 184), 0.75 pt
    $pdf->SetLineStyle(['width' => 0.75, 'color' => [148, 163, 184]]);
    $pdf->Rect(31, 31, 780, 533);

    // 3. Top Decorative Header Bar
    $pdf->SetFillColor(37, 99, 235);
    $pdf->Rect(35, 35, 772, 10, 'F');
    $pdf->SetFillColor(217, 119, 6);
    $pdf->Rect(35, 45, 772, 4, 'F');

    // 4. Header Text: Brand & Title
    $pdf->SetFont('freesans', 'B', 14);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetXY(0, 68);
    $pdf->Cell(842, 20, 'INTERNBOOT PLATFORM', 0, 1, 'C');

    $pdf->SetFont('freesans', 'B', 26);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->SetXY(0, 96);
    $pdf->Cell(842, 32, 'CERTIFICATE OF ACHIEVEMENT', 0, 1, 'C');

    // Accent line under title
    $pdf->SetLineStyle(['width' => 1.5, 'color' => [37, 99, 235]]);
    $pdf->Line(260, 134, 582, 134);

    // 5. Certification Statement
    $pdf->SetFont('freesans', '', 12);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(0, 155);
    $pdf->Cell(842, 18, 'THIS IS TO CERTIFY THAT', 0, 1, 'C');

    // Candidate Name (Supports Devanagari, Chinese, Arabic, Latin, etc.)
    $fontSize = 28;
    $pdf->SetFont('freesans', 'B', $fontSize);
    
    // Auto-shrink font if name is too wide (max width ~700)
    $nameWidth = $pdf->GetStringWidth($name);
    while ($nameWidth > 700 && $fontSize > 12) {
        $fontSize--;
        $pdf->SetFont('freesans', 'B', $fontSize);
        $nameWidth = $pdf->GetStringWidth($name);
    }
    
    // If it STILL overflows at 12pt (very unlikely for 100 chars), gracefully truncate
    if ($nameWidth > 700) {
        while ($pdf->GetStringWidth($name . '...') > 700 && mb_strlen($name) > 0) {
            $name = mb_substr($name, 0, -1);
        }
        $name .= '...';
    }

    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetXY(0, 182);
    $pdf->Cell(842, 38, $name, 0, 1, 'C');

    // Accent gold line under candidate name
    $pdf->SetLineStyle(['width' => 1.5, 'color' => [217, 119, 6]]);
    $pdf->Line(280, 226, 562, 226);

    // 6. Assessment Details
    $pdf->SetFont('freesans', '', 12);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(0, 245);
    $pdf->Cell(842, 18, 'has successfully demonstrated proficiency and completed the assessment:', 0, 1, 'C');

    $pdf->SetFont('freesans', 'B', 18);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->SetXY(0, 268);
    $pdf->Cell(842, 26, $assessment, 0, 1, 'C');

    // 7. Qualification Badge Box
    $pdf->SetFillColor(240, 246, 255);
    $pdf->SetLineStyle(['width' => 1.0, 'color' => [192, 216, 252]]);
    $pdf->Rect(180, 312, 482, 68, 'DF');

    $pdf->SetFont('freesans', 'B', 13);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetXY(180, 323);
    $pdf->Cell(482, 20, "Qualification: {$levelDisplay}", 0, 1, 'C');

    $pdf->SetFont('freesans', '', 12);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->SetXY(180, 347);
    $pdf->Cell(482, 20, "Final Evaluation Score: {$percentage}", 0, 1, 'C');

    // 8. Footer Section
    $pdf->SetLineStyle(['width' => 1.0, 'color' => [217, 224, 235]]);
    $pdf->Line(50, 480, 792, 480);

    $pdf->SetFont('freesans', '', 10);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(50, 495);
    $pdf->Cell(220, 16, "Certificate No: {$cert}", 0, 0, 'L');

    $pdf->SetFont('freesans', 'I', 10);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->SetXY(270, 495);
    $pdf->Cell(302, 16, 'Verified by InternBoot Assessment Engine', 0, 0, 'C');

    $pdf->SetFont('freesans', '', 10);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(572, 495);
    $pdf->Cell(220, 16, "Issue Date: {$date}", 0, 0, 'R');

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
