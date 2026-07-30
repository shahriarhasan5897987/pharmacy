<?php
/**
 * তিন ভাই ফার্মেসি - Complete Pharmacy Management System
 * Version: 10.2 - PAID/DUE ENHANCED & POS SEARCH OPTIMIZED
 * Features: POS, Barcode Support, Supplier DB, Backup/Restore, 
 *           Dashboard Alerts, Auto-Save Cart, Telegram Integration, Export CSV
 */

// -------------------------------
// ERROR HANDLING & SECURITY
// -------------------------------
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

@ini_set('session.cookie_httponly', 1);
@ini_set('session.use_only_cookies', 1);
@ini_set('session.cookie_samesite', 'Lax');

$sessionPath = __DIR__ . '/sessions';
if (!is_dir($sessionPath)) { @mkdir($sessionPath, 0777, true); }
if (is_dir($sessionPath) && is_writable($sessionPath)) { @session_save_path($sessionPath); }

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// -------------------------------
// DEFINE DATA FILES
// -------------------------------
define('MEDICINE_FILE', __DIR__ . '/medicine.data.json');
define('ALL_DATA_FILE', __DIR__ . '/All.Data.json');
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// -------------------------------
// TELEGRAM CONFIGURATION
// -------------------------------
define('TELEGRAM_BOT_TOKEN', '8762974738:AAEgn7mvY2MVqqTk0msFFHoCLPt0ZgfuLNA');
define('TELEGRAM_CHANNEL_ID', '-1004462257657');
define('TELEGRAM_MY_CHAT_ID', '7870638558');

// -------------------------------
// HELPER FUNCTIONS
// -------------------------------
function getStockStatus($med) {
    if (isset($med['stock_status'])) return $med['stock_status'];
    return (isset($med['stock_quantity']) && $med['stock_quantity'] > 0) ? 'In Stock' : 'Out of Stock';
}

function getNextId($arr, $key = 'id') {
    if (empty($arr)) return 1;
    $ids = array_column($arr, $key);
    return empty($ids) ? 1 : max($ids) + 1;
}

function getCustomerName($customers, $id) {
    if (empty($customers)) return 'Walk-in Customer';
    foreach ($customers as $c) {
        if (isset($c['id']) && $c['id'] == $id) { return isset($c['name']) ? $c['name'] : 'Walk-in Customer'; }
    }
    return 'Walk-in Customer';
}

function getSupplierName($suppliers, $id) {
    if (empty($suppliers)) return 'Unknown Supplier';
    foreach ($suppliers as $s) {
        if (isset($s['id']) && $s['id'] == $id) { return isset($s['name']) ? $s['name'] : 'Unknown Supplier'; }
    }
    return 'Unknown Supplier';
}

function bn_num($str) {
    $en = array('0','1','2','3','4','5','6','7','8','9');
    $bn = array('০','১','২','৩','৪','৫','৬','৭','৮','৯');
    return str_replace($en, $bn, $str);
}

function bn_date($timestamp) {
    $en_months = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
    $bn_months = array('জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর', 'জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে');
    $date = date('d F, Y', $timestamp);
    $time = date('h:i A', $timestamp);
    $date = str_replace($en_months, $bn_months, $date);
    return bn_num($date) . ' এ ' . bn_num(str_replace(array('AM', 'PM'), array('AM', 'PM'), $time));
}

function read_json($file, $default = array()) {
    if (!file_exists($file)) { write_json($file, $default); return $default; }
    $fp = @fopen($file, 'r');
    if ($fp === false) return $default;
    if (flock($fp, LOCK_SH)) {
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN); fclose($fp);
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : $default;
    }
    fclose($fp); return $default;
}

function write_json($file, $data) {
    $fp = @fopen($file, 'w');
    if ($fp === false) return false;
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp); flock($fp, LOCK_UN); fclose($fp);
        return true;
    }
    fclose($fp); return false;
}

// -------------------------------
// LAZY LOADING FOR MEDICINES
// -------------------------------
$allData = read_json(ALL_DATA_FILE, array());
$medicinesLoaded = false;
$medicines = array();

function load_medicines() {
    global $medicines, $medicinesLoaded;
    if ($medicinesLoaded) return;
    $medicines = read_json(MEDICINE_FILE, array());
    $medicinesLoaded = true;
}

function save_medicines() {
    global $medicines, $allData;
    write_json(MEDICINE_FILE, $medicines);
    $lowStock = 0;
    foreach ($medicines as $m) { if (getStockStatus($m) === 'Out of Stock') $lowStock++; }
    if (!isset($allData['settings'])) $allData['settings'] = array();
    $allData['settings']['cached_low_stock'] = $lowStock;
    $allData['settings']['cached_total_medicines'] = count($medicines);
    write_json(ALL_DATA_FILE, $allData);
}

$defaultStructures = array(
    'sales' => array(), 'purchases' => array(), 'suppliers' => array(),
    'customers' => array(), 'expenses' => array(), 'profit_loss_logs' => array(), 'bill_history' => array(),
    'users' => array( array('id' => 1, 'username' => 'admin', 'password' => password_hash('admin123', PASSWORD_DEFAULT), 'role' => 'Admin', 'email' => 'admin@pharma.com') ),
    'settings' => array('pharmacy_name' => 'তিন ভাই ফার্মেসি', 'phone' => '+880 1711-063005', 'address' => 'Tin Vai Pharmacy, Namazgor Road Chalksutrapur Bogura.', 'tax_rate' => 0, 'currency' => '৳', 'receipt_footer' => 'Copyright© 2026 তিন ভাই ফার্মেসি', 'logo_path' => '')
);

foreach ($defaultStructures as $key => $defaultValue) {
    if (!isset($allData[$key])) { $allData[$key] = $defaultValue; }
}
if (!isset($allData['settings']['logo_path'])) { $allData['settings']['logo_path'] = ''; }

if (!isset($allData['settings']['cached_total_medicines'])) { load_medicines(); save_medicines(); } 
else { write_json(ALL_DATA_FILE, $allData); }

// --- PRINT INVOICE HTML GENERATOR ---
function generateInvoiceHTML($invoice, $settings, $customers) {
    $customerName = $invoice['customer_id'] > 0 ? getCustomerName($customers, $invoice['customer_id']) : 'Walk-in Customer';
    $customerPhone = 'N/A';
    if($invoice['customer_id'] > 0) {
        foreach($customers as $c) { if(isset($c['id']) && $c['id'] == $invoice['customer_id']) { $customerPhone = isset($c['phone']) ? $c['phone'] : 'N/A'; } }
    }
    
    $bnDate = bn_date(strtotime($invoice['date']));
    $currency = '৳'; $itemsHtml = ''; $i = 1; $itemDiscountTotal = 0;
    
    foreach($invoice['items'] as $item) {
        $gross = $item['price'] * $item['quantity'];
        $itemDis = isset($item['dis_percent']) ? $gross * ($item['dis_percent'] / 100) : 0;
        $itemDiscountTotal += $itemDis;
        $netTotal = $gross - $itemDis;
        $rate = number_format($item['price'], 2);
        $total = number_format($netTotal, 2);
        $disAmountStr = $itemDis > 0 ? $currency . number_format($itemDis, 2) : '-';
        
        $itemsHtml .= "<tr><td>{$i}</td><td>" . htmlspecialchars($item['name']) . "</td><td class='text-center'>{$disAmountStr}</td><td class='text-center'>{$item['quantity']}</td><td class='text-right'>{$currency}{$rate}</td><td class='text-right'>{$currency}{$total}</td></tr>";
        $i++;
    }
    
    $extraDiscount = max(0, $invoice['discount_amount'] - $itemDiscountTotal);
    $status = 'Paid';
    $dueAmount = 0;
    if($invoice['change'] < 0) { 
        $status = 'Due'; 
        $dueAmount = abs($invoice['change']);
        if($invoice['paid'] > 0 && $invoice['paid'] < $invoice['total']) $status = 'Partial'; 
    }
    
    return "<style>@import url('https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&family=Noto+Sans+Bengali:wght@400;600;700&display=swap'); @media print { @page { size: 10cm 29cm; margin: 0; } body { margin: 0; padding: 0; } } .invoice-print { font-family: 'Kanit', 'Noto Sans Bengali', sans-serif; width: 10cm; margin: 0 auto; color: #000; background: #fff; padding: 2mm 3mm; box-sizing: border-box; } .invoice-header { text-align: center; } .invoice-header h1 { font-size: 16px; margin: 0; font-weight: bold; } .invoice-header p { font-size: 8.5px; margin: 2px 0; } .dashed-line { border-top: 1px dashed #333; margin: 4px 0; } .invoice-title { text-align: center; font-size: 12px; font-weight: bold; margin: 4px 0; } .meta-info { font-size: 8.5px; line-height: 1.3; margin-bottom: 6px; } .invoice-table { width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 8px; } .invoice-table th { border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 2px 1px; text-align: left; } .invoice-table td { padding: 2px 1px; border-bottom: 1px dotted #888; word-wrap: break-word; line-height: 1.1; } .text-right { text-align: right !important; } .text-center { text-align: center !important; } .footer-flex { display: flex; justify-content: space-between; align-items: flex-end; page-break-inside: avoid; margin-top: 4px;} .footer-left { width: 45%; } .total-bdt { font-size: 13px; font-weight: bold; border-bottom: 1.5px solid #000; display: inline-block; padding-bottom: 2px; margin-bottom: 4px; line-height: 1.1; } .copyright { font-size: 7px; font-weight: bold; text-align: left; line-height: 1.2;} .summary-box { border: 1.5px solid #000; border-radius: 6px; padding: 4px 6px; width: 52%; font-size: 8px; font-weight: bold; line-height: 1.4; box-sizing: border-box; } .summary-row { display: flex; justify-content: space-between; } .grand-total-row { border-top: 1px dashed #000; margin-top: 2px; padding-top: 2px; } </style> <div class='invoice-print'> <div class='invoice-header'> <h1>" . htmlspecialchars($settings['pharmacy_name']) . "</h1> <p>" . htmlspecialchars($settings['address']) . "<br>Phone: " . htmlspecialchars($settings['phone']) . "</p> </div> <div class='dashed-line'></div> <div class='invoice-title'>Invoice</div> <div class='dashed-line'></div> <div class='meta-info'> <strong>Customer:</strong> " . htmlspecialchars($customerName) . "<br> <strong>Phone:</strong> " . htmlspecialchars($customerPhone) . "<br> <strong>Bill No:</strong> " . htmlspecialchars($invoice['invoice_no']) . "<br> <strong>Date:</strong> " . $bnDate . " </div> <table class='invoice-table'> <thead> <tr> <th style='width: 5%;'>#</th> <th style='width: 35%;'>Description</th> <th class='text-center' style='width: 15%;'>Discount</th> <th class='text-center' style='width: 10%;'>Qty</th> <th class='text-right' style='width: 15%;'>Rate</th> <th class='text-right' style='width: 20%;'>Total</th> </tr> </thead> <tbody>{$itemsHtml}</tbody> </table> <div class='footer-flex'> <div class='footer-left'> <div style='font-size: 15px; font-weight: 900; line-height: 1; margin-bottom: 2px;'>Total</div> <div class='total-bdt'>BDT- " . number_format($invoice['total'], 2) . "</div> <div class='copyright'>" . htmlspecialchars($settings['receipt_footer']) . "</div> </div> <div class='summary-box'> <div class='summary-row'><span>Subtotal:</span> <span>BDT " . number_format($invoice['subtotal'], 2) . "</span></div> <div class='summary-row'><span>Extra Dis (Tk):</span> <span>BDT " . number_format($extraDiscount, 2) . "</span></div> <div class='summary-row'><span>Total Discount:</span> <span>BDT " . number_format($invoice['discount_amount'], 2) . "</span></div> <div class='summary-row grand-total-row'><span>Grand Total:</span> <span>BDT " . number_format($invoice['total'], 2) . "</span></div> <div class='summary-row'><span>Paid:</span> <span>BDT " . number_format($invoice['paid'], 2) . "</span></div> <div class='summary-row'><span>Due:</span> <span>BDT " . number_format($dueAmount, 2) . "</span></div> <div class='summary-row'><span>Status:</span> <span>" . $status . "</span></div> </div> </div> </div>";
}

if (!isset($_SESSION['theme'])) $_SESSION['theme'] = 'light';
if (isset($_GET['toggle_theme'])) { $_SESSION['theme'] = $_SESSION['theme'] === 'light' ? 'dark' : 'light'; header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

// AJAX REQUESTS
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $ajaxAction = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    
    // --- OPTIMIZED SEARCH ENGINE ---
    if ($ajaxAction === 'search_medicines') {
        load_medicines(); 
        $q = isset($_POST['query']) ? trim($_POST['query']) : (isset($_GET['query']) ? trim($_GET['query']) : '');
        $results = array(); 
        $limit = 50; 
        
        // Prepare search terms (split by space for multi-word search)
        $terms = $q === '' ? [] : explode(' ', strtolower($q));

        foreach ($medicines as $m) {
            // If empty search, just return first $limit items
            if ($q === '') { 
                $results[] = $m; 
                if (count($results) >= $limit) break; 
                continue; 
            }
            
            // Build searchable string
            $searchString = strtolower(
                $m['id'] . ' ' . 
                (isset($m['brand_name']) ? $m['brand_name'] : '') . ' ' . 
                (isset($m['generic_name']) ? $m['generic_name'] : '') . ' ' . 
                (isset($m['company']) ? $m['company'] : '') . ' ' . 
                (isset($m['batch_no']) ? $m['batch_no'] : '')
            );

            // Check if ALL typed terms exist anywhere in the string
            $match = true;
            foreach ($terms as $term) {
                if (strpos($searchString, $term) === false) {
                    $match = false;
                    break;
                }
            }

            // Scoring system for better relevance sorting
            if ($match) {
                $score = 0;
                // Exact Barcode/ID match gets highest priority
                if ((string)$m['id'] === $terms[0] || (isset($m['batch_no']) && strtolower($m['batch_no']) === $terms[0])) {
                    $score += 50; 
                }
                // Prefix match on Brand Name gets good priority
                if (isset($m['brand_name']) && strpos(strtolower($m['brand_name']), $terms[0]) === 0) {
                    $score += 20;
                }
                $m['search_score'] = $score;
                $results[] = $m;
            }
        }
        
        // Sort results by score if query exists
        if ($q !== '') {
            usort($results, function($a, $b) {
                return $b['search_score'] <=> $a['search_score'];
            });
            // Limit output after sorting
            $results = array_slice($results, 0, $limit);
        }
        
        echo json_encode(array('success' => true, 'medicines' => $results)); exit;
    }
    
    if ($ajaxAction === 'add_customer') {
        $newCust = array('id' => getNextId(isset($allData['customers']) ? $allData['customers'] : array(), 'id'), 'name' => isset($_POST['name']) ? $_POST['name'] : (isset($_GET['name']) ? $_GET['name'] : ''), 'phone' => isset($_POST['phone']) ? $_POST['phone'] : (isset($_GET['phone']) ? $_GET['phone'] : ''), 'email' => isset($_POST['email']) ? $_POST['email'] : (isset($_GET['email']) ? $_GET['email'] : ''), 'address' => '', 'total_purchases' => 0, 'joined_date' => date('Y-m-d'));
        if (!isset($allData['customers'])) $allData['customers'] = array();
        $allData['customers'][] = $newCust; write_json(ALL_DATA_FILE, $allData); echo json_encode(array('success' => true, 'customer' => $newCust)); exit;
    }
    
    if ($ajaxAction === 'get_bill') {
        $invoiceNo = isset($_POST['invoice_no']) ? $_POST['invoice_no'] : (isset($_GET['invoice_no']) ? $_GET['invoice_no'] : ''); $found = null;
        foreach ($allData['sales'] as $sale) { if ($sale['invoice_no'] === $invoiceNo) { $found = $sale; break; } }
        if($found) { echo json_encode(array('success' => true, 'bill' => $found, 'html' => generateInvoiceHTML($found, $allData['settings'], $allData['customers']))); } 
        else { echo json_encode(array('success' => false)); } exit;
    }
    
    if ($ajaxAction === 'get_dashboard_stats') {
        $totalSales = 0; $totalProfit = 0; $totalExpenses = 0;
        if (isset($allData['sales'])) $totalSales = array_sum(array_column($allData['sales'], 'total'));
        if (isset($allData['profit_loss_logs'])) $totalProfit = array_sum(array_column($allData['profit_loss_logs'], 'profit'));
        if (isset($allData['expenses'])) $totalExpenses = array_sum(array_column($allData['expenses'], 'amount'));
        echo json_encode(array('success' => true, 'total_sales' => $totalSales, 'total_profit' => $totalProfit, 'total_expenses' => $totalExpenses, 'low_stock' => isset($allData['settings']['cached_low_stock']) ? $allData['settings']['cached_low_stock'] : 0, 'currency' => $allData['settings']['currency'])); exit;
    }
    
    if ($ajaxAction === 'get_recent_sales') {
        $recent = array_slice($allData['sales'], -8); $formatted = array();
        foreach(array_reverse($recent) as $sale) { 
            $status = 'Paid';
            if($sale['change'] < 0) { $status = 'Due'; if($sale['paid'] > 0 && $sale['paid'] < $sale['total']) $status = 'Partial'; }
            $formatted[] = array(
                'invoice_no' => $sale['invoice_no'], 
                'date' => date('d M Y, H:i', strtotime($sale['date'])), 
                'total' => number_format($sale['total'], 2), 
                'profit' => number_format($sale['profit_earned'], 2), 
                'cashier' => $sale['cashier'],
                'status' => $status,
                'paid' => number_format($sale['paid'], 2),
                'due' => $sale['change'] < 0 ? number_format(abs($sale['change']), 2) : '0.00'
            ); 
        }
        echo json_encode(array('success' => true, 'sales' => $formatted, 'currency' => $allData['settings']['currency'])); exit;
    }
}

