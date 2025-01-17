=== WP-LDAP ===
Contributors: meitar
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=TJLPJYXHSRBEE&lc=US&item_name=WP-LDAP&item_number=WP-LDAP&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: multisite, ldap, users, administration
Requires at least: 4.6
Tested up to: 4.7.5
Stable tag: 0.1.1
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Translates the WordPress user database to an LDAP store of the same; manage your LDAP DIT using your WordPress Dashboard.

== Description ==

This plugin turns your WordPress Dashboard into a familiar management interface for an enterprise-scale LDAP Directory Information Tree (DIT). Configure a connection to your LDAPv3 directory server, and from then on any modifications you make to your WordPress user database through the WordPress admin screens will be reflected in your LDAP database. This offers a simpler and more convenient front-end for managing user account information to support single sign-on (SSO), identity management, and other enterprise functions.

*Donations for this plugin make up a chunk of my income. If you continue to enjoy this plugin, please consider [making a donation](https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=TJLPJYXHSRBEE&lc=US&item_name=WP-LDAP&item_number=WP-LDAP&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted). :) Thank you for your support!*

All user accounts on the WordPress side are mirrored as [`inetOrgPerson` (RFC 2798)](https://www.ietf.org/rfc/rfc2798.txt) entries on the LDAP side. The following WordPress user account fields to LDAP attribute translations take place when a new WordPress user is created:

* The WordPress `user_login` field becomes the `uid` attribute in the LDAP database.
* The WordPress `user_email` field becomes the `mail` attribute in the LDAP database.
* The WordPress `display_name` field becomes the `displayName` attribute in the LDAP database.
* The WordPress `user_pass` field becomes the `userPassword` attribute in the LDAP database.

There is no mapping for the WordPress user ID number on the LDAP side. Instead, users are uniquely identified by their fully-qualified Distinguished Name (DN). A user's DN is automatically composed by combining their WordPress `user_login` with the WordPress Multisite's configured LDAP Search Base setting. For instance, by default, a WordPress Multisite with WP-LDAP installed running at `https://example.com/` with a user whose username is `exampleuser` will automatically be mirrored over LDAP to the user identified as `uid=exampleuser,dc=example,dc=com`.

In addition to the above mappings, the following optional mappings also take place if or when the user updates their user profile:

* The WordPress `first_name` field becomes the `givenName` attribute in the LDAP database.
* The WordPress `last_name` field becomes the `sn` attribute in the LDAP database.
* The WordPress `nickname` field becomes the `cn` attribute in the LDAP database.
* The WordPress `description` field becomes the `description` attribute in the LDAP database.
* The WordPress `user_url` field becomes the `labeledURI` attribute in the LDAP database.
* The WordPress user's avatar becomes the `jpegPhoto` attribute in the LDAP database. (Not yet implemented.)

Moreover, WP-LDAP is aware of certain features provided by other plugins. These include:

* The WordPress user's S/MIME certificate (`smime_certificate` field) becomes the `userSMIMECertificate` attribute in the LDAP database. (This functionality is provided by the [WP PGP Encrypted Emails](https://wordpress.org/plugins/wp-pgp-encrypted-emails/) plugin and that plugin must be installed and activated for this to work.)

This plugin is designed for medium to large deployments of WordPress Multisite (or Multi-Network) instances, originally developed as a collaboration with the [Glocal Coop's Activist Network Platform](https://glocal.coop/activist-network-platform/) project. If you run multiple WordPress Multisite Networks, you can configure each WP Network with different LDAP settings. This plugin does not currently support single-site installs; please [post an issue on GitHub](https://github.com/meitar/wp-ldap/issues) if you want to use LDAP data stores with a WP single-site install and we can discuss use cases.

== Installation ==

For most systems, [WordPress's automatic plugin installation](https://codex.wordpress.org/Managing_Plugins#Automatic_Plugin_Installation) will correctly install WP-LDAP. However, you can also install the plugin manually by following these steps:

1. Upload and unzip the `wp-ldap.zip` archive to your WordPress install's `/wp-content/plugins/` directory.
1. *Network-activate* the plugin through the [Network Admin &rarr; Plugins](https://codex.wordpress.org/Multisite#Step_5:_Network_Admin_Settings) menu in WordPress. Again, the plugin must be network-activated.
1. Configure your LDAPv3 server address and authentication parameters in the Network Settings admin screen:
    * Enter the LDAP URI referring to where your server is listening for connections. (Only `ldaps:///` is usable for remote connections; an unsecured `ldap:///` connection URI will only be accepted if the server IP address is `127.0.0.1`. Please read the remarks at [OpenLDAP Administrator Guide § Security Considerations](https://www.openldap.org/doc/admin24/security.html) for more information.)
    * Enter the binding DN and its password.
    * Optionally, enter a custom search base, or accept the default. (The default for a WordPress Multisite at `example.com` is `dc=example,dc=com`.)

= Requirements =

* PHP 5.3 or later is required, as WP-LDAP makes use of [PHP Namespaces](https://php.net/manual/en/language.namespaces.php).
* The [PHP LDAP](https://php.net/manual/en/book.ldap.php) extension must be installed. On a Debian system, this is usually as simple as running `sudo apt install php-ldap`. The plugin will automatically deactivate itself if this requirement is not met.
* Your WordPress installation must be configured as a [Multisite](https://codex.wordpress.org/Multisite) instance. (WordPress single-site installs are not currently supported.)
* You must be able to bind to an LDAPv3 directory server. (LDAPv2 is not supported.)
* To configure the LDAP connection over the Web interface, you must be able to serve your website over HTTPS. (Unsecured HTTP Web-based configuration is not supported.)

= Configuration =

If your web server does not serve pages over HTTPS, you will need to use the [WP-CLI](https://wp-cli.org/) or the `mysql` command-line client to configure the plugin as follows:

`
mysql> SELECT meta_key,meta_value FROM wp_sitemeta WHERE site_id = 1 AND meta_key LIKE 'wp_ldap_%';
+------------------------+----------------------------+
| meta_key               | meta_value                 |
+------------------------+----------------------------+
| wp_ldap_bind_dn        | cn=admin,dc=example,dc=com |
| wp_ldap_bind_password  | password                   |
| wp_ldap_connect_uri    | ldaps://ldap.example.com/  |
| wp_ldap_search_base_dn | dc=example,dc=com          |
+------------------------+----------------------------+
`

Of course, you should replace the specific details shown above with values appropriate for your deployment. You should also consider configuring your LDAP server such that the bound DN has restrictive [access controls](https://www.openldap.org/doc/admin24/access-control.html) enforced on it, as its password must be stored in the clear within WordPress's database for the plugin to function.

**Security**

The extreme convenience this plugin offers makes it even more important that you take your LDAP DIT's security seriously. Here are some highly recommended additional configuration steps you should take if setting up a directory server for the first time.

OpenLDAP `slapd(8)` will by default listen on both the IPv4 and IPv6 "any" address, exposing your directory contents to the Internet if you have not configured a firewall. Worse, the unsecured `ldap://` protocol is used out-of-the-box, which will further expose the contents of LDAP traffic to eavesdroppers. This is almost certainly not what you want.

This is *bad*, and not what you want to see:

`
$ sudo netstat --listening --numeric --program --tcp | grep slapd
tcp        0      0 0.0.0.0:389             0.0.0.0:*               LISTEN      20244/slapd
tcp6       0      0 :::389                  :::*                    LISTEN      20244/slapd
$ ps -ef | grep slapd | head -n 1
openldap 12932     1  0 03:28 ?        00:00:00 /usr/sbin/slapd -h ldap:/// ldapi:/// -g openldap -u openldap -F /etc/ldap/slapd.d
`

The above shows us that `slapd` is listening for incoming TCP network connections on its default port (`389`). This is happening because `slapd` was invoked with the `-h ldap:///` option.

In contrast, this is *good*, and what you probably want to see instead:

`
$ sudo netstat --listening --numeric --program --tcp | grep slapd
tcp        0      0 127.0.0.1:389           0.0.0.0:*               LISTEN      20282/slapd
tcp        0      0 0.0.0.0:636             0.0.0.0:*               LISTEN      20282/slapd
tcp6       0      0 :::636                  :::*                    LISTEN      20282/slapd
$ ps -ef | grep slapd | head -n 1
openldap 20282     1  0 10:51 ?        00:00:00 /usr/sbin/slapd -h ldap://127.0.0.1:389/ ldaps:/// ldapi:/// -g openldap -u openldap -F /etc/ldap/slapd.d
`

The above shows us that `slapd` is still listening for connections on any configured IP address, but only with the secured `ldaps://` scheme (LDAP over TLS). It is still accepting unsecured connections, but *only* on IP address `127.0.0.1`, the local host. This means cleartext LDAP traffic is not transiting the network; it is contained within the machine itself, only traveling over the loopback interface.

On a typical Debian GNU/Linux system, you invoke `slapd` as `sudo service slapd start` (which runs the `/etc/init.d/slapd` script). This sources the file at `/etc/default/slapd` to set the invocation arguments. Look for the `SLAPD_SERVICES` variable in the `/etc/default/slapd` file and set it to sensible values, as shown above, to make the change persistent across system reboots.

== Changelog ==

= 0.1.1 =

* Feature: Recognize a user's S/MIME certificate and use [WP PGP Encrypted Emails](https://wordpress.org/plugins/wp-pgp-encrypted-emails/)'s S/MIME API to sync it, too.

= 0.1 =
* First prototype.

== Other notes ==

This plugin is free software, but grocery stores do not offer free food. *Donations for this plugin make up a chunk of my income. If you continue to enjoy this plugin, please consider [making a donation](https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=TJLPJYXHSRBEE&lc=US&item_name=WP-LDAP&item_number=WP-LDAP&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted). :) Thank you for your support!*
