import json
from pathlib import Path
ref=lambda name:{'$ref':'#/components/schemas/'+name}
obj=lambda props,required=None:{'type':'object','properties':props,'required':required or list(props)}
identifier={'type':'string','pattern':'^[1-9][0-9]{0,18}$','maxLength':19,'description':'Entier exact transmis comme chaîne JSON, maximum 9223372036854775807.','example':'123456789012345678'}
quantity={'type':'string','pattern':'^(0|[1-9][0-9]{0,18})$','maxLength':19,'description':'Quantité entière positive ou nulle, maximum 9223372036854775807.','example':'10000'}
nullable=lambda s:dict(s,nullable=True)
stamp={'type':'string','description':'Date PostgreSQL avec fuseau horaire (UTC).','example':'2026-10-10 18:00:00+00'}
schemas={'ID':identifier,'Quantite':quantity,'Erreur':obj({'ok':{'type':'boolean','enum':[False]},'error':{'type':'string'}})}
demand={'id':ref('ID'),'serveur':{'type':'string','enum':['s5']},'iddemandeur':ref('ID'),'pseudo_demandeur':{'type':'string'},'discord_demandeur':ref('ID')}
for name in ['nbpomme_original','nbpomme_restant','nbmateriaux_original','nbmateriaux_restant']:demand[name]=ref('Quantite')
demand.update({'valide':{'type':'boolean'},'idvalidateur':nullable(identifier),'pseudo_validateur':{'type':'string','nullable':True},'discord_validateur':nullable(identifier),'date_demande':stamp,'date_validation':nullable(stamp),'fini':{'type':'boolean'},'date_fin':nullable(stamp),'etat':{'type':'string','enum':['attente','en_cours','terminee']}})
schemas['Demande']=obj(demand)
convoy={'id':ref('ID'),'iddemande':ref('ID'),'idenvoyeur':ref('ID'),'pseudo_envoyeur':{'type':'string'},'discord_envoyeur':ref('ID'),'nbpomme':ref('Quantite'),'nbmateriaux':ref('Quantite'),'date_convoi':stamp,'date_enregistrement':stamp}
schemas['Convoi']=obj(convoy,[k for k in convoy if k!='date_enregistrement'])
command={'type':'string','pattern':'^[A-Za-z0-9_.:-]{1,100}$','maxLength':100,'description':'Identifiant unique de l’interaction Discord. Réutiliser le même identifiant et le même contenu lors des retries.','example':'EXEMPLE-remplacer-par-interaction-id'}
actor={'commande_id':command,'discord_id':dict(identifier,description='ID Discord de l’auteur réel de la commande. Le bot doit le lire depuis l’interaction Discord, jamais depuis un champ libre.')}
schemas['Validation']=dict(obj(actor),additionalProperties=False)
schemas['CreationDemande']=dict(obj(actor|{'nbpomme':ref('Quantite'),'nbmateriaux':ref('Quantite')}),additionalProperties=False)
schemas['DeclarationConvoi']=dict(obj(actor|{'nbpomme':ref('Quantite'),'nbmateriaux':ref('Quantite'),'date_convoi':{'type':'string','format':'date-time','pattern':r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$','description':'Facultatif, heure serveur par défaut. ISO 8601 avec secondes et fuseau ; pas de date future au-delà de 60 secondes de tolérance.','example':'2026-10-10T20:00:00+02:00'}},['commande_id','discord_id','nbpomme','nbmateriaux']),additionalProperties=False)
ok={'type':'boolean','enum':[True]}
cursor=nullable(identifier)
responses={'demande':obj({'ok':ok,'demande':ref('Demande')}),'convoi':obj({'ok':ok,'demande':ref('Demande'),'convoi':ref('Convoi')}),'demandes':obj({'ok':ok,'demandes':{'type':'array','items':ref('Demande')},'suite_apres':cursor}),'convois':obj({'ok':ok,'iddemande':ref('ID'),'convois':{'type':'array','items':ref('Convoi')},'suite_apres':cursor}),'health':obj({'ok':ok,'service':{'type':'string','example':'uptep-bot-api'},'version':{'type':'string','example':'1'},'status':{'type':'string','example':'healthy'},'time':{'type':'string','format':'date-time'}})}
errors={400:'JSON invalide.',401:'Secret absent, invalide, désactivé ou expiré.',403:'Permission du secret insuffisante, association Discord invalide ou droit utilisateur absent.',404:'Route ou demande introuvable.',405:'Méthode HTTP refusée.',409:'Conflit : commande réutilisée avec un autre contenu, validation déjà faite, demande non validée/terminée ou dépassement du restant.',415:'Content-Type application/json requis.',422:'Valeurs, champs ou paramètres invalides.',429:'Limite de requêtes dépassée (réponse Nginx HTML).',503:'Service momentanément indisponible.'}
def operation(tag,summary,scope,description,response,method='get',body=None,parameters=None,example=None):
 status='201' if response in ['demande','convoi'] and body!='Validation' else '200'
 result={'tags':[tag],'summary':summary,'description':'**Permission du secret : `'+scope+'`.**\n\n'+description,'responses':{status:{'description':'Succès.','content':{'application/json':{'schema':responses[response]}}}}}
 codes=[401,403,405,429,503]+([400,404,409,415,422] if method=='post' else ([404,422] if response!='health' else []))
 for code in codes:result['responses'][str(code)]={'description':errors[code],'content':{'text/html':{'schema':{'type':'string'}}} if code==429 else {'application/json':{'schema':ref('Erreur')}}}
 if method=='post':result['responses'][status]['headers']={'Idempotency-Replayed':{'description':'true si la réponse initiale est rejouée, false pour une nouvelle écriture.','schema':{'type':'string','enum':['true','false']}}}
 if parameters:result['parameters']=parameters
 if body:
  media={'schema':ref(body)}
  if example:media['example']=example
  result['requestBody']={'required':True,'content':{'application/json':media}}
 return result
idparam={'name':'id','in':'path','required':True,'schema':dict(identifier,example='1'),'description':'ID de la demande.'}
pagination=[{'name':'limite','in':'query','schema':{'type':'integer','minimum':1,'maximum':100,'default':100},'description':'Nombre maximal de résultats par page.'},{'name':'apres','in':'query','schema':{'type':'string','pattern':'^(0|[1-9][0-9]{0,18})$','default':'0'},'description':'Curseur suite_apres de la réponse précédente. Continuer jusqu’à null.'}]
paths={
'/health':{'get':operation('Disponibilité','Vérifier que l’API répond','health:read','Contrôle de disponibilité de l’API ; ne teste pas PostgreSQL.','health')},
'/demandes':{
 'get':operation('Demandes','Lister les demandes ouvertes','demandes:read','Demandes non terminées, ordonnées par ID croissant. Suivre `suite_apres` pour récupérer toutes les pages.','demandes',parameters=[{'name':'etat','in':'query','schema':{'type':'string','enum':['attente','en_cours','les_deux'],'default':'les_deux'},'description':'attente : non validées ; en_cours : validées non terminées ; les_deux : les deux catégories.'}]+pagination),
 'post':operation('Demandes','Créer une demande de ressources','demandes:create','Le demandeur doit avoir une association Discord unique dans la chaîne et être actuellement membre UPTEP sur s5. Les quantités restantes sont initialisées aux quantités originales. Au moins une ressource doit être supérieure à zéro. Pseudo et dates sont déterminés par le serveur.','demande','post','CreationDemande')},
'/demandes/{id}/validation':{'post':operation('Demandes','Valider une demande','demandes:validate','Le validateur doit avoir une association Discord unique et être membre UPTEP. Il doit soit figurer dans ADMIN_DISCORD_IDS du fichier privé .env du bot, soit posséder un unique compte du site lié à son joueur, actif, avec le droit **chaine** et son mot de passe initial changé. Une validation déjà effectuée est refusée, sauf retry identique de la même commande.','demande','post','Validation',[idparam])},
'/demandes/{id}/convois':{
 'get':operation('Convois','Lister les convois d’une demande','convois:read','Fonctionne aussi pour les demandes terminées. Ordre d’enregistrement (ID croissant). Une demande existante sans convoi renvoie une liste vide ; une demande absente renvoie 404. Suivre `suite_apres` pour obtenir tous les convois.','convois',parameters=[idparam]+pagination),
 'post':operation('Convois','Enregistrer un convoi effectué','convois:create','L’expéditeur doit être un membre UPTEP associé à son Discord, différent du demandeur. La demande doit être validée et non terminée. Chaque quantité doit être inférieure ou égale au restant correspondant. L’enregistrement et la soustraction des restants sont atomiques. Les deux restants à zéro clôturent automatiquement la demande. Ce signalement ne déclenche aucun envoi dans le jeu et ne prouve pas l’exécution du convoi.','convoi','post','DeclarationConvoi',[idparam],{'commande_id':'EXEMPLE-remplacer-par-interaction-id','discord_id':'123456789012345678','nbpomme':'3000','nbmateriaux':'5000'})}}
# Extensions utilisées par les boutons du bot.
metadata={'objet':{'type':'string','minLength':1,'maxLength':160},'niveau':{'type':'string','pattern':'^[1-9][0-9]{0,2}$'},'date_souhaitee':{'type':'string','format':'date'}}
schemas['CreationDemande']['properties'].update(metadata)
schemas['Demande']['properties'].update({k:nullable(v) for k,v in metadata.items()})
schemas['Demande']['properties']['niveau']={'type':'integer','nullable':True}
schemas['Demande']['properties'].update({'annulee':{'type':'boolean'},'date_annulation':nullable(stamp),'idannulateur':nullable(identifier),'pseudo_annulateur':{'type':'string','nullable':True},'discord_annulateur':nullable(identifier),'convois':{'type':'array','items':ref('Convoi')}})
schemas['Demande']['properties']['etat']['enum'].append('annulee')
schemas['Convoi']['properties']['date_arrivee']=nullable(stamp)
schemas['DeclarationConvoi']['properties']['date_arrivee']={'type':'string','format':'date-time','description':'Facultative, ISO 8601 avec secondes et fuseau. Une date future est autorisée.'}
paths['/demandes']['get']['parameters'] += [{'name':'include','in':'query','schema':{'type':'string','enum':['convois']},'description':'Inclure tous les convois de chaque demande.'},{'name':'terminees_depuis','in':'query','schema':{'type':'string','format':'date-time'},'description':'Inclure aussi les demandes terminées depuis cette date. Les demandes annulées sont exclues.'}]
paths['/demandes']['post']['description']+=' Les champs objet et niveau sont facultatifs mais doivent être renseignés ensemble. La date souhaitée est facultative et ne peut pas être passée (Europe/Paris).'
paths['/demandes/{id}/annulation']={'post':operation('Demandes','Annuler une demande','demandes:cancel','Le demandeur peut annuler sa propre demande ; un responsable chaîne peut annuler toute demande non terminée. Les convois déjà enregistrés restent conservés. État annulee.','demande','post','Validation',[idparam])}
responses['membre']=obj({'ok':ok,'membre':obj({'discord_id':ref('ID'),'player_id':ref('ID'),'pseudo':{'type':'string'},'is_chaine':{'type':'boolean'}})})
paths['/membres/{discord_id}']={'get':operation('Membres','Lire l’association et les droits','membres:read','Association définie dans chaine_members.iddiscord. Le joueur doit toujours être membre UPTEP. Aucun nom Discord ne remplace le pseudo de jeu.','membre',parameters=[{'name':'discord_id','in':'path','required':True,'schema':identifier}])}
schemas['Demande']['properties'].update({'fin_manuelle':{'type':'boolean'},'discord_cloture':nullable(identifier),'pseudo_cloture':{'type':'string','nullable':True}})
schemas['Convoi']['properties'].update({'annule':{'type':'boolean'},'date_annulation':nullable(stamp),'discord_annulateur':nullable(identifier),'pseudo_annulateur':{'type':'string','nullable':True}})
paths['/demandes/{id}/terminaison']={'post':operation('Demandes','Terminer manuellement une demande','demandes:finish','Le demandeur ou un responsable chaîne peut terminer une demande validée en cours. Le restant est conservé, aucun convoi fictif n’est créé. La fermeture manuelle est tracée et reste effective même après annulation d’un convoi.','demande','post','Validation',[idparam])}
responses['convoi_annulation']=obj({'ok':ok,'demande':ref('Demande'),'convoi':obj({'id':ref('ID'),'annule':{'type':'boolean','enum':[True]}})})
responses['convois_annulables']=obj({'ok':ok,'convois':{'type':'array','items':obj({'id':ref('ID'),'iddemande':ref('ID'),'idenvoyeur':ref('ID'),'pseudo_envoyeur':{'type':'string'},'pseudo_demandeur':{'type':'string'},'nbpomme':ref('Quantite'),'nbmateriaux':ref('Quantite'),'date_convoi':stamp,'date_arrivee':nullable(stamp)})},'suite_apres':cursor})
paths['/convois']={'get':operation('Convois','Lister les convois annulables par un membre','convois:read','ID Discord réel de l’auteur requis. Retourne ses propres convois non annulés, ou tous les convois pour un responsable chaîne. Inclut les demandes anciennes et fermées.','convois_annulables',parameters=[{'name':'discord_id','in':'query','required':True,'schema':identifier}]+pagination)}
paths['/convois/{id}/annulation']={'post':operation('Convois','Annuler un convoi déclaré','convois:cancel','L’expéditeur ou un responsable chaîne peut annuler la déclaration. Les ressources sont réintégrées au restant une seule fois. Une demande soldée automatiquement est rouverte ; une demande annulée ou terminée manuellement reste fermée. L’historique et l’auteur de l’annulation sont conservés. Aucun effet sur le jeu.','convoi_annulation','post','Validation',[dict(idparam,description='ID du convoi déclaré.')])}
for path,methods in paths.items():
 for method,op in methods.items():op['operationId']=method+'_'+path.replace('/','_').replace('{id}','id')
# Read-only reports for the private bot admin allowlist (not the broader chain role).
report_actor={'name':'discord_id','in':'query','required':True,'schema':identifier,'description':'Auteur réel de l’interaction Discord, associé à un joueur UPTEP et listé dans ADMIN_DISCORD_IDS.'}
report_page={'name':'page','in':'query','schema':{'type':'integer','minimum':1,'maximum':10000,'default':1},'description':'Page de 8 résultats.'}
report_period=[{'name':'periode','in':'query','schema':{'type':'string','enum':['7j','30j','personnalisee'],'default':'7j'}},*({'name':k,'in':'query','schema':{'type':'string','pattern':r'^\d{2}-\d{2}-\d{4}$'},'description':'JJ-MM-AAAA. Début et fin ensemble, inclusifs, maximum 366 jours, pas de date future.'} for k in ['debut','fin'])]
report_stats={'joueur_id':identifier,'pseudo':{'type':'string'},'envois':{'type':'string'},'receptions':{'type':'string'}}
for col in ['pommes_envoyees','materiaux_envoyes','pommes_recues','materiaux_recus']:
 report_stats[col]={'type':'string','pattern':'^[0-9]+$','description':'Total exact, peut dépasser bigint.'}
 report_stats['moyenne_'+col]={'type':'string','pattern':r'^[0-9]+\.[0-9]{2}$','description':'Total / nombre de jours calendaires, jours inactifs inclus.'}
schemas['StatistiquesConvois']=obj(report_stats)
for kind,item,filters in [('demandes',ref('Demande'),['joueur','etat']),('convois',ref('Convoi'),['expediteur','destinataire','demande']),('statistiques',ref('StatistiquesConvois'),['joueur'])]:
 key='rapport_'+kind
 responses[key]=obj({'ok':ok,'periode':obj({'debut':{'type':'string'},'fin':{'type':'string'},'jours':{'type':'integer'},'fuseau':{'type':'string'},'depuis':{'type':'string','format':'date-time'},'avant':{'type':'string','format':'date-time'}}),'lignes':{'type':'array','items':item},'page':{'type':'integer'},'pages':{'type':'integer'},'total':{'type':'integer'}})
 params=[report_actor,report_page]+report_period+[{'name':f,'in':'query','schema':({'type':'string','enum':['toutes','attente','en_cours','terminee','annulee']} if f=='etat' else identifier if f=='demande' else {'type':'string','maxLength':100}),'description':'Pseudo exact insensible à la casse.' if f in ['joueur','expediteur','destinataire'] else 'Filtre facultatif.'} for f in filters]
 paths['/rapports/'+kind]={'get':operation('Rapports','Lire le rapport '+kind,'rapports:read','Lecture réservée aux IDs de ADMIN_DISCORD_IDS associés à un membre actuel UPTEP. Le droit chaîne seul ne suffit pas. Dates civiles Europe/Paris, journée en cours incluse par défaut. Demandes filtrées par création ; convois et statistiques par date_convoi. Statistiques : envois déclarés, pas livraisons confirmées ; convois annulés exclus. Historique : annulations conservées. Moyennes sur tous les jours inclusifs. Joueurs sans activité absents du classement.',key,parameters=params)}
responses['rapport_detail']=obj({'ok':ok,'demande':ref('Demande'),'lignes':{'type':'array','items':ref('Convoi')},'page':{'type':'integer'},'pages':{'type':'integer'},'total':{'type':'integer'}})
paths['/rapports/demandes/{id}']={'get':operation('Rapports','Détail et convois d’une demande, même clôturée ou annulée','rapports:read','Mêmes droits que les rapports. Convois associés de toutes dates, 8 par page. date_fin correspond à la clôture des déclarations, pas à une livraison confirmée dans le jeu. fin_manuelle distingue la clôture manuelle.', 'rapport_detail',parameters=[idparam,report_actor,report_page])}

# Adjust remaining need without changing original quantities or recording fictitious convoys.
schemas['AjustementRestant']=dict(obj(actor|{key:ref('Quantite') for key in ['nbpomme_restant','nbmateriaux_restant','nbpomme_attendu','nbmateriaux_attendu']}),additionalProperties=False)
schemas['AjustementHistorique']=obj({'id':identifier,'discord_acteur':identifier,'pseudo_acteur':{'type':'string'},'pommes_avant':ref('Quantite'),'pommes_apres':ref('Quantite'),'materiaux_avant':ref('Quantite'),'materiaux_apres':ref('Quantite'),'date_ajustement':stamp})
schemas['Demande']['properties']['ajustements']={'type':'array','maxItems':10,'items':ref('AjustementHistorique'),'description':'Les dix derniers ajustements du restant, du plus récent au plus ancien. Les anciens restent en base.'}
adjust_operation=operation('Demandes','Ajuster les quantités restantes','demandes:adjust','Demande validée et en cours uniquement. Demandeur ou responsable effectif (droit chaîne ou ADMIN_DISCORD_IDS, avec association actuelle). Envoyer les NOUVEAUX restants et les valeurs attendues lues avant saisie. Transaction et verrou : si le restant a changé entre-temps, 409 ; rouvrir le formulaire, ne pas rejouer avec des valeurs périmées. Entre zéro et quantité originale moins les convois non annulés. Les originaux et les statistiques de convois restent inchangés. Zéro pour les deux ressources clôture manuellement. Historique séparé dans demandes_ajustements. Idempotence par commande_id.', 'demande','post','AjustementRestant',parameters=[idparam])
adjust_operation['responses']['200']=adjust_operation['responses'].pop('201')
paths['/demandes/{id}/ajustement']={'post':adjust_operation}

spec={'openapi':'3.0.3','info':{'title':'UPTEP · API du bot Discord','version':'1.0.0','description':'''API privée pour le bot Discord de l’alliance UPTEP sur le serveur 5.

### Authentification
Toutes les opérations nécessitent `Authorization: Bearer VOTRE_SECRET`. Chaque endpoint vérifie sa permission. Aucun secret n’est fourni par cette documentation. Le bot est un client de confiance : il doit transmettre l’ID Discord réel de l’auteur de l’interaction. Les associations, pseudos et droits sont résolus par le serveur. Les administrateurs déclarés dans ADMIN_DISCORD_IDS ont les mêmes droits de gestion que les responsables chaîne ; une association unique dans chaine_members et l’appartenance actuelle à UPTEP restent obligatoires. La liste est relue à chaque requête.

### Parcours
1. Créer une demande → `attente`.
2. Un responsable chaîne la valide → `en_cours`.
3. Enregistrer les convois partiels.
4. Les deux restants atteignent zéro → `terminee`.

### Fiabilité
`commande_id` est unique pour toutes les écritures. Un retry identique renvoie la réponse initiale, qui peut refléter un état plus ancien ; ne pas changer l’ID entre tentatives. Un même ID avec un autre contenu renvoie 409. Les droits sont revérifiés, même pour un retry. Les ID et quantités sont des chaînes JSON pour conserver leur précision (maximum 9223372036854775807).

### Pagination et limites
Les listes sont paginées (100 éléments maximum). Réutiliser `suite_apres` dans le paramètre `apres` jusqu’à obtenir null. Limite actuelle : 30 requêtes par minute et par IP, rafale de 10. Une réponse 429 peut être en HTML.

### Données réelles
Ces endpoints écrivent en base et sont indépendants du simulateur de ressources. Les valeurs d’exemple sont fictives.'''
},'servers':[{'url':'https://uptep.top/api/bot/v1','description':'API de production UPTEP'}],'tags':[{'name':'Disponibilité'},{'name':'Demandes'},{'name':'Convois'}],'security':[{'BotBearer':[]}],'paths':paths,'components':{'securitySchemes':{'BotBearer':{'type':'http','scheme':'bearer','description':'Secret dédié au bot. Saisir uniquement le token, sans le préfixe Bearer.'}},'schemas':schemas}}
Path(__file__).with_name('openapi.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2)+'\n')
