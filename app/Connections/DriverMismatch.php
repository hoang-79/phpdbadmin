<?php

namespace App\Connections;

/**
 * Der Server ist ein anderes Produkt als der Treiber der Verbindung (V-02): MySQL und MariaDB nehmen dieselbe Anmeldung
 * an, scheitern aber an den Befehlen des jeweils anderen (Zeitlimit, Konten). Eine Unterart von ConnectionNotAllowed:
 * Arbeitsbildschirm, Raster und Editor zeigen sie wie jeden anderen Grund, warum keine Verbindung zustande kommt.
 */
final class DriverMismatch extends ConnectionNotAllowed {}
