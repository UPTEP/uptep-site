<?php
declare(strict_types=1);
require __DIR__.'/../app/web-convoys.php';
foreach(['1,5k'=>'1500','2m'=>'2000000','0'=>'0','1 234'=>'1234','9223372036854775807'=>'9223372036854775807','0.001k'=>'1'] as $raw=>$value)if(convoyQuantity((string)$raw)!==$value)throw new RuntimeException('quantity '.$raw);
foreach(['1.2','9223372036854775808','-1','1e6','abc','0,0001k'] as $raw){try{convoyQuantity($raw);throw new RuntimeException('accepted '.$raw);}catch(DomainException $e){}}
foreach(['2h30m'=>9000,'1j2h3m4s'=>93784,'0s'=>0] as $raw=>$value)if(convoyDuration($raw)!==$value)throw new RuntimeException('duration');
foreach(['','-2h','400j','Infinity','1h2h'] as $raw){try{convoyDuration($raw);throw new RuntimeException('accepted duration');}catch(DomainException $e){}}
echo "OK: exact quantities, resource overflow, duration parsing and bounds.\n";
