# Custom Hide Login

Μικρό, αυτόνομο plugin για WordPress που μεταφέρει τη φόρμα σύνδεσης σε ένα ιδιωτικό URL (π.χ. `/ab-admin/`) και απαντά **404** σε όποιον δεν είναι συνδεδεμένος και ζητήσει το `wp-login.php` ή το `wp-admin`.

- **Έκδοση:** 2.0.2
- **Συγγραφέας:** Aboutnet
- **Άδεια:** GPL-2.0-or-later

> Το κρύψιμο της σελίδας σύνδεσης μειώνει τον θόρυβο από bots και scanners. Δεν αντικαθιστά τους ισχυρούς κωδικούς, το 2FA ή το rate limiting.

---

## Τι κάνει

| Αίτημα (χωρίς σύνδεση) | Αποτέλεσμα |
|---|---|
| `/ab-admin/` (το δικό σου slug) | Εμφανίζει τη φόρμα σύνδεσης |
| `/wp-login.php` (σκέτο ή με `action=login`) | 404 |
| `/wp-login.php?action=lostpassword`, `rp`, `resetpass`, `logout`, `register`, `postpass` κ.λπ. | Επιτρέπεται (δείτε παρακάτω) |
| `/wp-admin/...` | 404 |
| `/wp-admin/admin-ajax.php`, `admin-post.php`, `wp-cron.php`, `/wp-json/` | Περνάει κανονικά |
| `/xmlrpc.php` | 404 μόνο αν ενεργοποιηθεί ο αποκλεισμός XML-RPC |

Επιπλέον:

- Όλα τα links σύνδεσης, αποσύνδεσης, εγγραφής και ξεχασμένου κωδικού, καθώς και τα `action` των φορμών, γράφονται αυτόματα ώστε να δείχνουν στο νέο URL.
- Η σελίδα 404 είναι γενική και δεν αναφέρει WordPress, οπότε ο scanner δεν καταλαβαίνει ότι το URL υπάρχει και απλώς κρύβεται.
- Το path κανονικοποιείται πριν ελεγχθεί (percent-encoding, διπλές κάθετοι, `.`/`..`, backslashes, null bytes, `PATH_INFO`), οπότε παραλλαγές όπως `/wp%2Dlogin.php` ή `/wp-login.php/x` μπλοκάρονται κι αυτές.
- Υποστηρίζει εγκαταστάσεις σε υποφάκελο (π.χ. `siteurl = example.com/wp`).
- Το slug αγκυρώνεται στη ρίζα της εγκατάστασης: το `/2024/03/ab-admin/` **δεν** εμφανίζει τη φόρμα.
- Δεν παρεμβαίνει σε WP-CLI, cron ή κατά την εγκατάσταση του WordPress.

---

## Απαιτήσεις

- WordPress με **pretty permalinks**, δηλαδή ο server πρέπει να στέλνει τα άγνωστα paths στο `index.php`. Με «Απλά» permalinks το `/ab-admin/` δεν φτάνει ποτέ στο WordPress.
- PHP 5.6 ή νεότερη. Ο κώδικας αποφεύγει σκόπιμα σύνταξη νεότερων εκδόσεων και είναι ασφαλής για PHP 8.

---

## Εγκατάσταση

### Ως κανονικό plugin

1. Βάλε το αρχείο στο `wp-content/plugins/custom-hide-login/custom-hide-login.php`.
2. Ενεργοποίησέ το από **Πρόσθετα**.
3. Πήγαινε στις **Ρυθμίσεις → Custom Hide Login**, όρισε slug και **σημείωσε το νέο URL σύνδεσης** πριν αποσυνδεθείς.

### Ως must-use plugin (προτείνεται για sites πελατών)

1. Βάλε το αρχείο απευθείας στο `wp-content/mu-plugins/custom-hide-login.php`. Το WordPress δεν φορτώνει αρχεία από υποφακέλους του `mu-plugins`.
2. Φορτώνει αυτόματα και δεν μπορεί να απενεργοποιηθεί από το admin.

Αν το αρχείο βρίσκεται και στα δύο σημεία, φορτώνεται μόνο μία φορά (φραγή μέσω `CUSTOM_LOGIN_LOADED`).

---

## Ρυθμίσεις

Οι ρυθμίσεις ορίζονται από την οθόνη **Ρυθμίσεις → Custom Hide Login** ή με σταθερές στο `wp-config.php`. **Μια σταθερά υπερισχύει της οθόνης** και το αντίστοιχο πεδίο εμφανίζεται κλειδωμένο.

| Σταθερά | Option στη βάση | Προεπιλογή | Περιγραφή |
|---|---|---|---|
| `CUSTOM_LOGIN_SLUG` | `cl_login_slug` | `ab-admin` | Το slug της σελίδας σύνδεσης |
| `CUSTOM_LOGIN_TRUST_PLUGIN_ACTIONS` | `cl_trust_plugin_actions` | `true` | Επιτρέπει αυτόματα login actions άλλων plugins (2FA, SSO, passwordless) |
| `CUSTOM_LOGIN_PLUGIN_ACTIONS` | `cl_plugin_actions` | κενό | Comma-separated λίστα actions που επιτρέπονται πάντα |
| `CUSTOM_LOGIN_BLOCK_XMLRPC` | `cl_block_xmlrpc` | `false` | 404 στο `xmlrpc.php` |
| `CUSTOM_LOGIN_ALLOWED_ACTIONS` | — | βλ. παρακάτω | Τα actions του πυρήνα που μένουν στο `wp-login.php`. Αλλάζει μόνο με σταθερά. |

