<?php

if (! function_exists('format_money')) {
    function format_money($amount): string
    {
        $amount = (float) $amount;

        return ($amount < 0 ? '-' : '').'$'.number_format(abs($amount), 0, ',', '.');
    }
}

if (! function_exists('format_money_decimal')) {
    function format_money_decimal($amount): string
    {
        $amount = (float) $amount;

        return ($amount < 0 ? '-' : '').'$'.number_format(abs($amount), 2, ',', '.');
    }
}
