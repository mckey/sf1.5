<?php

/*
 * This file is part of the symfony package.
 * (c) 2004-2006 Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * I18NHelper.
 *
 * @package    symfony
 * @subpackage helper
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 */

/**
 * @param string|array|null $text
 * @param array|null        $args
 * @param string            $catalogue
 *
 * @return string
 */
function __($text, ?array $args = [], string $catalogue = 'messages') : string
{
    if (sfConfig::get('sf_i18n')) {
        return sfContext::getInstance()->getI18N()->__($text, $args, $catalogue);
    } else {
        if (empty($args)) {
            $args = [];
        }

        // replace object with strings
        foreach ($args as $key => $value) {
            if (is_object($value) && method_exists($value, '__toString')) {
                $args[$key] = $value->__toString();
            }
        }

        return strtr($text, $args);
    }
}

/**
 * Format a string according to a number.
 *
 * Every segment is separated with |
 * Each segment defines an intervale and a value.
 *
 * For example :
 *
 * * [0]Nobody is logged|[1]There is 1 person logged|(1,+Inf]There are %number persons logged
 *
 * @param string $text      Text used for different number values
 * @param array  $args      Arguments to replace in the string
 * @param int    $number    Number to use to determine the string to use
 * @param string $catalogue Catalogue for translation
 *
 * @return string Result of the translation
 */
function format_number_choice(string $text, array $args = [], int $number = 0, string $catalogue = 'messages') : string
{
    $translated = __($text, $args, $catalogue);

    $choice = new sfChoiceFormat();

    $retval = $choice->format($translated, $number);

    if ($retval === false) {
        throw new sfException(sprintf('Unable to parse your choice "%s".', $translated));
    }

    return $retval;
}

/**
 * @param string      $country_iso
 * @param string|null $culture
 *
 * @return string
 */
function format_country(string $country_iso, ?string $culture = null) : string
{
    $c = sfCultureInfo::getInstance($culture ?? sfContext::getInstance()->getUser()->getCulture());
    $countries = $c->getCountries();

    return $countries[$country_iso] ?? '';
}

/**
 * @param string      $language_iso
 * @param string|null $culture
 *
 * @return string
 */
function format_language(string $language_iso, ?string $culture = null) : string
{
    $c = sfCultureInfo::getInstance($culture ?? sfContext::getInstance()->getUser()->getCulture());
    $languages = $c->getLanguages();

    return isset($languages[$language_iso]) ? $languages[$language_iso] : '';
}
