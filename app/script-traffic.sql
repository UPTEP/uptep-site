-- Personal incoming/outgoing traffic; no changes to declarations or resource balances.
CREATE INDEX IF NOT EXISTS floods_sender_arrival ON floods(sender_id,arrival_at,id);
CREATE INDEX IF NOT EXISTS convois_sender_arrival ON convois_effectues(idenvoyeur,date_arrivee,id) WHERE NOT annule AND date_arrivee IS NOT NULL;
CREATE INDEX IF NOT EXISTS convois_demand_arrival ON convois_effectues(iddemande,date_arrivee,id) WHERE NOT annule AND date_arrivee IS NOT NULL;
CREATE INDEX IF NOT EXISTS demandes_requester ON demandes_convois(serveur,iddemandeur,id);
