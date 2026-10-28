<?php

namespace App\Enums;

/**
 * Ebene, auf der ein Recht gilt (W-04): der ganze Server, eine Datenbank, ein Schema (nur PostgreSQL) oder eine
 * Tabelle. Welche Rechte es je Ebene gibt, sagt der Dialekt (Dialect::privilegeChoices).
 */
enum PrivilegeLevel: string
{
    case Server = 'server';
    case Database = 'database';
    case Schema = 'schema';
    case Table = 'table';
}
