# Ενεργές εγκαταστάσεις

Μητρώο των sites όπου τρέχει το Custom Hide Login. Ενημερώνεται με κάθε εγκατάσταση, αναβάθμιση ή αφαίρεση.

---

## Ενεργά

| Site | Env | Έκδοση | Εγκατάσταση | Slug via | XML-RPC | Cache excl. | Ενεργό από | Σημειώσεις |
|---|---|---|---|---|---|---|---|---|
| *https://caneplexdesign.com/* | *prod* | *2.0.0* | *mu-plugin* | *wp-config* | *blocked* | *Litespeed ✔* | *2026-09-17* | ** |
| *https://vougioukasv.gr/* | *prod* | *2.0.2* | *mu-plugin* | *wp-config* | *blocked* | *Litespeed ✔* | *2026-09-30* | ** |

---



## Επεξήγηση στηλών

| Στήλη | Τιμές | Σημασία |
|---|---|---|
| **Env** | `prod` · `staging` · `dev` |
| **Έκδοση** | π.χ. `2.0.0` | Ποια έκδοση τρέχει *αυτή τη στιγμή* στο site. |
| **Εγκατάσταση** | `mu-plugin` · `plugin` | Το `mu-plugin` είναι το προτεινόμενο. |
| **Slug via** | `wp-config` · `DB` | Πού ορίζεται. Το `wp-config` υπερισχύει της βάσης και δεν αλλάζει κατά λάθος από το UI. |
| **XML-RPC** | `off` · `blocked` | `blocked` σημαίνει ότι το `xmlrpc.php` επιστρέφει 404. Έλεγξε πρώτα Jetpack / WordPress app. |
| **Cache excl.** | `—` · `<plugin> ✔` · `⚠ pending` | Αν υπάρχει full-page cache, το URL σύνδεσης **πρέπει** να εξαιρεθεί. Το `⚠ pending` είναι ανοιχτή εκκρεμότητα. |