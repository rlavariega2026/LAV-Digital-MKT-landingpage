<?php
define('LAV_MKT_TEST',true);require __DIR__.'/../api.php';
function checkOrders($a,$b){if($a!==$b)throw new Exception('Order assertion failed');}
$c=catalog();$cfg=['enabled'=>false,'environment'=>'test','access_token'=>'dummy','webhook_secret'=>str_repeat('x',64),'merchant_id'=>'1','test_key'=>str_repeat('k',32)];
checkOrders(authorizedTest($cfg,[]),false);checkOrders(authorizedTest($cfg,['test_key'=>str_repeat('k',32)]),true);
$q=calculate(['items'=>[['id'=>'qr','qty'=>1,'large'=>0]]],$c,array_merge($cfg,['enabled'=>true]));$r=str_repeat('a',32);$p=orderPayload($q,$r,'buyer@example.com');
checkOrders($p['total_amount'],'250.00');checkOrders($p['items'][0]['unit_price'],'250.00');checkOrders($p['type'],'online');checkOrders($p['processing_mode'],'manual');
$id='ORDTST01KS5AJ6HTK2HRQ3XJ3C2JCKP9';$local=['mp_order_id'=>$id,'reference'=>$r,'environment'=>'test','total'=>250];$remote=['id'=>$id,'external_reference'=>$r,'currency'=>'MXN','user_id'=>'1','total_amount'=>'250.00','total_paid_amount'=>'250.00','status'=>'processed','status_detail'=>'accredited'];
checkOrders(orderState($cfg,$local,$remote),'paid');$remote['total_paid_amount']='0';checkOrders(orderState($cfg,$local,$remote),'pending');
$remote['total_amount']='1.00';try{orderState($cfg,$local,$remote);throw new LogicException('Expected mismatch');}catch(RuntimeException $e){}
$ts='1742505638683';$req='test-request';$sig='ts='.$ts.',v1='.hash_hmac('sha256','id:'.strtolower($id).';request-id:'.$req.';ts:'.$ts.';',$cfg['webhook_secret']);
checkOrders(validSignature($id,$req,$sig,$cfg['webhook_secret']),true);checkOrders(validSignature($id,$req,$sig,str_repeat('z',64)),false);
echo "MKT_ORDERS_TESTS_OK\n";
