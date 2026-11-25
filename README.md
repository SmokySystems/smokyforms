# SmokyForms – Drag and Drop Form Builder with Calculations

A WordPress form builder where calculations are free. Build quote forms, order forms, booking forms and calculators by clicking fields and operators, without writing formulas.

- **Website:** https://www.smokyforms.com

## Features

- Drag and drop builder with field settings beside the form
- Calculations built by clicking: fields, operators, numbers, conditions (if/then/else), days between dates, price tables, rounding, min/max
- Live results in the form, recalculated on the server when the form is sent
- Values for choices (prices for options), ticked checkboxes added up
- Templates: contact form, quote request, order form, booking with nights, price table, BMI and loan calculators
- Notification and confirmation emails with smart tags
- Built-in SMTP settings (SmokyForms keeps its own), test email
- Entries are emailed, not stored; if an email fails, the entry is kept so it is not lost
- Spam protection: trap field, time check, rate limit, optional Cloudflare Turnstile, reCAPTCHA or hCaptcha
- Block and shortcode `[smokyforms id="123"]`, works without JavaScript
- Light: assets only on pages with a form, no jQuery, calculation script only when needed
- English by default, follows the site language, 63 languages included

## Repository

- `smokyforms.php`, `includes/`, `assets/`, `blocks/`, `languages/` – the plugin
- `includes/Mail.php` – SMTP sending with its own settings (option `smokyforms_mail`)
- `tools/` – development tools (string extraction, translation build), not part of the release

Build the language files: `python3 tools/build-languages.py`

## Translations

Translations are welcome on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/smokyforms/).

## License

GPLv2 or later.
