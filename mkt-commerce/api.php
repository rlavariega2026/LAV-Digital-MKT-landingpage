<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function send(array $v,int $code=200):never{http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function fail(string $m,int $c=400):never{send(['error'=>$m],$c);}
function catalog():array{return json_decode(file_get_contents(__DIR__.'/catalog.json'),true,512,JSON_THROW_ON_ERROR);}
function privateConfigPaths():array{
 $domain=dirname(__DIR__,2);
 $paths=[$domain.'/lav-mkt-commerce-config.php'];
 // Hostinger's full-account Home can be two levels above the domain directory.
 if(basename(dirname($domain))==='domains'&&basename($domain)==='lavdigitalmkt.com')$paths[]=dirname($domain,2).'/lav-mkt-commerce-config.php';
 return $paths;
}
function config():array{
 $public=realpath(dirname(__DIR__));if($public===false)return [];
 foreach(privateConfigPaths() as $p){
  if(!@is_file($p)||!@is_readable($p))continue;
  $real=@realpath($p);if($real===false||$real===$public||str_starts_with($real,$public.DIRECTORY_SEPARATOR))continue;
  $v=require $real;if(is_array($v))return $v;
 }
 return [];
}
function testAccessMessage(array $cfg,array $d):string{
 if(!$cfg)return 'No se encontró el archivo privado lav-mkt-commerce-config.php. Debe estar junto a public_html o en Home de la cuenta, nunca dentro de public_html.';
 if(($cfg['environment']??'')!=='test')return 'El archivo existe, pero falta environment con valor test.';
 if(!is_string($cfg['test_key']??null)||strlen($cfg['test_key'])<20)return 'El archivo existe, pero falta la línea test_key del enlace privado.';
 if(!is_string($d['test_key']??null)||!hash_equals($cfg['test_key'],$d['test_key']))return 'El archivo existe, pero test_key no coincide con el enlace privado. Revisa la clave y abre nuevamente ese enlace en incógnito.';
 return 'El acceso de prueba requiere revisar la configuración.';
}
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
function mp(array $cfg,string $path,?array $body=null,?string $idem=null):array{$c=curl_init('https://api.mercadopago.com'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['access_token'],'Content-Type: application/json','Accept: application/json',...($idem?['X-Idempotency-Key: '.$idem]:[])],CURLOPT_SSL_VERIFYPEER=>true]);if($body!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);if($raw===false||$status<200||$status>=300)fail('Mercado Pago no disponible. Intenta más tarde.',502);return json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
function isTest(array $cfg):bool{return ($cfg['environment']??'')==='test';}
function uuid():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));}
function authorizedTest(array $cfg,array $d):bool{return isTest($cfg)&&is_string($cfg['test_key']??null)&&strlen($cfg['test_key'])>=20&&is_string($d['test_key']??null)&&hash_equals($cfg['test_key'],$d['test_key']);}
function validOrderId(string $id):bool{return preg_match('/^ORD[A-Z0-9]{10,60}$/D',$id)===1;}
function orderPayload(array $q,string $ref,string $email):array{
 $items=array_map(static fn($l)=>['title'=>$l['title'],'quantity'=>$l['quantity'],'unit_price'=>number_format($l['unit_price'],2,'.','')],$q['lines']);
 if($q['shipping'])$items[]=['title'=>'Envío '.($q['method']==='express'?'Estafeta exprés':'Mexpost estándar'),'quantity'=>1,'unit_price'=>number_format($q['shipping'],2,'.','')];
 $url='https://lavdigitalmkt.com/?mkt_order='.$ref;
 return ['type'=>'online','processing_mode'=>'manual','capture_mode'=>'automatic','total_amount'=>number_format($q['total'],2,'.',''),'external_reference'=>$ref,'expiration_time'=>'PT1H','payer'=>['email'=>$email],'items'=>$items,'config'=>['online'=>['success_url'=>$url,'pending_url'=>$url,'failure_url'=>$url,'auto_return'=>'all']]];
}
function orderState(array $cfg,array $local,array $remote):string{
 if(($remote['id']??'')!==($local['mp_order_id']??'')||($remote['external_reference']??'')!==$local['reference']||(string)($remote['user_id']??'')!==(string)$cfg['merchant_id']||($remote['currency']??$remote['currency_id']??'')!=='MXN'||(int)round((float)($remote['total_amount']??0)*100)!==(int)$local['total']*100)throw new RuntimeException('Order mismatch');
 if(($local['environment']??'')!==($cfg['environment']??''))throw new RuntimeException('Environment mismatch');
 $status=$remote['status']??'';
 if($status==='processed'&&($remote['status_detail']??'')==='accredited'&&(int)round((float)($remote['total_paid_amount']??0)*100)===(int)$local['total']*100)return 'paid';
 return match($status){'failed'=>'failed','canceled','expired'=>'canceled','refunded'=>'refunded','charged_back'=>'charged_back',default=>'pending'};
}
function refreshOrder(array $cfg,string $id,?string $expected=null):array{
 if(!validOrderId($id))fail('Order inválida.');$p=mp($cfg,'/v1/orders/'.rawurlencode($id));$r=(string)($p['external_reference']??'');if($expected!==null&&!hash_equals($expected,$r))fail('Referencia incorrecta.',403);
 $o=readOrder($r);$o['status']=orderState($cfg,$o,$p);$o['updated']=gmdate('c');saveOrder($o);return ['status'=>$o['status'],'reference'=>$r,'environment'=>$o['environment']];
}
function validSignature(string $id,string $req,string $sig,string $secret):bool{
 $parts=[];foreach(explode(',',$sig) as $piece){$v=explode('=',trim($piece),2);if(count($v)===2)$parts[$v[0]]=$v[1];}
 $ts=$parts['ts']??'';$v1=$parts['v1']??'';return validOrderId($id)&&$req!==''&&ctype_digit($ts)&&strlen($secret)>=32&&hash_equals(hash_hmac('sha256','id:'.strtolower($id).';request-id:'.$req.';ts:'.$ts.';',$secret),$v1);
}
if(defined('LAV_MKT_TEST'))return;
try{
 $cfg=config();$cat=catalog();$action=$_GET['action']??'';
 if($_SERVER['REQUEST_METHOD']!=='POST')fail('Método no permitido.',405);
 $raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)fail('Solicitud demasiado grande.',413);$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($d))fail('Datos inválidos.');
 $test=isTest($cfg);$testAuthorized=authorizedTest($cfg,$d);$effective=$cfg;
 if($test)$effective['enabled']=$testAuthorized;elseif(($cfg['environment']??'')!=='production')$effective['enabled']=false;
 if($action==='quote'){ $q=calculate($d,$cat,$effective);unset($q['lines']);$q['test']=$testAuthorized; if($testAuthorized&&$q['payable'])$q['message']='MODO DE PRUEBA: utiliza únicamente la tarjeta de prueba de Mercado Pago. No se procesará un pedido real.';elseif($testAuthorized&&!$q['payable'])$q['message']='Prueba autorizada, pero falta validar la configuración de pago o el envío. Prueba primero un producto digital.';elseif(!$q['payable']&&is_string($d['test_key']??null)&&$d['test_key']!=='')$q['message']=testAccessMessage($cfg,$d);send($q);}
 if($action==='webhook'){
 $id=(string)($d['data']['id']??'');$queryId=(string)($_GET['data_id']??'');$req=$_SERVER['HTTP_X_REQUEST_ID']??'';$sig=$_SERVER['HTTP_X_SIGNATURE']??'';
 if(($d['type']??'')!=='order'||$id!==$queryId||!validSignature($id,$req,$sig,(string)($cfg['webhook_secret']??'')))fail('Firma inválida.',401);
 if(!isset($d['live_mode'])||$d['live_mode']!==!$test||(string)($d['user_id']??'')!==(string)($cfg['merchant_id']??''))fail('Entorno o cuenta incorrecta.',403);
 refreshOrder($cfg,$id);send(['ok'=>true]);}
 $cfg=$effective;
 if(!($cfg['enabled']??false)||empty($cfg['access_token'])||empty($cfg['webhook_secret'])||empty($cfg['merchant_id']))fail('Pago en línea pendiente de activación.',503);
 if($action==='checkout'){
 $q=calculate($d,$cat,$cfg);if(!$q['payable'])fail('El envío o pago requiere confirmación.');
 $name=textField($d,'name',100);$email=textField($d,'email',150);$phone=textField($d,'phone',30);$address=textField($d,'address',400);
 if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($phone)<7||($q['method']!=='digital'&&!$address))fail('Completa los datos del pedido.');
 $r=bin2hex(random_bytes(16));$o=['reference'=>$r,'environment'=>$cfg['environment'],'status'=>'pending','created'=>gmdate('c'),'total'=>$q['total'],'customer'=>['name'=>$name,'email'=>$email,'phone'=>$phone,'address'=>$address,'cp'=>$q['cp']],'order'=>$q];saveOrder($o);
 $p=mp($cfg,'/v1/orders',orderPayload($q,$r,$email),uuid());
 $id=(string)($p['id']??'');$target=$p['checkout_url']??'';
 if(!validOrderId($id)||($p['external_reference']??'')!==$r||($p['currency']??$p['currency_id']??'')!=='MXN'||(string)($p['user_id']??'')!==(string)$cfg['merchant_id']||(int)round((float)($p['total_amount']??0)*100)!==$q['total']*100||!is_string($target)||!preg_match('~^https://www\.mercadopago\.com\.mx/checkout/~D',$target))fail('Respuesta de Mercado Pago no válida.',502);
 if($test&&!str_starts_with($id,'ORDTST'))fail('La orden no corresponde a pruebas. No continuar.',409);
 $o['mp_order_id']=$id;saveOrder($o);send(['url'=>$target,'reference'=>$r,'test'=>$test]);}
 if($action==='status'){$r=textField($d,'reference',32);$o=readOrder($r);if(!empty($o['mp_order_id']))send(refreshOrder($cfg,$o['mp_order_id'],$r));send(['status'=>$o['status'],'reference'=>$r,'environment'=>$o['environment']??'']);}
 fail('Acción desconocida.',404);
}catch(Throwable $e){error_log('LAV MKT commerce error: '.get_class($e));fail('No se pudo procesar el pedido.',500);}
