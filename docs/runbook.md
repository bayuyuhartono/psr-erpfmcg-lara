# Runbook

For whoever is standing in front of a client's server. Written to be followed
under pressure, so every step says what to check before moving on.

Anything marked **[server]** depends on details nobody has answered yet — PRD
§10 Q14, *what is the current server and who administers it*. Those steps are
written for Linux; the Windows equivalents are noted where they differ.

---

## Before anything else

```bash
php artisan app:version
```

Prints the schema version, how many migrations are waiting, and the code
commit. **Write it down.** It is the one thing that identifies this
installation, and the first thing support will ask for.

---

## Installing for the first time

1. **[server]** PHP 8.4+, MySQL 8+, and a web server pointed at `public/`.
2. Copy `.env.example` to `.env` and set the database credentials.
3. ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   ```
   The seed writes the chart of accounts, journal mappings, roles, number
   sequences and starting print layouts. It is idempotent — running it twice
   is safe.
4. Create the first administrator:
   ```bash
   php artisan app:create-admin
   ```
   It asks for a name, an email and a password. **No account is seeded** — an
   administrator whose password shipped in a seeder is one whose password is
   known on every installation. Then check they can sign in at `/admin`.

   Later accounts are made on the Users screen, or with the same command and
   `--role=` for a warehouse supervisor, finance manager and so on.
5. Build the front end. Without these, every screen loads unstyled:
   ```bash
   php artisan filament:assets
   npm ci && npm run build
   ```
   An update package built with `app:package` already contains all of this, so
   this step is only for a checkout — but it is also why a package built
   *without* running it first produces an unstyled panel on the client's
   machine. `app:package` refuses to build in that state.

6. Go through [What to configure before going live](#what-to-configure-before-going-live).

### Just looking at it?

```bash
php artisan db:seed --class=DemoDataSeeder
```

One plausible distributor — ten products with batches and shelf lives, eight
outlets on different exchange days, two price lists, a rack walk and some
discount rules. Not part of `db:seed`: a real installation imports the client's
own masters.

---

## Updating

An update arrives as a package built with `php artisan app:package`. It carries
`vendor/` already built, so the server needs no internet access.

1. Take a backup — the **Backups** screen, or it happens automatically in step 3.
2. Stop the web server.
3. Unpack `app/` from the package over the installation. It contains no `.env`,
   no `storage/`, and no database.
4. ```bash
   php artisan app:update
   ```
   This backs up first, applies pending migrations in order, clears caches and
   prints the new schema version. **If it stops with an error, nothing after
   that point ran.** It names the backup it took — restore that before trying
   again, and call support.
5. Start the web server and run `php artisan app:version` to confirm.

### Rolling back

Restore the backup from step 4 and unpack the previous package. Migrations are
not reversed automatically: one that has run has already changed data, and
undoing it is a decision rather than a command.

---

## Backups

The **Backups** screen takes one, downloads it, and — the button that matters —
**checks it restores**. That restores into a scratch database and counts what
came back; it never touches the live one.

Take one before every update, and after any month-end close.

> **[server] Not automated yet.** Dumps are written to `storage/app/backups` on
> the server itself, which survives a bad upgrade and nothing else. Copying them
> somewhere else is a manual step until the server and an offsite destination
> are known.

### Restoring for real

```bash
mysql -h HOST -u USER -p TARGET_DATABASE < backup.sql
```

Restore into a scratch database first and look at it. Restoring over a live
database is a one-way step taken by someone who is certain.

---

## Month end

```bash
php artisan accounting:close --check 2026-08     # is it ready?
php artisan accounting:close 2026-08 --user=finance@client.co.id
```

The close refuses if:

- **Journals are still in draft.** Post or cancel them. Closing over a draft
  reports figures that are not final.
- **Stock does not reconcile to the inventory account.** Something posted stock
  without a journal or the other way round. Run
  `php artisan stock:rebuild-balances --check`, then without `--check` to
  correct the cache. If the difference is real and understood, `--force`
  closes anyway — and that is a decision someone owns.

Once closed, nothing can be posted into that month until it is reopened, which
is permissioned.

---

## When something looks wrong

| Symptom | What to run |
|---|---|
| Stock figure looks wrong | `php artisan stock:rebuild-balances --check` — reports without changing anything. Anything it finds means something wrote stock outside the posting kernel |
| An order is stuck holding stock | `php artisan stock:release-expired-reservations --check` |
| Collections do not know who to visit | `php artisan collections:today` |
| "This installation was licensed for N users" | Someone is being activated beyond the seats bought. Deactivate an unused account, or buy more. **Nobody already working is affected** |
| Nobody can sign in | No users exist yet, or the only one was deactivated. `php artisan app:create-admin` |
| Screens load unstyled | `php artisan filament:assets` and `npm ci && npm run build` were never run. If only the *custom* pages look wrong — order entry, imports, backups — the panel's theme is missing: it is registered with `->viteTheme()` and built from `resources/css/filament/admin/theme.css`, whose `@source` lines tell Tailwind where this application's markup lives |
| Order entry will not accept anything | The screen lists what is missing at the top — usually no warehouse, customers or prices yet |
| A month will not close | See [Month end](#month-end) — the message says which of the two checks failed |
| An invoice will not post | Almost always the three-way match: the invoice disagrees with the order price or the received quantity. The message gives both figures |

### The scheduler

```bash
# [server] Linux
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

**[server] Windows:** a Task Scheduler entry running `php artisan schedule:run`
every minute.

It drives the hourly reservation cleanup and the 07:00 collections list. Without
it, unprocessed orders hold stock indefinitely.

---

## What to configure before going live

These are data, not code — all editable in the admin panel.

| | Where | Why it matters |
|---|---|---|
| Chart of accounts | Accounting → Chart of accounts | Seeded with a starting set. The client's accountant will want their own codes |
| Journal mappings | Accounting → Journal mappings | Which account each document posts to. **Nothing is hardcoded**, so a different chart needs no code change |
| Print layouts | Accounting → Print templates | **Test against the client's actual printer before the first demo.** Column positions on continuous form are assumptions until paper comes out of their machine |
| Number sequences | Seeded per document type | Prefixes are client conventions. They roll into a new month on their own |
| Products, customers, opening stock | Catalogue → Import from Excel | Download the template, fill it in, check it, then import. One bad row imports nothing |
| Warehouses and rack locations | Warehouse → Warehouses | `pick_sequence` is the order pickers walk the racks. Without it, picking lists sort last |
| Credit limits and exchange days | Partners → Business partners | Drives credit holds and the collections day list |

### Two accounting choices made without an accountant

Both are seeder rows, changeable without touching code, and both should be
confirmed before the first live install:

- **2103 Goods Received Not Invoiced** — the receipt credits it and the supplier
  invoice clears it, so payables is not credited twice for the same goods.
- **3102 Opening Balance Equity** — what imported opening stock is booked
  against, keeping it out of month one's profit and loss.

---

## Things this system will not do

Worth knowing before someone asks:

- **It does not expire.** The software is sold, not rented. It has no kill
  switch and never stops working, whatever the support date says.
- **It does not reverse a migration** during an update. Restoring a backup is
  the rollback.
- **It does not delete a posted document.** Corrections are reversals, and the
  original stays visible. That is deliberate — an audit that cannot see what
  was originally recorded is not an audit.
