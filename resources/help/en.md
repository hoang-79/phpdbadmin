## Signing in {#anmelden}

phpdbadmin has no passwords for accounts. You sign in with a **passkey** (fingerprint, face or device PIN) or with a **code by email** that is valid for 10 minutes. Type the code digit by digit or paste it in one go — as soon as all six digits are in, phpdbadmin signs you in. Manage your passkeys under _Account → Security_.

New accounts are created only by **invitation** from an administrator.

## Connections {#verbindungen}

A connection leads to a database server (MariaDB, MySQL, PostgreSQL) or to an SQLite file. When you create one, phpdbadmin tests it and warns if the database account may do more than necessary (superuser, files on the server).

- **Environment** — _local_, _test_ or _production_: colour, symbol and word in the header always show where you are working.
- **Read-only** — phpdbadmin writes nothing through this connection, not even in the SQL editor.
- **Password for this session only** — phpdbadmin does not store it and asks again after every sign-in.
- Only servers and folders allowed by the installation can be reached (`PHPDBADMIN_ALLOWED_HOSTS`, `PHPDBADMIN_SQLITE_PATHS`).

## Workspace {#arbeitsbildschirm}

On the left, the **navigator** with databases or schemas, tables and views — `/` puts the focus in its filter. Hover over a database or schema and a bin appears on the right: it drops it with everything inside — after a preview and only once you type its name. At the top, the **tabs** of open objects and queries. **⌘K** (Windows/Linux: Ctrl+K) opens the command palette: search objects, connections and actions.

## Viewing data {#daten}

The grid loads more as you scroll. Combine **filters** with _+ Filter_; the search looks through text columns. For large tables the row count is estimated (≈) — _count exactly_ asks the server. `NULL` is shown in italics and is not the same as empty text.

Foreign keys are links: to the row they point to and, in the row detail, to the rows that point to this one. Clicking the row number or pressing **Space** opens the row detail.

## Changing data {#bearbeiten}

Double-click, **Enter** or **F2** edits a cell; in the row detail you edit the whole row. Nothing is written right away: changed cells are marked and a bar at the bottom counts the changes.

1. **Preview** shows every statement with its values — and which rows `ON DELETE CASCADE` would delete as well.
2. **Apply** writes everything in one transaction: if a statement does not hit exactly one row, nothing is written.
3. **Undo** restores the previous state — as long as nobody has changed the rows in the meantime.

On **production** you confirm by typing the name of the connection. Tables without a primary key and views are read-only.

## Temporary write access {#schreibschutz}

A read-only connection can be unlocked for **15 minutes** — in the connection menu, with a reason. Reason and end are recorded in the audit log. This requires the _write_ permission; _lock again_ ends it immediately.

## SQL editor {#sql}

**⌘↩** runs the statement under the cursor, **⇧⌘↩** runs all. Every statement has a time limit (30 seconds by default) and a row limit (1,000); _Cancel_ stops it on the server. Statements that destroy data or structure (`DROP`, `TRUNCATE`, `DELETE` without `WHERE` …) need a confirmation.

The **history** keeps your queries for 90 days; saved queries stay until you delete them.

**⌘E** explains the statement under the cursor: the plan appears as a tree with estimated rows and costs, plus hints such as «full scan of a large table». _Measure with ANALYZE_ actually runs a reading statement for this.

With **Ask AI**, Claude writes a statement from your question — if the installation has turned it on and you have added your own key under _Account → AI help_. Only your question and the schema (tables, columns, types, keys) are sent, never data. The suggestion lands in the editor; it only runs when you run it.

## Views, routines and diagram {#objekte}

Besides tables, the navigator lists views, routines, triggers, events, sequences and types. You can view, copy and open their definition in the editor and — with the _write_ permission — change or drop them; with a preview first, as when changing data.

The **relationship diagram** of a schema shows the tables with their foreign keys. Boxes can be moved and the view zoomed; _As SVG_ downloads the picture. With very many tables you choose which ones appear.

## Changing structure {#struktur}

_Alter table_ on the Structure page: add, change, rename and drop columns; indexes and foreign keys. The preview shows the SQL exactly as it will run. MySQL and MariaDB run structure changes statement by statement and cannot roll them back — the preview says so. Dropping, emptying and removing columns with data require the table name. _More actions_ renames, copies, empties or drops a table.

## Comparing and migrations {#vergleich}

_Compare schemas_ (in the connection overview) sets two schemas side by side — also between MySQL, MariaDB, PostgreSQL and SQLite — and creates a change script in the target's dialect. Nothing runs: you copy the script or open it in the target's SQL editor.

_Laravel migration_ on the Structure page creates a migration file for a Laravel project from a table.

## Export and import {#export-import}

From the connection menu or with _Export_ in a table's header: **CSV**, **JSON** or **SQL**. Large exports run in the background; the file is ready to download for 24 hours.

When **importing** a CSV file you map the columns and do a **dry run** first — it lists failing rows with their line number and writes nothing. An SQL file is executed statement by statement.

## Server administration {#server}

In the connection menu under _Server_: overview, running **processes** (end them), **variables**, **accounts and privileges** and **maintenance** (e.g. `ANALYZE`, `OPTIMIZE`, `VACUUM`). Every change shows its SQL first and is recorded in the audit log afterwards. Changes need the _manage_ permission and a connection that is not read-only.

## Connections over SSH {#ssh}

A database server that can only be reached through an SSH server: turn on _SSH tunnel_ in the connection form and enter the SSH host, user and private key (pasted and stored encrypted, or as a file in an allowed folder). On the first connection phpdbadmin remembers the host key and shows its fingerprint; if it changes later, phpdbadmin no longer connects.

If phpdbadmin runs locally with Laravel Herd, the form suggests the Herd services and the databases of your Herd sites.

## Accounts, permissions and audit log {#konten}

Each connection has three permissions that build on each other: **read**, **write**, **manage**. Personal connections are visible only to their owner; administrators share connections with other accounts.

The **audit log** (administration) records every writing action — who, when, on which connection, with which SQL. Values appear there only as placeholders.

Under _Account → Sessions_ you see where your account is signed in and end other sessions — for example on a lost device.

## Keyboard shortcuts {#kuerzel}

**?** shows all shortcuts. The most important: **⌘K** command palette, **/** filter the navigator, **⌘↩** run statement, **⌘E** explain statement, **Enter** or **F2** edit a cell, **Esc** cancel.
