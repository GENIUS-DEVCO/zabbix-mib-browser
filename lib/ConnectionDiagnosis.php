<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Connection test helpers: diagnosis of SNMP errors returned by Zabbix server and vendor names.
** Pure PHP so it can be unit tested. Error patterns come from Zabbix 8.0 server (net-snmp) messages.
*/

namespace Modules\MibBrowser\Lib;

class ConnectionDiagnosis {

	/** OIDs read by the connection test (SNMPv2-MIB system group). */
	public const TEST_OIDS = [
		'descr' => '1.3.6.1.2.1.1.1.0',
		'object_id' => '1.3.6.1.2.1.1.2.0',
		'uptime' => '1.3.6.1.2.1.1.3.0',
		'contact' => '1.3.6.1.2.1.1.4.0',
		'name' => '1.3.6.1.2.1.1.5.0',
		'location' => '1.3.6.1.2.1.1.6.0'
	];

	/** IANA private enterprise numbers of common network/system vendors. */
	private const VENDORS = [
		2 => 'IBM', 9 => 'Cisco', 11 => 'HP', 43 => '3Com', 171 => 'D-Link', 232 => 'HPE (Compaq)',
		311 => 'Microsoft', 318 => 'APC (Schneider Electric)', 534 => 'Eaton', 674 => 'Dell', 1588 => 'Brocade',
		1916 => 'Extreme Networks', 1991 => 'Brocade (Foundry)', 2011 => 'Huawei', 2620 => 'Check Point',
		2636 => 'Juniper Networks', 3375 => 'F5 Networks', 4413 => 'Broadcom', 4526 => 'Netgear', 5951 => 'Citrix',
		6027 => 'Dell (Force10)', 6486 => 'Alcatel-Lucent Enterprise', 6527 => 'Nokia (Alcatel-Lucent)',
		6574 => 'Synology', 6876 => 'VMware', 8072 => 'Net-SNMP', 8741 => 'SonicWall', 9148 => 'Acme Packet',
		11863 => 'TP-Link', 12356 => 'Fortinet', 14179 => 'Cisco (Airespace)', 14823 => 'Aruba (HPE)',
		14988 => 'MikroTik', 21067 => 'Stormshield', 24681 => 'QNAP', 25461 => 'Palo Alto Networks',
		25506 => 'H3C', 30065 => 'Arista Networks', 41112 => 'Ubiquiti', 47196 => 'HPE Aruba', 50536 => 'Stormshield'
	];

	/**
	 * Vendor from sysObjectID (1.3.6.1.4.1.<enterprise>...).
	 *
	 * @return array|null  ['enterprise' => int, 'vendor' => string|null]
	 */
	public static function vendor(string $object_id): ?array {
		if (!preg_match('/^\.?1\.3\.6\.1\.4\.1\.(\d+)/', $object_id, $m)) {
			return null;
		}

		$enterprise = (int) $m[1];

		return ['enterprise' => $enterprise, 'vendor' => self::VENDORS[$enterprise] ?? null];
	}

