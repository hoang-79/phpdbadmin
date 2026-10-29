<?php

namespace App\Connections;

/**
 * Der SSH-Tunnel kam nicht zustande (V-08) — mit verständlicher Art (Schlüssel abgelehnt, Host-Schlüssel geändert,
 * Zeitüberschreitung …) und der Meldung von ssh zum Aufklappen. Eine Unterart von ConnectionNotAllowed: Arbeits-
 * bildschirm, Raster und Editor zeigen sie wie jeden anderen Grund, warum keine Verbindung zustande kommt.
 */
final class TunnelFailed extends ConnectionNotAllowed {}
