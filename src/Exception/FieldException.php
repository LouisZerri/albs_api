<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Refus (400) qui porte sur UN champ du formulaire. Le nom du champ est renvoyé à l'app
 * (`field`), qui affiche l'erreur sous le bon champ sans avoir à deviner d'après le texte du
 * message — lequel change selon la langue.
 */
final class FieldException extends BadRequestHttpException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
