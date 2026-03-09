<?php

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function shortenText(string $text, int $maxLength = 300): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $maxLength, '...');
    }

    if (strlen($text) <= $maxLength) {
        return $text;
    }

    return substr($text, 0, $maxLength - 3) . '...';
}

function formatDateSpanish(?string $dateValue): string
{
    if (empty($dateValue)) {
        return 'Sin fecha';
    }

    $timestamp = strtotime($dateValue);

    if ($timestamp === false) {
        return 'Sin fecha';
    }

    $months = [
        1 => 'ene',
        2 => 'feb',
        3 => 'mar',
        4 => 'abr',
        5 => 'may',
        6 => 'jun',
        7 => 'jul',
        8 => 'ago',
        9 => 'sep',
        10 => 'oct',
        11 => 'nov',
        12 => 'dic',
    ];

    $day = (string) ((int) date('d', $timestamp));
    $month = $months[(int) date('n', $timestamp)] ?? date('m', $timestamp);
    $year = date('Y', $timestamp);

    return $day . '/' . $month . '/' . $year;
}