	/**
	 * Explain an error.
	 *
	 * @param string $error    Error message from Zabbix server or the module ("secret:...", "policy:...").
	 * @param int    $version  SNMP version of the interface (1, 2, 3).
	 * @param bool   $proxy    Whether the host is monitored by a proxy.
	 *
	 * @return array  ['category', 'title', 'causes' => [...], 'actions' => [...]]
	 */
	public static function diagnose(string $error, int $version, bool $proxy = false): array {
		$poller = $proxy ? _('le proxy') : _('le serveur Zabbix');
		$lower = strtolower($error);

		if (strpos($error, 'policy:') === 0) {
			return self::result('policy', _('Requête bloquée par la politique de sécurité du module'), [
				_('Le module exige une interface SNMPv3 chiffrée (authPriv) et cette interface ne l\'est pas.')
			], [
				_('Configurez SNMPv3 authPriv sur l\'équipement et sur l\'interface de l\'hôte.'),
				_('Ou demandez à un Super admin de changer la politique dans l\'onglet « Sécurité SNMP ».')
			]);
		}

		if (strpos($error, 'secret:') === 0) {
			return self::result('secret', _('Identifiants dans des macros secrètes ou non résolues'), [
				_('Le module ne peut pas lire les macros secrètes, ni les macros sans valeur.')
			], [
				_('Saisissez les identifiants dans « Identifiants » pour ce test (ils ne sont pas enregistrés).'),
				_('Vérifiez que les macros utilisées par l\'interface ont une valeur sur l\'hôte, un modèle lié ou en global.')
			]);
		}

		// Errors of the frontend ↔ server connection (CZabbixServer), never produced by SNMP itself.
		if (preg_match('/zabbix server/i', $error)) {
			return self::result('server', _('L\'interface web ne joint pas le serveur Zabbix'), [
				_('Le serveur Zabbix est arrêté, ou l\'interface web ne peut pas l\'atteindre (adresse ou port 10051).')
			], [
				_('Vérifiez le service : systemctl status zabbix-server.'),
				_('Vérifiez $ZBX_SERVER et $ZBX_SERVER_PORT dans zabbix.conf.php.')
			]);
		}

		// The server checks permissions on its configuration cache: a host created or changed a few seconds ago
		// is not known yet.
		if (stripos($error, 'failed to validate host permissions') !== false) {
			return self::result('sync', _('Hôte pas encore connu du serveur Zabbix'), [
				_('L\'hôte ou son interface vient d\'être créé ou modifié : le serveur Zabbix n\'a pas encore rechargé sa configuration (paramètre CacheUpdateFrequency, 10 secondes par défaut).'),
				_('Plus rarement : votre compte n\'a pas les droits nécessaires sur cet hôte.')
			], [
				_('Relancez le test dans quelques secondes.'),
				_('Pour recharger immédiatement : zabbix_server -R config_cache_reload (sur le serveur Zabbix).'),
				_('Si le problème persiste, vérifiez les droits de votre groupe d\'utilisateurs sur le groupe de l\'hôte.')
			]);
		}

		if ($proxy && preg_match('/proxy/i', $error)) {
			return self::result('proxy', _('Le proxy de l\'hôte ne répond pas'), [
				_('Le serveur Zabbix n\'arrive pas à joindre le proxy qui surveille cet hôte, ou le proxy est arrêté.')
			], [
				_('Vérifiez l\'état du proxy dans Administration → Proxys (dernière communication).'),
				_('Vérifiez le service zabbix-proxy et la connectivité serveur ↔ proxy (port 10051).')
			]);
		}

		if (strpos($lower, 'cannot resolve address') !== false) {
			return self::result('dns', _('Nom DNS introuvable'), [
				_s('Le nom de l\'interface ne peut pas être résolu par %1$s.', $poller)
			], [
				_('Vérifiez le nom DNS de l\'interface, ou utilisez son adresse IP.'),
				_s('Testez la résolution depuis %1$s : getent hosts <nom>.', $poller)
			]);
		}

		if (strpos($lower, 'unknown user name') !== false) {
			return self::result('v3_user', _('Utilisateur SNMPv3 inconnu de l\'équipement'), [
				_('Le nom de sécurité (security name) de l\'interface n\'existe pas sur l\'équipement.')
			], [
				_('Vérifiez le nom de sécurité de l\'interface (sensible à la casse).'),
				_('Vérifiez que l\'utilisateur est bien créé sur l\'équipement.')
			]);
		}

		if (strpos($lower, 'unsupported security level') !== false) {
			return self::result('v3_level', _('Niveau de sécurité SNMPv3 non accepté'), [
				_('L\'utilisateur SNMPv3 existe mais n\'est pas configuré pour ce niveau (par exemple authPriv demandé, authNoPriv configuré sur l\'équipement).')
			], [
				_('Alignez le niveau de sécurité de l\'interface sur celui de l\'utilisateur, de préférence authPriv des deux côtés.')
			]);
		}

		if (strpos($lower, 'authentication failure') !== false || strpos($lower, 'wrong digest') !== false) {
			return $version == 3
				? self::result('v3_auth', _('Échec d\'authentification SNMPv3'), [
					_('La phrase d\'authentification est incorrecte.'),
					_('Ou le protocole d\'authentification ne correspond pas (MD5, SHA1, SHA256…).')
				], [
					_('Vérifiez la phrase et le protocole d\'authentification de l\'interface.'),
					_('Si la phrase vient d\'une macro, vérifiez sa valeur sur l\'hôte.')
				])
				: self::result('auth', _('Communauté refusée'), [_('La communauté SNMP est incorrecte.')], [
					_('Vérifiez la communauté de l\'interface (macro {$SNMP_COMMUNITY} par défaut).')
				]);
		}

		if (strpos($lower, 'not in time window') !== false || strpos($lower, 'notintimewindow') !== false) {
			return self::result('v3_time', _('Fenêtre de temps SNMPv3 dépassée'), [
				_('L\'équipement a redémarré ou son compteur de démarrages a changé ; la session SNMPv3 en cache n\'est plus valide.')
			], [
				_('Relancez le test dans quelques secondes.'),
				_('Si le problème persiste, vérifiez que l\'engine ID de l\'équipement est unique sur le réseau.')
			]);
		}

		if (strpos($lower, 'probe successful') !== false && strpos($lower, 'timed out') !== false) {
			return self::result('v3_priv', _('Échec du chiffrement SNMPv3'), [
				_('L\'authentification a réussi mais l\'équipement ne peut pas déchiffrer la requête.'),
				_('La phrase de chiffrement (privacy) est incorrecte, ou le protocole ne correspond pas (DES, AES128, AES256…).')
			], [
				_('Vérifiez la phrase et le protocole de chiffrement de l\'interface.'),
				_('Vérifiez que l\'équipement prend en charge l\'algorithme choisi (AES192/AES256 ne sont pas disponibles partout).')
			]);
		}

		if (preg_match('/no such (object|instance)/i', $error)) {
			return self::result('view', _('L\'équipement répond mais ne donne pas accès à ces objets'), [
				_('La vue SNMP de l\'équipement n\'inclut pas le groupe system (SNMPv2-MIB).')
			], [
				_('Élargissez la vue SNMP de l\'équipement (par exemple .1.3.6.1.2.1).')
			]);
		}

		if (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false) {
			$causes = [
				_s('L\'équipement ne répond pas à %1$s sur le port UDP indiqué.', $poller)
			];
			$actions = [];

			if ($version != 3) {
				array_unshift($causes,
					_('Communauté incorrecte : en SNMPv1/v2c, l\'équipement ignore silencieusement les requêtes avec une mauvaise communauté.')
				);
				$actions[] = _('Vérifiez la communauté de l\'interface et celle configurée sur l\'équipement.');
			}

			$causes[] = _('L\'agent SNMP est arrêté, ou une ACL de l\'équipement n\'autorise pas l\'adresse du serveur ou du proxy.');
			$causes[] = _('Un pare-feu bloque le port UDP 161 entre Zabbix et l\'équipement.');
			$causes[] = _('Adresse IP, port ou version SNMP de l\'interface incorrects.');

			$actions[] = _s('Testez depuis %1$s : snmpget -v2c -c <communauté> <adresse> 1.3.6.1.2.1.1.5.0 (ou les options -v3 correspondantes).', $poller);
			$actions[] = _('Vérifiez les ACL SNMP de l\'équipement et les règles de pare-feu (UDP 161).');

			return self::result('timeout', _('Pas de réponse de l\'équipement'), $causes, $actions);
		}

		return self::result('unknown', _('Erreur SNMP'), [_('Voir le message d\'erreur détaillé.')], [
			_s('Testez depuis %1$s avec snmpget pour obtenir plus de détails.', $poller)
		]);
	}

	private static function result(string $category, string $title, array $causes, array $actions): array {
		return ['category' => $category, 'title' => $title, 'causes' => $causes, 'actions' => $actions];
	}
}
