=== SmokyForms – Drag and Drop Form Builder with Calculations ===
Contributors: smokysystems
Tags: form builder, contact form, calculator, calculated fields, quote form
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Form builder with calculations included for free. Contact forms, quote and order forms, booking forms and calculators, without writing formulas.

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
* **Calculations that use calculations.** Subtotal, discount and total as separate steps. A step can be hidden in the form and still appear in the email.
* **Checked on the server.** The result is calculated again when the form is sent, so a total changed in the browser never reaches your email.
* **Try it in the builder.** Enter sample answers and see the result before you publish.

= Forms =

* Drag and drop builder with field settings beside the form.
* Fields: single line text, paragraph, email, phone, website, number, dropdown, multiple choice, checkboxes, date, hidden field, consent checkbox, calculation, section heading and text block.
* Full width or half width fields.
* Templates: contact form, quote request, order form, booking with nights, price table, BMI calculator, loan calculator. All free.
* Add a form with the SmokyForms block or the shortcode `[smokyforms id="123"]`.
* Works without JavaScript too.

= Email =

* Notification to you for every entry, to one or more addresses, with a table of all answers.
* Confirmation email to the visitor.
* Smart tags: `{all_fields}`, `{form_title}`, `{site_name}`, `{date}`, `{page_url}` and `{field:ID}` for one answer.
* **Built-in SMTP.** Send through the SMTP account of your email provider so emails do not land in spam. No separate SMTP plugin needed. Test email included.
* **No lost entries.** SmokyForms does not store entries; they are emailed to you. If a notification email fails, the entry is kept for a number of days you choose, and you can read it or send it again.

= Spam protection =

* Hidden trap field and a time check on every form.
* Optional Cloudflare Turnstile, Google reCAPTCHA v2 or hCaptcha.
* At most 10 submissions per minute per visitor and form.

= Light and fast =

* The form's CSS and JavaScript load only on pages that show a form.
* No jQuery on the front end.
* The calculation script loads only for forms with a calculation field.
* Layout styles only: the form uses your theme's fonts, colours and field styles.

= Your language =

English by default, the plugin follows your site language. 63 languages are included, and every text can be translated. The Turkish translation is checked by a native speaker; the others were translated with care but not yet reviewed by native speakers. Translators are welcome on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/smokyforms/).

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

SmokyForms sends every entry to you by email and does not store entries in your database. Only when a notification email fails is the entry kept, under SmokyForms > Undelivered, so it is not lost.

= My notification emails do not arrive. =

Set up the SMTP account of your email provider under SmokyForms > Settings > Email sending and send a test email. Entries whose email failed are waiting under SmokyForms > Undelivered, where you can send them again.

= I already use an SMTP plugin. =

Then SmokyForms leaves email sending to it and its own SMTP settings are not used.

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

= 0.1.0 =
* First release: drag and drop form builder, calculations (click to build, conditions, dates, price tables, templates), notification and confirmation emails, built-in SMTP, undelivered entries kept, spam protection, block and shortcode, 63 languages.
