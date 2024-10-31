=== ProBoast ===
Requires at least: 5.0
Tested up to: 5.8
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The ProBoast plugin exposes a REST API (POST) endpoint on your WordPress site, and receives web hooks from ProBoast.com.

== Description ==

ProBoast is a service that enables uploading of images to your website via SMS text message. To sign up, visit https://proboast.com.

The goal of ProBoast is to eliminate the friction associated with uploading photos to your website from your mobile phone, improve SEO
and the experience of your users/customers.


== How to install/configure plugin and interaction with ProBoast service ==

1. Create an account at: https://proboast.com
2. Upon creating your user account on https://proboast.com, you are able to add a website via https://proboast.com/add-website. 
3. On your WordPress site, while logged in as an admin, access "Settings" > "ProBoast". Complete the "ProBoast Site UUID" field and
   "ProBoast Authorization Token Salt" fields.
4. On https://proboast.com, you must set the "Web hook URL" on the "Edit Website" user interface.
5. Once the web hook URL is defined on https://proboast.com, select the "Connect" link on the "Edit Website" user interface. Enter the 6
   digit code displayed on the WordPress ProBoast plugin configuration page.
6. Success! You've enabled and properly configured the ProBoast WordPress plugin. You are now ready to post photos!

== Security Notes ==

An API endpoint will be exposed at http(s)://your-site.com/wp-json/proboast/v1/webhooks. To interact with this API and execute any of the
4 commands that it is able to process, the POST parameter "push-authorization-token" must match the ProBoast module configuration value
for "proboast_authorization_token". This value can be changed at anytime if security is believed to have been compromised.

== Changelog ==

= 1.0 =
* Initial release of plugin.
* Plugin options form created.
* REST endpoint for receiving webhooks.
* Handling for 4 webhooks: "connect-website", "create-album", "create-image", "delete-image"