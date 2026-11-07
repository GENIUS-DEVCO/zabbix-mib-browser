# Journal des modifications

## 1.3.0 — 2026-11-07

### Ajouté
- **Export des résultats** Get/Walk en CSV, Excel (.xlsx), JSON, XML et texte snmpwalk (menu « Exporter… »).
- **Import d'un walk** (bouton « Importer un walk ») : sortie `snmpwalk` numérique, symbolique, `-OX` ou `iso.`, index texte ou énumérés, exports du module, fichiers `.gz` ou `.zip`. Parcours hors ligne, création d'éléments et génération d'un modèle limité aux objets du fichier.
- **Modèles en YAML, XML ou JSON** (choix du format au téléchargement).
- **Ajout de MIB en lot** depuis une archive `.zip`, `.tar`, `.tar.gz` ou `.tgz` ; les fichiers sans module MIB sont ignorés.
- **Téléchargement des MIB** : un fichier, les MIB ajoutées ou toutes les MIB (.zip).
- **Export des objets** d'un module ou d'une branche de l'arbre en CSV, Excel ou JSON.
- Options `max_walk_size` et `max_archive_size`.
- Tests : import de walks réels, réimport des exports, archives, Excel, modèles XML (167 tests).

### Modifié
- Un modèle généré depuis un walk importé est nommé d'après l'hôte ou le fichier.

## 1.2.0 — 2026-11-07

### Ajouté
- **Interface multilingue** : le module suit la langue du profil Zabbix de l'utilisateur. Les 25 langues proposées par Zabbix 8.0 sont prises en charge (anglais, allemand, catalan, chinois simplifié, coréen, danois, espagnol, français, géorgien, hébreu, italien, japonais, letton, norvégien, ouzbek, polonais, portugais du Brésil et du Portugal, russe, slovaque, tchèque, turc, ukrainien, vietnamien). Les autres langues utilisent l'anglais.
- Test de complétude des traductions (tous les textes, mêmes paramètres) dans `tests/run.php`.

### Modifié
- Textes sources du module en anglais (`lib/I18n.php`, fichiers `lang/`).

## 1.1.0 — 2026-11-07

### Ajouté
- **Tester la connexion** : lit le groupe *system* de l'équipement (nom, description, constructeur, modèle, uptime, emplacement, contact), avec le temps de réponse et le niveau de chiffrement. En cas d'échec, diagnostic de la cause probable : communauté, utilisateur SNMPv3, phrase d'authentification ou de chiffrement, niveau de sécurité, DNS, réseau/pare-feu, proxy, serveur Zabbix, hôte pas encore chargé par le serveur, macros secrètes.
- **Sécurité SNMP** :
  - l'interface SNMPv3 chiffrée (authPriv) est sélectionnée par défaut ;
  - badge de sécurité de l'interface et avertissement sur les requêtes en clair ;
  - algorithmes faibles signalés (MD5, SHA-1, DES) ;
  - onglet « Sécurité SNMP » : état de toutes les interfaces SNMP ;
  - politique réglable par un Super admin : avertir (par défaut) ou exiger SNMPv3 chiffré ;
  - avertissement si les identifiants sont saisis sur une page non HTTPS.

### Corrigé
- Valeur de délai par défaut envoyée au serveur si le réglage SNMP de Zabbix est vide.
- Les objets marqués `obsolete` dans les MIB ne sont plus ajoutés aux modèles générés.
- Noms techniques de modèles contenant des caractères refusés par Zabbix (parenthèses…).

## 1.0.0 — 2026-11-07

Première version :
- analyse des fichiers MIB (SMIv1/SMIv2), 43 MIB standards fournies, ajout de MIB constructeurs avec détection des dépendances manquantes ;
- arbre MIB avec recherche et détails des objets ;
- Get et Walk via le serveur Zabbix ou le proxy de l'hôte, avec les identifiants de l'interface (SNMPv1, v2c, v3) ;
- création d'un élément en un clic (type, unités, prétraitement, table de correspondance) ;
- génération de modèles Zabbix 8.0 (get[], walk[], découverte LLD et prototypes), téléchargement YAML ou import direct.
