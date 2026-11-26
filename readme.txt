=== SmokyForms – Drag and Drop Form Builder with Calculations ===
Contributors: smokysystems
Tags: form builder, contact form, calculator, calculated fields, quote form
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Form builder with free calculations, conditional logic, multi-page forms, file uploads and signatures. Quote and order forms, bookings, calculators.

== Description ==

SmokyForms is a form builder for WordPress where calculations are free. Most form plugins charge for calculated fields. In SmokyForms every calculation feature is part of the free plugin: live results, conditions, dates, price tables and all calculator templates.

You build a calculation by clicking: pick a field, an operator, another field, and the result updates in the form while your visitor fills it in. If you prefer, you can type the formula instead.

= Calculations =

* **Build by clicking.** Fields, operators, numbers and functions are buttons. The formula is shown with your field names, not codes.
* **Live result.** Visitors see the total change as they fill in the form. Choose decimals, and text before or after the result, such as $ or kg.
* **Values for choices.** Each choice of a dropdown, multiple choice or checkbox field can count as a number, for example its price. Ticked checkboxes are added up.
* **Conditions.** if … then … else, comparisons, and/or. For example: 10% discount over 100.
* **Dates.** Days between two dates, for nights of a stay or days of a rental.
* **Price tables.** A price for each answer, or for each combination of two answers, such as zone and parcel size.
* **Functions.** Round, round up, round down, smallest, largest, square root, power.
* **Repeating groups.** Add up a field over all rows, for example tickets for every person, or count the rows.
* **Calculations that use calculations.** Subtotal, discount and total as separate steps. A step can be hidden in the form and still appear in the email.
* **Checked on the server.** The result is calculated again when the form is sent, so a total changed in the browser never reaches your email.
* **Try it in the builder.** Enter sample answers and see the result before you publish.

= Forms =

* Drag and drop builder with field settings beside the form.
* Fields: single line text, paragraph, email, phone, website, number, dropdown, multiple choice, checkboxes, star rating, date, file upload, signature, repeating group, hidden field, consent checkbox, calculation, section heading, text block and page break.
* **Conditional logic.** Show or hide any field depending on other answers, calculation results included.
* **Multi-page forms.** Split a long form into steps with a progress bar; each step is checked before the next one opens.
* **File uploads.** Choose the file types, size and number of files. Files are linked in the email and can be attached.
* **Signature.** Visitors sign with a mouse, finger or pen; the signature is shown in the email.
* **Repeating groups.** Let visitors add rows, for example one row per guest or product.
* Full width or half width fields.
* Smart tags and HTML in emails and in the message after sending.
* Buttons follow your theme, or pick your own colours.
* Templates: contact form, quote request, order form, booking with nights, price table, BMI calculator, loan calculator, customer feedback, job application and event registration. All free.
* **Export and import forms.** Download one form or several as a .json file and import it on the same site or another site. Fields, logic, calculations, price tables and settings come along.
* Add a form with the SmokyForms block or the shortcode `[smokyforms id="123"]`.
* Works without JavaScript too.

= Email =

* Notification to you for every entry, to one or more addresses, with a table of all answers.
* Confirmation email to the visitor.
* Smart tags: `{all_fields}`, `{form_title}`, `{site_name}`, `{date}`, `{page_url}` and `{field:ID}` for one answer.
* **Built-in SMTP.** Send through the SMTP account of your email provider so emails do not land in spam. No separate SMTP plugin needed. Test email included.
* **No lost entries.** The free version emails every entry to you and does not save it (SmokyForms Pro, coming soon, saves all entries with search and CSV export). If a notification email fails, the entry is kept for a number of days you choose, and you can read it or send it again.

= Uploaded files =

Files and signatures are saved in the folder `wp-content/uploads/smokyforms` with long random folder names, so their links cannot be guessed. Directory listing and scripts are switched off in that folder, and only file types that WordPress allows can be uploaded. You can have them deleted automatically after a number of days under SmokyForms > Settings.

= Spam protection =

* Hidden trap field and a time check on every form.
* Optional math question (no account or outside service) and Cloudflare Turnstile, Google reCAPTCHA v2 or hCaptcha, alone or together.
* At most 10 submissions per minute per visitor and form.

= Light and fast =

* The form's CSS and JavaScript load only on pages that show a form.
* No jQuery on the front end.
* The calculation script loads only for forms with a calculation field.
* Layout styles only: the form uses your theme's fonts, colours and field styles.

= Your language =

English by default, the plugin follows your site language. Every text can be translated, and translations are delivered through translate.wordpress.org. Translators are welcome on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/smokyforms/).

= Support =

Support is given in the WordPress.org forum, in English.

== External services ==

SmokyForms works without any outside service. Only when you switch on a CAPTCHA under SmokyForms > Settings does it use the service you chose:

