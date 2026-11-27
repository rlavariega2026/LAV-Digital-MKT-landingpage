<?php
define('LAV_MKT_TEST',true);require __DIR__.'/../api.php';
function check($a,$b){if($a!==$b)throw new Exception('Expected '.json_encode($b).' got '.json_encode($a));}
$c=catalog();$cfg=['enabled'=>true,'access_token'=>'test','webhook_secret'=>'test','merchant_id'=>'1','max_physical_packages'=>2,'postal_zones'=>['00001'=>'A','00002'=>'B','00003'=>'C','00004'=>'D','00005'=>'E','00006'=>'F','00007'=>'G'],'express_postal_codes'=>['00001']];
foreach([150,170,190,200,210,220,230] as $i=>$price){$q=calculate(['items'=>[['id'=>'nfc','qty'=>1,'large'=>0]],'cp'=>'0000'.($i+1),'method'=>'standard','zone'=>'A'],$c,$cfg);check($q['shipping'],$price);check($q['total'],500+$price);check($q['payable'],true);}
$q=calculate(['items'=>[['id'=>'double-display','qty'=>1,'large'=>2]],'cp'=>'00001','method'=>'express'],$c,$cfg);check($q['total'],1585);check($q['payable'],true);
$q=calculate(['items'=>[['id'=>'digital','qty'=>1,'large'=>0]]],$c,$cfg);check($q['total'],400);check($q['shipping'],0);
$q=calculate(['items'=>[['id'=>'nfc','qty'=>1,'large'=>0]],'cp'=>'99999','method'=>'standard','zone'=>'A'],$c,$cfg);check($q['payable'],false);
$q=calculate(['items'=>[['id'=>'digital','qty'=>1,'large'=>0]]],$c,[]);check($q['payable'],false);
echo "MKT_SHIPPING_AND_CATALOG_TESTS_OK\n";
