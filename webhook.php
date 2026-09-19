<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-FamGateway-Signature');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* PUT YOUR FAM API KEY BETWEEN THE QUOTES */
$FAM_API_KEY = 'fam_0219a84297ec72b691eb1cb6a76d8ba1cf555380';

$dir = __DIR__ . '/fam_data';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$ordersFile = $dir . '/orders.json';
$paymentsFile = $dir . '/payments.json';

function read_json_file($f) {
    if (!file_exists($f)) return [];
    $x = json_decode(@file_get_contents($f), true);
    return is_array($x) ? $x : [];
}
function write_json_file($f, $x) {
    @file_put_contents($f, json_encode($x, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function body_json() {
    $x = json_decode(file_get_contents('php://input'), true);
    return is_array($x) ? $x : [];
}
function out($x, $code=200) {
    http_response_code($code);
    echo json_encode($x, JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['success'=>false,'message'=>'POST required'],405);
    if ($FAM_API_KEY === 'PASTE_YOUR_FAM_API_KEY_HERE') out(['success'=>false,'message'=>'Add FAM API key in webhook.php'],500);

    $b = body_json();
    $amount = (float)($b['amount'] ?? 0);
    $uid = trim((string)($b['uid'] ?? ''));
    $email = trim((string)($b['email'] ?? ''));
    if ($amount <= 0 || !$uid) out(['success'=>false,'message'=>'Invalid amount or user'],400);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $webhookUrl = $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?action=webhook';

    $payload = json_encode([
        'amount'=>round($amount,2),
        'customer_name'=>$uid,
        'customer_email'=>$email,
        'webhook_url'=>$webhookUrl
    ]);

    $ch = curl_init('https://famgateway.in/api/create-order');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-Api-Key: '.$FAM_API_KEY],
        CURLOPT_TIMEOUT=>30
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $http = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false || $err) out(['success'=>false,'message'=>'FAM connection failed: '.$err],502);

    $r = json_decode($raw,true);
    if (!is_array($r) || ($r['status'] ?? '') !== 'success') {
        out(['success'=>false,'message'=>is_array($r)?($r['message']??'FAM order failed'):'Invalid FAM response','http_code'=>$http],502);
    }

    $d = $r['data'] ?? [];
    $orderId = (string)($d['order_id'] ?? '');
    if (!$orderId) out(['success'=>false,'message'=>'FAM did not return order ID'],502);

    $orders = read_json_file($ordersFile);
    $orders[$orderId] = ['uid'=>$uid,'email'=>$email,'amount'=>(float)($d['amount']??$amount),'created_at'=>time()];
    write_json_file($ordersFile,$orders);

    out(['success'=>true,'order_id'=>$orderId,'amount'=>(float)($d['amount']??$amount),
         'qr_url'=>$d['qr_url']??'','checkout_url'=>$d['checkout_url']??'','upi_intent'=>$d['upi_intent']??'']);
}

if ($action === 'status') {
    $orderId = trim((string)($_GET['order_id'] ?? ''));
    $uid = trim((string)($_GET['uid'] ?? ''));
    $orders = read_json_file($ordersFile);
    if (!$orderId || !$uid || !isset($orders[$orderId]) || ($orders[$orderId]['uid'] ?? '') !== $uid)
        out(['success'=>false,'message'=>'Order not found'],404);

    $payments = read_json_file($paymentsFile);
    if (isset($payments[$orderId])) {
        $p=$payments[$orderId];
        out(['success'=>true,'status'=>'success','amount'=>(float)$p['amount'],'utr'=>$p['utr']??'','sender_name'=>$p['sender_name']??'','transaction_id'=>$p['transaction_id']??'']);
    }

    $url='https://famgateway.in/api/checkout-status.php?order_id='.urlencode($orderId);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_TIMEOUT=>20]);
    $raw=curl_exec($ch); curl_close($ch);
    $r=json_decode($raw?:'',true);
    $s=is_array($r)?strtolower((string)($r['status']??($r['data']['status']??'pending'))):'pending';
    $d=is_array($r)?($r['data']??$r):[];

    if (in_array($s,['success','paid','completed'],true))
        out(['success'=>true,'status'=>'success','amount'=>(float)($d['amount']??$orders[$orderId]['amount']),'utr'=>$d['utr']??'','sender_name'=>$d['sender_name']??'','transaction_id'=>$d['transaction_id']??'']);
    if (in_array($s,['expired','failed','cancelled'],true)) out(['success'=>true,'status'=>'expired']);
    out(['success'=>true,'status'=>'pending']);
}

if ($action === 'webhook') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['success'=>false,'message'=>'POST required'],405);
    if ($FAM_API_KEY === 'PASTE_YOUR_FAM_API_KEY_HERE') out(['success'=>false,'message'=>'Add FAM API key in webhook.php'],500);

    $raw=file_get_contents('php://input');
    $sig=$_SERVER['HTTP_X_FAMGATEWAY_SIGNATURE']??'';
    $expected=hash_hmac('sha256',$raw,$FAM_API_KEY);
    if (!$sig || !hash_equals($expected,$sig)) out(['success'=>false,'message'=>'Invalid signature'],401);

    $e=json_decode($raw,true);
    $orderId=(string)($e['order_id']??'');
    if (!$orderId) out(['success'=>false,'message'=>'Missing order ID'],400);

    if (($e['status']??'')==='success' || ($e['event']??'')==='payment.success') {
        $orders=read_json_file($ordersFile); $payments=read_json_file($paymentsFile);
        if (isset($orders[$orderId])) {
            $payments[$orderId]=[
                'uid'=>$orders[$orderId]['uid'],
                'amount'=>(float)($e['amount']??$orders[$orderId]['amount']),
                'utr'=>(string)($e['utr']??''),
                'sender_name'=>(string)($e['sender_name']??''),
                'transaction_id'=>(string)($e['transaction_id']??''),
                'received_at'=>time()
            ];
            write_json_file($paymentsFile,$payments);
        }
    }
    out(['status'=>'received']);
}

out(['success'=>true,'message'=>'FAM endpoint is running']);
?>