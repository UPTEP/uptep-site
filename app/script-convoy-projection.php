<?php
declare(strict_types=1);
require_once __DIR__.'/script-convoy-colonies.php';

/** Fold observed capacity changes onto complete 30-minute cycles from the baseline.
 * Unknown terrain never becomes an invented historical value. Arithmetic on
 * resource quantities stays in the userscript's BigInts; PHP counts cycles only.
 */
function scriptConvoyHarvestSegments(string $anchor,string $now,array $terrain): array {
 $start=(float)(new DateTimeImmutable($anchor))->format('U.u');$end=(float)(new DateTimeImmutable($now))->format('U.u');
 $total=max(0,(int)floor(($end-$start)/1800));$events=[];
 foreach($terrain as $row){$stamp=(float)(new DateTimeImmutable($row['observed_at']))->format('U.u');if($stamp<=$end)$events[]=[$stamp,$row['value']];}
 usort($events,static fn($a,$b)=>$a[0]<=>$b[0]);$tdc=null;$cursor=1;$segments=[];$complete=true;$approximate=false;
 $integer=static fn($v)=>is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/',$v)&&strlen($v)<=strlen((string)PHP_INT_MAX)&&(strlen($v)<strlen((string)PHP_INT_MAX)||strcmp($v,(string)PHP_INT_MAX)<=0);
 $valid=static function($v)use($integer):bool{
  if($integer($v))return true;if(!is_array($v)||!$integer($v['own']??null)||!is_array($v['gains']??null))return false;
  $transfers=$v['gains'];if(isset($v['loss']))$transfers[]=$v['loss'];foreach($transfers as $t)if(!is_array($t)||!$integer($t['terrain']??null)||!is_int($t['percent']??null)||$t['percent']<0||$t['percent']>100)return false;return true;
 };
 $append=static function(int $count)use(&$segments,&$complete,&$tdc,&$approximate):void{
  if($count<=0)return;if($tdc===null)$complete=false;if(is_array($tdc)&&($tdc['assumed_rates']??false))$approximate=true;
  $capacity=$tdc;
  $last=count($segments)-1;if($last>=0&&$segments[$last]['capacity_per_cycle']===$capacity)$segments[$last]['cycles']=(string)((int)$segments[$last]['cycles']+$count);
  else $segments[]=['cycles'=>(string)$count,'capacity_per_cycle'=>$capacity];
 };
 foreach($events as [$stamp,$value]){
  // An observation applies to the cycle at that instant, never earlier cycles.
  $next=max(1,min($total+1,(int)ceil(($stamp-$start)/1800)));
  $append(max(0,$next-$cursor));$cursor=max($cursor,$next);
  $tdc=$valid($value)?$value:null;
 }
 $append(max(0,$total+1-$cursor));
 return ['elapsed_cycles'=>(string)$total,'segments'=>$segments,'history_complete'=>$complete,'assumed_rates'=>$approximate];
}
function scriptConvoyProjection(PDO $p,PDO $game,array $d,string $now): array {
 $anchor=$d['date_validation'];$kind='validation';
 if(!empty($d['adjusted_at'])&&new DateTimeImmutable($d['adjusted_at'])>new DateTimeImmutable($anchor)){$anchor=$d['adjusted_at'];$kind='adjustment';}
 $id=(string)$d['iddemandeur'];
 $history=scriptConvoyColonyHistory($p,$game,$id,$anchor,$now);$terrain=$history['timeline'];
 $anchor=(new DateTimeImmutable($anchor))->format('Y-m-d\TH:i:s.uP');
 $fold=scriptConvoyHarvestSegments($anchor,$now,$terrain);
 return ['anchor_at'=>$anchor,'anchor_kind'=>$kind,'calculated_at'=>$now,'last_export_at'=>$history['last_export_at'],'colonies_included'=>true]+$fold;
}
