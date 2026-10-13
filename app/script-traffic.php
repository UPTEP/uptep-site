<?php
declare(strict_types=1);

// $actor must come from authorizeScript, never from a client-supplied player ID.
function scriptTraffic(PDO $p,array $actor): array {
    $player=(string)($actor['game_player_id']??'');
    if(!preg_match('/\A[1-9][0-9]{0,18}\z/',$player)||strlen($player)===19&&strcmp($player,'9223372036854775807')>0)throw new DomainException('Joueur UPTEP non associé.',403);
    // A closed resource request can still have convoys travelling to its owner.
    // Only the convoy cancellation and its arrival determine whether it is in flight.
    $q=$p->prepare("SELECT * FROM (
        SELECT 'flood' AS kind,id::text,'outgoing' AS direction,target_pseudo AS pseudo,location,
               NULL::text AS food,NULL::text AS materials,arrival_at
        FROM floods WHERE sender_id=? AND arrival_at>now()
        UNION ALL
        SELECT 'flood',id::text,'incoming',sender_pseudo,location,NULL::text,NULL::text,arrival_at
        FROM floods WHERE target_id=? AND arrival_at>now()
        UNION ALL
        SELECT 'convoy',c.id::text,'outgoing',d.pseudo_demandeur,NULL::text,c.nbpomme::text,c.nbmateriaux::text,c.date_arrivee
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE d.serveur='s5' AND c.idenvoyeur=? AND NOT c.annule AND c.date_arrivee>now()
        UNION ALL
        SELECT 'convoy',c.id::text,'incoming',c.pseudo_envoyeur,NULL::text,c.nbpomme::text,c.nbmateriaux::text,c.date_arrivee
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE d.serveur='s5' AND d.iddemandeur=? AND NOT c.annule AND c.date_arrivee>now()
    ) AS traffic ORDER BY arrival_at,kind,id::bigint,direction");
    $q->execute([$player,$player,$player,$player]);
    $out=['outgoing'=>[],'incoming'=>[]];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $direction=$row['direction'];unset($row['direction']);
        $row['arrival_at']=(new DateTimeImmutable($row['arrival_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $out[$direction][]=$row;
    }
    return $out;
}
