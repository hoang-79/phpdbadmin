<?php

namespace App\Services\Schema;

/**
 * Kurze Beschriftung je Anweisung in der Vorschau («Spalte neu», «umbenannt», «Index weg» — Wireframe 9). Nur zum
 * Lesen: ausgeführt wird der Text der Anweisung, nicht die Beschriftung.
 */
final class StatementLabel
{
    public static function of(string $sql): ?string
    {
        $label = self::label($sql);

        return $label === '' ? null : $label;
    }

    private static function label(string $sql): string
    {
        $text = (string) preg_replace('/\s+/', ' ', strtolower(trim($sql)));

        return match (true) {
            str_contains($text, '__temp__') => __('Umbau'),
            str_starts_with($text, 'create table') => __('Tabelle neu'),
            str_starts_with($text, 'create database'), str_starts_with($text, 'create schema') => __('neu'),
            str_starts_with($text, 'drop database'), str_starts_with($text, 'drop schema') => __('wird gelöscht'),
            str_starts_with($text, 'drop table') => __('Tabelle weg'),
            str_starts_with($text, 'truncate'), str_starts_with($text, 'delete from') => __('leeren'),
            str_starts_with($text, 'insert into') => __('Daten kopieren'),
            str_starts_with($text, 'rename table'), preg_match('/^alter table \S+ rename to /', $text) === 1 => __('umbenannt'),
            str_contains($text, ' rename column ') => __('umbenannt'),
            preg_match('/ drop (foreign key|constraint) /', $text) === 1 => __('Fremdschlüssel weg'),
            str_contains($text, ' foreign key') => __('Fremdschlüssel neu'),
            str_starts_with($text, 'drop index'), preg_match('/ drop index /', $text) === 1 => __('Index weg'),
            preg_match('/^create (unique )?index /', $text) === 1, preg_match('/ add (unique|index|primary key)/', $text) === 1 => __('Index neu'),
            str_contains($text, ' drop column ') => __('Spalte weg'),
            preg_match('/^alter table \S+ add (column )?[`"]/', $text) === 1 => __('Spalte neu'),
            str_starts_with($text, 'comment on'), preg_match('/^alter table \S+ comment ?=/', $text) === 1 => __('Kommentar'),
            preg_match('/ (modify|change) (column )?[`"]/', $text) === 1, str_contains($text, ' alter column ') => __('geändert'),
            str_starts_with($text, 'create sequence'), str_starts_with($text, 'select setval') => __('Zähler'),
            str_starts_with($text, 'create trigger'), str_starts_with($text, 'drop trigger') => __('Trigger'),
            str_starts_with($text, 'create view'), str_starts_with($text, 'drop view') => __('Sicht'),
            default => '',
        };
    }
}
