<?php
declare(strict_types=1);
/*
 * Shared read-only stock center for GClub and Bayclub.
 * UI intentionally reuses the existing Stockist page CSS classes and layout.
 * This file never writes stock transactions or combines club balances.
 */
define('GCLUB_APP', true);
require_once __DIR__ . '/app/bootstrap.php';

function sc_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function sc_qty(mixed $v): string {
    return number_format((float)$v, 2, ',', '.');
}
function sc_date(string $value): string {
    $ts = strtotime($value);
    return $ts === false ? $value : date('d/m/Y', $ts);
}
function sc_stock(PDO $pdo, string $month): array {
    $itemStmt = $pdo->query("SELECT item_key,name,unit,location,minimum_qty FROM gm_stock_items WHERE item_key='chlorine' LIMIT 1");
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException('Item kaporit belum tersedia di database ini.');
    $stmt = $pdo->query("SELECT l.id,l.transaction_date,l.qty_in,l.qty_out,l.movement_type,l.note,u.name AS pic FROM gm_stock_ledger l LEFT JOIN gm_users u ON u.id=l.actor_id WHERE l.item_key='chlorine' ORDER BY l.transaction_date ASC,l.id ASC");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $balance = 0.0; $rows = []; $totalIn = 0.0; $totalOut = 0.0;
    foreach ($all as $r) {
        $in = (float)($r['qty_in'] ?? 0); $out = (float)($r['qty_out'] ?? 0);
        $balance = round($balance + $in - $out, 3);
        if (substr((string)$r['transaction_date'], 0, 7) === $month) {
            $r['balance'] = $balance; $r['qty_in'] = $in; $r['qty_out'] = $out;
            $rows[] = $r; $totalIn += $in; $totalOut += $out;
        }
    }
    return ['item'=>$item, 'balance'=>$balance, 'rows'=>$rows, 'month_in'=>round($totalIn, 3), 'month_out'=>round($totalOut, 3), 'minimum_qty'=>(float)($item['minimum_qty'] ?? 0), 'month'=>$month];
}
function sc_connect_bayclub(): PDO {
    $path = __DIR__ . '/app/stock-center-bayclub-config.php';
    if (!is_readable($path)) throw new RuntimeException('Koneksi database Bayclub belum diatur.');
    if (!defined('GCLUB_STOCK_CENTER_PRIVATE')) define('GCLUB_STOCK_CENTER_PRIVATE', true);
    $config = (static function (string $p): mixed { return require $p; })($path);
    if (!is_array($config)) throw new RuntimeException('Format konfigurasi Bayclub tidak sesuai.');
    foreach (['db_host','db_name','db_user','db_pass'] as $key) {
        if (!isset($config[$key]) || !is_scalar($config[$key]) || (string)$config[$key] === '') throw new RuntimeException('Konfigurasi database Bayclub belum lengkap.');
    }
    $host = (string)$config['db_host']; $port = isset($config['db_port']) ? (int)$config['db_port'] : 3306;
    $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.(string)$config['db_name'].';charset=utf8mb4';
    return new PDO($dsn, (string)$config['db_user'], (string)$config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>8]);
}
function sc_pdo_for_club(string $club): PDO {
    if ($club === 'gclub') return db();
    if ($club === 'bayclub') return sc_connect_bayclub();
    throw new RuntimeException('Klub tidak valid.');
}
function sc_valid_date(string $value): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) throw new InvalidArgumentException('Tanggal laporan tidak valid.');
    return $value;
}
function sc_report_payload(PDO $pdo, string $startRaw, string $endRaw): array {
    $start = sc_valid_date($startRaw); $end = sc_valid_date($endRaw);
    if ($start > $end) throw new InvalidArgumentException('Tanggal awal tidak boleh melewati tanggal akhir.');
    $days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
    if ($days > 3660) throw new InvalidArgumentException('Rentang laporan maksimal 10 tahun.');
    $item = $pdo->query("SELECT item_key,name,unit,location,minimum_qty FROM gm_stock_items WHERE item_key='chlorine' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException('Item kaporit belum tersedia di database ini.');
    $stmt = $pdo->prepare("SELECT l.id,l.transaction_date,l.qty_in,l.qty_out,l.movement_type,l.note,u.name AS pic FROM gm_stock_ledger l LEFT JOIN gm_users u ON u.id=l.actor_id WHERE l.item_key='chlorine' AND l.transaction_date<=? ORDER BY l.transaction_date ASC,l.id ASC");
    $stmt->execute([$end]); $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $balance = 0.0; $opening = 0.0; $rows = []; $totalIn = 0.0; $totalOut = 0.0;
    foreach ($all as $r) {
        $in = (float)($r['qty_in'] ?? 0); $out = (float)($r['qty_out'] ?? 0);
        $balance = round($balance + $in - $out, 3);
        if ((string)$r['transaction_date'] < $start) { $opening = $balance; continue; }
        $r['qty_in'] = $in; $r['qty_out'] = $out; $r['balance'] = $balance;
        $rows[] = $r; $totalIn += $in; $totalOut += $out;
    }
    return ['item'=>$item, 'start_date'=>$start, 'end_date'=>$end, 'opening_balance'=>$opening, 'closing_balance'=>$balance, 'rows'=>$rows, 'total_in'=>round($totalIn,3), 'total_out'=>round($totalOut,3)];
}
function sc_json_error(string $message, int $status=422): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function sc_pdf_text(string $value): string {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    if (function_exists('iconv')) { $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value); if ($encoded !== false) $value = $encoded; }
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $value);
}
function sc_pdf_num(float $value): string { return number_format($value, 2, ',', '.'); }
function sc_pdf_draw_text(float $x, float $y, string $text, float $size=9, bool $bold=false): string {
    return "BT /".($bold?'F2':'F1')." ".$size." Tf 0.08 0.08 0.08 rg ".$x." ".$y." Td (".sc_pdf_text($text).") Tj ET\n";
}
function sc_pdf_draw_text_rgb(float $x, float $y, string $text, float $size, bool $bold, string $rgb): string {
    return "BT /".($bold?'F2':'F1')." ".$size." Tf ".$rgb." rg ".$x." ".$y." Td (".sc_pdf_text($text).") Tj ET\n";
}
function sc_pdf_cell_text(string $text, int $max=34): string {
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) return mb_substr($text, 0, $max-1, 'UTF-8').'…';
    if (strlen($text) > $max) return substr($text, 0, $max-1).'.';
    return $text;
}
function sc_pdf_summary_icon(string $kind, float $x, float $y): string {
    $palette = ['in'=>['0.12 0.50 0.92','in'], 'out'=>['0.84 0.36 0.00','out'], 'left'=>['0.00 0.56 0.56','left']];
    [$stroke,$type] = $palette[$kind] ?? $palette['left']; $c = "q\n$stroke RG 1.8 w\n";
    if ($type === 'in') { $c .= ($x+12)." ".($y+24)." m ".($x+12)." ".($y+6)." l S\n"; $c .= ($x+8)." ".($y+10)." m ".($x+12)." ".($y+6)." l ".($x+16)." ".($y+10)." l S\n"; }
    elseif ($type === 'out') { $c .= ($x+12)." ".($y+4)." m ".($x+12)." ".($y+22)." l S\n"; $c .= ($x+8)." ".($y+18)." m ".($x+12)." ".($y+22)." l ".($x+16)." ".($y+18)." l S\n"; }
    else { $c .= ($x+4)." ".($y+18)." m ".($x+12)." ".($y+23)." l ".($x+20)." ".($y+18)." l ".($x+12)." ".($y+13)." l h S\n"; $c .= ($x+4)." ".($y+18)." m ".($x+4)." ".($y+8)." l ".($x+12)." ".($y+3)." l ".($x+20)." ".($y+8)." l ".($x+20)." ".($y+18)." l S\n"; $c .= ($x+12)." ".($y+13)." m ".($x+12)." ".($y+3)." l S\n"; }
    return $c."Q\n";
}
function sc_pdf_bytes(array $data): string {
    $rows = $data['rows'] ?? []; $chunks = []; $first = array_splice($rows, 0, 19); $chunks[] = $first; while ($rows) $chunks[] = array_splice($rows, 0, 20);
    $pages = []; $pageCount = count($chunks); $totalIn = (float)($data['total_in'] ?? array_sum(array_map(fn($r)=>(float)$r['qty_in'], $data['rows'] ?? []))); $totalOut = (float)($data['total_out'] ?? array_sum(array_map(fn($r)=>(float)$r['qty_out'], $data['rows'] ?? [])));
    $item = $data['item'] ?? []; $itemName = (string)($item['name'] ?? 'Kaporit'); $itemUnit = (string)($item['unit'] ?? 'Kg'); $itemLocation = (string)($item['location'] ?? '');
    $period = sc_date((string)$data['start_date']).' s.d. '.sc_date((string)$data['end_date']); $printed = date('d/m/Y');
    foreach ($chunks as $pageIndex=>$chunk) {
        $c = "1 1 1 rg 0 0 595 842 re f\n";
        $c .= "0.06 0.36 0.70 rg 40 812 105 4 re f\n0.90 0.93 0.96 rg 145 812 320 4 re f\n";
        $c .= sc_pdf_draw_text(474,811,'LAPORAN KAPORIT',9,false); $c .= sc_pdf_draw_text(42,765,'KARTU STOK',31,true);
        $c .= "0.95 0.97 0.99 rg 42 642 511 102 re f\n"; $metaY=712; $labelX=58; $colonX=143; $valueX=154;
        foreach ([['Nama Barang',$itemName],['Satuan Barang',$itemUnit],['Lokasi Barang',$itemLocation],['Periode',$period]] as [$label,$value]) { $c .= sc_pdf_draw_text($labelX,$metaY,$label,10,false); $c .= sc_pdf_draw_text($colonX,$metaY,':',10,false); $c .= sc_pdf_draw_text($valueX,$metaY,(string)$value,10,true); $metaY-=22; }
        $x=42; $top=620; $header1=22; $header2=20; $rowH=22; $widths=[85,72,61,61,61,180];
        $c .= "0.79 0.85 0.92 RG 0.7 w\n"; $headerFill="0.95 0.97 0.99 rg "; $cx=$x; $headerY=$top-$header1-$header2;
        $c .= $headerFill."$x $headerY {$widths[0]} ".($header1+$header2)." re f\n$x $headerY {$widths[0]} ".($header1+$header2)." re S\n"; $c .= sc_pdf_draw_text($x+23,$top-27,'Tanggal',8,true);
        $cx=$x+$widths[0]; $c .= $headerFill."$cx $headerY {$widths[1]} ".($header1+$header2)." re f\n$cx $headerY {$widths[1]} ".($header1+$header2)." re S\n"; $c .= sc_pdf_draw_text($cx+28,$top-27,'PIC',8,true); $cx+=$widths[1];
        $barangW=$widths[2]+$widths[3]+$widths[4]; $c .= $headerFill."$cx ".($top-$header1)." $barangW $header1 re f\n$cx ".($top-$header1)." $barangW $header1 re S\n"; $c .= sc_pdf_draw_text($cx+57,$top-15,'Barang',8,true);
        $subY=$headerY; foreach (['Masuk','Keluar','Sisa'] as $i=>$label) { $w=$widths[$i+2]; $c .= $headerFill."$cx $subY $w $header2 re f\n$cx $subY $w $header2 re S\n"; $c .= sc_pdf_draw_text($cx+17,$subY+7,$label,8,true); $cx+=$w; }
        $c .= $headerFill."$cx $headerY {$widths[5]} ".($header1+$header2)." re f\n$cx $headerY {$widths[5]} ".($header1+$header2)." re S\n"; $c .= sc_pdf_draw_text($cx+52,$top-27,'Keterangan',8,true); $rowY=$headerY;
        $drawRow = function(array $values) use (&$c,&$rowY,$rowH,$x,$widths): void { $rowY-=$rowH; $cx=$x; foreach ($widths as $i=>$w) { $c .= "0.83 0.88 0.93 RG 0.55 w $cx $rowY $w $rowH re S\n"; $text=(string)$values[$i]; $fontSize=$i===5?7.0:7.5; $max=$i===5?41:18; $c .= sc_pdf_draw_text($cx+4,$rowY+7,sc_pdf_cell_text($text,$max),$fontSize,false); $cx+=$w; } };
        if ($pageIndex===0) $drawRow([sc_date((string)$data['start_date']),'Sistem','','',sc_pdf_num((float)$data['opening_balance']),'Saldo Awal Periode']);
        foreach ($chunk as $row) $drawRow([sc_date((string)$row['transaction_date']),(string)($row['pic'] ?? 'Sistem'),(float)$row['qty_in']>0?sc_pdf_num((float)$row['qty_in']):'',(float)$row['qty_out']>0?sc_pdf_num((float)$row['qty_out']):'',sc_pdf_num((float)$row['balance']),(string)($row['note'] ?? '')]);
        if ($pageIndex===$pageCount-1) {
            $summaryY=max(86,$rowY-66); $cards=[[42,'0.12 0.50 0.92','in','Total Masuk',sc_pdf_num($totalIn).' '.$itemUnit],[217,'0.84 0.36 0.00','out','Total Keluar',sc_pdf_num($totalOut).' '.$itemUnit],[392,'0.00 0.56 0.56','left','Sisa',sc_pdf_num((float)$data['closing_balance']).' '.$itemUnit]];
            $c .= "0.88 0.91 0.95 RG 0.7 w 207 ".($summaryY+3)." m 207 ".($summaryY+41)." l S\n0.88 0.91 0.95 RG 0.7 w 382 ".($summaryY+3)." m 382 ".($summaryY+41)." l S\n";
            foreach ($cards as [$sx,$rgb,$kind,$label,$value]) { $c .= sc_pdf_summary_icon($kind,$sx+5,$summaryY+9); $c .= sc_pdf_draw_text_rgb($sx+37,$summaryY+27,$label,8,false,'0.42 0.48 0.58'); $c .= sc_pdf_draw_text_rgb($sx+37,$summaryY+11,$value,13,true,$rgb); }
        }
        $c .= "0.77 0.83 0.90 RG 1 w 40 54 m 555 54 l S\n"; $c .= sc_pdf_draw_text(42,34,'KARTU STOK',9,false); $c .= sc_pdf_draw_text(452,34,'Halaman '.($pageIndex+1).' dari '.$pageCount,9,false); $c .= sc_pdf_draw_text(448,20,'Dicetak pada '.$printed,9,false); $pages[]=$c;
    }
    $objects=[]; $pageObjectCount=count($pages)*2; $fontRegular=3+$pageObjectCount; $fontBold=$fontRegular+1; $kids=[]; $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
    foreach ($pages as $i=>$stream) { $pageId=3+$i*2; $contentId=$pageId+1; $kids[]="$pageId 0 R"; $objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 $fontRegular 0 R /F2 $fontBold 0 R >> >> /Contents $contentId 0 R >>"; $objects[$contentId]="<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream"; }
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($pages).' >>'; $objects[$fontRegular]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'; $objects[$fontBold]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'; ksort($objects);
    $pdf="%PDF-1.4\n%âãÏÓ\n"; $offsets=[0]; foreach ($objects as $id=>$object) { $offsets[$id]=strlen($pdf); $pdf.="$id 0 obj\n$object\nendobj\n"; } $xref=strlen($pdf); $size=$fontBold+1; $pdf.="xref\n0 $size\n0000000000 65535 f \n"; for ($id=1;$id<$size;$id++) $pdf.=sprintf('%010d 00000 n ',(int)($offsets[$id]??0))."\n"; $pdf.="trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF"; return $pdf;
}

// A separate Stock Center login gate is required even when the user already has a GClub app session.
// The credentials are verified through the existing GClub login endpoint (api.php?action=login).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['sc_action'] ?? '') === 'authorize') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    try {
        csrf();
        $postedNonce = (string)($_POST['sc_nonce'] ?? '');
        $knownNonce = (string)($_SESSION['stock_center_login_nonce'] ?? '');
        if ($postedNonce === '' || $knownNonce === '' || !hash_equals($knownNonce, $postedNonce)) {
            fail('Sesi login pusat stok berubah. Muat ulang halaman lalu masuk lagi.', 419);
        }
        $authUser = user();
        if (!$authUser) fail('Login GClub tidak ditemukan. Silakan masuk lagi.', 401);
        $authIsStockist = function_exists('is_stockist_account') && is_stockist_account($authUser);
        $authIsSupervisor = (($authUser['role'] ?? '') === 'supervisor');
        if (!$authIsSupervisor && !$authIsStockist) fail('Akun ini tidak memiliki akses Pusat Stok. Gunakan akun Supervisor atau Stockist GClub.', 403);
        if (!empty($authUser['must_change']) || !empty($authUser['needs_pin'])) fail('Akun perlu menyelesaikan pengaturan PIN di aplikasi GClub terlebih dahulu.', 403);
        $_SESSION['stock_center_auth_uid'] = (int)$authUser['id'];
        $_SESSION['stock_center_auth_at'] = time();
        $_SESSION['stock_center_record_location'] = true;
        unset($_SESSION['stock_center_login_nonce']);
        echo json_encode(['ok'=>true,'data'=>['message'=>'Akses Pusat Stok berhasil dibuka.']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        $status = ($e instanceof AppError && $e->getCode() > 0) ? (int)$e->getCode() : 500;
        http_response_code($status);
        if (!($e instanceof AppError)) error_log('Stock center authorization failed: '.get_class($e).' '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>$e instanceof AppError ? $e->getMessage() : 'Login Pusat Stok belum dapat diselesaikan. Coba lagi.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

if (isset($_GET['sc_logout'])) {
    unset($_SESSION['stock_center_auth_uid'], $_SESSION['stock_center_auth_at'], $_SESSION['stock_center_record_location'], $_SESSION['stock_center_login_nonce']);
    header('Location: stock-center.php');
    exit;
}

$viewer = user();
$authorizedUid = (int)($_SESSION['stock_center_auth_uid'] ?? 0);
$authorizedAt = (int)($_SESSION['stock_center_auth_at'] ?? 0);
$gateValid = $viewer && $authorizedUid === (int)($viewer['id'] ?? 0) && $authorizedAt > 0 && (time() - $authorizedAt) <= 43200;
if (!$gateValid) {
    unset($_SESSION['stock_center_auth_uid'], $_SESSION['stock_center_auth_at'], $_SESSION['stock_center_record_location']);
    $loginNonce = bin2hex(random_bytes(32));
    $_SESSION['stock_center_login_nonce'] = $loginNonce;
    $loginCsrf = (string)($_SESSION['csrf'] ?? '');
    $loginVersion = rawurlencode(defined('GCLUB_DEPLOY_ID') ? (string)GCLUB_DEPLOY_ID : 'stock-center-login-v6');
    $existingUsername = strtolower((string)($viewer['username'] ?? ''));
    $existingIsStockist = $viewer && function_exists('is_stockist_account') && is_stockist_account($viewer);
    $existingIsSupervisor = $viewer && (($viewer['role'] ?? '') === 'supervisor');
    $existingRoleNotice = ($viewer && !$existingIsStockist && !$existingIsSupervisor)
        ? 'Akun yang sedang aktif bukan Supervisor atau Stockist. Silakan masuk dengan akun yang berwenang.' : '';
    header('Cache-Control: no-store, private');
    ?><!doctype html>
<html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#087e8b">
<title>Masuk · Pusat Stok Kaporit | GClub</title>
<link rel="icon" type="image/png" href="assets/brand/gclub-logo.png?v=<?=$loginVersion?>">
<link rel="stylesheet" href="assets/style.css?v=<?=$loginVersion?>"><link rel="stylesheet" href="assets/modern.css?v=<?=$loginVersion?>"><link rel="stylesheet" href="assets/branding.css?v=<?=$loginVersion?>">

<style id="sc-login-dual-logo-v1">
/* SC_LOGIN_DUAL_LOGO_V3: logo lebih kecil, ukuran elemen sama, dan terpusat */
.auth .sc-login-logos{display:flex;align-items:center;justify-content:center;gap:12px;width:100%;max-width:100%;margin:0 auto 1rem}
.auth .sc-login-logos .auth-logo{display:block;margin:0;flex:0 0 4.5rem;width:4.5rem;height:4.5rem;max-width:calc(50% - 12px);object-fit:contain;background:transparent}
.auth .sc-login-logos .auth-logo:first-child{border-radius:9px}
.auth .sc-login-logos .sc-login-logo-bayclub{width:4.5rem;height:4.5rem;min-width:0;object-fit:contain}
.auth .sc-login-logos .sc-login-logo-divider{flex:0 0 1px;width:1px;height:2.7rem;background:#dce4eb}
@media (max-width:360px){.auth .sc-login-logos{gap:9px}.auth .sc-login-logos .auth-logo,.auth .sc-login-logos .auth-logo:first-child,.auth .sc-login-logos .sc-login-logo-bayclub{flex-basis:4rem;width:4rem;height:4rem}}
</style>
</head><body class="login-screen">
<header class="top"><a class="brand" href="./" aria-label="GClub Maintenance"><img class="brand-logo" src="assets/brand/gclub-logo.png?v=<?=$loginVersion?>" width="64" height="64" alt="Logo GClub"><strong>GClub</strong><span>Maintenance</span></a></header>
<div id="network" role="status" class="network">Koneksi aman diperlukan</div>
<main class="wrap"><section class="card auth">
<div class="sc-login-logos" role="group" aria-label="Logo GClub dan Bayclub"><img class="auth-logo" src="assets/brand/gclub-logo.png?v=<?=$loginVersion?>" alt="Logo GClub" width="72" height="72"><span class="sc-login-logo-divider" aria-hidden="true"></span><img class="auth-logo sc-login-logo-bayclub" src="assets/brand/logo-bayclub-login.png?v=bayclub-logo-20261022-v3" alt="Logo Bayclub" width="72" height="72"></div>
<h1>Masuk ke Pusat Stok Kaporit</h1>
<form id="stock-center-login-form" data-stock-center-login-v5="1" autocomplete="on">
<input type="hidden" name="csrf" value="<?=sc_h($loginCsrf)?>">
<input type="hidden" name="sc_nonce" value="<?=sc_h($loginNonce)?>">
<div class="field"><label for="sc-login-username">Username</label><input id="sc-login-username" name="username" type="text" required autocomplete="username" autocapitalize="none" maxlength="60"></div>
<div class="field"><label for="sc-login-password">PIN / Password</label><input id="sc-login-password" name="password" type="password" required autocomplete="current-password" maxlength="72"></div>
<p class="form-error" id="stock-center-login-error" role="alert"><?=sc_h($existingRoleNotice)?></p>
<button type="submit" class="btn full">Masuk</button>
</form>
</section></main>
<script>
'use strict';
const scLoginForm=document.getElementById('stock-center-login-form');
const scLoginError=document.getElementById('stock-center-login-error');
const scLoginButton=scLoginForm.querySelector('[type="submit"]');
function scEncodeForm(formData){const params=new URLSearchParams();formData.forEach((value,key)=>params.append(key,String(value)));return params;}
async function scReadJson(response){const text=await response.text();let body;try{body=JSON.parse(text);}catch{throw new Error('Server mengembalikan respons yang tidak dikenali (HTTP '+response.status+'). Coba muat ulang halaman.');}if(!response.ok||!body.ok)throw new Error(body.error||'Login tidak berhasil.');return body.data;}
scLoginForm.addEventListener('submit',async event=>{
 event.preventDefault();if(scLoginButton.disabled)return;scLoginError.textContent='';scLoginButton.disabled=true;scLoginButton.textContent='Memeriksa akun…';
 try{
  // InfinityFree currently blocks multipart/form-data POSTs at the edge. Login has no files,
  // so send URL-encoded fields that PHP still exposes through $_POST.
  const loginData=scEncodeForm(new FormData(scLoginForm));
  const loginResponse=await fetch('api.php?action=login',{method:'POST',body:loginData,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}});
  await scReadJson(loginResponse);
  const bootResponse=await fetch('api.php?action=bootstrap',{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
  const boot=await scReadJson(bootResponse);
  const user=boot.user||null;
  const username=String(user?.username||'').toLowerCase();
  const isStockist=username==='stockist.gclub';
  const isSupervisor=String(user?.role||'')==='supervisor';
  scLoginForm.elements.csrf.value=String(boot.csrf||'');
  if(!user)throw new Error('Akun tidak terbaca setelah login. Coba lagi.');
  if(!isSupervisor&&!isStockist)throw new Error('Akun berhasil masuk, tetapi bukan akun Supervisor atau Stockist. Gunakan akun yang berwenang.');
  if(user.must_change||user.needs_pin)throw new Error('Akun ini perlu membuat atau memperbarui PIN di aplikasi GClub terlebih dahulu. Buka GClub, selesaikan pengaturan PIN, lalu coba lagi.');
  scLoginButton.textContent='Membuka Pusat Stok…';
  const authForm=new URLSearchParams();authForm.append('sc_action','authorize');authForm.append('sc_nonce',scLoginForm.elements.sc_nonce.value);authForm.append('csrf',String(boot.csrf||''));
  const authResponse=await fetch('stock-center.php',{method:'POST',body:authForm,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}});
  await scReadJson(authResponse);
  const month=new Date().toISOString().slice(0,7);
  window.location.replace('stock-center.php?club=gclub&month='+encodeURIComponent(month)+'&_fresh='+Date.now());
 }catch(error){scLoginError.textContent=error.message||'Login tidak berhasil. Coba lagi.';scLoginButton.disabled=false;scLoginButton.textContent='Masuk';}
});
</script></body></html><?php
    exit;
}

$username = strtolower((string)($viewer['username'] ?? ''));
$isStockist = function_exists('is_stockist_account') && is_stockist_account($viewer);
$isSupervisor = (($viewer['role'] ?? '') === 'supervisor');
if (!$isSupervisor && !$isStockist) {
    unset($_SESSION['stock_center_auth_uid'], $_SESSION['stock_center_auth_at']);
    http_response_code(403);
    ?><!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Akses terbatas</title><body style="font:16px/1.55 system-ui,sans-serif;max-width:620px;margin:12vh auto;padding:24px;color:#183044"><h1>Akses terbatas</h1><p>Pusat Stok Kaporit hanya dapat dibuka oleh Supervisor atau Stockist.</p><p><a href="stock-center.php">Kembali ke login Pusat Stok</a></p></body></html><?php exit;
}
if (!empty($viewer['must_change']) || !empty($viewer['needs_pin'])) { unset($_SESSION['stock_center_auth_uid'], $_SESSION['stock_center_auth_at']); http_response_code(403); ?><!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Perbarui PIN</title><body style="font:16px/1.55 system-ui,sans-serif;max-width:620px;margin:12vh auto;padding:24px;color:#183044"><h1>Perbarui PIN terlebih dahulu</h1><p>Selesaikan pengaturan PIN di GClub sebelum membuka pusat stok.</p><p><a href="./">Buka GClub</a></p></body></html><?php exit; }

$club = strtolower((string)($_GET['club'] ?? 'gclub')); if (!in_array($club, ['gclub','bayclub'], true)) $club='gclub';
$month = (string)($_GET['month'] ?? date('Y-m')); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month=date('Y-m');
$clubLabel = $club === 'gclub' ? 'GClub' : 'Bayclub';
/* STOCK_CENTER_MOBILE_FILL_PLUS_V1: Bayclub writer mirrors the existing GClub stock_entry action. */
function sc_save_bayclub_stock_entry(array $viewer): array {
    $type = trim((string)($_POST['movement_type'] ?? ''));
    if (!in_array($type, ['opening','purchase','correction_in','correction_out'], true)) throw new InvalidArgumentException('Jenis transaksi stok tidak valid.');
    $raw = str_replace(',', '.', trim((string)($_POST['amount'] ?? '')));
    if (strlen($raw) > 30 || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw <= 0) throw new InvalidArgumentException('Jumlah stok harus lebih dari nol.');
    $amount = round((float)$raw, 3);
    $dateRaw = trim((string)($_POST['transaction_date'] ?? ''));
    $date = sc_valid_date($dateRaw);
    $note = trim((string)($_POST['note'] ?? ''));
    $name = trim((string)($_POST['item_name'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $minimumRaw = str_replace(',', '.', trim((string)($_POST['minimum_qty'] ?? '')));
    if ($note === '' || strlen($note) > 500) throw new InvalidArgumentException('Lengkapi keterangan stok (maksimal 500 karakter).');
    if ($name === '' || strlen($name) > 160) throw new InvalidArgumentException('Nama barang wajib diisi (maksimal 160 karakter).');
    if (strlen($location) > 200) throw new InvalidArgumentException('Lokasi barang terlalu panjang (maksimal 200 karakter).');
    if (strlen($minimumRaw) > 30 || !is_numeric($minimumRaw) || !is_finite((float)$minimumRaw) || (float)$minimumRaw < 0) throw new InvalidArgumentException('Batas minimum stok tidak valid.');
    $minimum = round((float)$minimumRaw, 3);

    $pdo = sc_pdo_for_club('bayclub');
    $username = trim((string)($viewer['username'] ?? ''));
    $displayName = trim((string)($viewer['name'] ?? ''));
    $actorId = 0;
    if ($username !== '') {
        $q = $pdo->prepare('SELECT id FROM gm_users WHERE LOWER(username)=LOWER(?) AND active=1 LIMIT 2');
        $q->execute([$username]); $matches = $q->fetchAll(PDO::FETCH_COLUMN);
        if (count($matches) === 1) $actorId = (int)$matches[0];
        elseif (count($matches) > 1) throw new DomainException('Akun PIC di Bayclub tidak unik. Transaksi belum disimpan.');
    }
    if ($actorId <= 0 && $displayName !== '') {
        $q = $pdo->prepare('SELECT id FROM gm_users WHERE LOWER(name)=LOWER(?) AND active=1 LIMIT 2');
        $q->execute([$displayName]); $matches = $q->fetchAll(PDO::FETCH_COLUMN);
        if (count($matches) === 1) $actorId = (int)$matches[0];
        elseif (count($matches) > 1) throw new DomainException('Ada lebih dari satu akun dengan nama PIC yang sama di Bayclub. Samakan username akun Supervisor pada kedua klub terlebih dahulu.');
    }
    if ($actorId <= 0) throw new DomainException('Akun Supervisor yang login tidak ditemukan sebagai PIC aktif di database Bayclub. Samakan username akun Supervisor di Bayclub terlebih dahulu; transaksi belum disimpan.');

    $in = in_array($type, ['opening','purchase','correction_in'], true) ? $amount : 0.0;
    $out = $type === 'correction_out' ? $amount : 0.0;
    $stamp = function_exists('now') ? now() : date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        // Same serialization lock and stock-item update used by maintenance/app/actions.php::stock_entry.
        $pdo->query('SELECT id FROM gm_settings WHERE id=1 FOR UPDATE')->fetchColumn();
        $item = $pdo->query("SELECT item_key FROM gm_stock_items WHERE item_key='chlorine' FOR UPDATE")->fetchColumn();
        if (!$item) throw new DomainException('Data barang kaporit belum tersedia di Bayclub. Transaksi belum disimpan.');
        $balance = (float)$pdo->query("SELECT COALESCE(SUM(qty_in-qty_out),0) FROM gm_stock_ledger WHERE item_key='chlorine'")->fetchColumn();
        if ($out > $balance + 0.0001) throw new DomainException('Koreksi keluar melebihi stok Bayclub yang tersedia ('.number_format($balance, 3, ',', '.').' Kg).');
        $q = $pdo->prepare("UPDATE gm_stock_items SET name=?,location=?,minimum_qty=?,updated_at=? WHERE item_key='chlorine'");
        $q->execute([$name, $location, $minimum, $stamp]);
        $q = $pdo->prepare("INSERT INTO gm_stock_ledger (item_key,transaction_date,occurred_at,actor_id,movement_type,qty_in,qty_out,note) VALUES ('chlorine',?,?,?,?,?,?,?)");
        $q->execute([$date, $stamp, $actorId, $type, $in, $out, $note]);
        $ledgerId = (int)$pdo->lastInsertId();
        try {
            $payload = json_encode(['type'=>$type,'qty_in'=>$in,'qty_out'=>$out,'date'=>$date,'note'=>$note,'source'=>'stock_center'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $audit = $pdo->prepare('INSERT INTO gm_audit (actor_id,action,entity_id,payload,created_at) VALUES (?,?,?,?,?)');
            $audit->execute([$actorId, 'chlorine_stock_entry', $ledgerId, $payload, $stamp]);
        } catch (Throwable $auditError) {
            // Match the existing app behavior: audit write is best-effort and must not roll back the stock transaction.
            error_log('Stock Center Bayclub non-fatal audit failure: '.get_class($auditError).' '.$auditError->getMessage());
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['message'=>'Transaksi stok kaporit tersimpan.','transaction_date'=>$date];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['sc_action'] ?? '') === 'bayclub_stock_entry') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    try {
        if (!$isSupervisor) sc_json_error('Khusus Supervisor.', 403);
        csrf();
        if ((string)($_POST['club'] ?? '') !== 'bayclub') throw new InvalidArgumentException('Pilihan klub tidak cocok. Muat ulang halaman lalu coba lagi.');
        $saved = sc_save_bayclub_stock_entry($viewer);
        echo json_encode(['ok'=>true,'data'=>$saved], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (AppError $e) {
        sc_json_error($e->getMessage(), $e->getCode() > 0 ? (int)$e->getCode() : 422);
    } catch (InvalidArgumentException $e) {
        sc_json_error($e->getMessage(), 422);
    } catch (DomainException $e) {
        sc_json_error($e->getMessage(), 409);
    } catch (Throwable $e) {
        error_log('Stock Center Bayclub stock entry failed: '.get_class($e).' '.$e->getMessage());
        sc_json_error('Transaksi belum dapat dipastikan tersimpan. Periksa Kartu Stok sebelum mencoba ulang; koneksi atau struktur database mungkin bermasalah.', 503);
    }
    exit;
}
$scAction = (string)($_GET['sc_action'] ?? '');
if (in_array($scAction, ['report','pdf'], true)) {
    try {
        $reportPdo = sc_pdo_for_club($club);
        $payload = sc_report_payload($reportPdo, (string)($_GET['start_date'] ?? ''), (string)($_GET['end_date'] ?? ''));
        if ($scAction === 'report') { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode(['ok'=>true,'data'=>$payload], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
        $pdf = sc_pdf_bytes($payload); $filename='Kartu-Stok-Kaporit-'.$clubLabel.'-'.sc_date($payload['start_date']).'-'.sc_date($payload['end_date']).'.pdf';
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '-', str_replace('/','-',$filename)) ?? 'Kartu-Stok-Kaporit.pdf';
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Content-Length: '.strlen($pdf)); header('Cache-Control: no-store'); echo $pdf; exit;
    } catch (InvalidArgumentException $e) { sc_json_error($e->getMessage(),422); }
      catch (Throwable $e) { error_log('Stock center report failed ['.$club.']: '.get_class($e).' '.$e->getMessage()); sc_json_error('Laporan tidak dapat dibuat. Periksa koneksi dan data stok untuk klub yang dipilih.',503); }
}

$data = null; $loadError = '';
try { $pdo = sc_pdo_for_club($club); $data = sc_stock($pdo, $month); $pdo = null; }
catch (Throwable $e) { error_log('Stock center read failure ['.$club.']: '.get_class($e).' '.$e->getMessage()); $loadError = $club === 'bayclub' ? 'Data stok Bayclub belum dapat dibaca. Supervisor perlu memeriksa Pengaturan Koneksi Database Bayclub.' : 'Data stok GClub belum dapat dibaca. Periksa koneksi database atau struktur kartu stok.'; }
$assetVersion = rawurlencode(defined('GCLUB_DEPLOY_ID') ? (string)GCLUB_DEPLOY_ID : 'stock-center-login-v6');
$monthStart = $month.'-01'; $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
$nowLabel = date('H.i');
$csrfToken = (string)($_SESSION['csrf'] ?? '');
$recordCenterLocation = !empty($_SESSION['stock_center_record_location']);
unset($_SESSION['stock_center_record_location']);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#087e8b"><title>Kartu Stok Kaporit | GClub</title>
<link rel="icon" type="image/png" href="assets/brand/gclub-logo.png?v=<?=$assetVersion?>">
<link rel="stylesheet" href="assets/style.css?v=<?=$assetVersion?>"><link rel="stylesheet" href="assets/modern.css?v=<?=$assetVersion?>"><link rel="stylesheet" href="assets/supervisor.css?v=<?=$assetVersion?>"><link rel="stylesheet" href="assets/branding.css?v=<?=$assetVersion?>"><link rel="stylesheet" href="assets/stock.css?v=<?=$assetVersion?>"><link rel="stylesheet" href="assets/stock-preview.css?v=<?=$assetVersion?>">
<style>
body.stock-center-page{overflow-x:hidden}.stock-center-page .top{display:flex}.stock-center-page .network{display:block}.stock-center-page .stock-ledger-screen{grid-template-rows:auto auto auto minmax(0,1fr) auto}.stock-club-tabs{display:flex;gap:6px;padding:5px;border:1px solid #dce5ed;background:#eaf0f4;border-radius:12px;margin:0 0 4px}.stock-club-tabs a{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:11px 12px;border-radius:9px;color:#607087;text-decoration:none;font-weight:650}.stock-club-tabs a.active{background:#fff;color:#087e8b;box-shadow:0 2px 7px #102b4612}.stock-club-tabs a:focus-visible{outline:2px solid #087e8b;outline-offset:2px}.stock-center-error{border:1px solid #f2c8c2;background:#fff5f3;color:#8f2c21;border-radius:13px;padding:16px;margin:10px 0}.stock-center-error p{margin:0 0 8px}.stock-center-error a{color:#087e8b;font-weight:700}.stock-center-notice{font-size:12px;color:#6c7d8f;margin:10px 2px 0}.stock-center-modal-help{color:#68788c;font-size:13px;line-height:1.55}.stock-center-modal-form .split{display:grid;grid-template-columns:1fr 1fr;gap:12px}.stock-center-modal-form input{width:100%}.stock-center-modal-form .form-error{color:#b42318;min-height:18px}.stock-report-preview{max-height:min(65vh,760px)}.stock-center-print-button{cursor:pointer}.stock-center-page .stock-ledger-table-wrap{overflow-x:hidden}.stock-center-page .stockist-logout-button{cursor:pointer}.stock-center-supervisor-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:8px 0 10px}.stock-center-supervisor-tools .role-badge{margin-right:auto}.stock-center-supervisor-tools .btn{min-height:36px;padding:8px 11px;font-size:12px}.stock-club-tabs .club-indicator{width:8px;height:8px;border-radius:50%;background:currentColor;opacity:.75}@media(max-width:430px){.stock-club-tabs a{font-size:13px;min-height:42px;padding:10px 6px}.stock-center-modal-form .split{grid-template-columns:1fr}}@media(min-width:1000px){body.stock-center-page.stockist-web-layout .stock-ledger-screen{height:calc(100dvh - 108px);max-width:1180px;margin:0 auto}.stock-center-page .stock-ledger-hero{border-radius:24px;margin-left:0;margin-right:0}.stock-center-page #app.wrap{padding-top:58px}}
/* STOCK_CENTER_MOBILE_NATIVE_V1: mobile layout follows the GClub Stockist shell. */
@media (max-width: 999px) {
  body.stock-center-page.stock-ledger-active {
    height: 100dvh !important;
    min-height: 0 !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
  }
  body.stock-center-page #app.wrap {
    width: 100% !important;
    max-width: 540px !important;
    margin: 0 auto !important;
    padding: 14px 15px 104px !important;
  }
  body.stock-center-page .stock-ledger-screen {
    display: grid !important;
    grid-template-rows: auto auto auto minmax(0, 1fr) auto !important;
    align-items: stretch !important;
    gap: 12px !important;
    width: 100% !important;
    max-width: 540px !important;
    height: calc(100dvh - 230px) !important;
    min-height: 420px !important;
    margin: 0 auto !important;
    overflow: hidden !important;
  }
  body.stock-center-page .stock-ledger-screen:has(.stock-center-supervisor-tools) {
    grid-template-rows: auto auto auto auto minmax(0, 1fr) auto !important;
    height: calc(100dvh - 270px) !important;
    min-height: 420px !important;
  }
  body.stock-center-page .stock-ledger-hero {
    width: 100% !important;
    min-height: 84px;
    margin: 0 !important;
    padding: 16px 58px 16px 15px !important;
    border-radius: 18px !important;
    gap: 11px !important;
  }
  body.stock-center-page .stock-ledger-hero > span {
    width: 44px !important;
    height: 44px !important;
    flex: 0 0 44px !important;
    border-radius: 13px !important;
  }
  body.stock-center-page .stock-ledger-hero > span svg { width: 27px !important; height: 27px !important; }
  body.stock-center-page .stock-ledger-hero h1 { font-size: 20px !important; line-height: 1.2 !important; }
  body.stock-center-page .stock-ledger-hero p { margin-top: 3px !important; font-size: 12px !important; }
  body.stock-center-page .stockist-logout-button {
    top: 11px !important;
    right: 11px !important;
    width: 36px !important;
    height: 36px !important;
    min-height: 36px !important;
    padding: 0 !important;
    border-radius: 11px !important;
  }
  body.stock-center-page .stockist-logout-button span { display: none !important; }

  /* GClub/Bayclub selector matches the native segmented-tab pattern. */
  body.stock-center-page .stock-club-tabs {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
    gap: 4px !important;
    margin: 0 !important;
    padding: 4px !important;
    border-radius: 11px !important;
  }
  body.stock-center-page .stock-club-tabs a {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    min-width: 0 !important;
    min-height: 42px !important;
    padding: 9px 5px !important;
    border-radius: 8px !important;
    font-size: 12px !important;
    line-height: 1.2 !important;
    text-align: center !important;
    overflow-wrap: anywhere;
  }
  body.stock-center-page .stock-club-tabs .club-indicator { width: 7px !important; height: 7px !important; flex: 0 0 7px !important; }

  body.stock-center-page .stock-center-supervisor-tools {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
    align-items: stretch !important;
    gap: 7px !important;
    margin: 0 !important;
  }
  body.stock-center-page .stock-center-supervisor-tools .role-badge { display: none !important; }
  body.stock-center-page .stock-center-supervisor-tools .btn {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 0 !important;
    min-height: 42px !important;
    padding: 8px 7px !important;
    font-size: 11px !important;
    line-height: 1.25 !important;
    white-space: normal !important;
    text-align: center !important;
  }

  body.stock-center-page .stock-ledger-actions {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) !important;
    gap: 7px !important;
    margin: 0 !important;
  }
  body.stock-center-page .stock-month-heading { min-height: 38px !important; gap: 8px !important; }
  body.stock-center-page .stock-month-heading > strong { font-size: 14px !important; line-height: 1.25 !important; }
  body.stock-center-page #stock-center-month-form { display: block !important; width: 100% !important; margin: 0 !important; }
  body.stock-center-page #stock-center-month {
    display: block !important;
    width: 100% !important;
    min-width: 0 !important;
    min-height: 43px !important;
    margin: 0 !important;
    padding: 9px 11px !important;
    border: 1px solid #d8e2ec !important;
    border-radius: 11px !important;
    background: #fff !important;
    font: inherit !important;
    font-size: 14px !important;
  }

  /* Use the same stock table and summary rules as the regular GClub Stockist page. */
  body.stock-center-page .stock-ledger-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
    height: 100% !important;
    min-height: 0 !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
    border-radius: 16px !important;
    box-shadow: 0 5px 15px #17345108 !important;
  }
  body.stock-center-page .stock-ledger-table {
    width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    font-size: 11px !important;
  }
  /* Keep the default Stockist font, cell rhythm, and colored quantity columns. */
  body.stock-center-page .stock-ledger-table th {
    position: sticky !important;
    top: 0 !important;
    z-index: 2 !important;
    padding: 14px 3px !important;
    font-size: 9px !important;
    letter-spacing: normal !important;
    white-space: nowrap !important;
  }
  body.stock-center-page .stock-ledger-table td {
    padding: 13px 3px !important;
    font-size: 11px !important;
    line-height: 1.3 !important;
    text-align: center !important;
    white-space: nowrap !important;
  }
  body.stock-center-page .stock-ledger-table td strong { font-size: 10px !important; }
  body.stock-center-page .stock-ledger-table th:first-child,
  body.stock-center-page .stock-ledger-table td:first-child { width: 25% !important; }
  body.stock-center-page .stock-ledger-table th:nth-child(2),
  body.stock-center-page .stock-ledger-table td:nth-child(2) { width: 17% !important; }
  body.stock-center-page .stock-ledger-table th:nth-child(3),
  body.stock-center-page .stock-ledger-table th:nth-child(4),
  body.stock-center-page .stock-ledger-table th:nth-child(5),
  body.stock-center-page .stock-ledger-table td:nth-child(3),
  body.stock-center-page .stock-ledger-table td:nth-child(4),
  body.stock-center-page .stock-ledger-table td:nth-child(5) { width: 19.33% !important; }
  body.stock-center-page .stock-ledger-table .stock-empty-cell { padding: 30px 10px !important; white-space: normal !important; }
  body.stock-center-page .stock-ledger-summary { margin: 0 0 4px !important; }
  body.stock-center-page .stock-center-error { margin: 0 !important; }
  body.stock-center-page .footer { margin: 12px 0 0 !important; }
}
@media (max-width: 999px) and (max-height: 740px) {
  body.stock-center-page.stock-ledger-active:has(.stock-center-supervisor-tools) {
    height: auto !important;
    min-height: 100dvh !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
  }
  body.stock-center-page:has(.stock-center-supervisor-tools) .stock-ledger-screen {
    display: flex !important;
    flex-direction: column !important;
    height: auto !important;
    min-height: 0 !important;
    overflow: visible !important;
  }
  body.stock-center-page:has(.stock-center-supervisor-tools) .stock-ledger-table-wrap {
    height: auto !important;
    overflow: visible !important;
  }
}
@media (max-width: 380px) {
  body.stock-center-page #app.wrap { padding-left: 13px !important; padding-right: 13px !important; }
  body.stock-center-page .stock-club-tabs a { font-size: 12px !important; padding-left: 4px !important; padding-right: 4px !important; }
  body.stock-center-page .stock-ledger-table { font-size: 10px !important; }
  body.stock-center-page .stock-ledger-table th { font-size: 8px !important; padding-left: 2px !important; padding-right: 2px !important; }
  body.stock-center-page .stock-ledger-table td { font-size: 10px !important; padding: 12px 2px !important; }
  body.stock-center-page .stock-ledger-summary > div { grid-template-columns: 27px minmax(0,1fr) !important; gap: 3px !important; padding: 0 4px !important; }
  body.stock-center-page .stock-summary-icon svg { width: 21px !important; height: 21px !important; }
  body.stock-center-page .stock-ledger-summary small { font-size: 8px !important; }
  body.stock-center-page .stock-ledger-summary strong { font-size: 13px !important; }
}
/* STOCK_CENTER_SUPERVISOR_DESKTOP_GRID_FIX_V1: allocate a dedicated row for Supervisor tools on desktop. */
@media (min-width: 1000px) {
  body.stock-center-page.stockist-web-layout .stock-ledger-screen:has(.stock-center-supervisor-tools) {
    grid-template-rows: auto auto auto auto minmax(0, 1fr) auto !important;
    align-content: stretch !important;
    gap: 12px !important;
  }
  body.stock-center-page.stockist-web-layout .stock-center-supervisor-tools {
    min-width: 0 !important;
    min-height: 36px !important;
    margin: 0 !important;
    position: relative !important;
    z-index: 1 !important;
  }
  body.stock-center-page.stockist-web-layout .stock-ledger-actions {
    min-width: 0 !important;
    min-height: 0 !important;
    align-self: stretch !important;
    position: relative !important;
    z-index: 2 !important;
    overflow: visible !important;
  }
  body.stock-center-page.stockist-web-layout .stock-month-heading {
    min-height: 42px !important;
    position: relative !important;
    z-index: 3 !important;
    overflow: visible !important;
  }
  body.stock-center-page.stockist-web-layout .stock-month-heading > strong {
    min-width: 0 !important;
    line-height: 1.35 !important;
    overflow-wrap: anywhere !important;
  }
  body.stock-center-page.stockist-web-layout .stock-quick-actions {
    flex: 0 0 auto !important;
    position: relative !important;
    z-index: 4 !important;
  }
  body.stock-center-page.stockist-web-layout .stock-center-print-button {
    position: relative !important;
    z-index: 5 !important;
    flex: 0 0 40px !important;
    min-width: 40px !important;
  }
  body.stock-center-page.stockist-web-layout .stock-ledger-table-wrap {
    min-height: 0 !important;
    align-self: stretch !important;
    position: relative !important;
    z-index: 0 !important;
  }
}
/* STOCK_CENTER_STOCKIST_MOBILE_PARITY_V1: same natural mobile scroll behavior for Stockist as Supervisor. */
@media (max-width: 999px) {
  /* Stockist has no Supervisor tools row, so opt it into the same natural page-flow mobile layout. */
  body.stock-center-page.stock-ledger-active:not(:has(.stock-center-supervisor-tools)) {
    height: auto !important;
    min-height: 100dvh !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
  }
  body.stock-center-page:not(:has(.stock-center-supervisor-tools)) #app.wrap {
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
    padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px)) !important;
  }
  body.stock-center-page:not(:has(.stock-center-supervisor-tools)) .stock-ledger-screen {
    display: flex !important;
    flex-direction: column !important;
    grid-template-rows: none !important;
    align-items: stretch !important;
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
    gap: 12px !important;
  }
  body.stock-center-page:not(:has(.stock-center-supervisor-tools)) .stock-ledger-table-wrap {
    flex: 0 0 auto !important;
    width: 100% !important;
    max-width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
    overflow-x: hidden !important;
    overflow-y: visible !important;
    -webkit-overflow-scrolling: auto !important;
  }
  body.stock-center-page:not(:has(.stock-center-supervisor-tools)) .stock-ledger-summary {
    flex-shrink: 0 !important;
    margin-bottom: 8px !important;
  }
}

/* STOCK_CENTER_HIDE_GLOBAL_HEADER_MOBILE_V1
   Hide only the global GClub brand header + connection status on mobile.
   Keep the blue Stok Kaporit hero because it contains the page title and logout action.
   The PC/laptop layout is intentionally unchanged. */
@media (max-width: 999px) {
  body.stock-center-page.stock-ledger-active.stockist-web-layout > header.top,
  body.stock-center-page.stock-ledger-active.stockist-web-layout > #network {
    display: none !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap {
    padding-top: 8px !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-hero {
    margin-top: 0 !important;
  }
  /* Supervisor uses a fixed-height mobile ledger viewport. Reclaim the height
     formerly occupied by the global header/status line. Stockist keeps its
     natural page scroll behavior from the previous mobile parity patch. */
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools) .stock-ledger-screen {
    height: calc(100dvh - 155px) !important;
    min-height: max(0px, calc(100dvh - 155px)) !important;
  }
}

/* STOCK_CENTER_TABLE_ONLY_SCROLL_MOBILE_V1
   Keep all mobile controls and totals stationary; only the ledger rows scroll.
   Shared by Supervisor and Stockist. Desktop/laptop layout is not touched. */
@media (max-width: 999px) {
  body.stock-center-page.stock-ledger-active.stockist-web-layout {
    height: 100dvh !important;
    min-height: 0 !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
    overflow-y: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap {
    box-sizing: border-box !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-screen {
    display: grid !important;
    grid-template-rows: auto auto auto minmax(0, 1fr) auto !important;
    align-items: stretch !important;
    gap: 10px !important;
    height: calc(100dvh - 155px) !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 155px) !important;
    overflow: hidden !important;
    margin: 0 auto !important;
  }
  /* Supervisor has one extra tools row above the month selector. */
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools) .stock-ledger-screen {
    grid-template-rows: auto auto auto auto minmax(0, 1fr) auto !important;
    height: calc(100dvh - 155px) !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 155px) !important;
  }
  /* Override the older short-screen fallback that let the whole page scroll. */
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools),
  body.stock-center-page.stock-ledger-active.stockist-web-layout:not(:has(.stock-center-supervisor-tools)) {
    height: 100dvh !important;
    min-height: 0 !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
    overflow-y: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools) #app.wrap,
  body.stock-center-page.stock-ledger-active.stockist-web-layout:not(:has(.stock-center-supervisor-tools)) #app.wrap {
    height: auto !important;
    min-height: 0 !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    padding-bottom: calc(104px + env(safe-area-inset-bottom, 0px)) !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout:not(:has(.stock-center-supervisor-tools)) .stock-ledger-screen {
    display: grid !important;
    grid-template-rows: auto auto auto minmax(0, 1fr) auto !important;
    height: calc(100dvh - 155px) !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 155px) !important;
    overflow: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table-wrap,
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools) .stock-ledger-table-wrap,
  body.stock-center-page.stock-ledger-active.stockist-web-layout:not(:has(.stock-center-supervisor-tools)) .stock-ledger-table-wrap {
    display: block !important;
    align-self: stretch !important;
    width: 100% !important;
    min-width: 0 !important;
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
    touch-action: pan-y !important;
    scrollbar-gutter: stable;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table thead th {
    position: sticky !important;
    top: 0 !important;
    z-index: 4 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary {
    position: relative !important;
    z-index: 5 !important;
    flex-shrink: 0 !important;
    align-self: stretch !important;
    margin: 0 0 4px !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-actions,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-hero,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-club-tabs,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-center-supervisor-tools {
    flex-shrink: 0 !important;
  }
}

/* STOCK_CENTER_MOBILE_FILL_PLUS_V1: use mobile viewport efficiently; only the table scrolls. */
@media (max-width: 999px) {
  body.stock-center-page.stock-ledger-active.stockist-web-layout {
    height: 100dvh !important;
    min-height: 0 !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
    overflow-y: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap {
    box-sizing: border-box !important;
    width: 100% !important;
    height: calc(100dvh - 66px - env(safe-area-inset-bottom, 0px)) !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 66px - env(safe-area-inset-bottom, 0px)) !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-screen {
    display: grid !important;
    grid-template-rows: auto auto auto auto minmax(0, 1fr) auto !important;
    gap: 8px !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 0 !important;
    max-height: none !important;
    margin: 0 !important;
    overflow: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools) .stock-ledger-screen {
    grid-template-rows: auto auto auto auto auto minmax(0, 1fr) auto !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-hero,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-club-tabs,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-center-supervisor-tools,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-actions,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary {
    min-height: 0 !important;
    flex-shrink: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table-wrap {
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
    align-self: stretch !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
    touch-action: pan-y !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table thead th {
    position: sticky !important;
    top: 0 !important;
    z-index: 4 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary {
    position: relative !important;
    z-index: 5 !important;
    align-self: stretch !important;
    margin: 0 0 2px !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-center-supervisor-tools {
    margin: 0 !important;
  }
}
@media (max-width: 999px) and (max-height: 700px) {
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-screen { gap: 5px !important; }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-hero { padding-top: 13px !important; padding-bottom: 13px !important; }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary { padding-top: 9px !important; padding-bottom: 9px !important; }
}

/* STOCK_CENTER_ICON_ONLY_FILL_BOTTOM_MOBILE_V2
   Remove the extra implicit grid track so the table fills available mobile height for both roles. */
@media (max-width: 999px) {
  body.stock-center-page.stock-ledger-active.stockist-web-layout {
    height: 100dvh !important;
    min-height: 0 !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
    overflow-y: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap {
    box-sizing: border-box !important;
    width: 100% !important;
    height: calc(100dvh - 66px - env(safe-area-inset-bottom, 0px)) !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 66px - env(safe-area-inset-bottom, 0px)) !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-screen {
    display: grid !important;
    /* Stockist has 5 direct rows: hero, club tabs, actions, table, summary. */
    grid-template-rows: auto auto auto minmax(0, 1fr) auto !important;
    grid-auto-rows: 0 !important;
    align-content: stretch !important;
    gap: 8px !important;
    box-sizing: border-box !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 0 !important;
    max-height: 100% !important;
    margin: 0 !important;
    padding: 0 0 2px !important;
    overflow: hidden !important;
  }
  /* Supervisor has exactly one extra direct row for its management links. */
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools) .stock-ledger-screen {
    grid-template-rows: auto auto auto auto minmax(0, 1fr) auto !important;
    grid-auto-rows: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-hero,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-club-tabs,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-center-supervisor-tools,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-actions,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary {
    min-height: 0 !important;
    flex-shrink: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-actions {
    margin: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table-wrap {
    display: block !important;
    align-self: stretch !important;
    width: 100% !important;
    min-width: 0 !important;
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
    touch-action: pan-y !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table thead th {
    position: sticky !important;
    top: 0 !important;
    z-index: 4 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary {
    position: relative !important;
    z-index: 5 !important;
    align-self: stretch !important;
    margin: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-center-supervisor-tools {
    margin: 0 !important;
  }
  /* Icon-only plus: do not show visible helper text or a hover title tooltip. */
  body.stock-center-page.stock-ledger-active.stockist-web-layout #stock-center-add {
    display: inline-grid !important;
    place-items: center !important;
    width: 42px !important;
    min-width: 42px !important;
    height: 42px !important;
    padding: 0 !important;
    line-height: 1 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout #stock-center-add > b {
    display: block !important;
    margin: 0 !important;
    font-size: 27px !important;
    line-height: 1 !important;
  }
}
@media (min-width: 1000px) {
  /* Keep the approved PC/laptop layout intact; only remove the visible button text. */
  body.stock-center-page.stock-ledger-active.stockist-web-layout #stock-center-add > b {
    display: block;
    line-height: 1;
  }
}

/* STOCK_CENTER_MOBILE_SUMMARY_BOTTOM_V3
   The global GClub header/network strip is hidden on mobile. The previous 66px
   reservation therefore left unused space below the fixed summary. Reclaim it
   so the ledger table grows and the three summary metrics sit at the bottom. */
@media (max-width: 999px) {
  body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap {
    height: calc(100vh - env(safe-area-inset-bottom, 0px)) !important;
    max-height: calc(100vh - env(safe-area-inset-bottom, 0px)) !important;
    height: calc(100dvh - env(safe-area-inset-bottom, 0px)) !important;
    max-height: calc(100dvh - env(safe-area-inset-bottom, 0px)) !important;
    margin-bottom: 0 !important;
  }
}

/* STOCK_CENTER_MOBILE_SUMMARY_BOTTOM_V4
   Fix the real cause of the unused bottom gap: the old Stockist CSS hard-coded
   .stock-ledger-screen to calc(100dvh - 255px), and #app kept a 104px bottom pad.
   On mobile, size the entire app to the space above the fixed bottom navigation;
   the grid's minmax(0,1fr) track then gives all remaining height to the table.
   Summary cards retain their existing size and three-column layout. Desktop untouched. */
@media (max-width: 999px) {
  body.stock-center-page.stock-ledger-active.stockist-web-layout {
    height: 100vh !important;
    height: 100dvh !important;
    min-height: 0 !important;
    max-height: 100dvh !important;
    overflow: hidden !important;
    overflow-x: hidden !important;
    overflow-y: hidden !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap {
    box-sizing: border-box !important;
    display: block !important;
    width: 100% !important;
    height: calc(100vh - 80px - env(safe-area-inset-bottom, 0px)) !important;
    height: calc(100dvh - 80px - env(safe-area-inset-bottom, 0px)) !important;
    min-height: 0 !important;
    max-height: calc(100dvh - 80px - env(safe-area-inset-bottom, 0px)) !important;
    margin: 0 !important;
    padding: 0 22px 0 !important;
    overflow: hidden !important;
    position: relative !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-screen {
    display: grid !important;
    grid-template-rows: auto auto auto minmax(0, 1fr) auto !important;
    grid-auto-rows: 0 !important;
    align-content: stretch !important;
    gap: 8px !important;
    box-sizing: border-box !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 0 !important;
    max-height: 100% !important;
    margin: 0 !important;
    padding: 0 0 2px !important;
    overflow: hidden !important;
  }
  /* Supervisor has one additional direct child: its management-tools row. */
  body.stock-center-page.stock-ledger-active.stockist-web-layout:has(.stock-center-supervisor-tools) .stock-ledger-screen {
    grid-template-rows: auto auto auto auto minmax(0, 1fr) auto !important;
    grid-auto-rows: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-hero,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-club-tabs,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-center-supervisor-tools,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-actions,
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary {
    min-height: 0 !important;
    flex-shrink: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-actions {
    margin: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table-wrap {
    display: block !important;
    align-self: stretch !important;
    width: 100% !important;
    min-width: 0 !important;
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
    touch-action: pan-y !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-table thead th {
    position: sticky !important;
    top: 0 !important;
    z-index: 4 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .stock-ledger-summary {
    position: relative !important;
    z-index: 5 !important;
    align-self: stretch !important;
    margin: 0 !important;
    margin-bottom: 0 !important;
  }
  body.stock-center-page.stock-ledger-active.stockist-web-layout .nav {
    position: fixed !important;
    top: auto !important;
    bottom: 0 !important;
  }
}

/* STOCK_CENTER_LAYOUT_V5 (mobile)
   Aturan layout final untuk Supervisor DAN Stockist. Sengaja memakai selector lebih spesifik
   (html + #app.wrap#app) supaya menang atas patch lama (V3/V4 dan aturan :has(...)).
   Body jadi kolom setinggi layar: [ app (isi sisa ruang) ][ nav bawah ].
   Ringkasan menempel tepat di atas nav, tabel mengisi semua ruang sisanya. Desktop tidak diubah. */
@media (max-width: 999px) {
  html body.stock-center-page.stock-ledger-active.stockist-web-layout {
    display: flex !important;
    flex-direction: column !important;
    height: 100vh !important;
    height: 100dvh !important;
    min-height: 0 !important;
    max-height: 100dvh !important;
    padding-bottom: 0 !important;
    overflow: hidden !important;
  }
  html body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap#app {
    flex: 1 1 auto !important;
    display: flex !important;
    flex-direction: column !important;
    box-sizing: border-box !important;
    position: relative !important;
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    margin: 0 !important;
    padding: 0 22px 10px !important;
    overflow: hidden !important;
  }
  html body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap#app > .stock-ledger-screen {
    flex: 1 1 auto !important;
    display: grid !important;
    grid-template-rows: auto auto auto minmax(0, 1fr) auto !important;
    gap: 8px !important;
    box-sizing: border-box !important;
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
  }
  html body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap#app .stock-ledger-table-wrap {
    display: block !important;
    min-height: 0 !important;
    height: auto !important;
    max-height: none !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
    -webkit-overflow-scrolling: touch !important;
  }
  html body.stock-center-page.stock-ledger-active.stockist-web-layout #app.wrap#app .stock-ledger-summary {
    position: relative !important;
    z-index: 5 !important;
    align-self: end !important;
    margin: 0 !important;
  }
  html body.stock-center-page.stock-ledger-active.stockist-web-layout #nav.nav {
    position: relative !important;
    flex: 0 0 auto !important;
    left: auto !important;
    right: auto !important;
    bottom: auto !important;
    width: 100% !important;
    margin: 0 !important;
    z-index: 20 !important;
  }
}
</style>
</head>
<body class="supervisor-layout stockist-web-layout stock-ledger-active stock-center-page">
<header class="top"><a class="brand" href="./" aria-label="GClub Maintenance"><img class="brand-logo" src="assets/brand/gclub-logo.png?v=<?=$assetVersion?>" width="64" height="64" alt="Logo GClub"><strong>GClub</strong><span>Maintenance</span></a><div id="account"><span class="role-badge"><?= $isStockist ? 'STOCKIST' : 'SUPERVISOR' ?></span></div></header>
<div id="network" role="status" class="network">Terhubung · Kaporit · Diperbarui <?=sc_h($nowLabel)?> · WIB</div>
<main id="app" class="wrap">
<section class="stock-ledger-screen">
<header class="stock-ledger-hero"><span><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 21 7v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10"/></svg></span><div><h1>Stok Kaporit</h1><p>Riwayat Pemakaian</p></div><button type="button" class="stockist-logout-button" id="stock-center-logout" aria-label="Keluar dari akun" title="Keluar dari akun"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5H5v14h5 M13 8l4 4-4 4 M17 12H9"/></svg><span>Keluar</span></button><div class="stock-chemistry" aria-hidden="true"><i class="bubble b1"></i><i class="bubble b2"></i><i class="bubble b3"></i><i class="bubble b4"></i><svg viewBox="0 0 150 105"><g fill="none" stroke="currentColor" stroke-width="3"><path d="M46 19 72 35v30L46 81 20 65V35zM72 35l26-15m0 0 25 15m-25-15v-15m25 30 17 26m-17-26v30l-25 15"/><circle cx="46" cy="19" r="7"/><circle cx="20" cy="65" r="7"/><circle cx="72" cy="65" r="7"/><circle cx="98" cy="20" r="7"/><circle cx="123" cy="35" r="7"/><circle cx="123" cy="65" r="7"/><circle cx="140" cy="91" r="7"/></g></svg></div></header>
<nav class="stock-club-tabs" aria-label="Pilih klub"><a class="<?= $club==='gclub'?'active':'' ?>" href="?club=gclub&amp;month=<?=sc_h($month)?>" <?= $club==='gclub'?'aria-current="page"':'' ?>><span class="club-indicator"></span>Stok Kaporit GClub</a><a class="<?= $club==='bayclub'?'active':'' ?>" href="?club=bayclub&amp;month=<?=sc_h($month)?>" <?= $club==='bayclub'?'aria-current="page"':'' ?>><span class="club-indicator"></span>Stok Kaporit Bayclub</a></nav>
<div class="stock-ledger-actions"><div class="stock-month-heading"><strong>Riwayat Bulan · <?=sc_h($clubLabel)?></strong><span class="stock-quick-actions"><?php if ($isSupervisor): ?><button type="button" class="stock-icon-action add" id="stock-center-add" aria-label="Tambah Stok"><b aria-hidden="true">+</b></button><?php endif; ?><button type="button" class="stock-icon-action print stock-center-print-button" id="stock-center-print" aria-label="Preview dan simpan laporan PDF"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z"/><path d="M18 12h.01"/></svg></button></span></div><form id="stock-center-month-form" method="get"><input type="hidden" name="club" value="<?=sc_h($club)?>"><label class="sr-only" for="stock-center-month">Pilih Riwayat Bulan</label><input id="stock-center-month" name="month" type="month" value="<?=sc_h($month)?>"></form></div>
<?php if ($data): ?>
<div class="stock-ledger-table-wrap"><table class="stock-ledger-table"><thead><tr><th>Tanggal</th><th>PIC</th><th>Masuk</th><th>Keluar</th><th>Sisa</th></tr></thead><tbody>
<?php if (!$data['rows']): ?><tr><td colspan="5" class="stock-empty-cell">Belum Ada Transaksi Pada Bulan Ini</td></tr><?php else: foreach ($data['rows'] as $r): $in=(float)$r['qty_in']; $out=(float)$r['qty_out']; ?><tr><td><strong><?=sc_h(sc_date((string)$r['transaction_date']))?></strong></td><td><?=sc_h(($r['pic'] ?? '') ?: 'Sistem')?></td><td class="stock-in"><?=$in>0?sc_h(sc_qty($in)):'–'?></td><td class="stock-out"><?=$out>0?sc_h(sc_qty($out)):'–'?></td><td class="stock-left"><?=sc_h(sc_qty((float)$r['balance']))?></td></tr><?php endforeach; endif; ?>
</tbody></table></div>
<footer class="stock-ledger-summary"><div><span class="stock-summary-icon in">↓</span><small>Total Masuk</small><strong><?=sc_h(sc_qty((float)$data['month_in']))?></strong></div><div><span class="stock-summary-icon out">↑</span><small>Total Keluar</small><strong><?=sc_h(sc_qty((float)$data['month_out']))?></strong></div><div><span class="stock-summary-icon left"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 21 7v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10"/></svg></span><small>Stok Saat Ini</small><strong><?=sc_h(sc_qty((float)$data['balance']))?></strong></div></footer>
<?php else: ?><div class="stock-center-error"><p><?=sc_h($loadError)?></p><?php if ($club==='bayclub' && $isSupervisor): ?><a href="setup-stock-center-bayclub.php">Buka Pengaturan Koneksi Database Bayclub</a><?php endif; ?></div><?php endif; ?>
</section>
</main>
<nav id="nav" class="nav" aria-label="Menu utama"><button type="button" class="active stockist-stock-tab" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 21 7v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10"/></svg><span>Kartu Stok</span></button></nav>
<dialog id="modal"><div class="dialog-head"><h2 id="modal-title">Laporan Stok</h2><button type="button" id="close-modal" class="icon-button" aria-label="Tutup">×</button></div><div id="modal-body"></div></dialog>
<script>
'use strict';
const STOCK_CENTER_CLUB = <?=json_encode($club, JSON_UNESCAPED_UNICODE)?>;
const STOCK_CENTER_MONTH = <?=json_encode($month, JSON_UNESCAPED_UNICODE)?>;
const STOCK_CENTER_CSRF = <?=json_encode($csrfToken, JSON_UNESCAPED_UNICODE)?>;
const STOCK_CENTER_RECORD_LOCATION = <?= $recordCenterLocation ? 'true' : 'false' ?>;
const STOCK_CENTER_IS_SUPERVISOR = <?= $isSupervisor ? 'true' : 'false' ?>;
const STOCK_CENTER_ITEM = <?=json_encode(is_array($data) ? ($data['item'] ?? []) : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)?>;
const STOCK_CENTER_TODAY = <?=json_encode(date('Y-m-d'))?>;
const scEsc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const scNum = v => Number(v || 0).toLocaleString('id-ID',{minimumFractionDigits:2,maximumFractionDigits:3});
const scDate = v => { const p=String(v||'').split('-'); return p.length===3 ? `${p[2]}/${p[1]}/${p[0]}` : String(v||''); };
const scModal = document.getElementById('modal');
function scShowModal(title, html) { document.getElementById('modal-title').textContent=title; document.getElementById('modal-body').innerHTML=html; if (!scModal.open) scModal.showModal(); }
function scCloseModal(){ if(scModal.open)scModal.close(); }
async function scLogLoginLocation(status, coords=null){
 const fd=new URLSearchParams();fd.append('csrf',STOCK_CENTER_CSRF);fd.append('status',status);
 if(coords){fd.append('latitude',Number(coords.latitude).toFixed(7));fd.append('longitude',Number(coords.longitude).toFixed(7));fd.append('accuracy',Number(coords.accuracy||0).toFixed(2));}
 try{await fetch('api.php?action=login_location',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',keepalive:true,headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'}});}catch{}
}
function scRecordLoginLocationOnce(){
 if(!STOCK_CENTER_RECORD_LOCATION)return;
 if(!window.isSecureContext||!navigator.geolocation){scLogLoginLocation('unsupported');return;}
 navigator.geolocation.getCurrentPosition(p=>scLogLoginLocation('granted',p.coords),e=>{
  const status=e?.code===1?'denied':e?.code===2?'unavailable':e?.code===3?'timeout':'error';scLogLoginLocation(status);
 },{enableHighAccuracy:true,timeout:6000,maximumAge:0});
}
document.getElementById('close-modal').addEventListener('click',scCloseModal);
scRecordLoginLocationOnce();
scModal.addEventListener('click',e=>{if(e.target===scModal)scCloseModal();});
scModal.addEventListener('cancel',e=>{e.preventDefault();scCloseModal();});
document.getElementById('stock-center-month').addEventListener('change',()=>document.getElementById('stock-center-month-form').submit());
document.getElementById('stock-center-logout').addEventListener('click',()=>{window.location.href='stock-center.php?sc_logout=1';});
document.getElementById('stock-center-print').addEventListener('click',()=>scPrintModal());
document.getElementById('stock-center-add')?.addEventListener('click',scStockEntryModal);
function scStockEntryModal(){
 if(!STOCK_CENTER_IS_SUPERVISOR)return;
 const item=STOCK_CENTER_ITEM||{};
 scShowModal('Tambah Stok Kaporit',`<form id="sc-stock-entry-form" class="stock-center-modal-form">
 <div class="split"><div class="field"><label for="sc-stock-type">Jenis Transaksi</label><select id="sc-stock-type" name="movement_type"><option value="purchase">Barang Masuk</option><option value="opening">Stok Awal</option><option value="correction_in">Koreksi Masuk</option><option value="correction_out">Koreksi Keluar</option></select></div><div class="field"><label for="sc-stock-date">Tanggal</label><input id="sc-stock-date" name="transaction_date" type="date" required value="${scEsc(STOCK_CENTER_TODAY)}"></div></div>
 <div class="field"><label for="sc-stock-amount">Jumlah Stok (Kg)</label><input id="sc-stock-amount" name="amount" type="number" required min="0.001" step="0.001" inputmode="decimal"></div>
 <div class="field"><label for="sc-stock-note">Keterangan</label><input id="sc-stock-note" name="note" type="text" required maxlength="500" placeholder="Contoh: Pembelian 5 karung"></div>
 <details class="stock-settings"><summary>Identitas &amp; Peringatan Stok</summary><div class="field"><label for="sc-stock-item-name">Nama Barang</label><input id="sc-stock-item-name" name="item_name" type="text" required maxlength="160" value="${scEsc(item.name||'Triklorit 90% Granul Brataco')}"></div><div class="field"><label for="sc-stock-location">Lokasi Barang</label><input id="sc-stock-location" name="location" type="text" maxlength="200" value="${scEsc(item.location||'G club')}"></div><div class="field"><label for="sc-stock-minimum">Peringatan Minimum (Kg)</label><input id="sc-stock-minimum" name="minimum_qty" type="number" required min="0" step="0.001" value="${scEsc(item.minimum_qty??20)}"></div></details>
 <p class="form-error" role="alert"></p><button type="submit" class="btn full">Simpan Tambahan Stok</button></form>`);
 const form=document.getElementById('sc-stock-entry-form');
 form.addEventListener('submit',scSubmitStockEntry);
}
async function scSubmitStockEntry(event){
 event.preventDefault();
 const form=event.currentTarget,button=form.querySelector('[type="submit"]'),error=form.querySelector('.form-error');
 if(button.disabled)return; error.textContent=''; button.disabled=true; button.textContent='Menyimpan…';
 const fd=new URLSearchParams(); new FormData(form).forEach((value,key)=>fd.append(key,String(value))); fd.append('csrf',STOCK_CENTER_CSRF); fd.append('club',STOCK_CENTER_CLUB);
 try{
  let url;
  if(STOCK_CENTER_CLUB==='gclub'){
   url=new URL('api.php?action=stock_entry',window.location.href);
  }else{
   url=new URL('stock-center.php',window.location.href); url.searchParams.set('club','bayclub'); url.searchParams.set('month',STOCK_CENTER_MONTH); fd.append('sc_action','bayclub_stock_entry');
  }
  const response=await fetch(url.toString(),{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}});
  const text=await response.text(); let body;
  try{body=JSON.parse(text);}catch{throw new Error('Server mengembalikan respons yang tidak dikenali. Periksa Kartu Stok sebelum mencoba ulang.');}
  if(!response.ok||!body.ok)throw new Error(body.error||'Transaksi stok belum berhasil disimpan.');
  const result=body.data||{}; const savedDate=String(result.transaction_date||form.elements.transaction_date.value||STOCK_CENTER_TODAY);
  scShowModal('Stok Tersimpan',`<p>${scEsc(result.message||'Transaksi stok kaporit tersimpan.')}</p><p class="stock-center-modal-help">Kartu Stok akan dimuat ulang agar saldo dan riwayat terbaru terlihat.</p><button type="button" class="btn full" id="sc-stock-entry-finish">Kembali ke Kartu Stok</button>`);
  document.getElementById('sc-stock-entry-finish').addEventListener('click',()=>{
   const next=new URL('stock-center.php',window.location.href); next.searchParams.set('club',STOCK_CENTER_CLUB); next.searchParams.set('month',savedDate.slice(0,7)); next.searchParams.set('_fresh',String(Date.now())); window.location.replace(next.toString());
  });
 }catch(e){error.textContent=e?.message||'Transaksi stok belum berhasil disimpan.';button.disabled=false;button.textContent='Simpan Tambahan Stok';}
}
function scPrintModal(){
 const first=STOCK_CENTER_MONTH+'-01'; const last=new Date(Date.UTC(Number(STOCK_CENTER_MONTH.slice(0,4)),Number(STOCK_CENTER_MONTH.slice(5,7)),0)).toISOString().slice(0,10);
 scShowModal('Simpan Laporan Stok',`<form id="sc-print-form" class="stock-center-modal-form"><p class="stock-center-modal-help">Pilih rentang tanggal untuk ${STOCK_CENTER_CLUB==='gclub'?'GClub':'Bayclub'}. Laporan lengkap akan ditampilkan terlebih dahulu sebelum disimpan menjadi PDF.</p><div class="split"><div class="field"><label for="sc-start-date">Tanggal Awal</label><input id="sc-start-date" name="start_date" type="date" required value="${scEsc(first)}"></div><div class="field"><label for="sc-end-date">Tanggal Akhir</label><input id="sc-end-date" name="end_date" type="date" required value="${scEsc(last)}"></div></div><p class="form-error" role="alert"></p><button type="submit" class="btn full">Lihat Preview Laporan</button></form>`);
 document.getElementById('sc-print-form').addEventListener('submit',scLoadPreview);
}
async function scLoadPreview(event){
 event.preventDefault(); const form=event.currentTarget,button=form.querySelector('[type=submit]'),error=form.querySelector('.form-error'); error.textContent=''; button.disabled=true; button.textContent='Menyiapkan preview…';
 const start=form.elements.start_date.value,end=form.elements.end_date.value;
 try{const u=new URL(window.location.href);u.search='';u.searchParams.set('club',STOCK_CENTER_CLUB);u.searchParams.set('sc_action','report');u.searchParams.set('start_date',start);u.searchParams.set('end_date',end);const response=await fetch(u,{credentials:'same-origin',cache:'no-store'});const body=await response.json();if(!response.ok||!body.ok)throw new Error(body.error||'Preview laporan gagal dibuat.');scPreviewReport(body.data);}
 catch(e){error.textContent=e.message||'Preview laporan belum dapat dibuat.';}
 finally{button.disabled=false;button.textContent='Lihat Preview Laporan';}
}
function scMetricIcon(kind){if(kind==='in')return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V4m-4 4 4-4 4 4"/></svg>';if(kind==='out')return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16m-4-4 4 4 4-4"/></svg>';return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 5v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10"/></svg>';}
function scReportHtml(data){
 const item=data.item||{},rows=data.rows||[],totalIn=rows.reduce((n,r)=>n+Number(r.qty_in||0),0),totalOut=rows.reduce((n,r)=>n+Number(r.qty_out||0),0),printed=new Date().toLocaleDateString('id-ID');
 const tableRows=rows.map(r=>`<tr><td>${scEsc(scDate(r.transaction_date))}</td><td>${scEsc(r.pic||'Sistem')}</td><td>${Number(r.qty_in)>0?scNum(r.qty_in):''}</td><td>${Number(r.qty_out)>0?scNum(r.qty_out):''}</td><td>${scNum(r.balance)}</td><td>${scEsc(r.note||'')}</td></tr>`).join('');
 return `<div class="stock-report-shell"><header class="stock-report-head"><span class="stock-report-bar"></span><small>LAPORAN KAPORIT · ${scEsc(STOCK_CENTER_CLUB==='gclub'?'GCLUB':'BAYCLUB')}</small></header><section class="stock-print-title"><h2>KARTU STOK</h2><dl><div><dt>Nama Barang</dt><dd>${scEsc(item.name||'Kaporit')}</dd></div><div><dt>Satuan Barang</dt><dd>${scEsc(item.unit||'Kg')}</dd></div><div><dt>Lokasi Barang</dt><dd>${scEsc(item.location||'')}</dd></div><div><dt>Periode</dt><dd>${scEsc(scDate(data.start_date))} s.d. ${scEsc(scDate(data.end_date))}</dd></div></dl></section><table class="stock-table"><colgroup><col class="stock-col-date"><col class="stock-col-pic"><col class="stock-col-move"><col class="stock-col-move"><col class="stock-col-move"><col class="stock-col-note"></colgroup><thead><tr><th rowspan="2">Tanggal</th><th rowspan="2">PIC</th><th colspan="3">Barang</th><th rowspan="2">Keterangan</th></tr><tr><th>Masuk</th><th>Keluar</th><th>Sisa</th></tr></thead><tbody><tr class="stock-opening-row"><td>${scEsc(scDate(data.start_date))}</td><td>Sistem</td><td></td><td></td><td>${scNum(data.opening_balance)}</td><td>Saldo Awal Periode</td></tr>${tableRows}</tbody></table><footer class="stock-report-totals modern"><span class="in"><i class="stock-report-metric-icon">${scMetricIcon('in')}</i><small>Total Masuk</small><strong>${scNum(totalIn)} ${scEsc(item.unit||'Kg')}</strong></span><span class="out"><i class="stock-report-metric-icon">${scMetricIcon('out')}</i><small>Total Keluar</small><strong>${scNum(totalOut)} ${scEsc(item.unit||'Kg')}</strong></span><span class="left"><i class="stock-report-metric-icon">${scMetricIcon('left')}</i><small>Sisa</small><strong>${scNum(data.closing_balance)} ${scEsc(item.unit||'Kg')}</strong></span></footer><footer class="stock-report-footer"><span>KARTU STOK · ${scEsc(STOCK_CENTER_CLUB==='gclub'?'GClub':'Bayclub')}</span><span>Dicetak pada ${scEsc(printed)}</span></footer></div>`;
}
function scPreviewReport(data){
 scShowModal('Preview Laporan',`<p class="stock-preview-help">Periksa isi laporan di bawah ini. Jika sudah benar, tekan <strong>Simpan PDF</strong>.</p><div class="stock-report-preview">${scReportHtml(data)}</div><button type="button" id="sc-save-pdf" class="btn full">${scMetricIcon('left')} Simpan PDF</button>`);
 document.getElementById('sc-save-pdf').addEventListener('click',()=>scSavePdf(data));
}
async function scSavePdf(data){
 const button=document.getElementById('sc-save-pdf');if(!button)return;const original=button.innerHTML;button.disabled=true;button.textContent='Menyiapkan PDF…';
 const u=new URL(window.location.href);u.search='';u.searchParams.set('club',STOCK_CENTER_CLUB);u.searchParams.set('sc_action','pdf');u.searchParams.set('start_date',data.start_date);u.searchParams.set('end_date',data.end_date);
 try{const response=await fetch(u,{credentials:'same-origin',cache:'no-store'});if(!response.ok){let msg='PDF tidak dapat dibuat.';try{const e=await response.json();msg=e.error||msg;}catch{}throw new Error(msg);}const blob=await response.blob();if(!blob.type.includes('application/pdf'))throw new Error('Server tidak mengembalikan file PDF yang valid.');const name=`Kartu Stok Kaporit ${STOCK_CENTER_CLUB==='gclub'?'GClub':'Bayclub'} ${scDate(data.start_date).replaceAll('/','-')} - ${scDate(data.end_date).replaceAll('/','-')}.pdf`;const objectUrl=URL.createObjectURL(blob),a=document.createElement('a');a.href=objectUrl;a.download=name;a.rel='noopener';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(objectUrl),60000);button.textContent='PDF tersimpan';setTimeout(()=>{if(button.isConnected){button.disabled=false;button.innerHTML=original;}},1400);}
 catch(e){button.disabled=false;button.innerHTML=original;const help=document.querySelector('.stock-preview-help');if(help)help.textContent=e.message||'PDF belum dapat disimpan.';}
}
</script>
</body>
</html>
