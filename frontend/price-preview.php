<?php
/**
 * price-preview.php — AJAX dynamic-price preview.
 *
 * Read-only: computes what tms_dynamic_price() would charge for a given
 * package + date + traveler count, using the exact same function that
 * package-details.php's actual booking handler uses. This is what makes
 * the price shown on screen match the price charged — both paths call the
 * same code, this endpoint just lets the page show it before submission.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
header('Content-Type: application/json');

$pid    = tms_int($_POST['pkgid'] ?? 0, 1);
$from   = tms_clean($_POST['fromdate'] ?? '');
$persons = tms_int($_POST['persons'] ?? 1, 1, 20);

if ($pid === 0) { echo json_encode(['success' => false, 'msg' => 'Invalid package']); exit; }

$q = $dbh->prepare("SELECT PackagePrice FROM tbltourpackages WHERE PackageId=:pid LIMIT 1");
$q->execute([':pid' => $pid]);
$rawPrice = $q->fetchColumn();

if ($rawPrice === false || $rawPrice === null || (float)$rawPrice <= 0) {
    echo json_encode(['success' => false, 'msg' => 'This package has no price set yet.']);
    exit;
}

// Accept dd-mm-yyyy (what the datepicker produces) or Y-m-d.
$fromDateNormalized = null;
if ($from !== '') {
    $d = DateTime::createFromFormat('d-m-Y', $from) ?: DateTime::createFromFormat('Y-m-d', $from);
    if ($d) $fromDateNormalized = $d->format('Y-m-d');
}

$pricing = tms_dynamic_price($dbh, $pid, (float)$rawPrice, $fromDateNormalized);

echo json_encode([
    'success'      => true,
    'unit_price'   => $pricing['final'],
    'total_price'  => $pricing['final'] * $persons,
    'label'        => $pricing['label'],
    'is_adjusted'  => $pricing['label'] !== 'Standard pricing',
    'formatted_unit'  => tms_currency((float)$pricing['final']),
    'formatted_total' => tms_currency((float)($pricing['final'] * $persons)),
]);
