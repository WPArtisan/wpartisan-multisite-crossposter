=== Multisite Crossposter ===
Contributors: OzTheGreat
Donate link: https://ozthegreat.io
Tags: wpmu, posts, crossposting, multisite
Requires at least: 3.0.1
Tested up to: 4.5.2
Stable tag: 4.5.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Crosspost posts between blogs in a WordPress Multisite environment

== Description ==

In a multisite environment allows crossposting of a post from any blog to any other blogs.
The crossposted articles have exactly the same properties as the original except for the permalink
which links back to the original.

The initial idea and some of the functionality is heavily borrowed from
Code For The People's Aggregator plugin https://github.com/cftp/aggregator/. Unfortunately, that hasn't been updated
in years, it's vastly too complicated, the admin side of things is very clunky and it's a bit slow. This plugin uses
some of its methods as a base but has a much more streamlined admin process. We have maintained the same post meta
field names so this plugin should be 100% backwards compatible.

THIS PLUGIN REQUIRES WORDPRESS MULTISITE AND IS USELESS WITHOUT IT

== Installation ==

1. Upload `multisite-crossposter` to the `/wp-content/plugins/` directory
2. Network activate the plugin through the 'Network Plugins' menu in WordPress

== Frequently Asked Questions ==

= Are there any options? =

Nope, not at the moment

= Does it have to be activated on every site? =

Yes. While it will technically crosspost to any site regardless of whether the plugin is active on it not the admin
functionality won't work if the plugin isn't enabled.

== Screenshots ==

1. The Crossposting box

== Changelog ==

= 1.0 =
* Plugin released