* **Cloudflare Turnstile.** The visitor's browser loads the widget from challenges.cloudflare.com, and when the form is sent your site sends the visitor's answer token and IP address to Cloudflare to check it. [Terms](https://www.cloudflare.com/website-terms/), [Privacy policy](https://www.cloudflare.com/privacypolicy/).
* **Google reCAPTCHA v2.** The widget is loaded from www.google.com, and the answer token and IP address are sent to Google to check it. [Terms](https://policies.google.com/terms), [Privacy policy](https://policies.google.com/privacy).
* **hCaptcha.** The widget is loaded from js.hcaptcha.com, and the answer token and IP address are sent to hCaptcha to check it. [Terms](https://www.hcaptcha.com/terms), [Privacy policy](https://www.hcaptcha.com/privacy).

When you use the SMTP settings, emails are sent through the email server you enter there.

== Installation ==

1. Install SmokyForms from Plugins > Add New, or upload the zip under Plugins > Add New > Upload Plugin, and activate it.
2. Go to SmokyForms > Add New Form and pick a template or start with an empty form.
3. Save the form and add it to a page with the SmokyForms block or the shortcode shown in the builder.
4. Optional: set up SMTP under SmokyForms > Settings > Email sending, and a CAPTCHA under SmokyForms > Settings.

== Frequently Asked Questions ==

= Are calculations really free? =

Yes. Every calculation feature, including conditions, dates, price tables and all calculator templates, is in the free plugin.

= Do I need to write formulas? =

No. You click fields, operators and functions, and the builder writes the formula. If you like formulas, you can type them too.

= Where are the entries saved? =

The free version of SmokyForms sends every entry to you by email and does not save entries in your database; SmokyForms Pro (coming soon) saves them in your dashboard with search and CSV export. Only when a notification email fails is the entry kept, under SmokyForms > Undelivered, so it is not lost.

= My notification emails do not arrive. =

Set up the SMTP account of your email provider under SmokyForms > Settings > Email sending and send a test email. Entries whose email failed are waiting under SmokyForms > Undelivered, where you can send them again.

= I already use an SMTP plugin. =

Then SmokyForms leaves email sending to it and its own SMTP settings are not used.

= Where are uploaded files saved? =

In `wp-content/uploads/smokyforms`, in folders with random names. The notification email links to them and can attach them. Under SmokyForms > Settings you can have them deleted after a number of days.

= Can a visitor change the total in the browser? =

No. The calculations are done again on the server when the form is sent, and only that result is emailed.

= Does it work with my theme and page builder? =

The form uses your theme's styles. You can add it with the block, or with the shortcode in any page builder that supports shortcodes.

== Screenshots ==

1. Building a calculation by clicking.
2. A quote form with a live price.
3. Templates, calculators included.
4. Price table for prices by zone and parcel size.
5. Email sending (SMTP) settings.

== Changelog ==

= 0.3.0 =
* Export and import forms: Export a single form from the Forms list, or select several and export them in one .json file. Import the file on this or another site under Forms; imported forms are added as new forms and nothing is overwritten.

= 0.2.9 =
* SMTP settings belong to SmokyForms alone: enter them under SmokyForms > Settings > Email sending. Other plugins' email settings are no longer shared. The password constant is now `SMOKYFORMS_SMTP_PASSWORD`.

= 0.2.8 =
* Uploaded files and signatures are saved with WordPress' own upload functions and can only be opened through signed links.
* Button colours are added with WordPress' inline style function.
* Translations are delivered through translate.wordpress.org.

= 0.2.7 =
* New menu icon.
* Add-ons can now decide how long uploaded files are kept.

= 0.2.6 =
* Error messages from the formula parser are escaped, as the plugin directory checks require.

= 0.2.5 =
* New: a simple math question (such as 7 + 4) as spam protection. It needs no account, key or outside service, and works alone or together with a CAPTCHA. Switch it on under SmokyForms > Settings.

= 0.2.4 =
* Updates show up straight away on sites that strip the version number from script and style addresses (before, the browser could keep the old files).
* The form builder no longer says there are unsaved changes just because the settings tab was opened.

= 0.2.3 =
* Smart tags are picked from a tag icon next to each email and message setting.
* The message after sending and the email messages have a Visual / Code editor.

= 0.2.2 =
* More smart tags: unique value (a reference number for each entry), form ID, date in your own format, page ID, address parameters, referring page, page author, logged-in user details and login/logout/registration addresses.
* The unique value, page and visitor details stay the same in every email, in the message after sending and when an undelivered entry is sent again.

= 0.2.1 =
* Smart tags for email recipients, subjects and messages: pick fields and site details from a list.
* HTML in the message after sending and in the email messages.
* Optional form title and description above the form (block option or shortcode `title="yes" description="yes"`).
* Button colours per form; by default buttons follow the theme.
* "Go to this page after sending" suggests the pages of your site.
* The block preview in the editor now looks like the page.

= 0.2.0 =
* Conditional logic: show or hide fields depending on other answers.
* Multi-page forms with a progress bar.
* New fields: star rating, file upload, signature, repeating group and page break.
* Calculations can count rows and add up a field over the rows of a repeating group.
* New templates: customer feedback, job application, event registration.

= 0.1.0 =
* First release: drag and drop form builder, calculations (click to build, conditions, dates, price tables, templates), notification and confirmation emails, built-in SMTP, undelivered entries kept, spam protection, block and shortcode, 63 languages.