$message = isset($_GET['msg']) ? $_GET['msg'] : '';
$messageType = isset($_GET['msg_type']) ? $_GET['msg_type'] : 'success';
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : 'dashboard');

// LOGIN & AUTHENTICATION
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? $_POST['username'] : ''; $password = isset($_POST['password']) ? $_POST['password'] : ''; $userFound = false;
    foreach ($allData['users'] as $user) {
        if (strtolower($user['username']) === strtolower($username) && password_verify($password, $user['password'])) { $_SESSION['user'] = $user; $userFound = true; break; }
    }
    if ($userFound) { header('Location: ?action=dashboard'); exit; } else { $message = "Invalid credentials!"; $messageType = 'error'; }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }
if (empty($_SESSION['user']) && $action !== 'login') { header('Location: ?action=login'); exit; }

if (isset($_SESSION['user']) && $_SESSION['user']['role'] !== 'Admin' && in_array($action, array('users_manage', 'settings', 'reports', 'expenses', 'purchase', 'suppliers'))) {
    header('Location: ?action=dashboard&msg='.urlencode('Access Denied. Admins Only!').'&msg_type=error'); exit;
}

// -------------------------------
// DATABASE BACKUP & RESTORE & EXPORT
// -------------------------------
if ($action === 'backup_database' && $_SESSION['user']['role'] === 'Admin') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="pharmacy_backup_'.date('Y-m-d_H-i-s').'.json"');
    load_medicines();
    $backupData = array('allData' => $allData, 'medicines' => $medicines);
    echo json_encode($backupData, JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'restore_database' && $_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['user']['role'] === 'Admin') {
    if(isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $fileData = file_get_contents($_FILES['backup_file']['tmp_name']);
        $decoded = json_decode($fileData, true);
        if ($decoded && isset($decoded['allData']) && isset($decoded['medicines'])) {
            write_json(ALL_DATA_FILE, $decoded['allData']);
            write_json(MEDICINE_FILE, $decoded['medicines']);
            header('Location: ?action=settings&msg='.urlencode('Database successfully restored! Please login again.'));
            session_destroy(); exit;
        } else {
            header('Location: ?action=settings&msg='.urlencode('Invalid backup file format!').'&msg_type=error'); exit;
        }
    }
    header('Location: ?action=settings&msg='.urlencode('Failed to upload backup file!').'&msg_type=error'); exit;
}

if ($action === 'export_bills') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bills_export_'.date('Y-m-d').'.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Invoice No', 'Date', 'Customer', 'Total', 'Discount', 'Paid', 'Due', 'Change', 'Profit', 'Payment Method', 'Cashier', 'Status'));
    foreach(array_reverse($allData['sales']) as $s) {
        $custName = $s['customer_id'] > 0 ? getCustomerName($allData['customers'], $s['customer_id']) : 'Walk-in';
        $due = $s['change'] < 0 ? abs($s['change']) : 0;
        $status = 'Paid';
        if($s['change'] < 0) { $status = 'Due'; if($s['paid'] > 0 && $s['paid'] < $s['total']) $status = 'Partial'; }
        fputcsv($output, array($s['invoice_no'], $s['date'], $custName, $s['total'], $s['discount_amount'], $s['paid'], $due, $s['change'], $s['profit_earned'], $s['payment_method'], $s['cashier'], $status));
    }
    fclose($output); exit;
}

// -------------------------------
// CRUD OPERATIONS (BACKEND ONLY)
// -------------------------------
if ($action === 'add_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']); $email = trim($_POST['email']); $password = $_POST['password']; $role = $_POST['role']; $exists = false;
    foreach ($allData['users'] as $u) { if (strtolower($u['username']) === strtolower($username)) { $exists = true; break; } }
    if ($exists) { header('Location: ?action=users_manage&msg='.urlencode('Username already exists!').'&msg_type=error'); exit; } 
    else { $allData['users'][] = array('id' => getNextId($allData['users'], 'id'), 'username' => $username, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role); write_json(ALL_DATA_FILE, $allData); header('Location: ?action=users_manage&msg='.urlencode('User added successfully!')); exit; }
}

if ($action === 'delete_user' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id === $_SESSION['user']['id']) { header('Location: ?action=users_manage&msg='.urlencode('You cannot delete yourself!').'&msg_type=error'); exit; } 
    else { $newUsers = array(); foreach ($allData['users'] as $u) { if ($u['id'] !== $id) $newUsers[] = $u; } $allData['users'] = array_values($newUsers); write_json(ALL_DATA_FILE, $allData); header('Location: ?action=users_manage&msg='.urlencode('User deleted successfully!')); exit; }
}

if ($action === 'delete_bill' && isset($_GET['invoice_no'])) {
    $invNo = trim($_GET['invoice_no']); $saleIndex = null; $saleData = null;
    foreach ($allData['sales'] as $idx => $sale) { if ($sale['invoice_no'] === $invNo) { $saleIndex = $idx; $saleData = $sale; break; } }
    if ($saleData) {
        load_medicines();
        foreach ($saleData['items'] as $item) { foreach ($medicines as &$mRef) { if ($mRef['id'] == $item['id']) { $mRef['stock_status'] = 'In Stock'; unset($mRef['stock_quantity']); break; } } unset($mRef); }
        save_medicines();
        if ($saleData['customer_id'] > 0) { foreach ($allData['customers'] as &$cRef) { if ($cRef['id'] == $saleData['customer_id']) { $cRef['total_purchases'] -= $saleData['total']; break; } } unset($cRef); }
        array_splice($allData['sales'], $saleIndex, 1);
        foreach ($allData['bill_history'] as $idx => $bh) { if ($bh['invoice_no'] === $invNo) { array_splice($allData['bill_history'], $idx, 1); break; } }
        if (isset($allData['profit_loss_logs'])) { foreach ($allData['profit_loss_logs'] as $idx => $pl) { if ($pl['invoice_no'] === $invNo) { array_splice($allData['profit_loss_logs'], $idx, 1); break; } } }
        write_json(ALL_DATA_FILE, $allData); header("Location: ?action=bill_history&msg=" . urlencode("Invoice $invNo deleted securely.")); exit;
    }
}

if ($action === 'add_medicine' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    load_medicines(); $medicines[] = array('id' => getNextId($medicines), 'brand_name' => $_POST['brand_name'], 'generic_name' => $_POST['generic_name'], 'company' => $_POST['company'], 'category' => $_POST['category'], 'purchase_price' => (float)$_POST['purchase_price'], 'selling_price' => (float)$_POST['selling_price'], 'stock_status' => $_POST['stock_status'], 'batch_no' => $_POST['batch_no'], 'expiry_date' => $_POST['expiry_date'], 'location' => isset($_POST['location']) ? $_POST['location'] : ''); save_medicines(); header("Location: ?action=stocks&msg=" . urlencode("Medicine added successfully!")); exit;
}

if ($action === 'edit_medicine' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    load_medicines(); $id = (int)$_POST['id'];
    foreach ($medicines as &$mRef) { if ($mRef['id'] === $id) { $mRef['brand_name'] = $_POST['brand_name']; $mRef['generic_name'] = $_POST['generic_name']; $mRef['company'] = $_POST['company']; $mRef['category'] = $_POST['category']; $mRef['purchase_price'] = (float)$_POST['purchase_price']; $mRef['selling_price'] = (float)$_POST['selling_price']; $mRef['stock_status'] = $_POST['stock_status']; $mRef['batch_no'] = $_POST['batch_no']; $mRef['expiry_date'] = $_POST['expiry_date']; $mRef['location'] = isset($_POST['location']) ? $_POST['location'] : ''; unset($mRef['stock_quantity']); break; } }
    unset($mRef);
    save_medicines(); header("Location: ?action=stocks&msg=" . urlencode("Medicine updated successfully!")); exit;
}

if ($action === 'delete_medicine' && isset($_GET['id'])) {
    load_medicines(); $id = (int)$_GET['id']; $newMedicines = array(); foreach ($medicines as $m) { if ($m['id'] !== $id) $newMedicines[] = $m; }
    $medicines = array_values($newMedicines); save_medicines(); header("Location: ?action=stocks&msg=" . urlencode("Medicine deleted.")); exit;
}

if ($action === 'add_expense' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $allData['expenses'][] = array('id' => getNextId($allData['expenses']), 'date' => $_POST['date'], 'description' => $_POST['description'], 'amount' => (float)$_POST['amount'], 'added_by' => $_SESSION['user']['username']);
    write_json(ALL_DATA_FILE, $allData); header('Location: ?action=expenses&msg='.urlencode('Expense added successfully!')); exit;
}

if ($action === 'delete_expense' && isset($_GET['id'])) {
    $id = (int)$_GET['id']; $newExp = array(); foreach ($allData['expenses'] as $e) { if ($e['id'] !== $id) $newExp[] = $e; }
    $allData['expenses'] = $newExp; write_json(ALL_DATA_FILE, $allData); header('Location: ?action=expenses&msg='.urlencode('Expense deleted!')); exit;
}

if ($action === 'update_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $allData['settings']['pharmacy_name'] = $_POST['pharmacy_name']; $allData['settings']['phone'] = $_POST['phone']; $allData['settings']['address'] = $_POST['address']; $allData['settings']['currency'] = $_POST['currency'];
    if(isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0777, true); }
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION); $filename = 'logo_' . time() . '.' . $ext;
        if(move_uploaded_file($_FILES['logo']['tmp_name'], UPLOAD_DIR . $filename)) { $allData['settings']['logo_path'] = 'uploads/' . $filename; }
    }
    write_json(ALL_DATA_FILE, $allData); header('Location: ?action=settings&msg='.urlencode('Settings updated!')); exit;
}

// SUPPLIER & PURCHASE
if ($action === 'add_supplier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $allData['suppliers'][] = array('id' => getNextId($allData['suppliers']), 'name' => $_POST['name'], 'phone' => $_POST['phone'], 'address' => $_POST['address']);
    write_json(ALL_DATA_FILE, $allData); header('Location: ?action=suppliers&msg='.urlencode('Supplier added successfully!')); exit;
}

if ($action === 'delete_supplier' && isset($_GET['id'])) {
    $id = (int)$_GET['id']; $newSup = array(); foreach ($allData['suppliers'] as $s) { if ($s['id'] !== $id) $newSup[] = $s; }
    $allData['suppliers'] = $newSup; write_json(ALL_DATA_FILE, $allData); header('Location: ?action=suppliers&msg='.urlencode('Supplier deleted!')); exit;
}

if ($action === 'add_purchase' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if(!isset($allData['purchases'])) $allData['purchases'] = array();
    $allData['purchases'][] = array('id' => getNextId($allData['purchases']), 'supplier_id' => (int)$_POST['supplier_id'], 'invoice_no' => $_POST['invoice_no'], 'amount' => (float)$_POST['amount'], 'date' => $_POST['date'], 'notes' => $_POST['notes']);
    write_json(ALL_DATA_FILE, $allData); header('Location: ?action=purchase&msg='.urlencode('Purchase record added!')); exit;
}

if ($action === 'delete_purchase' && isset($_GET['id'])) {
    $id = (int)$_GET['id']; $newP = array(); foreach ($allData['purchases'] as $p) { if ($p['id'] !== $id) $newP[] = $p; }
    $allData['purchases'] = $newP; write_json(ALL_DATA_FILE, $allData); header('Location: ?action=purchase&msg='.urlencode('Purchase deleted!')); exit;
}

if ($action === 'add_customer_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $allData['customers'][] = array('id' => getNextId($allData['customers']), 'name' => $_POST['name'], 'phone' => $_POST['phone'], 'email' => $_POST['email'], 'address' => '', 'total_purchases' => 0, 'joined_date' => date('Y-m-d'));
    write_json(ALL_DATA_FILE, $allData); header('Location: ?action=customers&msg='.urlencode('Customer added!')); exit;
}

if ($action === 'delete_customer' && isset($_GET['id'])) {
    $id = (int)$_GET['id']; $newCust = array(); foreach ($allData['customers'] as $c) { if ($c['id'] !== $id) $newCust[] = $c; }
    $allData['customers'] = $newCust; write_json(ALL_DATA_FILE, $allData); header('Location: ?action=customers&msg='.urlencode('Customer deleted!')); exit;
}

