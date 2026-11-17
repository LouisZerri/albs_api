<?php

namespace App\Command;

use App\Content\GhostStations;
use App\Content\JourneyCatalog;
use App\I18n\Translator;
use App\Repository\BadgeRepository;
use App\Service\BadgeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Liste les textes de CONTENU (anecdotes, parcours, stations fantômes, badges) qui n'ont pas de
 * traduction : à lancer après avoir ajouté ou modifié du contenu. Un texte non traduit n'est pas
 * une panne (l'app l'affiche en français), mais c'est rarement voulu.
 */
#[AsCommand(name: 'app:i18n:check', description: 'Textes de contenu sans traduction')]
final class I18nCheckCommand extends Command
{
    public function __construct(private readonly BadgeRepository $badges)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $texts = $this->contentTexts();
        $missing = 0;

        foreach (array_diff(Translator::LOCALES, ['fr']) as $locale) {
            foreach ($texts as $text) {
                if (!Translator::has($locale, $text)) {
                    $io->writeln(sprintf('[%s] %s', $locale, $text));
                    ++$missing;
                }
            }
        }

        if ($missing > 0) {
            $io->error(sprintf('%d texte(s) sans traduction (à ajouter dans src/I18n/).', $missing));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d textes de contenu, tous traduits.', count($texts)));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function contentTexts(): array
    {
        $texts = array_values((new \ReflectionClassConstant(\App\Content\StationFacts::class, 'FACTS'))->getValue());

        foreach (JourneyCatalog::all() as $journey) {
            array_push($texts, $journey['title'], $journey['pitch'], ...array_values(array_filter($journey['stops'])));
        }
        foreach (GhostStations::all() as $ghost) {
            array_push($texts, $ghost['lines'], $ghost['story']);
        }

        $descriptions = (new \ReflectionClassConstant(BadgeService::class, 'DESCRIPTIONS_TU'))->getValue();
        foreach ($this->badges->findAll() as $badge) {
            $texts[] = (string) $badge->getName();
            $texts[] = $descriptions[$badge->getName()] ?? (string) $badge->getDescription();
        }

        return array_values(array_unique($texts));
    }
}
