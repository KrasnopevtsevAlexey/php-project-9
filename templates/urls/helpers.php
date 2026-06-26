<?php

function truncate($text, $length = 200)
{
    if (empty($text)) {
        return '';
    }
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}
