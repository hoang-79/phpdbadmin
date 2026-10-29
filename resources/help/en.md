## Signing in {#anmelden}

phpdbadmin has no passwords for accounts. You sign in with a **passkey** (fingerprint, face or device PIN) or with a **code by email** that is valid for 10 minutes. Manage your passkeys under _Account → Security_.

New accounts are created only by **invitation** from an administrator.

## Connections {#verbindungen}

A connection leads to a database server (MariaDB, MySQL, PostgreSQL) or to an SQLite file. When you create one, phpdbadmin tests it and warns if the database account may do more than necessary (superuser, files on the server).

- **Environment** — _local_, _test_ or _production_: colour, symbol and word in the header always show where you are working.
- **Read-only** — phpdbadmin writes nothing through this connection, not even in the SQL editor.
- **Password for this session only** — phpdbadmin does not store it and asks again after every sign-in.
- Only servers and folders allowed by the installation can be reached (`PHPDBADMIN_ALLOWED_HOSTS`, `PHPDBADMIN_SQLITE_PATHS`).

## Workspace {#arbeitsbildschirm}

On the left, the **navigator** with databases or schemas, tables and views — `/` puts the focus in its filter. At the top, the **tabs** of open objects and queries. **⌘K** (Windows/Linux: Ctrl+K) opens the command palette: search objects, connections and actions.

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

## Export and import {#export-import}

From the connection menu or with _Export_ in a table's header: **CSV**, **JSON** or **SQL**. Large exports run in the background; the file is ready to download for 24 hours.

When **importing** a CSV file you map the columns and do a **dry run** first — it lists failing rows with their line number and writes nothing. An SQL file is executed statement by statement.

## Server administration {#server}

In the connection menu under _Server_: overview, running **processes** (end them), **variables**, **accounts and privileges** and **maintenance** (e.g. `ANALYZE`, `OPTIMIZE`, `VACUUM`). Every change shows its SQL first and is recorded in the audit log afterwards. Changes need the _manage_ permission and a connection that is not read-only.

## Accounts, permissions and audit log {#konten}

Each connection has three permissions that build on each other: **read**, **write**, **manage**. Personal connections are visible only to their owner; administrators share connections with other accounts.

The **audit log** (administration) records every writing action — who, when, on which connection, with which SQL. Values appear there only as placeholders.

## Keyboard shortcuts {#kuerzel}

**?** shows all shortcuts. The most important: **⌘K** command palette, **/** filter the navigator, **⌘↩** run statement, **Enter** or **F2** edit a cell, **Esc** cancel.
