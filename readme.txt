=== Sitemap Files Generator ===

Contributors: vmassuchetto, rodrigosprimo
Donate link: http://vmassuchetto.wordpress.com
Tags: sitemap, google, google news, dump, memory usage
Requires at least: 2.9.2
Tested up to: 3.9
Stable tag: 0.02
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Generate Google and Google News Sitemap files for really large number of posts
being stable in memory usage.

== Description ==

When you have a large number os posts it's impossible to create sitemaps by
using _pseudocron_ or hooks like <code>save_post</code>. This plugin will
require a system cron schedule to visit a secret URL and trigger the sitemap
dump procedure.

It splits the sitemaps into 50,000 URLs each, and also tries to stabilize the
memory usage during the run, doing small queries and using a limited buffer
to write the files.

If you don't have administration privileges over your server, you can use a
[cron job provider](http://www.google.com/search?q=cron+job+service) on the
Internet. If you do, please report your experience for the other users.

== Installation ==

1. Download and activate the plugin.
2. Make sure you web server user can write on the
   <code>wp-content/sitemaps</code> folder.
3. Go to the 'Settings > Sitemap Files Generator' page and test the generation
   using your secret link. You should see the sitemaps in your log file and status
   table.
4. Submit the <code>wp-content/sitemaps/index.xml</code> sitemap in Google
   Webmasters.
5. Schedule a cron job in your server or a [cron job
   provider](http://www.google.com/search?q=cron+job+service).

== Screenshots ==

1. Plugin screen with the sitemap status panel and generation log.

== Changelog ==

= 0.01 =

* First version. That's it.

= 0.02 =

* Bug fix: don't override first value of the public_query_vars array 
