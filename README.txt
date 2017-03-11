=== WPArtisan Multisite Crossposter ===
Contributors: ozthegreat
Donate link: https://wpartisan.me
Tags: wpmu, posts, crossposting, multisite
Requires at least: 4.4
Tested up to: 4.7.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Sync or crosspost posts, pages or custom post types between blogs or sites in a WordPress Multisite environment

== Description ==

In a multisite environment enables syncing or crossposting of posts, pages, or custom post types from any blog to any other blogs.
The crossposted articles have exactly the same properties as the original except for the permalink
which links back to the original.

The initial idea and some of the functionality is heavily borrowed from
Code For The People's Aggregator plugin https://github.com/cftp/aggregator/. Unfortunately, that hasn't been updated
in years, it's vastly too complicated, the admin side of things is very clunky and it's a bit slow. This plugin uses
some of its methods as a base but has a much more streamlined admin process. We have maintained the same post meta
field names so this plugin should be 100% backwards compatible.

== Installation ==

1. Upload `wpartisan-multisite-crossposter` to the `/wp-content/plugins/` directory
2. Network activate the plugin through the 'Network Plugins' menu in WordPress
3. On the post editor screen there will now be a new metabox where you can select which sites in your network to crosspost that post to

== Frequently Asked Questions ==

= Does it support custom post types? =

Yes, as of version 0.1.0.

= Are there any options? =

Nope, not at the moment

= Does it have to be activated on every site? =

Yes. While it will technically crosspost to any site regardless of whether the plugin is active on it not the admin
functionality won't work if the plugin isn't enabled.

== Screenshots ==

1. The Crossposting box

== Changelog ==

= 0.1.0 =
* Support for Pages and Custom Post Types.
* Re write how posts + meta are saved. Better consistency when using hooks.
* Bug fixes + small speed improvements.
* Move towards WordPress coding standards.

= 0.0.9 =
* Fix missing parameter error

= 0.0.8 =
* Readme corrections

= 0.0.7 =
* WordPress 4.6 updates. wp_get_sites() deprecated
* Switch to using site_transients instead. Cached sites can be cleared by saving any user profile on any site

= 0.0.6 =
* Fix not all sites displaying for super admins

= 0.0.5 =
* Fix incorrect WEEK_IN_SECONDS constant name

= 0.0.4 =
* Change how featured image is handled. Works better with CDN plugins now

= 0.0.3 =
* Fix correct permissions name
* Disable 'check_image_exists' function. Seems flaky.

= 0.0.2 =
* Readme updates

= 0.0.1 =
* Plugin released
