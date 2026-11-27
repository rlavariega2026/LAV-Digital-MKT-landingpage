<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function send(array $v,int $code=200):never{http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function fail(string $m,int $c=400):never{send(['error'=>$m],$c);}
function catalog():array{return json_decode(file_get_contents(__DIR__.'/catalog.json'),true,512,JSON_THROW_ON_ERROR);}
function config():array{$p=dirname(__DIR__,2).'/lav-mkt-commerce-config.php';return is_file($p)?(array)require $p:[];}
function textField(array $d,string $k,int $max):string{$v=$d[$k]??'';if(!is_string($v)||strlen($v)>$max)fail('Dato inválido: '.$k);return trim($v);}
function calculate(array $d,array $cat,array $cfg):array{
 $raw=$d['items']??null;if(!is_array($raw)||!count($raw)||count($raw)>30)fail('Carrito inválido.');
 $by=array_column($cat['items'],null,'id');$lines=[];$subtotal=0;$physical=0;$seen=[];
 foreach($raw as $x){if(!is_array($x)||!is_string($x['id']??null)||!isset($by[$x['id']]))fail('Producto inválido.');$p=$by[$x['id']];$qty=$x['qty']??null;$large=$x['large']??null;if($p['quote']||!is_int($qty)||$qty<1||$qty>20||!is_int($large)||$large<0||$large>$p['displays'])fail('Selección inválida.');$key=$p['id'].':'.$large;if(isset($seen[$key]))fail('Producto duplicado.');$seen[$key]=true;$price=$p['price']+100*$large;$subtotal+=$price*$qty;if($p['physical'])$physical+=$qty;$lines[]=['id'=>$p['id'],'title'=>$p['name'].($p['displays']?' ('.$large.' grande(s), '.($p['displays']-$large).' compacto(s))':''),'quantity'=>$qty,'currency_id'=>'MXN','unit_price'=>$price];}
 $ship=0;$verified=true;$method='digital';$cp='';$message='';$zone=null;
 if($physical){$cp=textField($d,'cp',5);$method=textField($d,'method',20);$choice=textField($d,'zone',1);$zones=array_column($cat['zones'],null,'id');$actual=$cfg['postal_zones'][$cp]??null;$verified=preg_match('/^\d{5}$/D',$cp)===1&&isset($zones[$actual])&&$physical<= (int)($cfg['max_physical_packages']??0);if($method==='standard'){$zone=$verified?$actual:$choice;$ship=$zones[$zone]['price']??null;}elseif($method==='express'){$ship=$cat['express']['price'];$verified=$verified&&in_array($cp,$cfg['express_postal_codes']??[],true);}else fail('Envío inválido.');if(!$verified)$message='Tarifa estimada. Confirma destino, cobertura y peso por WhatsApp antes del pago.';}
 $total=$ship===null?null:$subtotal+$ship;$active=($cfg['enabled']??false)===true&&!empty($cfg['access_token'])&&!empty($cfg['webhook_secret'])&&!empty($cfg['merchant_id']);$payable=$active&&$verified&&$total!==null;if(!$active)$message='Pago en línea pendiente de activación. Puedes confirmar tu pedido por WhatsApp.';elseif($payable)$message='Total confirmado. Puedes continuar a Mercado Pago.';
 return ['subtotal'=>$subtotal,'shipping'=>$ship,'total'=>$total,'payable'=>$payable,'message'=>$message,'lines'=>$lines,'method'=>$method,'zone'=>$zone,'cp'=>$cp];
}
function ordersDir():string{$dir=dirname(__DIR__,2).'/lav-mkt-orders';if(!is_dir($dir)&&!mkdir($dir,0700,true))fail('Almacenamiento no disponible.',503);return $dir;}
function orderPath(string $r):string{if(!preg_match('/^[a-f0-9]{32}$/D',$r))fail('Referencia inválida.');return ordersDir().'/'.$r.'.json';}
function saveOrder(array $o):void{$p=orderPath($o['reference']);$tmp=$p.'.'.bin2hex(random_bytes(6));if(file_put_contents($tmp,json_encode($o,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)fail('No se pudo guardar pedido.',503);chmod($tmp,0600);if(!rename($tmp,$p))fail('No se pudo guardar pedido.',503);}
function readOrder(string $r):array{$p=orderPath($r);if(!is_file($p))fail('Pedido no encontrado.',404);return json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);}
function mp(array $cfg,string $path,?array $body=null):array{$c=curl_init('https://api.mercadopago.com'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['access_token'],'Content-Type: application/json'],CURLOPT_SSL_VERIFYPEER=>true]);if($body!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);if($raw===false||$status<200||$status>=300)fail('Mercado Pago no disponible. Intenta más tarde.',502);return json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
function payment(array $cfg,string $id,?string $expected=null):array{if(!preg_match('/^\d{1,25}$/D',$id))fail('Pago inválido.');$p=mp($cfg,'/v1/payments/'.$id);$r=(string)($p['external_reference']??'');if($expected!==null&&!hash_equals($expected,$r))fail('Referencia de pago incorrecta.',403);$o=readOrder($r);if((string)($p['collector_id']??'')!==(string)$cfg['merchant_id']||($p['currency_id']??'')!=='MXN'||(int)round((float)($p['transaction_amount']??0)*100)!==(int)$o['total']*100)fail('Pago no coincide con el pedido.',409);if(($p['status']??'')==='approved'){$o['status']='paid';$o['payment_id']=$id;saveOrder($o);}return ['status'=>$o['status'],'reference'=>$r];}
if(defined('LAV_MKT_TEST'))return;
try{
 $cfg=config();$cat=catalog();$action=$_GET['action']??'';
 if($_SERVER['REQUEST_METHOD']!=='POST')fail('Método no permitido.',405);
 $raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)fail('Solicitud demasiado grande.',413);$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($d))fail('Datos inválidos.');
 if($action==='quote'){ $q=calculate($d,$cat,$cfg);unset($q['lines']);send($q);}
 if(!($cfg['enabled']??false)||empty($cfg['access_token'])||empty($cfg['webhook_secret'])||empty($cfg['merchant_id']))fail('Pago en línea pendiente de activación.',503);
 if($action==='checkout'){
 $q=calculate($d,$cat,$cfg);if(!$q['payable'])fail('El envío o pago requiere confirmación.');
 $name=textField($d,'name',100);$email=textField($d,'email',150);$phone=textField($d,'phone',30);$address=textField($d,'address',400);
 if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($phone)<7||($q['method']!=='digital'&&!$address))fail('Completa los datos del pedido.');
 $r=bin2hex(random_bytes(16));$o=['reference'=>$r,'status'=>'pending','created'=>gmdate('c'),'total'=>$q['total'],'customer'=>['name'=>$name,'email'=>$email,'phone'=>$phone,'address'=>$address,'cp'=>$q['cp']],'order'=>$q];saveOrder($o);
 $items=$q['lines'];if($q['shipping'])$items[]=['id'=>'shipping','title'=>'Envío '.$q['method'],'quantity'=>1,'currency_id'=>'MXN','unit_price'=>$q['shipping']];
 $url='https://lavdigitalmkt.com/?mkt_order='.$r;
 $p=mp($cfg,'/checkout/preferences',['items'=>$items,'external_reference'=>$r,'payer'=>['email'=>$email],'back_urls'=>['success'=>$url,'pending'=>$url,'failure'=>$url],'auto_return'=>'approved','notification_url'=>'https://lavdigitalmkt.com/mkt-commerce/api.php?action=webhook&source_news=webhooks','expires'=>true,'expiration_date_to'=>gmdate('Y-m-d\TH:i:s.000\Z',time()+3600)]);
 $target=$p['init_point']??'';if(!is_string($target)||!preg_match('~^https://([a-z0-9-]+\.)?mercadopago\.com(\.mx)?/~i',$target))fail('Enlace de pago no válido.',502);$o['preference_id']=$p['id']??'';saveOrder($o);send(['url'=>$target,'reference'=>$r]);}
 if($action==='status'){$r=textField($d,'reference',32);$o=readOrder($r);$id=textField($d,'payment_id',25);if($id!=='')send(payment($cfg,$id,$r));send(['status'=>$o['status'],'reference'=>$r]);}
 if($action==='webhook'){
 $id=(string)($d['data']['id']??'');$queryId=(string)($_GET['data_id']??'');$requestId=$_SERVER['HTTP_X_REQUEST_ID']??'';$sig=$_SERVER['HTTP_X_SIGNATURE']??'';$parts=[];foreach(explode(',',$sig) as $piece){$v=explode('=',trim($piece),2);if(count($v)===2)$parts[$v[0]]=$v[1];}
 $ts=$parts['ts']??'';$v1=$parts['v1']??'';if($id!==$queryId||!preg_match('/^\d+$/D',$id)||!$requestId||!ctype_digit($ts)||!hash_equals(hash_hmac('sha256','id:'.$id.';request-id:'.$requestId.';ts:'.$ts.';',$cfg['webhook_secret']),$v1))fail('Firma inválida.',401);
 if(($d['type']??'')==='payment')payment($cfg,$id);send(['ok'=>true]);}
 fail('Acción desconocida.',404);
}catch(Throwable $e){error_log('LAV MKT commerce error: '.get_class($e));fail('No se pudo procesar el pedido.',500);}