Παράδειγμα `wp-config.php`:

```php
define( 'CUSTOM_LOGIN_SLUG', 'pyli-eisodou' );
define( 'CUSTOM_LOGIN_BLOCK_XMLRPC', true );
define( 'CUSTOM_LOGIN_PLUGIN_ACTIONS', 'my_2fa_action,my_sso_action' );
```

Οι σταθερές πρέπει να οριστούν **πριν** από τη γραμμή `require_once ABSPATH . 'wp-settings.php';`.

### Κανόνες για το slug

- Επιτρέπονται μόνο πεζά λατινικά, αριθμοί, `-` και `_`.
- Δεσμευμένα slugs (`wp-admin`, `wp-content`, `wp-json`, `feed`, `admin`, `login`, `administrator`, `sitemap.xml` κ.ά.) απορρίπτονται.
- Αν το slug συμπίπτει με υπάρχουσα σελίδα ή άρθρο, η οθόνη ρυθμίσεων προειδοποιεί και ζητά επιβεβαίωση. Αν συνεχίσεις, η φόρμα σύνδεσης «κλέβει» το URL από το περιεχόμενο.

---

## Actions που μένουν στο `wp-login.php`

Τα παρακάτω actions του πυρήνα εξυπηρετούνται κανονικά στο πραγματικό `wp-login.php`:

```
postpass, logout, lostpassword, retrievepassword, rp, resetpass,
register, confirmaction, confirm_admin_email, exit_recovery_mode
```

Επιπλέον επιτρέπεται το `?checkemail=...`, που εμφανίζει μόνο μήνυμα και ποτέ φόρμα.

**Γιατί το `rp`/`resetpass` δεν μετακινείται:** ο πυρήνας δένει το cookie `wp-resetpass-*` στο path του request που το δημιούργησε. Αν η επαναφορά κωδικού μεταφερόταν στο slug, το cookie δεν θα στελνόταν και η επαναφορά θα αποτύγχανε.

### Actions από άλλα plugins (2FA, SSO κ.λπ.)

- Με ενεργό το **«Εμπιστοσύνη σε plugin actions»**, κάθε action για το οποίο κάποιο plugin έχει δηλώσει `login_form_{action}` επιτρέπεται αυτόματα.
- Αν το απενεργοποιήσεις, πρόσθεσε ρητά όσα actions χρειάζεσαι στο πεδίο **«Ρητά επιτρεπόμενα plugin actions»**.

---

## Recovery mode

Το link «Recovery mode» από το email για fatal error δείχνει πάντα στο πραγματικό `wp-login.php?action=enter_recovery_mode`, που το χειρίζεται ο πυρήνας πριν φορτώσουν τα plugins. Αφού επικυρωθεί το cookie, ο χρήστης ανακατευθύνεται στο κρυφό URL. Το slug δεν αποκαλύπτεται σε τρίτους.

---

## Cache

Αν το site χρησιμοποιεί full-page cache (WP Rocket, LiteSpeed Cache, W3 Total Cache, Varnish, Cloudflare APO κ.λπ.), **εξαίρεσε το URL σύνδεσης από το cache**. Το `advanced-cache.php` φορτώνει πριν από το plugin και μπορεί να σερβίρει αποθηκευμένη σελίδα σύνδεσης.

---

## Hooks για developers

### Φίλτρο `cl_not_found_html`

Αλλάζει το HTML της απάντησης 404:

```php
add_filter( 'cl_not_found_html', function ( $html ) {
	return '<!DOCTYPE html><html><head><title>404</title></head><body><h1>404</h1></body></html>';
} );
```

### Φίλτρα του WordPress που χρησιμοποιεί το plugin

`login_url`, `logout_url`, `lostpassword_url`, `register_url`, `site_url` / `network_site_url` (scheme `login_post`), `wp_redirect`, `recovery_mode_begin_url`. Όλα τρέχουν με priority `999`.

---

## Αν κλειδωθείς έξω

1. **Δες το slug στη βάση:**
   ```sql
   SELECT option_value FROM wp_options WHERE option_name = 'cl_login_slug';
   ```
   ή με WP-CLI (το plugin δεν παρεμβαίνει σε WP-CLI):
   ```bash
   wp option get cl_login_slug
   ```
2. **Όρισε slug που ξέρεις** στο `wp-config.php`:
   ```php
   define( 'CUSTOM_LOGIN_SLUG', 'kainourgio-slug' );
   ```
3. **Απενεργοποίησέ το:** μετονόμασε ή διέγραψε το αρχείο μέσω FTP/SFTP ή από τον file manager του hosting.

---

## Απεγκατάσταση

Το plugin δεν σβήνει μόνο του τις ρυθμίσεις του. Για πλήρη καθαρισμό:

```bash
wp option delete cl_login_slug cl_trust_plugin_actions cl_plugin_actions cl_block_xmlrpc
```

Θυμήσου επίσης να αφαιρέσεις τις σταθερές `CUSTOM_LOGIN_*` από το `wp-config.php`, αν τις είχες ορίσει.

---

## Άδεια

GPL-2.0-or-later. Δείτε <https://www.gnu.org/licenses/gpl-2.0.html>.
