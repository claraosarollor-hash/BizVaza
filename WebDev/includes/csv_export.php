<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }

// Shared helpers for professional CSV report exports (open cleanly in Excel).

// Neutralize Excel formula injection (=, +, -, @, tab, CR).
function csv_safe(string $v): string {
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $v;
    }
    return $v;
}

function csv_row(array $cells): array {
    return array_map(function ($c) { return csv_safe((string)$c); }, $cells);
}

// Download headers with a dated filename, e.g. csv_send('bizvaza-revenue').
function csv_send(string $base): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '_' . date('Y-m-d') . '.csv"');
}

// UTF-8 BOM + report title, generation timestamp and applied date filter.
function csv_title_block($out, string $title, string $from = '', string $to = ''): void {
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$title]);
    fputcsv($out, ['Generated: ' . date('M j, Y g:i A')]);
    if ($from !== '' && $to !== '') {
        fputcsv($out, ['Period: ' . date('M j, Y', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to))]);
    } elseif ($from !== '') {
        fputcsv($out, ['Period: ' . date('M j, Y', strtotime($from)) . ' onwards']);
    } elseif ($to !== '') {
        fputcsv($out, ['Period: up to ' . date('M j, Y', strtotime($to))]);
    } else {
        fputcsv($out, ['Period: All time']);
    }
}

// Blank line + uppercase section heading.
function csv_section($out, string $name): void {
    fputcsv($out, []);
    fputcsv($out, [strtoupper($name)]);
}

// Human-readable period line shared by CSV and print/PDF views.
function report_period_text(string $from = '', string $to = ''): string {
    if ($from !== '' && $to !== '') return date('M j, Y', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to));
    if ($from !== '') return date('M j, Y', strtotime($from)) . ' onwards';
    if ($to !== '') return 'up to ' . date('M j, Y', strtotime($to));
    return 'All time';
}
