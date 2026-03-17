<?php
// ===== get_app_stats.php =====
// Returns JSON with APP stats pulled live from Google Sheets.
// Called by index.php every 60 s via fetch('get_app_stats.php?t=...')

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Google Sheet (APP data) ──────────────────────────────────────────────────
// Sheet: https://docs.google.com/spreadsheets/d/1WhY3oBDU__XUc9KPCyRF-1_QcDzQ_10HTH3Kk1gMWKk
// Row 1 = headers, data from Row 2 onwards:
//   Col A = Total Amount per APP  (large number, e.g. ~707M)
//   Col B = ABC                   (smaller number, e.g. ~17.9M)
//   Col C = Savings               (₱-prefixed or plain numeric, e.g. ~11.2M)
//
// Dashboard tiles:
//   Total Amount per APP  = SUM(Col A)
//   ABC                   = SUM(Col B)
//   Savings               = SUM(Col C)
//   Total Items           = COUNT of non-empty data rows
// ────────────────────────────────────────────────────────────────────────────

$app_csv_url = 'https://docs.google.com/spreadsheets/d/1WhY3oBDU__XUc9KPCyRF-1_QcDzQ_10HTH3Kk1gMWKk/export?format=csv&gid=0';

$result = [
    'success' => false,
    'total_amount' => 0,
    'abc' => 0,
    'savings' => 0,
    'total_items' => 0,
];

try {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0\r\n",
        ],
    ]);

    $raw = @file_get_contents($app_csv_url, false, $ctx);

    if ($raw !== false && trim($raw) !== '') {

        // Strip ₱, commas, currency symbols — return float
        $toNum = fn($v) => (float) preg_replace('/[^\d.\-]/', '', str_replace(',', '', (string) $v));

        $lines = array_values(
            array_filter(explode("\n", $raw), fn($l) => trim($l) !== '')
        );

        $col_a_sum = 0.0;   // Col A = Total Amount per APP
        $col_b_sum = 0.0;   // Col B = ABC
        $col_c_sum = 0.0;   // Col C = Savings
        $count = 0;

        // Row 0 is the header — start from Row 1
        for ($i = 1; $i < count($lines); $i++) {
            $row = str_getcsv($lines[$i]);

            $a = trim($row[0] ?? '');
            $b = trim($row[1] ?? '');
            $c = trim($row[2] ?? '');

            // Skip only fully empty rows (all columns blank)
            $row_vals = array_map('trim', $row);
            $row_nonempty = array_filter($row_vals, fn($v) => $v !== '');
            if (empty($row_nonempty)) {
                continue;
            }

            $count++;
            $col_a_sum += $toNum($a);
            $col_b_sum += $toNum($b);
            $col_c_sum += $toNum($c);
        }

        $result = [
            'success' => true,
            'total_amount' => round($col_a_sum, 2),  // Total Amount per APP = SUM(Col A)
            'abc' => round($col_b_sum, 2),  // ABC                  = SUM(Col B)
            'savings' => round($col_c_sum, 2),  // Savings              = SUM(Col C)
            'total_items' => $count,
        ];
    }
} catch (Exception $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result);