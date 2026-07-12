<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Fuseau horaire = Europe/Paris (identique au web : base partagée, cohérence des timestamps
// + fiabilité des badges horaires « Lève-tôt » / « Oiseau de nuit »).
date_default_timezone_set('Europe/Paris');

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