// --- POS CHECKOUT ---
if ($action === 'checkout' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart'])) {
    load_medicines();
    $cart = json_decode($_POST['cart'], true);
    $subtotal = (float)$_POST['subtotal'];
    $discountAmount = isset($_POST['discount_amount']) ? (float)$_POST['discount_amount'] : 0;
    $total = max(0, $subtotal - $discountAmount);
    
    $paymentMethod = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $paidAmount = isset($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : $total;
    $changeDue = $paidAmount - $total;
    
    $isEdit = false; $oldSaleIdx = -1; $oldSaleData = null;
    if (!empty($_POST['update_invoice_no'])) {
        $invoiceNo = trim($_POST['update_invoice_no']);
        foreach ($allData['sales'] as $idx => $s) { if ($s['invoice_no'] === $invoiceNo) { $oldSaleIdx = $idx; $isEdit = true; $oldSaleData = $s; break; } }
        if ($isEdit) {
            foreach ($oldSaleData['items'] as $oldItem) { foreach ($medicines as &$mRef) { if ($mRef['id'] == $oldItem['id']) { $mRef['stock_status'] = 'In Stock'; break; } } unset($mRef); }
            if ($oldSaleData['customer_id'] > 0) { foreach ($allData['customers'] as &$cRef) { if ($cRef['id'] == $oldSaleData['customer_id']) { $cRef['total_purchases'] -= $oldSaleData['total']; break; } } unset($cRef); }
        }
    } else {
        $invoiceNo = 'INV-' . date('ymd') . '-' . str_pad((count($allData['sales']) + 1), 4, '0', STR_PAD_LEFT);
    }
    
    $errors = array();
    foreach ($cart as $item) {
        $found = false;
        foreach ($medicines as $med) {
            if ($med['id'] == $item['id']) {
                if (getStockStatus($med) === 'Out of Stock') { $errors[] = "{$med['brand_name']} is Out of Stock."; }
                $found = true; break;
            }
        }
        if (!$found) { $errors[] = "Medicine not found: {$item['name']}"; }
    }
    
    if (!empty($errors)) { header("Location: ?action=pos" . ($isEdit ? "&edit_invoice=".urlencode($invoiceNo) : "") . "&msg=" . urlencode(implode(", ", $errors)) . "&msg_type=error"); exit; }
    
    $profitTotal = 0;
    foreach ($cart as &$cItem) {
        foreach ($medicines as &$mRef) {
            if ($mRef['id'] == $cItem['id']) {
                $profitPerUnit = $cItem['price'] - $mRef['purchase_price'];
                $cItem['profit'] = $profitPerUnit * $cItem['quantity'];
                $profitTotal += $cItem['profit'];
                break;
            }
        }
        unset($mRef);
    }
    unset($cItem);
    save_medicines();
    $profitTotal -= $discountAmount;
    
    $saleRecord = array(
        'id' => $isEdit ? $oldSaleData['id'] : getNextId(isset($allData['sales']) ? $allData['sales'] : array(), 'id'),
        'invoice_no' => $invoiceNo, 'date' => $isEdit ? $oldSaleData['date'] : date('Y-m-d H:i:s'),
        'items' => $cart, 'subtotal' => $subtotal, 'discount_amount' => $discountAmount,
        'total' => $total, 'paid' => $paidAmount, 'change' => $changeDue,
        'profit_earned' => $profitTotal, 'payment_method' => $paymentMethod,
        'customer_id' => $customerId, 'cashier' => $_SESSION['user']['username']
    );
    
    if ($isEdit) {
        $allData['sales'][$oldSaleIdx] = $saleRecord;
        foreach ($allData['bill_history'] as &$bh) { if ($bh['invoice_no'] === $invoiceNo) { 
            $bh['customer_name'] = $customerId > 0 ? getCustomerName($allData['customers'], $customerId) : 'Walk-in Customer'; 
            $bh['total'] = $total; 
            $bh['cashier'] = $_SESSION['user']['username']; 
            $bh['paid'] = $paidAmount;
            $bh['status'] = $changeDue < 0 ? ($paidAmount > 0 && $paidAmount < $total ? 'Partial' : 'Due') : 'Paid';
            break; 
        } }
        unset($bh);
        if (isset($allData['profit_loss_logs'])) { foreach ($allData['profit_loss_logs'] as &$pl) { if ($pl['invoice_no'] === $invoiceNo) { $pl['amount'] = $total; $pl['profit'] = $profitTotal; break; } } unset($pl); }
    } else {
        $allData['sales'][] = $saleRecord;
        $status = $changeDue < 0 ? ($paidAmount > 0 && $paidAmount < $total ? 'Partial' : 'Due') : 'Paid';
        $allData['bill_history'][] = array(
            'invoice_no' => $invoiceNo, 
            'date' => date('Y-m-d H:i:s'), 
            'customer_name' => $customerId > 0 ? getCustomerName($allData['customers'], $customerId) : 'Walk-in Customer', 
            'total' => $total, 
            'cashier' => $_SESSION['user']['username'],
            'paid' => $paidAmount,
            'status' => $status
        );
        if (!isset($allData['profit_loss_logs'])) $allData['profit_loss_logs'] = array();
        $allData['profit_loss_logs'][] = array('id' => getNextId($allData['profit_loss_logs'], 'id'), 'date' => date('Y-m-d'), 'type' => 'Sale', 'invoice_no' => $invoiceNo, 'amount' => $total, 'profit' => $profitTotal);
    }
    
    if ($customerId > 0 && isset($allData['customers'])) {
        foreach ($allData['customers'] as &$custRef) { if ($custRef['id'] == $customerId) { $custRef['total_purchases'] += $total; break; } }
        unset($custRef);
    }
    
    write_json(ALL_DATA_FILE, $allData);
    $_SESSION['last_invoice'] = $saleRecord;
    
    $customerName = $customerId > 0 ? getCustomerName($allData['customers'], $customerId) : 'Walk-in Customer';
    $dueAmountStr = $changeDue < 0 ? abs($changeDue) : 0;
    $status = 'Paid';
    if ($changeDue < 0) { $status = 'Due'; if ($paidAmount > 0 && $paidAmount < $total) $status = 'Partial'; }
    $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $invoiceLink = $protocol . "://" . $domain . "/?action=pos&invoice_display=" . urlencode($invoiceNo);
    
    $tgMsg = "<b>" . ($isEdit ? "✏️ INVOICE UPDATED" : "🧾 NEW INVOICE GENERATED") . "</b>\n═══════════════════════\n\n<b>📋 Invoice ID:</b> <code>" . htmlspecialchars($invoiceNo) . "</code>\n<b>🔗 Invoice Link:</b> <a href='" . $invoiceLink . "'>Click to View</a>\n<b>👤 Customer:</b> " . htmlspecialchars($customerName) . "\n<b>📅 Date:</b> " . date('d-m-Y H:i:s') . "\n\n<b>📦 Items:</b>\n─────────────────\n";
    foreach ($cart as $item) {
        $netTotal = ($item['price'] * $item['quantity']) - (isset($item['dis_percent']) ? ($item['price'] * $item['quantity']) * ($item['dis_percent'] / 100) : 0);
        $tgMsg .= "• " . htmlspecialchars($item['name']) . " x" . $item['quantity'] . " = " . number_format($netTotal, 2) . " " . $allData['settings']['currency'] . "\n";
    }
    $tgMsg .= "─────────────────\n\n<b>💰 Payment Summary:</b>\n  Subtotal: " . number_format($subtotal, 2) . " " . $allData['settings']['currency'] . "\n  Discount: -" . number_format($discountAmount, 2) . " " . $allData['settings']['currency'] . "\n  <b>Grand Total:</b> " . number_format($total, 2) . " " . $allData['settings']['currency'] . "\n  Paid: " . number_format($paidAmount, 2) . " " . $allData['settings']['currency'] . "\n";
    if ($dueAmountStr > 0) { $tgMsg .= "  <b>Due Amount:</b> " . number_format($dueAmountStr, 2) . " " . $allData['settings']['currency'] . "\n"; }
    $tgMsg .= "  Status: <b>" . $status . "</b>\n\n<b>👨‍💼 Cashier:</b> " . htmlspecialchars($_SESSION['user']['username']) . "\n═══════════════════════\n📱 <i>Pharmacy System</i>";

    $_SESSION['tg_dispatch'] = array(
        'msg' => $tgMsg,
        'html' => generateInvoiceHTML($saleRecord, $allData['settings'], $allData['customers']),
        'inv' => $invoiceNo
    );

    header("Location: ?action=pos&invoice_display=" . urlencode($invoiceNo) . ($isEdit ? "&msg=Sale+Updated+Successfully" : ""));
    exit;
}

$invoiceDisplay = null;
if (isset($_GET['invoice_display'])) {
    $invNo = $_GET['invoice_display'];
    foreach ($allData['sales'] as $sale) { if ($sale['invoice_no'] === $invNo) { $invoiceDisplay = $sale; break; } }
}

$totalSales = isset($allData['sales']) ? array_sum(array_column($allData['sales'], 'total')) : 0;
$totalProfit = isset($allData['profit_loss_logs']) ? array_sum(array_column($allData['profit_loss_logs'], 'profit')) : 0;
$totalExpenses = isset($allData['expenses']) ? array_sum(array_column($allData['expenses'], 'amount')) : 0;
$themeClass = $_SESSION['theme'] === 'dark' ? 'dark' : '';
$systemLogo = !empty($allData['settings']['logo_path']) ? htmlspecialchars($allData['settings']['logo_path']) : '';
?>

<?php if ($action === 'login'): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login | <?php echo htmlspecialchars($allData['settings']['pharmacy_name']); ?> System</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Noto+Sans+Bengali:wght@100..900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Kanit', 'Noto Sans Bengali', sans-serif; }
        body { background: linear-gradient(145deg, #1e2a2e 0%, #1a2a2a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .login-container { width: 100%; max-width: 1100px; background: #ffffff; border-radius: 2rem; box-shadow: 0 25px 45px rgba(0, 0, 0, 0.25), 0 8px 18px rgba(0, 0, 0, 0.15); display: flex; overflow: hidden; transition: all 0.2s ease; }
        .left-panel { width: 34%; background: linear-gradient(145deg, #02b663, #01804a); position: relative; display: flex; flex-direction: column; justify-content: center; align-items: flex-end; padding: 2rem 0; overflow: hidden; }
        .bg-shape { position: absolute; background: rgba(255, 255, 255, 0.08); border-radius: 50%; filter: blur(60px); z-index: 0; }
        .shape-lg { width: 280px; height: 280px; top: -80px; right: -60px; background: #c4ffdf; opacity: 0.2; }
        .shape-sm { width: 180px; height: 180px; bottom: -40px; left: -50px; background: #fff3c9; opacity: 0.15; }
        .role-tabs { position: relative; z-index: 10; width: 100%; display: flex; flex-direction: column; align-items: flex-end; gap: 12px; padding-right: 0; }
        .role-btn { font-size: 1.1rem; font-weight: 600; padding: 14px 28px; width: 180px; text-align: left; cursor: pointer; transition: all 0.25s; border-radius: 40px 0 0 40px; background: rgba(255, 255, 255, 0.12); color: #f9f9f9; display: flex; align-items: center; gap: 12px; border: none; }
        .role-btn.active { background: #ffffff; color: #016d3e; font-weight: 700; }
        .right-panel { width: 66%; background: #ffffff; padding: 48px 52px; display: flex; flex-direction: column; justify-content: center; }
        .brand-logo { height: 85px; width: auto; max-width: 200px; object-fit: contain; margin: 0 auto 12px auto; display: block; filter: drop-shadow(0 6px 12px rgba(1, 111, 61, 0.2)); }
        .brand-header h2 { font-size: 2rem; font-weight: 700; background: linear-gradient(135deg, #018f4d, #02b663); -webkit-background-clip: text; color: transparent; text-align: center; }
        .role-badge { display: inline-block; background: #eef3f0; padding: 6px 18px; border-radius: 40px; font-size: 0.8rem; font-weight: 600; color: #01804a; margin-top: 8px; text-align: center; margin: 8px auto 0; display: table;}
        .input-group { position: relative; margin-bottom: 28px; }
        .input-group i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9aa6af; font-size: 1.4rem; }
        .input-group input { width: 100%; padding: 14px 14px 14px 54px; border: 1.5px solid #e2e8f0; border-radius: 40px; font-size: 0.95rem; font-weight: 500; color: #1e2a3a; background: #fefefe; outline: none; }
        .input-group input:focus { border-color: #02b663; box-shadow: 0 0 0 3px rgba(2, 182, 99, 0.2); }
        .submit-btn { background: linear-gradient(95deg, #01804a, #02b663); border: none; padding: 12px 42px; border-radius: 44px; font-weight: 700; font-size: 0.95rem; color: white; cursor: pointer; display: flex; align-items: center; gap: 10px; margin-left: auto; }
        @media (max-width: 780px) {
            .login-container { flex-direction: column; border-radius: 1.5rem; }
            .left-panel, .right-panel { width: 100%; align-items: center; }
            .role-tabs { flex-direction: row; justify-content: center; flex-wrap: wrap; }
            .role-btn { width: auto; border-radius: 60px; padding: 10px 24px; }
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="left-panel">
        <div class="bg-shape shape-lg"></div><div class="bg-shape shape-sm"></div>
        <div class="role-tabs">
            <button class="role-btn active" data-role="admin"><i class="ph-duotone ph-shield-check"></i> <span>Admin Login</span></button>
            <button class="role-btn" data-role="staff"><i class="ph-duotone ph-users-three"></i> <span>Staff Login</span></button>
            <button class="role-btn" data-role="customer"><i class="ph-duotone ph-user"></i> <span>Customer Login</span></button>
        </div>
    </div>
    <div class="right-panel">
        <div class="brand-header">
            <?php $loginLogo = $systemLogo ?: 'https://placehold.co/80x80/018f4d/FFFFFF?text=' . urlencode(substr($allData['settings']['pharmacy_name'], 0, 2)); ?>
            <img src="<?php echo $loginLogo; ?>" alt="Brand Logo" class="brand-logo">
            <h2 id="formTitle">ADMIN ACCESS</h2>
            <div class="role-badge" id="roleCaption">🔑 Administrator Portal</div>
        </div>
        <form class="login-form mt-4" method="POST" action="?action=login">
            <input type="hidden" name="action" value="login">
            <div class="input-group"><i class="ph-duotone ph-envelope"></i><input type="text" id="username" name="username" placeholder="Username" required></div>
            <div class="input-group"><i class="ph-duotone ph-lock-key"></i><input type="password" id="password" name="password" placeholder="Password" required></div>
            <button type="submit" class="submit-btn"><i class="ph-bold ph-sign-in"></i> Sign In</button>
        </form>
        <?php if (!empty($message)): ?>
            <div style="margin-top:20px; background:#fff2f0; padding:12px; border-radius:12px; color:#bc3900; border-left:4px solid #e55c3c; font-size:0.85rem;">
                <i class="ph-fill ph-warning-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
    const roleBtns = document.querySelectorAll('.role-btn');
    roleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            roleBtns.forEach(b => b.classList.remove('active')); btn.classList.add('active');
            let role = btn.getAttribute('data-role');
            document.getElementById('formTitle').innerText = role === 'admin' ? 'ADMIN ACCESS' : (role === 'staff' ? 'STAFF PORTAL' : 'CUSTOMER LOGIN');
            document.getElementById('roleCaption').innerText = role === 'admin' ? '🔑 Administrator Portal' : (role === 'staff' ? '👔 Staff Management Zone' : '👤 Customer Dashboard');
        });
    });
</script>
</body>
</html>
<?php exit; endif; ?>

<!DOCTYPE html>
<html lang="en" class="<?php echo $themeClass; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($allData['settings']['pharmacy_name']); ?> | Pharmacy Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Noto+Sans+Bengali:wght@100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Kanit', 'Noto Sans Bengali', 'sans-serif'] }, colors: { primary: { 50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 300: '#6ee7b7', 400: '#34d399', 500: '#10b981', 600: '#059669', 700: '#047857', 800: '#065f46', 900: '#064e3b' } } } } }
    </script>
    <style>
        body { font-family: 'Kanit', 'Noto Sans Bengali', sans-serif; }
        .scrollbar-thin::-webkit-scrollbar { width: 6px; height: 6px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dark .scrollbar-thin::-webkit-scrollbar-track { background: #1f2937; }
        .dark .scrollbar-thin::-webkit-scrollbar-thumb { background: #4b5563; }
        .animate-slide-up { animation: slideUp 0.3s ease-out; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .hide-arrows::-webkit-outer-spin-button, .hide-arrows::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .hide-arrows { -moz-appearance: textfield; }
        .telegram-badge { display: inline-flex; align-items: center; gap: 6px; background: #0088cc; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .telegram-badge i { font-size: 14px; }
        .status-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-due { background: #fef3c7; color: #92400e; }
        .status-partial { background: #fde68a; color: #92400e; }
        .dark .status-paid { background: #064e3b; color: #6ee7b7; }
        .dark .status-due { background: #78350f; color: #fcd34d; }
        .dark .status-partial { background: #78350f; color: #fbbf24; }
        .payment-summary { background: #f8fafc; border-radius: 12px; padding: 8px 12px; margin-top: 6px; font-size: 0.9rem; }
        .dark .payment-summary { background: #1e293b; }
        .payment-summary .label { color: #64748b; }
        .dark .payment-summary .label { color: #94a3b8; }
        .payment-summary .value { font-weight: 600; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 font-sans antialiased text-gray-800 dark:text-gray-100">

<div class="flex h-screen w-full overflow-hidden flex-col md:flex-row">
    
    <!-- HEADER FOR MOBILE -->
    <header class="md:hidden bg-white dark:bg-gray-800 shadow z-20 px-4 py-3 flex items-center justify-between border-b dark:border-gray-700">
        <div class="flex items-center gap-2">
            <?php if($systemLogo): ?><img src="<?php echo $systemLogo; ?>" class="h-8 w-auto object-contain"><?php else: ?><i class="ph-duotone ph-pill text-primary-500 text-3xl"></i><?php endif; ?>
            <span class="font-extrabold text-xl bg-gradient-to-r from-primary-600 to-emerald-600 bg-clip-text text-transparent"><?php echo htmlspecialchars($allData['settings']['pharmacy_name']); ?></span>
        </div>
        <button id="mobileMenuBtn" class="text-gray-600 dark:text-gray-300 p-2"><i class="ph-bold ph-list text-2xl"></i></button>
    </header>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden transition-opacity opacity-0 md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="sidebar" class="fixed md:static inset-y-0 left-0 z-50 w-72 md:w-80 bg-white dark:bg-gray-800 shadow-2xl flex flex-col transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out border-r dark:border-gray-700 h-full">
        <div class="p-6 border-b dark:border-gray-700 relative">
            <button id="closeSidebarBtn" class="md:hidden absolute top-4 right-4 text-gray-500 hover:text-red-500 bg-gray-100 dark:bg-gray-700 rounded-full p-1.5"><i class="ph-bold ph-x text-lg"></i></button>
            <div class="flex items-center space-x-3 mt-2 md:mt-0">
                <?php if($systemLogo): ?>
                    <div class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center shadow-lg p-1.5 border border-gray-100"><img src="<?php echo $systemLogo; ?>" class="max-w-full max-h-full object-contain"></div>
                <?php else: ?>
                    <div class="w-12 h-12 bg-gradient-to-br from-primary-500 to-emerald-600 rounded-2xl flex items-center justify-center shadow-lg"><i class="ph-duotone ph-pill text-white text-3xl"></i></div>
                <?php endif; ?>
                <div>
                    <span class="font-extrabold text-2xl bg-gradient-to-r from-primary-600 to-emerald-600 bg-clip-text text-transparent whitespace-nowrap overflow-hidden text-ellipsis w-48 block" title="<?php echo htmlspecialchars($allData['settings']['pharmacy_name']); ?>"><?php echo htmlspecialchars($allData['settings']['pharmacy_name']); ?></span>
                    <p class="text-xs text-gray-500">Version 10.2 | <?php echo $_SESSION['user']['role']; ?></p>
                </div>
            </div>
            <div class="mt-5 pt-3 border-t dark:border-gray-700 flex items-center space-x-3">
                <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-600"><i class="ph-duotone ph-user-circle text-3xl"></i></div>
                <div class="flex-1"><p class="text-sm font-semibold"><?php echo htmlspecialchars($_SESSION['user']['username']); ?></p><p class="text-xs text-gray-500"><?php echo $_SESSION['user']['role']; ?> Access</p></div>
            </div>
        </div>
        
        <nav class="flex-1 p-4 space-y-2 overflow-y-auto scrollbar-thin">
            <?php 
            $navItems = array(
                array('action' => 'dashboard', 'icon' => 'squares-four', 'label' => 'Dashboard', 'color' => 'text-primary-600'),
                array('action' => 'pos', 'icon' => 'storefront', 'label' => 'Point of Sale', 'color' => 'text-emerald-600'),
                array('action' => 'bill_history', 'icon' => 'clock-counter-clockwise', 'label' => 'Bill History', 'color' => 'text-blue-600'),
                array('action' => 'stocks', 'icon' => 'database', 'label' => 'Inventory', 'color' => 'text-purple-600'),
                array('action' => 'customers', 'icon' => 'users', 'label' => 'Customers', 'color' => 'text-cyan-600'),
            ); 
            if($_SESSION['user']['role'] === 'Admin') {
                $navItems[] = array('action' => 'suppliers', 'icon' => 'truck', 'label' => 'Suppliers', 'color' => 'text-yellow-600');
                $navItems[] = array('action' => 'purchase', 'icon' => 'shopping-bag', 'label' => 'Purchases', 'color' => 'text-orange-600');
                $navItems[] = array('action' => 'expenses', 'icon' => 'wallet', 'label' => 'Expenses', 'color' => 'text-red-600');
                $navItems[] = array('action' => 'reports', 'icon' => 'trend-up', 'label' => 'Analytics', 'color' => 'text-indigo-600');
                $navItems[] = array('action' => 'users_manage', 'icon' => 'shield-check', 'label' => 'User Management', 'color' => 'text-pink-600');
                $navItems[] = array('action' => 'settings', 'icon' => 'gear', 'label' => 'Settings', 'color' => 'text-gray-600');
            }
            foreach($navItems as $item): ?>
                <a href="?action=<?php echo $item['action']; ?>" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-primary-50 dark:hover:bg-gray-700/50 transition-all group <?php echo $action == $item['action'] ? 'bg-primary-50 dark:bg-gray-700 text-primary-700 dark:text-primary-400 font-semibold' : ''; ?>">
                    <i class="ph-duotone ph-<?php echo $item['icon']; ?> text-2xl <?php echo $item['color']; ?> group-hover:scale-110 transition-transform"></i>
                    <span class="text-sm"><?php echo $item['label']; ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        
        <div class="p-4 border-t dark:border-gray-700 space-y-2">
            <div class="flex items-center justify-between px-4 py-2.5 rounded-xl bg-blue-50 dark:bg-blue-900/20">
                <span class="flex items-center text-sm text-blue-600 dark:text-blue-400"><i class="ph-fill ph-telegram-logo text-xl mr-2"></i> Telegram</span>
                <span class="telegram-badge"><i class="ph-fill ph-check-circle"></i> Active</span>
            </div>
            <a href="?toggle_theme=1" class="flex items-center justify-between w-full px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700/50 hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <span class="flex items-center text-sm"><i class="ph-duotone <?php echo $_SESSION['theme'] === 'dark' ? 'ph-sun' : 'ph-moon'; ?> text-xl mr-3 text-primary-500"></i><?php echo $_SESSION['theme'] === 'dark' ? 'Light Mode' : 'Dark Mode'; ?></span>
            </a>
            <a href="?logout=1" class="flex items-center justify-between w-full px-4 py-2.5 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-600 hover:bg-red-100 transition">
                <span class="flex items-center text-sm"><i class="ph-duotone ph-sign-out text-xl mr-3"></i>Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-6 scrollbar-thin relative w-full pb-20 md:pb-6">
        
        <?php if($message): ?>
            <div class="fixed top-4 right-4 z-[60] bg-white dark:bg-gray-800 shadow-2xl rounded-xl p-4 border-l-4 <?php echo $messageType === 'error' ? 'border-red-500' : 'border-green-500'; ?> animate-slide-up" id="toastBox">
                <div class="flex items-center"><i class="ph-fill <?php echo $messageType === 'error' ? 'ph-warning-circle text-red-500' : 'ph-check-circle text-green-500'; ?> text-2xl mr-3"></i><p class="text-sm font-medium <?php echo $messageType === 'error' ? 'text-red-500' : ''; ?>"><?php echo htmlspecialchars($message); ?></p></div>
            </div>
            <script>setTimeout(() => { document.getElementById('toastBox')?.remove(); }, 5000);</script>
        <?php endif; ?>

        <!-- DASHBOARD -->
        <?php if($action === 'dashboard'): ?>
        <?php 
            load_medicines();
            $lowStockMeds = array(); $expiringMeds = array(); $thirtyDays = time() + (30 * 24 * 60 * 60);
            foreach ($medicines as $m) {
                if (getStockStatus($m) === 'Out of Stock') { $lowStockMeds[] = $m; }
                if (!empty($m['expiry_date']) && strtotime($m['expiry_date']) <= $thirtyDays) { $expiringMeds[] = $m; }
            }
        ?>
        <div class="space-y-6 animate-slide-up">
            <div class="bg-gradient-to-r from-primary-500 to-emerald-600 rounded-2xl p-5 md:p-6 text-white shadow-xl flex justify-between items-center">
                <div><h1 class="text-xl md:text-2xl font-bold">Welcome back, <?php echo htmlspecialchars($_SESSION['user']['username']); ?>!</h1><p class="opacity-90 mt-1 text-sm">Here's what's happening with your pharmacy today.</p></div>
                <div class="bg-white/20 px-4 py-2 rounded-xl backdrop-blur-sm hidden md:block"><div class="text-xs opacity-80 uppercase">Today's Date</div><div class="text-xl font-bold"><?php echo date('d M, Y'); ?></div></div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-5">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-5 border border-gray-100 dark:border-gray-700">
                    <div class="flex justify-between items-start"><div><p class="text-gray-500 text-sm font-medium">Total Sales</p><h3 class="text-xl font-bold mt-1 realtime-sales"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($totalSales, 2); ?></h3></div><div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-2xl flex items-center justify-center"><i class="ph-duotone ph-trend-up text-3xl text-green-600"></i></div></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-5 border border-gray-100 dark:border-gray-700">
                    <div class="flex justify-between items-start"><div><p class="text-gray-500 text-sm">Gross Profit</p><h3 class="text-xl font-bold mt-1 realtime-profit"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($totalProfit, 2); ?></h3></div><div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/30 rounded-2xl flex items-center justify-center"><i class="ph-duotone ph-coins text-3xl text-purple-600"></i></div></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-5 border border-gray-100 dark:border-gray-700">
                    <div class="flex justify-between items-start"><div><p class="text-gray-500 text-sm">Expenses</p><h3 class="text-xl font-bold mt-1 text-red-600 realtime-expenses"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($totalExpenses, 2); ?></h3></div><div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-2xl flex items-center justify-center"><i class="ph-duotone ph-receipt text-3xl text-red-600"></i></div></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-5 border border-gray-100 dark:border-gray-700">
                    <div class="flex justify-between items-start"><div><p class="text-gray-500 text-sm">Total Medicines</p><h3 class="text-xl font-bold mt-1"><?php echo number_format(isset($allData['settings']['cached_total_medicines']) ? $allData['settings']['cached_total_medicines'] : 0); ?></h3></div><div class="w-12 h-12 bg-cyan-100 dark:bg-cyan-900/30 rounded-2xl flex items-center justify-center"><i class="ph-duotone ph-pill text-3xl text-cyan-600"></i></div></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-5">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-red-100 dark:border-red-900/30">
                    <div class="px-5 py-4 border-b border-red-100 dark:border-red-900/30 bg-red-50 dark:bg-red-900/20 flex justify-between items-center"><h3 class="font-bold text-red-700 dark:text-red-400"><i class="ph-duotone ph-warning-circle mr-2"></i>Out of Stock Alerts</h3><span class="bg-red-600 text-white px-2 py-0.5 rounded-full text-xs font-bold"><?php echo count($lowStockMeds); ?> items</span></div>
                    <div class="p-4 overflow-y-auto max-h-60 scrollbar-thin">
                        <?php if(empty($lowStockMeds)): ?><p class="text-gray-500 text-sm text-center py-4">All items are in stock.</p><?php else: ?>
                            <ul class="space-y-3">
                                <?php foreach(array_slice($lowStockMeds, 0, 10) as $med): ?>
                                    <li class="flex justify-between items-center bg-gray-50 dark:bg-gray-700/50 p-2 rounded-lg">
                                        <div><p class="font-bold text-sm"><?php echo htmlspecialchars($med['brand_name']); ?></p><p class="text-xs text-gray-500"><?php echo htmlspecialchars($med['company']); ?></p></div>
                                        <a href="?action=edit_medicine&id=<?php echo $med['id']; ?>" class="text-blue-500 text-xs hover:underline">Update</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-orange-100 dark:border-orange-900/30">
                    <div class="px-5 py-4 border-b border-orange-100 dark:border-orange-900/30 bg-orange-50 dark:bg-orange-900/20 flex justify-between items-center"><h3 class="font-bold text-orange-700 dark:text-orange-400"><i class="ph-duotone ph-calendar-warning mr-2"></i>Expiring Soon (< 30 days)</h3><span class="bg-orange-600 text-white px-2 py-0.5 rounded-full text-xs font-bold"><?php echo count($expiringMeds); ?> items</span></div>
                    <div class="p-4 overflow-y-auto max-h-60 scrollbar-thin">
                        <?php if(empty($expiringMeds)): ?><p class="text-gray-500 text-sm text-center py-4">No items expiring soon.</p><?php else: ?>
                            <ul class="space-y-3">
                                <?php foreach(array_slice($expiringMeds, 0, 10) as $med): ?>
                                    <li class="flex justify-between items-center bg-gray-50 dark:bg-gray-700/50 p-2 rounded-lg">
                                        <div><p class="font-bold text-sm"><?php echo htmlspecialchars($med['brand_name']); ?></p><p class="text-xs font-semibold text-orange-600 dark:text-orange-400">Exp: <?php echo date('d M, Y', strtotime($med['expiry_date'])); ?></p></div>
                                        <a href="?action=edit_medicine&id=<?php echo $med['id']; ?>" class="text-blue-500 text-xs hover:underline">Manage</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
                <div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30 flex justify-between items-center"><h3 class="font-bold text-lg"><i class="ph-duotone ph-receipt mr-2 text-primary-500"></i>Recent Sales</h3><a href="?action=bill_history" class="text-primary-600 text-sm hover:underline">View All</a></div>
                <div class="overflow-x-auto pb-2">
                    <table class="w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-500 uppercase"><tr><th class="p-4">Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Cashier</th></tr></thead>
                        <tbody id="recentTransactions" class="text-sm">
                            <?php $recent = array_slice($allData['sales'], -8); foreach(array_reverse($recent) as $sale): 
                                $status = 'Paid';
                                $due = 0;
                                if($sale['change'] < 0) { 
                                    $status = 'Due'; 
                                    $due = abs($sale['change']);
                                    if($sale['paid'] > 0 && $sale['paid'] < $sale['total']) $status = 'Partial'; 
                                }
                                $statusClass = $status === 'Paid' ? 'status-paid' : ($status === 'Partial' ? 'status-partial' : 'status-due');
                            ?>
                            <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                <td class="p-4 font-mono font-bold"><?php echo htmlspecialchars($sale['invoice_no']); ?></td>
                                <td class="text-gray-500"><?php echo date('d M Y, H:i', strtotime($sale['date'])); ?></td>
                                <td class="font-semibold text-green-600"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($sale['total'], 2); ?></td>
                                <td class="font-semibold text-blue-600"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($sale['paid'], 2); ?></td>
                                <td class="font-semibold <?php echo $due > 0 ? 'text-red-600' : 'text-gray-400'; ?>"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($due, 2); ?></td>
                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                                <td><span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded-full text-xs"><?php echo htmlspecialchars($sale['cashier']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        function refreshDashboard() {
            $.ajax({ 
                url: '?ajax=1&action=get_dashboard_stats', method: 'GET', dataType: 'json', xhrFields: { withCredentials: true },
                error: function(xhr) { if(xhr.responseText && xhr.responseText.includes('Cookies')) window.location.reload(); },
                success: function(res) {
                    if(res.success) {
                        $('.realtime-sales').text(res.currency + ' ' + parseFloat(res.total_sales).toFixed(2));
                        $('.realtime-profit').text(res.currency + ' ' + parseFloat(res.total_profit).toFixed(2));
                        $('.realtime-expenses').text(res.currency + ' ' + parseFloat(res.total_expenses).toFixed(2));
                    }
                }
            });
            $.ajax({ 
                url: '?ajax=1&action=get_recent_sales', method: 'GET', dataType: 'json', xhrFields: { withCredentials: true },
                success: function(res) {
                    if(res.success && res.sales) { 
                        let html = ''; 
                        res.sales.forEach(function(sale) { 
                            let statusClass = sale.status === 'Paid' ? 'status-paid' : (sale.status === 'Partial' ? 'status-partial' : 'status-due');
                            html += '<tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/30"><td class="p-4 font-mono font-bold">'+sale.invoice_no+'</td><td class="text-gray-500">'+sale.date+'</td><td class="font-semibold text-green-600">'+res.currency+' '+sale.total+'</td><td class="font-semibold text-blue-600">'+res.currency+' '+sale.paid+'</td><td class="font-semibold '+(parseFloat(sale.due)>0?'text-red-600':'text-gray-400')+'">'+res.currency+' '+sale.due+'</td><td><span class="status-badge '+statusClass+'">'+sale.status+'</span></td><td><span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded-full text-xs">'+sale.cashier+'</span></td></tr>'; 
                        }); 
                        $('#recentTransactions').html(html); 
                    }
                }
            });
        }
        setInterval(refreshDashboard, 15000);
        </script>
        <?php endif; ?>

        <!-- POS MODULE - ENHANCED WITH OPTIMIZED SEARCH & PAID/DUE DISPLAY -->
        <?php if($action === 'pos'): ?>
        <?php 
        $editInvoiceData = null;
        if(isset($_GET['edit_invoice'])) {
            foreach($allData['sales'] as $sale) {
                if($sale['invoice_no'] === $_GET['edit_invoice']) {
                    $editInvoiceData = $sale;
                    break;
                }
            }
        }
        ?>
        <?php if($editInvoiceData): ?>
            <div class="bg-blue-100 dark:bg-blue-900/30 border-l-4 border-blue-500 text-blue-700 dark:text-blue-200 p-4 rounded-xl mb-4 font-bold flex items-center justify-between shadow-md animate-slide-up">
                <span class="flex items-center text-sm md:text-base"><i class="ph-duotone ph-pencil-simple text-xl mr-2"></i> Editing: <?php echo htmlspecialchars($_GET['edit_invoice']); ?></span>
                <a href="?action=pos" class="text-xs md:text-sm bg-white dark:bg-gray-800 px-3 py-1.5 md:px-4 md:py-2 rounded-lg shadow text-blue-700 dark:text-blue-300 hover:bg-gray-50 transition">Cancel</a>
            </div>
        <?php endif; ?>
        
        <div class="grid lg:grid-cols-3 gap-4 md:gap-6 animate-slide-up">
            <div class="lg:col-span-2 space-y-4">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 md:p-5 border-t-4 border-primary-500">
                    <div class="relative flex items-center">
                        <i class="ph-bold ph-barcode absolute left-4 text-gray-400 text-2xl"></i>
                        <input type="text" id="searchMedicine" placeholder="Scan Barcode (Enter) or Search Medicine..." class="w-full pl-12 pr-4 py-3 md:py-4 border border-gray-200 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:text-white text-base shadow-inner">
                        <span class="absolute right-4 text-xs text-gray-400 flex items-center gap-1"><i class="ph-bold ph-key-return"></i> Auto-Add on Enter</span>
                    </div>
                    <div id="medicineResults" class="flex flex-col gap-1.5 mt-4 max-h-[5cm] overflow-y-auto scrollbar-thin px-1">
                        <div class="w-full text-center text-gray-400 py-4"><i class="ph-duotone ph-barcode text-4xl mb-2 opacity-50"></i><br>Ready for barcode scanning or manual search...</div>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 md:p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg flex items-center"><i class="ph-duotone ph-shopping-cart mr-2 text-2xl text-primary-500"></i>Current Cart</h3>
                        <button type="button" onclick="clearCart()" class="text-sm text-red-500 hover:text-red-700 font-semibold"><i class="ph-bold ph-trash"></i> Empty Cart</button>
                    </div>
                    <div class="overflow-x-auto pb-2">
                        <table class="w-full text-sm min-w-[500px]">
                            <thead class="bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-b dark:border-gray-600 sticky top-0 z-10">
                                <tr>
                                    <th class="p-2 text-left w-2/5">Item</th>
                                    <th class="p-2 text-center w-[80px]">Dis(%)</th>
                                    <th class="p-2 text-center w-[130px]">Qty</th>
                                    <th class="p-2 text-center">Price</th>
                                    <th class="p-2 text-center">Total</th>
                                    <th class="p-2 text-right"></th>
                                </tr>
                            </thead>
                            <tbody id="cartItems" class="text-center dark:text-gray-200"></tbody>
                        </table>
                    </div>
                    <div class="mt-4 space-y-3 border-t pt-4 dark:text-gray-200 bg-gray-50 dark:bg-gray-700/30 p-4 rounded-xl">
                        <div class="flex justify-between items-center text-sm md:text-base"><span>Subtotal:</span><span class="font-semibold" id="subtotal"><?php echo $allData['settings']['currency']; ?>0.00</span></div>
                        <div class="flex justify-between items-center gap-3 text-sm md:text-base"><span class="font-medium">Extra Discount (Tk):</span><input type="number" id="discountValue" class="w-24 md:w-32 border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1.5 text-right dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-1 focus:ring-primary-500 shadow-inner" value="0" step="0.5"></div>
                        <div class="flex justify-between items-center text-green-600 dark:text-green-400 text-sm md:text-base"><span>Total Discount:</span><span id="discountAmount"><?php echo $allData['settings']['currency']; ?>0.00</span></div>
                        <div class="flex justify-between items-center text-lg md:text-xl font-bold text-primary-600 border-t border-gray-200 dark:border-gray-600 pt-2"><span>Grand Total:</span><span id="grandTotal"><?php echo $allData['settings']['currency']; ?>0.00</span></div>
                        
                        <!-- ENHANCED PAYMENT SECTION -->
                        <div class="flex flex-col md:flex-row gap-3 mt-4">
                            <div class="flex gap-3 w-full md:w-auto">
                                <select id="paymentMethod" class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-3 md:py-2 flex-1 dark:bg-gray-700 dark:text-white shadow-sm">
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="mobile">Mobile</option>
                                </select>
                                <input type="number" id="paidAmount" placeholder="Paid Amount" class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-3 md:py-2 flex-1 w-32 dark:bg-gray-700 dark:text-white shadow-inner font-bold" step="0.01">
                            </div>
                            <button id="checkoutBtn" class="bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg w-full md:flex-1 flex justify-center items-center gap-2 mt-2 md:mt-0 transition-all"><i class="ph-bold ph-check-circle text-xl"></i> Complete Checkout</button>
                        </div>
                        
                        <!-- PAID / DUE / CHANGE DISPLAY -->
                        <div id="paymentSummary" class="payment-summary grid grid-cols-3 gap-2 text-center mt-3">
                            <div>
                                <div class="label text-xs uppercase">Paid</div>
                                <div id="displayPaid" class="value text-blue-600 dark:text-blue-400"><?php echo $allData['settings']['currency']; ?>0.00</div>
                            </div>
                            <div>
                                <div class="label text-xs uppercase">Due</div>
                                <div id="displayDue" class="value text-red-600 dark:text-red-400"><?php echo $allData['settings']['currency']; ?>0.00</div>
                            </div>
                            <div>
                                <div class="label text-xs uppercase">Change</div>
                                <div id="displayChange" class="value text-green-600 dark:text-green-400"><?php echo $allData['settings']['currency']; ?>0.00</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="space-y-4">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 md:p-5">
                    <h3 class="font-bold mb-3 flex items-center text-lg"><i class="ph-duotone ph-user-plus mr-2 text-2xl text-primary-500"></i>Customer</h3>
                    <select id="customerSelect" class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-3 md:py-2 dark:bg-gray-700 dark:text-white"><option value="0">Walk-in Customer</option><?php foreach($allData['customers'] as $cust): ?><option value="<?php echo $cust['id']; ?>"><?php echo htmlspecialchars($cust['name']); ?> (<?php echo htmlspecialchars($cust['phone']); ?>)</option><?php endforeach; ?></select>
                    <button onclick="document.getElementById('addCustomerModal').classList.remove('hidden');" class="text-primary-600 text-sm mt-3 hover:underline flex items-center gap-1"><i class="ph-bold ph-plus-circle"></i> Add New Customer</button>
                    
                    <div class="mt-4 pt-4 border-t dark:border-gray-700">
                        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <i class="ph-fill ph-telegram-logo text-blue-500 text-lg"></i>
                            <span>Invoice will be instantly dispatched to Telegram</span>
                        </div>
                    </div>
                </div>
                
                <?php if($invoiceDisplay): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 md:p-5 border-2 border-green-500">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="font-bold flex items-center"><i class="ph-duotone ph-printer mr-2 text-2xl text-green-500"></i>Last Invoice</h3>
                        <button onclick="printLastInvoice()" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm shadow flex items-center gap-1"><i class="ph-bold ph-printer"></i> Print</button>
                    </div>
                    <div class="text-sm text-green-600 font-bold flex items-center mb-2"><i class="ph-fill ph-check-circle mr-1 text-lg"></i> Transaction successful!</div>
                    <div id="printInvoiceContent" class="hidden"><?php echo generateInvoiceHTML($invoiceDisplay, $allData['settings'], $allData['customers']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <form id="checkoutForm" method="POST" action="?action=checkout">
            <input type="hidden" name="cart" id="cartJson">
            <input type="hidden" name="subtotal" id="formSubtotal">
            <input type="hidden" name="discount_amount" id="formDiscountAmount">
            <input type="hidden" name="payment_method" id="formPaymentMethod">
            <input type="hidden" name="customer_id" id="formCustomerId">
            <input type="hidden" name="paid_amount" id="formPaidAmount">
            <input type="hidden" name="update_invoice_no" value="<?php echo isset($_GET['edit_invoice']) ? htmlspecialchars($_GET['edit_invoice']) : ''; ?>">
        </form>
        
        <div id="addCustomerModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-[70] p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-slide-up mx-auto">
                <h3 class="font-bold text-xl mb-4 flex items-center"><i class="ph-duotone ph-user-plus mr-2 text-primary-500 text-2xl"></i>Add Customer</h3>
                <div class="space-y-3">
                    <input type="text" id="custName" placeholder="Name *" class="w-full border border-gray-300 dark:border-gray-600 rounded-xl p-3 dark:bg-gray-700 dark:text-white outline-none">
                    <input type="text" id="custPhone" placeholder="Phone *" class="w-full border border-gray-300 dark:border-gray-600 rounded-xl p-3 dark:bg-gray-700 dark:text-white outline-none">
                    <input type="email" id="custEmail" placeholder="Email (Optional)" class="w-full border border-gray-300 dark:border-gray-600 rounded-xl p-3 dark:bg-gray-700 dark:text-white outline-none">
                </div>
                <div class="flex justify-end space-x-3 mt-5">
                    <button onclick="document.getElementById('addCustomerModal').classList.add('hidden');" class="px-5 py-2.5 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:text-white rounded-xl font-medium transition">Cancel</button>
                    <button onclick="saveCustomer()" class="px-5 py-2.5 bg-primary-600 hover:bg-primary-700 text-white rounded-xl font-bold transition flex items-center gap-2"><i class="ph-bold ph-check"></i> Save</button>
                </div>
            </div>
        </div>

        <script>
        // --- OPTIMIZED JS SEARCH IMPLEMENTATION ---
        var currentResults = []; 
        var cart = []; 
        window.cartLoadedForEdit = false; 
        var searchTimer;
        var searchCache = {}; // Cache to prevent redundant AJAX requests
        var lastQuery = null; 
        
        var searchInput = document.getElementById('searchMedicine');

        // Typing event with debounce and cache check
        searchInput.addEventListener('input', function(e) { 
            var q = e.target.value.trim();
            if (q === lastQuery) return; // Prevent triggering if query hasn't changed
            lastQuery = q;

            clearTimeout(searchTimer); 
            
            // Serve instantly from cache if available
            if(searchCache[q]) {
                currentResults = searchCache[q];
                renderSearchUI();
                return;
            }

            searchTimer = setTimeout(function() { 
                fetchMedicines(q); 
            }, 300); // 300ms debounce
        });
        
        // Enter key handling (Optimized for Barcode Scanners)
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var q = this.value.trim();
                
                // If it's a direct barcode match (from cache or current results), add immediately
                if (currentResults.length > 0) {
                    addToCart(currentResults[0].id);
                    this.value = ''; 
                    lastQuery = '';
                    fetchMedicines('');
                    this.focus();
                } else {
                    // Fallback for extremely fast barcode scans before AJAX debounce completes
                    fetchMedicines(q);
                    setTimeout(() => {
                        if (currentResults.length > 0) {
                            addToCart(currentResults[0].id);
                            searchInput.value = '';
                            lastQuery = '';
                            fetchMedicines('');
                            searchInput.focus();
                        }
                    }, 400); // Wait for fallback AJAX
                }
            }
        });

        function fetchMedicines(query) {
            if(query === undefined) query = '';
            if(query.length < 1 && cart.length === 0 && !<?php echo isset($_GET['edit_invoice']) ? 'true' : 'false'; ?>) { 
                document.getElementById('medicineResults').innerHTML = '<div class="w-full text-center text-gray-400 py-4"><i class="ph-duotone ph-barcode text-4xl mb-2 opacity-50"></i><br>Ready for barcode scanning or manual search...</div>'; 
                return; 
            }
            
            document.getElementById('medicineResults').innerHTML = '<div class="w-full text-center text-gray-400 py-4"><i class="ph-duotone ph-spinner animate-spin text-3xl mb-1"></i><br>Searching...</div>';
            
            $.ajax({ 
                url: '?ajax=1&action=search_medicines&query=' + encodeURIComponent(query), 
                method: 'GET', dataType: 'json', xhrFields: { withCredentials: true },
                success: function(res) {
                    if(res.success) { 
                        currentResults = res.medicines; 
                        searchCache[query] = currentResults; // Store in cache for future
                        renderSearchUI(); 
                        if (!window.cartLoadedForEdit) { loadEditCart(); window.cartLoadedForEdit = true; } 
                        else { updateCartUI(); } 
                    }
                },
                error: function(xhr) { 
                    if(xhr.responseText && xhr.responseText.includes('Cookies')) { window.location.reload(); } 
                    else { document.getElementById('medicineResults').innerHTML = '<div class="w-full text-center text-red-400 py-4">Search failed.</div>'; } 
                }
            });
        }
        
        function loadEditCart() {
            var isEditMode = <?php echo isset($_GET['edit_invoice']) ? 'true' : 'false'; ?>;
            if (isEditMode) {
                <?php if($editInvoiceData): ?>
                cart = <?php echo json_encode($editInvoiceData['items']); ?>; var loadedSale = <?php echo json_encode($editInvoiceData); ?>; var totalItemDiscount = 0;
                cart.forEach(function(item) { totalItemDiscount += (item.price * item.quantity) * ((item.dis_percent || 0) / 100); });
                var extraDiscount = loadedSale.discount_amount - totalItemDiscount;
                document.getElementById('discountValue').value = extraDiscount > 0 ? extraDiscount.toFixed(2) : 0;
                document.getElementById('customerSelect').value = loadedSale.customer_id || 0;
                document.getElementById('paidAmount').value = loadedSale.paid;
                document.getElementById('paymentMethod').value = loadedSale.payment_method || 'cash';
                var btn = document.getElementById('checkoutBtn');
                btn.innerHTML = '<i class="ph-bold ph-floppy-disk text-xl mr-2"></i> Update Sale'; 
                btn.className = "bg-gradient-to-r from-blue-500 to-blue-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg w-full md:flex-1 flex justify-center items-center gap-2 mt-2 md:mt-0 transition-all";
                updateCartUI();
                <?php endif; ?>
            } else {
                var saved = localStorage.getItem('pos_cart');
                if (saved) { try { cart = JSON.parse(saved); updateCartUI(); } catch(e) {} }
            }
        }
        
        function renderSearchUI() {
            var html = currentResults.map(function(m) {
                var status = m.stock_status || (m.stock_quantity !== undefined ? (m.stock_quantity > 0 ? 'In Stock' : 'Out of Stock') : 'In Stock');
                var statusClass = status === 'In Stock' ? 'text-green-500' : 'text-red-500';
                return '<div onclick="addToCart('+m.id+')" class="flex flex-row items-center justify-between bg-gray-50 dark:bg-gray-700 px-3 py-2 rounded-lg cursor-pointer hover:bg-primary-50 dark:hover:bg-primary-900/30 transition border border-gray-200 dark:border-gray-600 active:scale-[0.98]"><div class="flex items-center overflow-hidden flex-1 mr-2"><span class="font-bold text-sm md:text-base dark:text-white truncate whitespace-nowrap">'+escapeHtml(m.brand_name)+'</span><span class="text-xs text-gray-500 dark:text-gray-400 truncate whitespace-nowrap ml-2 hidden sm:inline">- '+escapeHtml(m.generic_name)+'</span></div><div class="flex items-center shrink-0 space-x-3"><span class="text-primary-600 font-bold text-sm md:text-base">'+window.currencySymbol+' '+parseFloat(m.selling_price).toFixed(2)+'</span><span class="text-[10px] md:text-xs font-bold px-2 py-0.5 rounded bg-white dark:bg-gray-800 shadow-sm '+statusClass+'">'+status+'</span></div></div>';
            }).join('');
            document.getElementById('medicineResults').innerHTML = html || '<div class="w-full text-center text-gray-400 py-4"><i class="ph-duotone ph-magnifying-glass text-4xl mb-2"></i><br>No medicines found</div>';
        }
        
        function escapeHtml(str) { return String(str).replace(/[&<>]/g, function(m){ if(m === '&') return '&amp;'; if(m === '<') return '&lt;'; if(m === '>') return '&gt;'; return m;}); }
        function addToCart(id) {
            var med = currentResults.find(function(m) { return m.id === id; }); var isEditMode = <?php echo isset($_GET['edit_invoice']) ? 'true' : 'false'; ?>;
            if (!med) return; var status = med.stock_status || (med.stock_quantity !== undefined ? (med.stock_quantity > 0 ? 'In Stock' : 'Out of Stock') : 'In Stock');
            if (status === 'Out of Stock' && !isEditMode) return alert('Item is out of stock!');
            var existing = cart.find(function(i) { return i.id === id; });
            if(existing) { existing.quantity += 1; } else { cart.push({ id: med.id, name: med.brand_name, price: med.selling_price, quantity: 1, dis_percent: 0 }); }
            updateCartUI();
        }
        function changeQty(idx, delta) { var newVal = parseInt(cart[idx].quantity) + delta; if (newVal < 1) newVal = 1; cart[idx].quantity = newVal; updateCartUI(); }
        function updateCartItem(idx, field, value) {
            if (field === 'qty') { var newVal = parseInt(value) || 1; if (newVal < 1) newVal = 1; cart[idx].quantity = newVal; } else if (field === 'dis') { cart[idx].dis_percent = parseFloat(value) || 0; } else if (field === 'price') { var newPrice = parseFloat(value) || 0; if (newPrice < 0) newPrice = 0; cart[idx].price = newPrice; } updateCartUI();
        }
        
        function clearCart() { if(confirm('Clear the entire cart?')) { cart = []; updateCartUI(); } }

        function updateCartUI() {
            var tbody = document.getElementById('cartItems'); var subtotal = 0, totalDiscountFromItems = 0; tbody.innerHTML = '';
            cart.forEach(function(item, idx) {
                var total = item.price * item.quantity; subtotal += total;
                var itemDis = total * ((item.dis_percent || 0) / 100); totalDiscountFromItems += itemDis; var netTotal = total - itemDis;
                tbody.innerHTML += '<tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition"><td class="p-2 text-left font-medium max-w-[150px] truncate" title="'+escapeHtml(item.name)+'">'+escapeHtml(item.name)+'</td><td class="py-2"><div class="relative flex items-center justify-center w-[70px] mx-auto"><input type="number" class="w-full border rounded-md text-center pr-4 py-1.5 text-sm hide-arrows dark:bg-gray-700" value="'+(item.dis_percent || 0)+'" min="0" step="0.1" onchange="updateCartItem('+idx+', \'dis\', this.value)"><span class="absolute right-1.5 text-gray-400 font-bold text-xs">%</span></div></td><td class="py-2"><div class="flex items-center justify-center space-x-1"><button type="button" class="w-6 h-6 rounded bg-gray-200 dark:bg-gray-600 hover:text-red-600" onclick="changeQty('+idx+', -1)"><i class="ph-bold ph-minus"></i></button><input type="number" class="w-10 border rounded-md text-center py-1 text-sm hide-arrows dark:bg-gray-700" value="'+item.quantity+'" min="1" onchange="updateCartItem('+idx+', \'qty\', this.value)"><button type="button" class="w-6 h-6 rounded bg-gray-200 dark:bg-gray-600 hover:text-green-600" onclick="changeQty('+idx+', 1)"><i class="ph-bold ph-plus"></i></button></div></td><td class="py-2"><div class="flex justify-center items-center"><span class="mr-1 text-gray-500">'+window.currencySymbol+'</span><input type="number" class="w-16 md:w-20 border rounded-md text-right px-2 py-1 text-sm hide-arrows dark:bg-gray-700" value="'+parseFloat(item.price).toFixed(2)+'" min="0" step="0.01" onchange="updateCartItem('+idx+', \'price\', this.value)"></div></td><td class="py-2 font-bold text-sm text-primary-600">'+window.currencySymbol+''+netTotal.toFixed(2)+'</td><td class="py-2 text-right pr-2"><button type="button" onclick="removeItem('+idx+')" class="w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-500 hover:text-white flex items-center justify-center ml-auto transition"><i class="ph-bold ph-trash"></i></button></td></tr>';
            });
            var extraDiscountVal = parseFloat(document.getElementById('discountValue').value) || 0; var finalDiscount = totalDiscountFromItems + extraDiscountVal; var grand = subtotal - finalDiscount;
            document.getElementById('subtotal').innerText = window.currencySymbol + subtotal.toFixed(2); document.getElementById('discountAmount').innerText = window.currencySymbol + finalDiscount.toFixed(2); document.getElementById('grandTotal').innerText = window.currencySymbol + grand.toFixed(2);
            
            // Update Paid / Due / Change display
            var paid = parseFloat(document.getElementById('paidAmount').value) || 0;
            var due = grand - paid;
            var change = 0;
            if(due < 0) { change = Math.abs(due); due = 0; } else { change = 0; }
            document.getElementById('displayPaid').innerText = window.currencySymbol + paid.toFixed(2);
            document.getElementById('displayDue').innerText = window.currencySymbol + due.toFixed(2);
            document.getElementById('displayChange').innerText = window.currencySymbol + change.toFixed(2);
            
            document.getElementById('formSubtotal').value = subtotal; document.getElementById('formDiscountAmount').value = finalDiscount; document.getElementById('cartJson').value = JSON.stringify(cart);
            
            var isEditMode = <?php echo isset($_GET['edit_invoice']) ? 'true' : 'false'; ?>;
            if (!isEditMode) { localStorage.setItem('pos_cart', JSON.stringify(cart)); }
        }
        
        function removeItem(idx) { cart.splice(idx,1); updateCartUI(); }
        document.getElementById('discountValue').addEventListener('input', function() { updateCartUI(); }); 
        document.getElementById('paidAmount').addEventListener('input', function() { updateCartUI(); });
        
        document.getElementById('checkoutBtn').addEventListener('click', function() {
            if(cart.length === 0) return alert('Cart is empty!');
            var grand = parseFloat(document.getElementById('grandTotal').innerText.replace(window.currencySymbol, ''));
            var paid = parseFloat(document.getElementById('paidAmount').value) || 0;
            document.getElementById('formPaymentMethod').value = document.getElementById('paymentMethod').value;
            document.getElementById('formCustomerId').value = document.getElementById('customerSelect').value;
            document.getElementById('formPaidAmount').value = paid || grand; document.getElementById('checkoutForm').submit();
        });
        
        function saveCustomer() {
            var name = document.getElementById('custName').value, phone = document.getElementById('custPhone').value; if(!name || !phone) return alert('Name and phone required');
            fetch('?ajax=1&action=add_customer&name='+encodeURIComponent(name)+'&phone='+encodeURIComponent(phone)+'&email='+encodeURIComponent(document.getElementById('custEmail').value), { method:'GET', credentials: 'include' }).then(function(res){ return res.text(); }).then(function(text){ if(text.includes('Cookies')) window.location.reload(); else location.reload(); });
        }
        
        function printLastInvoice() { var printContent = document.getElementById('printInvoiceContent').innerHTML; var win = window.open('', '_blank'); win.document.write('<html><head><title>Print Invoice</title></head><body style="margin:0; padding:0;">' + printContent + '</body></html>'); win.document.close(); win.focus(); setTimeout(function(){ win.print(); win.close(); }, 250); }
        
        window.currencySymbol = '<?php echo $allData['settings']['currency']; ?>';
        fetchMedicines('');
        </script>
        <?php endif; ?>

        <!-- INVENTORY / STOCKS -->
        <?php if($action === 'stocks'): ?>
        <?php
            load_medicines(); $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; $limit = 50; $searchQuery = isset($_GET['stock_search']) ? trim($_GET['stock_search']) : '';
            $filteredStock = array();
            foreach($medicines as $m) { $brand = isset($m['brand_name']) ? (string)$m['brand_name'] : ''; $generic = isset($m['generic_name']) ? (string)$m['generic_name'] : ''; $company = isset($m['company']) ? (string)$m['company'] : ''; $batch = isset($m['batch_no']) ? (string)$m['batch_no'] : ''; if ($searchQuery === '' || stripos($brand, $searchQuery) !== false || stripos($generic, $searchQuery) !== false || stripos($company, $searchQuery) !== false || stripos($batch, $searchQuery) !== false || (string)$m['id'] === $searchQuery) { $filteredStock[] = $m; } }
            $totalStock = count($filteredStock); $totalPages = ceil($totalStock / $limit) ?: 1; $offset = ($page - 1) * $limit; $pagedStock = array_slice($filteredStock, $offset, $limit);
        ?>
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden animate-slide-up">
            <div class="px-5 py-4 border-b flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-gray-50 dark:bg-gray-700/30"><h2 class="text-xl font-bold flex items-center"><i class="ph-duotone ph-database mr-2 text-2xl text-primary-500"></i>Medicine Inventory</h2><a href="?action=add_medicine" class="bg-primary-600 hover:bg-primary-700 text-white px-5 py-2.5 rounded-xl shadow flex items-center w-full md:w-auto justify-center"><i class="ph-bold ph-plus mr-2 text-lg"></i>Add Medicine</a></div>
            <div class="p-4 border-b dark:border-gray-700">
                <form method="GET" class="relative w-full md:w-96 flex items-center">
                    <input type="hidden" name="action" value="stocks"><i class="ph-bold ph-magnifying-glass absolute left-3 text-gray-400 text-lg"></i>
                    <input type="text" name="stock_search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search by Name, Generic, Batch or ID..." class="pl-10 pr-4 py-2 border rounded-xl w-full dark:bg-gray-700 dark:text-white outline-none focus:ring-2 focus:ring-primary-500">
                    <?php if(!empty($searchQuery)): ?><a href="?action=stocks" class="absolute right-3 text-gray-400 hover:text-red-500"><i class="ph-bold ph-x"></i></a><?php endif; ?>
                </form>
            </div>
            <div class="overflow-x-auto pb-2">
                <table class="w-full text-sm whitespace-nowrap text-left"><thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="p-3">ID / Barcode</th><th>Brand</th><th>Generic</th><th>Company</th><th>Batch</th><th>Purchase</th><th>Selling</th><th>Stock Status</th><th>Expiry</th><th>Actions</th></tr></thead><tbody>
                    <?php if(empty($pagedStock)): ?><tr><td colspan="10" class="p-6 text-center text-gray-500">No medicines found matching your search.</td></tr><?php else: ?>
                    <?php foreach($pagedStock as $m): $status = getStockStatus($m); ?>
                        <tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"><td class="p-3 font-mono font-bold text-gray-500"><?php echo $m['id']; ?></td><td class="font-bold"><?php echo htmlspecialchars($m['brand_name']); ?></td><td><?php echo htmlspecialchars($m['generic_name']); ?></td><td><?php echo htmlspecialchars($m['company']); ?></td><td class="font-mono text-xs text-gray-500"><?php echo htmlspecialchars($m['batch_no'] ?? '-'); ?></td><td><?php echo $allData['settings']['currency']; ?><?php echo number_format($m['purchase_price'],2); ?></td><td class="font-bold text-green-600"><?php echo $allData['settings']['currency']; ?><?php echo number_format($m['selling_price'],2); ?></td><td><span class="px-2 py-1 rounded-md text-xs font-bold <?php echo $status === 'Out of Stock' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>"><?php echo $status; ?></span></td><td class="font-semibold <?php echo strtotime($m['expiry_date']) <= time() ? 'text-red-500' : ''; ?>"><?php echo date('d/m/Y', strtotime($m['expiry_date'])); ?></td><td class="flex items-center gap-3 py-3 pr-3"><a href="?action=edit_medicine&id=<?php echo $m['id']; ?>" class="text-blue-500 hover:text-blue-700 bg-blue-50 p-1.5 rounded-lg"><i class="ph-bold ph-pencil-simple text-lg"></i></a><a href="?action=delete_medicine&id=<?php echo $m['id']; ?>" onclick="return confirm('Delete?')" class="text-red-500 hover:text-red-700 bg-red-50 p-1.5 rounded-lg"><i class="ph-bold ph-trash text-lg"></i></a></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table>
            </div>
            <div class="flex flex-col md:flex-row justify-between items-center p-4 bg-gray-50 dark:bg-gray-700/50">
                <span class="text-sm text-gray-500 mb-3 md:mb-0">Showing <?php echo $totalStock == 0 ? 0 : $offset + 1; ?> to <?php echo min($offset + $limit, $totalStock); ?> of <?php echo $totalStock; ?> entries</span>
                <div class="flex space-x-2">
                    <?php if($page > 1): ?><a href="?action=stocks&page=<?php echo $page - 1; ?>&stock_search=<?php echo urlencode($searchQuery); ?>" class="px-3 py-1.5 bg-white border rounded-lg shadow-sm hover:bg-gray-100 text-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">Previous</a><?php endif; ?>
                    <span class="px-3 py-1.5 bg-primary-50 text-primary-600 font-bold border border-primary-200 rounded-lg text-sm"><?php echo $page; ?> / <?php echo $totalPages; ?></span>
                    <?php if($page < $totalPages): ?><a href="?action=stocks&page=<?php echo $page + 1; ?>&stock_search=<?php echo urlencode($searchQuery); ?>" class="px-3 py-1.5 bg-white border rounded-lg shadow-sm hover:bg-gray-100 text-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">Next</a><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ADD / EDIT MEDICINE -->
        <?php if(in_array($action, ['add_medicine', 'edit_medicine'])): ?>
        <?php 
        $med = null; 
        if($action === 'edit_medicine' && isset($_GET['id'])) { load_medicines(); foreach($medicines as $m) { if($m['id'] == $_GET['id']) { $med = $m; break; } } }
        ?>
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 max-w-3xl mx-auto animate-slide-up border-t-4 border-primary-500">
            <h2 class="text-xl font-bold mb-4 flex items-center">
                <i class="ph-duotone ph-pill mr-2 text-primary-500 text-2xl"></i>
                <?php echo $action === 'edit_medicine' ? 'Edit Medicine' : 'Add Medicine'; ?>
            </h2>
            <form method="POST" action="?action=<?php echo $action; ?>">
                <?php if($med): ?><input type="hidden" name="id" value="<?php echo $med['id']; ?>"><?php endif; ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm text-gray-500 mb-1">Brand Name *</label><input type="text" name="brand_name" value="<?php echo $med ? htmlspecialchars($med['brand_name']) : ''; ?>" required class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Generic Name</label><input type="text" name="generic_name" value="<?php echo $med ? htmlspecialchars($med['generic_name']) : ''; ?>" class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Company *</label><input type="text" name="company" value="<?php echo $med ? htmlspecialchars($med['company']) : ''; ?>" required class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Category</label><input type="text" name="category" value="<?php echo $med ? htmlspecialchars($med['category']) : ''; ?>" class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Purchase Price *</label><input type="number" step="0.01" name="purchase_price" value="<?php echo $med ? $med['purchase_price'] : ''; ?>" required class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Selling Price *</label><input type="number" step="0.01" name="selling_price" value="<?php echo $med ? $med['selling_price'] : ''; ?>" required class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Stock Status</label>
                        <select name="stock_status" class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="In Stock" <?php echo ($med && getStockStatus($med) === 'In Stock') ? 'selected' : ''; ?>>In Stock</option>
                            <option value="Out of Stock" <?php echo ($med && getStockStatus($med) === 'Out of Stock') ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>
                    <div><label class="block text-sm text-gray-500 mb-1">Batch No / Barcode</label><input type="text" name="batch_no" value="<?php echo $med ? htmlspecialchars($med['batch_no'] ?? '') : ''; ?>" class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500 font-mono text-sm" placeholder="Scan or type barcode here"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Expiry Date *</label><input type="date" name="expiry_date" value="<?php echo $med ? $med['expiry_date'] : ''; ?>" required class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Location / Rack</label><input type="text" name="location" value="<?php echo $med ? htmlspecialchars($med['location'] ?? '') : ''; ?>" class="w-full border rounded-xl p-3 dark:bg-gray-700 outline-none focus:ring-2 focus:ring-primary-500"></div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <a href="?action=stocks" class="px-6 py-3 bg-gray-200 dark:bg-gray-700 rounded-xl font-bold transition hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="px-6 py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-xl font-bold transition flex items-center gap-2"><i class="ph-bold ph-floppy-disk"></i> Save Medicine</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- BILL HISTORY - WITH PAID & DUE -->
        <?php if($action === 'bill_history'): ?>
        <div class="space-y-6 animate-slide-up">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
                <div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <h2 class="text-xl font-bold flex items-center"><i class="ph-duotone ph-clock-counter-clockwise mr-2 text-2xl text-primary-500"></i>Bill History</h2>
                    <a href="?action=export_bills" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl shadow flex items-center"><i class="ph-bold ph-download-simple mr-2"></i> Export to CSV</a>
                </div>
                <div class="p-4">
                    <div class="relative mb-4 w-full md:w-80 flex items-center">
                        <i class="ph-bold ph-magnifying-glass absolute left-3 text-gray-400 text-lg"></i>
                        <input type="text" id="searchBill" placeholder="Search Invoice or Customer..." class="pl-10 pr-4 py-2 border rounded-xl w-full dark:bg-gray-700 dark:text-white outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                    <div class="overflow-x-auto pb-2">
                        <table class="w-full text-left whitespace-nowrap" id="billTable">
                            <thead class="bg-gray-100 dark:bg-gray-700 text-sm">
                                <tr>
                                    <th class="p-3">Invoice No</th>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Due</th>
                                    <th>Status</th>
                                    <th>Cashier</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $bills = array_reverse($allData['sales']); foreach($bills as $bill): 
                                    $status = 'Paid';
                                    $dueAmount = 0;
                                    if($bill['change'] < 0) { 
                                        $status = 'Due'; 
                                        $dueAmount = abs($bill['change']);
                                        if($bill['paid'] > 0 && $bill['paid'] < $bill['total']) { 
                                            $status = 'Partial'; 
                                        }
                                    }
                                    $statusClass = $status === 'Paid' ? 'status-paid' : ($status === 'Partial' ? 'status-partial' : 'status-due');
                                ?>
                                <tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="p-3 font-mono font-bold"><?php echo htmlspecialchars($bill['invoice_no']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($bill['date'])); ?></td>
                                    <td><?php echo $bill['customer_id'] > 0 ? htmlspecialchars(getCustomerName($allData['customers'], $bill['customer_id'])) : 'Walk-in'; ?></td>
                                    <td class="font-semibold text-green-600"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($bill['total'], 2); ?></td>
                                    <td class="font-semibold text-blue-600"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($bill['paid'], 2); ?></td>
                                    <td class="font-semibold <?php echo $dueAmount > 0 ? 'text-red-600' : 'text-gray-400'; ?>"><?php echo $allData['settings']['currency']; ?> <?php echo number_format($dueAmount, 2); ?></td>
                                    <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                                    <td><?php echo htmlspecialchars($bill['cashier']); ?></td>
                                    <td class="flex items-center space-x-2 py-2 pr-2">
                                        <button onclick="viewBill('<?php echo $bill['invoice_no']; ?>')" class="bg-primary-600 text-white px-3 py-2 rounded-lg text-sm shadow flex items-center hover:bg-primary-700"><i class="ph-bold ph-eye text-lg"></i></button>
                                        <?php if($_SESSION['user']['role'] === 'Admin'): ?>
                                            <a href="?action=pos&edit_invoice=<?php echo urlencode($bill['invoice_no']); ?>" class="bg-blue-600 text-white px-3 py-2 rounded-lg text-sm shadow flex items-center hover:bg-blue-700"><i class="ph-bold ph-pencil-simple text-lg"></i></a>
                                            <a href="?action=delete_bill&invoice_no=<?php echo urlencode($bill['invoice_no']); ?>" onclick="return confirm('Are you sure? This will restore stock.')" class="bg-red-600 text-white px-3 py-2 rounded-lg text-sm shadow flex items-center hover:bg-red-700"><i class="ph-bold ph-trash text-lg"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div id="billModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-[70] p-4 overflow-y-auto">
            <div class="bg-white rounded-2xl p-6 w-full max-w-xl mx-auto shadow-2xl text-black">
                <div class="flex justify-between items-center mb-4 border-b pb-2">
                    <h3 class="font-bold text-xl">Invoice Details</h3>
                    <button onclick="document.getElementById('billModal').classList.add('hidden');document.getElementById('billModal').classList.remove('flex');" class="text-gray-500 hover:text-red-500 p-2"><i class="ph-bold ph-x text-2xl"></i></button>
                </div>
                <div class="flex justify-center bg-gray-100 p-4 rounded-xl border overflow-x-auto">
                    <div id="billDetails" class="bg-white shadow" style="width:10cm; min-width: 10cm;"></div>
                </div>
                <div class="flex justify-end mt-5 border-t pt-4">
                    <button onclick="printBill()" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-xl shadow font-bold flex items-center w-full md:w-auto justify-center"><i class="ph-bold ph-printer text-xl mr-2"></i> Print Invoice</button>
                </div>
            </div>
        </div>
        <script>
        function viewBill(inv) { fetch('?ajax=1&action=get_bill&invoice_no='+encodeURIComponent(inv), { method:'GET', credentials: 'include' }).then(res=>res.text()).then(text=>{ if(text.includes('Cookies')) { window.location.reload(); return; } try { var data = JSON.parse(text); if(data.success && data.html){ document.getElementById('billDetails').innerHTML = data.html; document.getElementById('billModal').classList.add('flex'); document.getElementById('billModal').classList.remove('hidden'); } } catch(e) {} }); }
        function printBill() { var content = document.getElementById('billDetails').innerHTML; var win = window.open('', '_blank'); win.document.write('<html><body>'+content+'</body></html>'); win.document.close(); win.focus(); setTimeout(function(){win.print(); win.close();}, 250); }
        document.getElementById('searchBill')?.addEventListener('keyup', function(){ var filter = this.value.toLowerCase(); document.querySelectorAll('#billTable tbody tr').forEach(function(row){ row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none'; }); });
        </script>
        <?php endif; ?>

        <!-- CUSTOMERS -->
        <?php if($action === 'customers'): ?>
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden animate-slide-up border-t-4 border-cyan-500">
            <div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30 flex justify-between items-center">
                <h2 class="text-xl font-bold flex items-center"><i class="ph-duotone ph-users mr-2 text-2xl text-cyan-500"></i>Customers Database</h2>
                <button onclick="document.getElementById('addCustomerModalFull').classList.remove('hidden');" class="bg-cyan-600 hover:bg-cyan-700 text-white px-4 py-2 rounded-xl shadow flex items-center"><i class="ph-bold ph-plus mr-2"></i>Add Customer</button>
            </div>
            <div class="overflow-x-auto p-4">
                <table class="w-full text-left whitespace-nowrap">
                    <thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="p-3">ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Total Purchases</th><th>Joined</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach($allData['customers'] as $c): ?>
                        <tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="p-3"><?php echo $c['id']; ?></td>
                            <td class="font-bold"><?php echo htmlspecialchars($c['name']); ?></td>
                            <td><?php echo htmlspecialchars($c['phone']); ?></td>
                            <td><?php echo htmlspecialchars($c['email'] ?? 'N/A'); ?></td>
                            <td class="font-semibold text-green-600"><?php echo $allData['settings']['currency']; ?><?php echo number_format($c['total_purchases'] ?? 0, 2); ?></td>
                            <td><?php echo $c['joined_date'] ?? 'N/A'; ?></td>
                            <td><a href="?action=delete_customer&id=<?php echo $c['id']; ?>" onclick="return confirm('Delete?')" class="text-red-500 hover:text-red-700 bg-red-50 p-1.5 rounded-lg"><i class="ph-bold ph-trash text-lg"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="addCustomerModalFull" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-[70] p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 w-full max-w-sm shadow-2xl animate-slide-up mx-auto">
                <h3 class="font-bold text-xl mb-4 flex items-center"><i class="ph-duotone ph-user-plus mr-2 text-primary-500 text-2xl"></i>Add Customer</h3>
                <form method="POST" action="?action=add_customer_post" class="space-y-3">
                    <input type="text" name="name" placeholder="Name *" required class="w-full border rounded-xl p-3 dark:bg-gray-700 dark:text-white outline-none focus:ring-2 focus:ring-primary-500">
                    <input type="text" name="phone" placeholder="Phone *" required class="w-full border rounded-xl p-3 dark:bg-gray-700 dark:text-white outline-none focus:ring-2 focus:ring-primary-500">
                    <input type="email" name="email" placeholder="Email (Optional)" class="w-full border rounded-xl p-3 dark:bg-gray-700 dark:text-white outline-none focus:ring-2 focus:ring-primary-500">
                    <div class="flex justify-end space-x-3 mt-5">
                        <button type="button" onclick="document.getElementById('addCustomerModalFull').classList.add('hidden');" class="px-5 py-2.5 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:text-white rounded-xl font-medium transition">Cancel</button>
                        <button type="submit" class="px-5 py-2.5 bg-primary-600 hover:bg-primary-700 text-white rounded-xl font-bold transition flex items-center gap-2"><i class="ph-bold ph-check"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- SUPPLIERS -->
        <?php if($action === 'suppliers' && $_SESSION['user']['role'] === 'Admin'): ?>
        <div class="grid lg:grid-cols-3 gap-6 animate-slide-up">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-5 border-t-4 border-yellow-500">
                <h3 class="font-bold text-lg mb-4 flex items-center"><i class="ph-duotone ph-truck mr-2 text-2xl text-yellow-500"></i>Add Supplier</h3>
                <form method="POST" action="?action=add_supplier" class="space-y-4">
                    <div><label class="block text-sm text-gray-500 mb-1">Company / Name</label><input type="text" name="name" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-yellow-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Phone</label><input type="text" name="phone" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-yellow-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Address</label><textarea name="address" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-yellow-500"></textarea></div>
                    <button class="bg-yellow-600 hover:bg-yellow-700 text-white font-bold px-6 py-3 rounded-xl w-full flex items-center justify-center gap-2 transition"><i class="ph-bold ph-plus"></i> Save Supplier</button>
                </form>
            </div>
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border-t-4 border-yellow-500">
                <div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30"><h2 class="text-xl font-bold flex items-center"><i class="ph-duotone ph-address-book mr-2"></i>Supplier Directory</h2></div>
                <div class="overflow-x-auto p-4">
                    <table class="w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="p-3">ID</th><th>Supplier Name</th><th>Phone</th><th>Address</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach(array_reverse($allData['suppliers']) as $s): ?>
                            <tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="p-3"><?php echo $s['id']; ?></td>
                                <td class="font-bold"><?php echo htmlspecialchars($s['name']); ?></td>
                                <td><?php echo htmlspecialchars($s['phone']); ?></td>
                                <td class="truncate max-w-[200px]" title="<?php echo htmlspecialchars($s['address']); ?>"><?php echo htmlspecialchars($s['address']); ?></td>
                                <td><a href="?action=delete_supplier&id=<?php echo $s['id']; ?>" onclick="return confirm('Delete Supplier?')" class="text-red-500 hover:text-red-700 bg-red-50 p-1.5 rounded-lg"><i class="ph-bold ph-trash text-lg"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- PURCHASES -->
        <?php if($action === 'purchase' && $_SESSION['user']['role'] === 'Admin'): ?>
        <div class="grid lg:grid-cols-3 gap-6 animate-slide-up">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-5 border-t-4 border-orange-500">
                <h3 class="font-bold text-lg mb-4 flex items-center"><i class="ph-duotone ph-shopping-bag mr-2 text-2xl text-orange-500"></i>Record Purchase</h3>
                <form method="POST" action="?action=add_purchase" class="space-y-4">
                    <div><label class="block text-sm text-gray-500 mb-1">Date</label><input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-orange-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Supplier</label>
                        <select name="supplier_id" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-orange-500">
                            <option value="">Select Supplier...</option>
                            <?php foreach($allData['suppliers'] as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label class="block text-sm text-gray-500 mb-1">Supplier Inv No.</label><input type="text" name="invoice_no" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-orange-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Total Amount</label><input type="number" step="0.01" name="amount" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-orange-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Notes</label><textarea name="notes" class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-orange-500"></textarea></div>
                    <button class="bg-orange-600 hover:bg-orange-700 text-white font-bold px-6 py-3 rounded-xl w-full flex items-center justify-center gap-2 transition"><i class="ph-bold ph-plus"></i> Save Purchase</button>
                </form>
            </div>
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border-t-4 border-orange-500">
                <div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30"><h2 class="text-xl font-bold flex items-center"><i class="ph-duotone ph-list-numbers mr-2"></i>Purchase History</h2></div>
                <div class="overflow-x-auto p-4">
                    <table class="w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="p-3">Date</th><th>Supplier</th><th>Inv No</th><th>Amount</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach(array_reverse($allData['purchases']) as $p): ?>
                            <tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="p-3"><?php echo date('d/m/Y', strtotime($p['date'])); ?></td>
                                <td class="font-bold"><?php echo htmlspecialchars(getSupplierName($allData['suppliers'], $p['supplier_id'])); ?></td>
                                <td><?php echo htmlspecialchars($p['invoice_no']); ?></td>
                                <td class="font-bold text-orange-600"><?php echo $allData['settings']['currency']; ?><?php echo number_format($p['amount'],2); ?></td>
                                <td><a href="?action=delete_purchase&id=<?php echo $p['id']; ?>" onclick="return confirm('Delete?')" class="text-red-500 hover:text-red-700 bg-red-50 p-1.5 rounded-lg"><i class="ph-bold ph-trash"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- EXPENSES -->
        <?php if($action === 'expenses' && $_SESSION['user']['role'] === 'Admin'): ?>
        <div class="grid lg:grid-cols-3 gap-6 animate-slide-up">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-5 border-t-4 border-red-500">
                <h3 class="font-bold text-lg mb-4 flex items-center"><i class="ph-duotone ph-receipt mr-2 text-2xl text-red-500"></i>Add Expense</h3>
                <form method="POST" action="?action=add_expense" class="space-y-4">
                    <div><label class="block text-sm text-gray-500 mb-1">Date</label><input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-red-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Description</label><input type="text" name="description" placeholder="e.g. Electricity Bill" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-red-500"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Amount</label><input type="number" step="0.01" name="amount" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 focus:ring-2 focus:ring-red-500"></div>
                    <button class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-3 rounded-xl w-full flex justify-center items-center gap-2 transition"><i class="ph-bold ph-plus"></i> Save Expense</button>
                </form>
            </div>
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border-t-4 border-red-500">
                <div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30"><h2 class="text-xl font-bold flex items-center"><i class="ph-duotone ph-wallet mr-2"></i>Expense History</h2></div>
                <div class="overflow-x-auto p-4">
                    <table class="w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="p-3">Date</th><th>Description</th><th>Amount</th><th>Added By</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach(array_reverse($allData['expenses']) as $e): ?>
                            <tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="p-3"><?php echo date('d/m/Y', strtotime($e['date'])); ?></td>
                                <td><?php echo htmlspecialchars($e['description']); ?></td>
                                <td class="font-bold text-red-600"><?php echo $allData['settings']['currency']; ?><?php echo number_format($e['amount'],2); ?></td>
                                <td><span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded-md text-xs"><?php echo htmlspecialchars($e['added_by']); ?></span></td>
                                <td><a href="?action=delete_expense&id=<?php echo $e['id']; ?>" onclick="return confirm('Delete?')" class="text-red-500 hover:text-red-700 bg-red-50 p-1.5 rounded-lg"><i class="ph-bold ph-trash"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- SETTINGS & BACKUP -->
        <?php if($action === 'settings' && $_SESSION['user']['role'] === 'Admin'): ?>
        <div class="grid lg:grid-cols-2 gap-6 animate-slide-up">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-t-4 border-gray-600">
                <h2 class="text-xl font-bold mb-4 flex items-center"><i class="ph-duotone ph-gear mr-2 text-2xl text-gray-600 dark:text-gray-400"></i>System Settings</h2>
                <form method="POST" action="?action=update_settings" enctype="multipart/form-data" class="space-y-4">
                    <div><label class="block text-sm text-gray-500 mb-1">Pharmacy Name</label><input type="text" name="pharmacy_name" value="<?php echo htmlspecialchars($allData['settings']['pharmacy_name']); ?>" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Phone</label><input type="text" name="phone" value="<?php echo htmlspecialchars($allData['settings']['phone']); ?>" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Address</label><input type="text" name="address" value="<?php echo htmlspecialchars($allData['settings']['address']); ?>" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white"></div>
                    <div><label class="block text-sm text-gray-500 mb-1">Currency Symbol</label><input type="text" name="currency" value="<?php echo htmlspecialchars($allData['settings']['currency']); ?>" required class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white"></div>
                    <div>
                        <label class="block text-sm text-gray-500 mb-1">Pharmacy Logo (Optional)</label>
                        <?php if($allData['settings']['logo_path']): ?><img src="<?php echo htmlspecialchars($allData['settings']['logo_path']); ?>" class="h-16 mb-2 rounded bg-gray-100 p-1 border dark:bg-gray-700 dark:border-gray-600"><?php endif; ?>
                        <input type="file" name="logo" accept="image/*" class="w-full border rounded-xl p-2 outline-none dark:bg-gray-700 text-sm">
                    </div>
                    <button class="bg-gray-800 dark:bg-gray-600 hover:bg-gray-900 dark:hover:bg-gray-500 text-white font-bold px-6 py-3 rounded-xl w-full mt-4 flex items-center justify-center gap-2 transition"><i class="ph-bold ph-floppy-disk"></i> Update Settings</button>
                </form>
            </div>

            <div class="space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-t-4 border-blue-500">
                    <h2 class="text-xl font-bold mb-4 flex items-center"><i class="ph-duotone ph-database mr-2 text-2xl text-blue-500"></i>Data Backup</h2>
                    <p class="text-sm text-gray-500 mb-4">Download a full JSON backup of your database including medicines, sales, customers, and settings.</p>
                    <a href="?action=backup_database" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-xl w-full flex items-center justify-center gap-2 transition shadow"><i class="ph-bold ph-download-simple"></i> Download Full Backup</a>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-t-4 border-purple-500">
                    <h2 class="text-xl font-bold mb-4 flex items-center"><i class="ph-duotone ph-upload-simple mr-2 text-2xl text-purple-500"></i>Data Restore</h2>
                    <p class="text-sm text-red-500 mb-4 font-semibold"><i class="ph-bold ph-warning"></i> Warning: Restoring will overwrite all existing data!</p>
                    <form method="POST" action="?action=restore_database" enctype="multipart/form-data" onsubmit="return confirm('Are you strictly sure you want to overwrite the current database?');">
                        <input type="file" name="backup_file" accept=".json" required class="w-full border rounded-xl p-2 outline-none dark:bg-gray-700 text-sm mb-3">
                        <button class="bg-purple-600 hover:bg-purple-700 text-white font-bold px-6 py-3 rounded-xl w-full flex items-center justify-center gap-2 transition shadow"><i class="ph-bold ph-upload-simple"></i> Restore Database</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- USER MANAGEMENT -->
        <?php if($action === 'users_manage' && $_SESSION['user']['role'] === 'Admin'): ?>
        <div class="grid lg:grid-cols-3 gap-6 animate-slide-up">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-5 border-t-4 border-pink-500"><h3 class="font-bold text-lg mb-4 flex items-center"><i class="ph-duotone ph-user-plus mr-2 text-2xl text-pink-500"></i>Add User</h3><form method="POST" class="space-y-4"><input type="hidden" name="action" value="add_user"><input type="text" name="username" placeholder="Username" class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-pink-500" required><input type="email" name="email" placeholder="Email" class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-pink-500" required><input type="password" name="password" placeholder="Password" class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-pink-500" required><select name="role" class="w-full border rounded-xl p-3 outline-none dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-pink-500"><option value="Admin">Admin</option><option value="Staff">Staff</option><option value="Customer">Customer</option></select><button class="bg-pink-600 hover:bg-pink-700 transition text-white font-bold px-6 py-3 rounded-xl w-full">Create User</button></form></div>
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border-t-4 border-pink-500"><div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30"><h2 class="text-xl font-bold flex items-center"><i class="ph-duotone ph-users-three mr-2 text-2xl text-pink-500"></i>Existing Users</h2></div><div class="overflow-x-auto p-4"><table class="w-full text-left whitespace-nowrap"><thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="p-3">ID</th><th>Username</th><th>Role</th><th>Actions</th></tr></thead><tbody><?php foreach($allData['users'] as $u): ?><tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"><td class="p-3"><?php echo $u['id']; ?></td><td class="font-bold"><?php echo htmlspecialchars($u['username']); ?></td><td><span class="px-2 py-1 rounded bg-gray-100 dark:bg-gray-600 text-xs font-semibold"><?php echo htmlspecialchars($u['role']); ?></span></td><td><?php if($u['id'] !== $_SESSION['user']['id']): ?><a href="?action=delete_user&id=<?php echo $u['id']; ?>" class="text-red-500 font-bold hover:underline">Delete</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        </div>
        <?php endif; ?>

        <!-- REPORTS -->
        <?php if($action === 'reports' && $_SESSION['user']['role'] === 'Admin'): ?>
        <div class="animate-slide-up">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-5 mb-6 border-t-4 border-indigo-500"><h2 class="text-xl font-bold mb-5 flex items-center border-b pb-3"><i class="ph-duotone ph-chart-line-up mr-2 text-2xl text-indigo-500"></i>Financial Overview</h2><div class="grid grid-cols-1 md:grid-cols-3 gap-4"><div class="bg-green-50 dark:bg-green-900/20 p-5 rounded-2xl border dark:border-green-800"><p class="text-sm font-semibold text-green-800 dark:text-green-300">Total Revenue</p><p class="text-3xl font-bold text-green-600"><?php echo $allData['settings']['currency']; ?><?php echo number_format($totalSales,2); ?></p></div><div class="bg-purple-50 dark:bg-purple-900/20 p-5 rounded-2xl border dark:border-purple-800"><p class="text-sm font-semibold text-purple-800 dark:text-purple-300">Total Profit</p><p class="text-3xl font-bold text-purple-600"><?php echo $allData['settings']['currency']; ?><?php echo number_format($totalProfit,2); ?></p></div><div class="bg-red-50 dark:bg-red-900/20 p-5 rounded-2xl border dark:border-red-800"><p class="text-sm font-semibold text-red-800 dark:text-red-300">Total Expenses</p><p class="text-3xl font-bold text-red-600"><?php echo $allData['settings']['currency']; ?><?php echo number_format($totalExpenses,2); ?></p></div></div></div>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border-t-4 border-indigo-500">
                <div class="px-5 py-4 border-b bg-gray-50 dark:bg-gray-700/30"><h2 class="text-lg font-bold flex items-center"><i class="ph-duotone ph-list-dashes mr-2"></i>Recent Profit Logs</h2></div>
                <div class="overflow-x-auto p-4">
                    <table class="w-full text-left whitespace-nowrap text-sm">
                        <thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="p-3">Date</th><th>Type</th><th>Invoice No</th><th>Amount</th><th>Profit</th></tr></thead>
                        <tbody>
                            <?php $plogs = isset($allData['profit_loss_logs']) ? array_reverse(array_slice($allData['profit_loss_logs'], -20)) : []; foreach($plogs as $log): ?>
                            <tr class="border-b dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="p-3"><?php echo $log['date']; ?></td>
                                <td><span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-bold"><?php echo $log['type']; ?></span></td>
                                <td class="font-mono"><?php echo $log['invoice_no']; ?></td>
                                <td class="text-green-600 font-bold"><?php echo $allData['settings']['currency']; ?><?php echo number_format($log['amount'],2); ?></td>
                                <td class="text-emerald-600 font-bold">+<?php echo number_format($log['profit'],2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- CLIENT-SIDE TELEGRAM DISPATCHER -->
<?php if(isset($_SESSION['tg_dispatch'])): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const tgToken = '<?php echo TELEGRAM_BOT_TOKEN; ?>';
    const tgChannel = '<?php echo TELEGRAM_CHANNEL_ID; ?>';
    const tgAdmin = '<?php echo TELEGRAM_MY_CHAT_ID; ?>';
    const tgMsg = <?php echo json_encode($_SESSION['tg_dispatch']['msg']); ?>;
    const tgHtml = <?php echo json_encode($_SESSION['tg_dispatch']['html']); ?>;
    const tgInv = <?php echo json_encode($_SESSION['tg_dispatch']['inv']); ?>;

    function sendTgText(chatId) {
        fetch(`https://api.telegram.org/bot${tgToken}/sendMessage`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chat_id: chatId, text: tgMsg, parse_mode: 'HTML', disable_web_page_preview: true })
        }).then(r => console.log('TG Sent')).catch(e => console.error(e));
    }

    function sendTgDoc(chatId) {
        if(!tgHtml) return;
        const blob = new Blob([tgHtml], { type: 'text/html' });
        const fd = new FormData();
        fd.append('chat_id', chatId);
        fd.append('document', blob, tgInv + '.html');
        fd.append('caption', '🧾 Invoice File: ' + tgInv);
        fetch(`https://api.telegram.org/bot${tgToken}/sendDocument`, { method: 'POST', body: fd })
        .then(r => console.log('TG Doc Sent')).catch(e => console.error(e));
    }

    sendTgText(tgChannel);
    sendTgText(tgAdmin);
    setTimeout(() => { sendTgDoc(tgChannel); sendTgDoc(tgAdmin); }, 1500);
});
</script>
<?php unset($_SESSION['tg_dispatch']); endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar'); var overlay = document.getElementById('sidebarOverlay');
    var mobileMenuBtn = document.getElementById('mobileMenuBtn'); var closeSidebarBtn = document.getElementById('closeSidebarBtn');
    function toggleMenu() {
        if(sidebar.classList.contains('-translate-x-full')) { sidebar.classList.remove('-translate-x-full'); overlay.classList.remove('hidden'); setTimeout(function() { overlay.classList.remove('opacity-0'); }, 10); } 
        else { sidebar.classList.add('-translate-x-full'); overlay.classList.add('opacity-0'); setTimeout(function() { overlay.classList.add('hidden'); }, 300); }
    }
    if(mobileMenuBtn) mobileMenuBtn.addEventListener('click', toggleMenu);
    if(closeSidebarBtn) closeSidebarBtn.addEventListener('click', toggleMenu);
    if(overlay) overlay.addEventListener('click', toggleMenu);
});
</script>
</body>
</html>
