<?php

declare(strict_types=1);

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface, ModuleGlobalInterface, RequestHandlerInterface {
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use ModuleGlobalTrait;

    private const ROUTE_URL = '/tree/{tree}/potts-narrative-ancestor-book';
    private const CUSTOM_VERSION = '0.8.2';
    private const GITHUB_REPO_URL = 'https://github.com/PottsNet/potts-narrative-ancestor-book';
    private const LATEST_VERSION_URL = 'https://raw.githubusercontent.com/PottsNet/potts-narrative-ancestor-book/main/latest-version.txt';

    public function title(): string
    {
        return I18N::translate('Potts Narrative Ancestor Book');
    }

    public function description(): string
    {
        return I18N::translate('Create a personalised narrative ancestor book from GEDCOM facts, family events, notes and photographs.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Jason Potts';
    }

    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    public function customModuleLatestVersionUrl(): string
    {
        return self::LATEST_VERSION_URL;
    }

    public function customModuleSupportUrl(): string
    {
        return self::GITHUB_REPO_URL;
    }

    public function boot(): void
    {
        Registry::routeFactory()->routeMap()->get(static::class, self::ROUTE_URL, $this);
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }



    public function defaultMenuOrder(): int
    {
        return 99;
    }

    public function getMenu(Tree $tree): ?Menu
    {
        $url = route(static::class, [
            'tree' => $tree->name(),
        ]);

        return new Menu(I18N::translate('Your Book'), e($url), $this->name());
    }

    public function headContent(): string
    {
        return '<style>
            .' . $this->name() . ' > .nav-link,
            .' . $this->name() . ' > a,
            .' . $this->name() . ' .nav-link {
                text-align: center;
            }
            .' . $this->name() . ' > .nav-link::before,
            .' . $this->name() . ' > a::before,
            .' . $this->name() . ' .nav-link::before {
                content: "";
                display: block;
                width: 32px;
                height: 32px;
                margin: 0 auto 0.25rem;
                background-image: url(' . $this->assetUrl("icons/your-book-menu-32.png") . ');
                background-repeat: no-repeat;
                background-size: 32px 32px;
                background-position: center;
            }
            .dropdown-menu .' . $this->name() . ' .nav-link::before,
            .dropdown-menu .' . $this->name() . ' > a::before {
                display: inline-block;
                width: 16px;
                height: 16px;
                margin: 0 0.35rem 0 0;
                background-size: 16px 16px;
                vertical-align: text-bottom;
            }
        </style>';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);

        $query = $request->getQueryParams();

        if (($query['action'] ?? '') === 'search') {
            return $this->searchResponse($tree, trim((string) ($query['q'] ?? '')));
        }

        $xref = strtoupper(trim((string) ($query['xref'] ?? '')));
        $person_query = trim((string) ($query['person'] ?? ''));
        $has_selection_query = array_key_exists('xref', $query) || array_key_exists('person', $query);
        $default_wording = 'neutral';

        if ($xref === '' && preg_match('/\[([^\]]+)\]/', $person_query, $match)) {
            $xref = strtoupper(trim($match[1]));
        }

        if (!$has_selection_query && $xref === '' && $person_query === '') {
            $default = $this->defaultIndividualWithSource($tree);

            if (is_array($default) && ($default['individual'] ?? null) instanceof Individual) {
                $default_person = $default['individual'];
                assert($default_person instanceof Individual);

                $xref = $default_person->xref();
                $person_query = (string) $this->searchResultForIndividual($default_person)['label'];
                $default_wording = (string) ($default['wording'] ?? 'neutral');
            }
        }

        $generations = min(8, max(1, (int) ($query['generations'] ?? 4)));

        $options = [
            'include_media' => ($query['include_media'] ?? '1') === '1',
            'include_occupations' => ($query['include_occupations'] ?? '1') === '1',
            'include_residences' => ($query['include_residences'] ?? '1') === '1',
            'include_education' => ($query['include_education'] ?? '1') === '1',
            'include_migration' => ($query['include_migration'] ?? '1') === '1',
            'include_children' => ($query['include_children'] ?? '1') === '1',
            'include_notes' => ($query['include_notes'] ?? '1') === '1',
            'wording' => (string) ($query['wording'] ?? $default_wording),
            'story_detail' => (string) ($query['story_detail'] ?? 'detailed'),
            'book_sections' => (string) ($query['book_sections'] ?? 'personalised'),
        ];

        $error = '';
        $book = null;

        if ($xref === '' && $person_query !== '') {
            $matches = $this->searchIndividuals($tree, $person_query, 2);

            if (count($matches) === 1) {
                $xref = (string) $matches[0]['xref'];
            } elseif (count($matches) > 1) {
                $error = I18N::translate('More than one individual matched this name. Please choose one of the suggestions from the list.');
            }
        }

        if (($has_selection_query || ($query['download'] ?? '') === 'html') && $xref !== '' && $error === '') {
            $root = $this->individual($xref, $tree);

            if ($root instanceof Individual) {
                $book = $this->buildBook($root, $generations, $options);

                if (($query['download'] ?? '') === 'html') {
                    return $this->downloadHtml($book, $tree);
                }
            } else {
                $error = I18N::translate('The selected individual could not be found, or you do not have permission to view them.');
            }
        }

        return $this->viewResponse($this->name() . '::page', [
            'title' => $this->title(),
            'tree' => $tree,
            'module_name' => $this->name(),
            'module_class' => static::class,
            'xref' => $xref,
            'person_query' => $person_query,
            'generations' => $generations,
            'options' => $options,
            'error' => $error,
            'book' => $book,
        ]);
    }

    private function individual(string $xref, Tree $tree): ?Individual
    {
        $individual = null;

        if (method_exists(Registry::class, 'individualFactory')) {
            $individual = Registry::individualFactory()->make($xref, $tree);
        }

        if (!$individual instanceof Individual && method_exists(Individual::class, 'getInstance')) {
            /** @phpstan-ignore-next-line Compatibility with older webtrees releases. */
            $individual = Individual::getInstance($xref, $tree);
        }

        if ($individual instanceof Individual && $individual->canShow()) {
            return $individual;
        }

        return null;
    }


    /**
     * @return array{individual: Individual, wording: string}|null
     */
    private function defaultIndividualWithSource(Tree $tree): ?array
    {
        foreach ($this->loggedInUserIndividualXrefs($tree) as $xref) {
            $individual = $this->individual($xref, $tree);

            if ($individual instanceof Individual && $individual->canShowName()) {
                return [
                    'individual' => $individual,
                    'wording' => 'your',
                ];
            }
        }

        foreach ($this->treeFavouriteIndividualXrefs($tree) as $xref) {
            $individual = $this->individual($xref, $tree);

            if ($individual instanceof Individual && $individual->canShowName()) {
                return [
                    'individual' => $individual,
                    'wording' => 'neutral',
                ];
            }
        }

        return null;
    }

    private function defaultIndividual(Tree $tree): ?Individual
    {
        $default = $this->defaultIndividualWithSource($tree);

        return is_array($default) && ($default['individual'] ?? null) instanceof Individual
            ? $default['individual']
            : null;
    }

    /**
     * @return array<int,string>
     */
    private function defaultIndividualXrefs(Tree $tree): array
    {
        $xrefs = array_merge(
            $this->loggedInUserIndividualXrefs($tree),
            $this->treeFavouriteIndividualXrefs($tree)
        );

        $clean = [];

        foreach ($xrefs as $xref) {
            $xref = strtoupper(trim((string) $xref));

            if ($xref !== '' && !in_array($xref, $clean, true)) {
                $clean[] = $xref;
            }
        }

        return $clean;
    }

    /**
     * @return array<int,string>
     */
    private function loggedInUserIndividualXrefs(Tree $tree): array
    {
        $xrefs = [];

        try {
            if (!class_exists(Auth::class)) {
                return [];
            }

            if (method_exists(Auth::class, 'check') && !Auth::check()) {
                return [];
            }

            $user = Auth::user();
        } catch (\Throwable $ex) {
            return [];
        }

        if (!is_object($user)) {
            return [];
        }

        foreach (['gedcomid', 'rootid', 'xref', 'individual_xref', 'default_xref'] as $setting) {
            $xrefs[] = $this->tryUserPreference($user, [$setting]);
            $xrefs[] = $this->tryUserPreference($user, [$tree, $setting]);
            $xrefs[] = $this->tryUserPreference($user, [$tree->name(), $setting]);
        }

        $user_id = $this->objectNumericId($user);
        $tree_id = method_exists($tree, 'id') ? (int) $tree->id() : 0;

        if ($user_id > 0 && $tree_id > 0) {
            foreach (['user_gedcom_setting', 'user_tree_setting'] as $table) {
                foreach (['gedcom_id', 'tree_id'] as $tree_column) {
                    try {
                        $rows = DB::table($table)
                            ->where('user_id', '=', $user_id)
                            ->where($tree_column, '=', $tree_id)
                            ->whereIn('setting_name', ['gedcomid', 'rootid', 'xref', 'individual_xref', 'default_xref'])
                            ->pluck('setting_value')
                            ->all();

                        foreach ($rows as $row) {
                            $xrefs[] = (string) $row;
                        }
                    } catch (\Throwable $ex) {
                        // Table or column names differ between webtrees versions.
                    }
                }
            }
        }

        return array_values(array_filter(array_map('strval', $xrefs)));
    }

    /**
     * @return array<int,string>
     */
    private function treeFavouriteIndividualXrefs(Tree $tree): array
    {
        $xrefs = [];
        $tree_id = method_exists($tree, 'id') ? (int) $tree->id() : 0;
        $preference_names = [
            'PEDIGREE_ROOT_ID',
            'DEFAULT_INDIVIDUAL',
            'DEFAULT_XREF',
            'FAVORITE_INDIVIDUAL',
            'HOME_INDIVIDUAL',
            'ROOT_ID',
        ];

        foreach ($preference_names as $preference_name) {
            try {
                if (method_exists($tree, 'getPreference')) {
                    $xrefs[] = (string) $tree->getPreference($preference_name);
                }
            } catch (\Throwable $ex) {
                // Preference not available in this webtrees version.
            }
        }

        if ($tree_id > 0) {
            foreach (['gedcom_setting', 'tree_setting'] as $table) {
                foreach (['gedcom_id', 'tree_id'] as $tree_column) {
                    try {
                        $rows = DB::table($table)
                            ->where($tree_column, '=', $tree_id)
                            ->whereIn('setting_name', $preference_names)
                            ->pluck('setting_value')
                            ->all();

                        foreach ($rows as $row) {
                            $xrefs[] = (string) $row;
                        }
                    } catch (\Throwable $ex) {
                        // Table or column names differ between webtrees versions.
                    }
                }
            }

            foreach (['favorite', 'favorites'] as $table) {
                foreach (['gedcom_id', 'tree_id'] as $tree_column) {
                    foreach (['favorite_type', 'type'] as $type_column) {
                        try {
                            $row = DB::table($table)
                                ->where($tree_column, '=', $tree_id)
                                ->where($type_column, '=', 'INDI')
                                ->orderBy('sort_order')
                                ->value('xref');

                            if (is_string($row) && $row !== '') {
                                $xrefs[] = $row;
                            }
                        } catch (\Throwable $ex) {
                            // Table or column names differ between webtrees versions.
                        }
                    }
                }
            }
        }

        return array_values(array_filter(array_map('strval', $xrefs)));
    }

    /**
     * @param array<int,mixed> $arguments
     */
    private function tryUserPreference(object $user, array $arguments): string
    {
        foreach (['getPreference', 'preference'] as $method) {
            try {
                if (method_exists($user, $method)) {
                    $value = $user->{$method}(...$arguments);

                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            } catch (\Throwable $ex) {
                // Different webtrees versions use different preference method signatures.
            }
        }

        return '';
    }

    private function objectNumericId(object $object): int
    {
        foreach (['id', 'userId', 'getId'] as $method) {
            try {
                if (method_exists($object, $method)) {
                    return (int) $object->{$method}();
                }
            } catch (\Throwable $ex) {
                // Try the next common method name.
            }
        }

        return 0;
    }


    private function searchResponse(Tree $tree, string $term): ResponseInterface
    {
        $results = $this->searchIndividuals($tree, $term, 20);

        return response(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]')
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function searchIndividuals(Tree $tree, string $term, int $limit): array
    {
        $term = trim((string) preg_replace('/\s+/', ' ', $term));

        if ($term === '' || mb_strlen($term) < 2) {
            return [];
        }

        if (preg_match('/^I\d+$/i', $term)) {
            $individual = $this->individual(strtoupper($term), $tree);

            if ($individual instanceof Individual && $individual->canShowName()) {
                return [$this->searchResultForIndividual($individual)];
            }
        }

        $tree_id = method_exists($tree, 'id') ? $tree->id() : null;
        $rows = [];

        foreach (['names', 'name'] as $table) {
            try {
                $rows = DB::table($table)
                    ->select(['n_id', 'n_full', 'n_surn', 'n_givn'])
                    ->where('n_file', '=', $tree_id)
                    ->where('n_type', '=', 'NAME')
                    ->where(static function ($query) use ($term): void {
                        $query
                            ->where('n_full', 'LIKE', '%' . $term . '%')
                            ->orWhere('n_givn', 'LIKE', '%' . $term . '%')
                            ->orWhere('n_surn', 'LIKE', '%' . $term . '%');
                    })
                    ->orderBy('n_surn')
                    ->orderBy('n_givn')
                    ->limit($limit * 4)
                    ->get();

                break;
            } catch (Throwable $ex) {
                $rows = [];
            }
        }

        $results = [];
        $seen = [];

        foreach ($rows as $row) {
            $xref = strtoupper((string) ($row->n_id ?? ''));

            if ($xref === '' || isset($seen[$xref])) {
                continue;
            }

            $individual = $this->individual($xref, $tree);

            if (!$individual instanceof Individual || !$individual->canShowName()) {
                continue;
            }

            $seen[$xref] = true;
            $results[] = $this->searchResultForIndividual($individual);

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array<string,string>
     */
    private function searchResultForIndividual(Individual $individual): array
    {
        $name = strip_tags($individual->fullName());
        $birth = $this->yearFromEvent($this->firstEvent($individual, 'BIRT'));
        $death = $this->yearFromEvent($this->firstEvent($individual, 'DEAT'));
        $life = trim($birth . '–' . $death, '–');
        $label = $name;

        if ($life !== '') {
            $label .= ' (' . $life . ')';
        }

        $label .= ' [' . $individual->xref() . ']';

        return [
            'xref' => $individual->xref(),
            'name' => $name,
            'life' => $life,
            'label' => $label,
        ];
    }

    /**
     * @param array<string,string> $event
     */
    private function yearFromEvent(array $event): string
    {
        $date = trim($event['date'] ?? '');

        if ($date === '') {
            return '';
        }

        if (preg_match('/(\d{4})/', $date, $match)) {
            return $match[1];
        }

        return '';
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function buildBook(Individual $root, int $generations, array $options): array
    {
        $seen = [];
        $by_generation = [];
        $frontier = [[
            'person' => $root,
            'distance' => 0,
            'path' => [],
            'direct_child_xref' => '',
        ]];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $entry) {
                $person = $entry['person'];
                assert($person instanceof Individual);

                $distance = (int) $entry['distance'];
                $path = (array) $entry['path'];
                $direct_child_xref = (string) ($entry['direct_child_xref'] ?? '');
                $xref = $person->xref();

                if (!isset($seen[$xref])) {
                    $seen[$xref] = true;
                    $generation = $distance + 1;
                    $by_generation[$generation][] = $this->profile($root, $person, $distance, $path, $direct_child_xref, $options);
                }

                if ($distance + 1 < $generations) {
                    foreach ($this->parents($person) as $parent) {
                        $next[] = [
                            'person' => $parent,
                            'distance' => $distance + 1,
                            'path' => [...$path, $this->parentPathWord($parent)],
                            'direct_child_xref' => $person->xref(),
                        ];
                    }
                }
            }

            $frontier = $next;
        }

        $generation_labels = [];
        foreach (array_keys($by_generation) as $generation_number) {
            $generation_labels[(int) $generation_number] = $this->generationLabel((int) $generation_number, (string) ($options['wording'] ?? 'your'), strip_tags($root->fullName()));
        }

        $book_sections = (string) ($options['book_sections'] ?? 'personalised');

        return [
            'title' => I18N::translate('Ancestors of %s', $root->fullName()),
            'root_name' => $root->fullName(),
            'root_xref' => $root->xref(),
            'generations' => $generations,
            'people_count' => count($seen),
            'created' => date('j F Y'),
            'by_generation' => $by_generation,
            'generation_labels' => $generation_labels,
            'introduction' => $this->bookIntroduction($root, $by_generation, $generations, count($seen), (string) ($options['story_detail'] ?? 'detailed'), $book_sections, (string) ($options['wording'] ?? 'your')),
            'conclusion' => $this->bookConclusion($root, $by_generation, $book_sections, (string) ($options['wording'] ?? 'your')),
            'query' => [
                'xref' => $root->xref(),
                'generations' => $generations,
                'include_media' => $options['include_media'] ? '1' : '0',
                'include_occupations' => $options['include_occupations'] ? '1' : '0',
                'include_residences' => $options['include_residences'] ? '1' : '0',
                'include_education' => $options['include_education'] ? '1' : '0',
                'include_migration' => $options['include_migration'] ? '1' : '0',
                'include_children' => $options['include_children'] ? '1' : '0',
                'include_notes' => $options['include_notes'] ? '1' : '0',
                'wording' => (string) $options['wording'],
                'story_detail' => (string) ($options['story_detail'] ?? 'detailed'),
                'book_sections' => (string) ($options['book_sections'] ?? 'personalised'),
            ],
        ];
    }

    private function generationLabel(int $generation, string $wording, string $root_name = 'the selected person'): string
    {
        $root_name = trim(strip_tags($root_name));

        if ($root_name === '') {
            $root_name = 'the selected person';
        }

        if ($wording === 'neutral') {
            return match ($generation) {
                1 => $root_name,
                2 => 'Parents of ' . $root_name,
                3 => 'Grandparents of ' . $root_name,
                4 => 'Great-grandparents of ' . $root_name,
                default => ($generation - 3) . 'x great-grandparents of ' . $root_name,
            };
        }

        return match ($generation) {
            1 => 'You',
            2 => 'Your Parents',
            3 => 'Your Grandparents',
            4 => 'Your Great-grandparents',
            default => 'Your ' . ($generation - 3) . 'x Great-grandparents',
        };
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $by_generation
     * @return array<int,string>
     */
    private function bookIntroduction(Individual $root, array $by_generation, int $generations, int $people_count, string $story_detail, string $mode, string $wording): array
    {
        if ($mode === 'none') {
            return [];
        }

        $root_name = strip_tags($root->fullName());
        $use_you = $wording === 'your';
        $pronouns = $this->pronouns($root->sex(), false);
        $generation_word = $generations === 1 ? 'generation' : 'generations';
        $people_word = $people_count === 1 ? 'recorded person' : 'recorded people';
        $detail_label = match ($story_detail) {
            'summary' => 'This summary version focuses on the main life events and family connections.',
            'research' => 'This research version includes more supporting records, notes and historical clues, so it is more detailed than the general family story.',
            default => 'This detailed version is shaped as a readable family story while still preserving the key facts recorded in the family tree.',
        };

        if ($mode === 'generic') {
            return [
                'This book has been created to tell a family history through the lives of the people recorded in the family tree. It is based on the information currently held in the tree, including names, dates, places, occupations, residences, photographs, family notes and other surviving records.',
                $detail_label . ' Family history is always a work in progress, and future discoveries may add more detail, correct earlier assumptions or bring new stories to light.',
            ];
        }

        $surnames = $this->bookSurnames($by_generation);
        $earliest = $this->earliestBirthYear($by_generation);
        $parts = [];

        if ($surnames !== []) {
            $parts[] = 'The family lines represented include ' . $this->plainJoin($surnames) . '.';
        }

        if ($earliest !== '') {
            $parts[] = 'The known dates in this book reach back to around ' . $earliest . '.';
        }

        $second = $parts !== []
            ? implode(' ', $parts)
            : 'Some ancestors are represented by rich stories, while others are known through only a small number of surviving facts.';

        if ($use_you) {
            $opening = 'This book tells your ancestral story. It follows you and the people who came before you, covering ' . (string) $people_count . ' ' . $people_word . ' across ' . (string) $generations . ' ' . $generation_word . '.';
            $movement = 'It begins with you and then moves back through your parents, grandparents and earlier generations who shaped the family story.';
        } else {
            $opening = 'This book tells the ancestral story of ' . $root_name . '. It begins with ' . $root_name . ' and follows the people who came before ' . (string) $pronouns['object'] . ', covering ' . (string) $people_count . ' ' . $people_word . ' across ' . (string) $generations . ' ' . $generation_word . '.';
            $movement = 'It then moves back through ' . (string) $pronouns['possessive'] . ' parents, grandparents and earlier generations who shaped the family story.';
        }

        return [
            $opening . ' ' . $movement,
            $second . ' The aim is not simply to list names and dates, but to preserve something of where these people lived, the work they did, the families they raised and the records they left behind.',
            $detail_label,
        ];
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $by_generation
     * @return array<int,string>
     */
    private function bookConclusion(Individual $root, array $by_generation, string $mode, string $wording): array
    {
        if ($mode === 'none') {
            return [];
        }

        $root_name = strip_tags($root->fullName());
        $use_you = $wording === 'your';
        $pronouns = $this->pronouns($root->sex(), false);
        $inheritance_phrase = $use_you ? 'you' : $root_name;
        $past_phrase = $use_you ? 'you' : (string) $pronouns['object'];

        if ($mode === 'generic') {
            return [
                'This book is a snapshot of the family history as it is currently known. Some people are represented by many records and stories, while others appear only briefly in the surviving evidence.',
                'As more photographs, documents, memories and records are found, this story can continue to grow. Each new detail helps turn a list of names into a fuller picture of the people who came before.',
            ];
        }

        return [
            'Together, these lives form part of the inheritance behind ' . $inheritance_phrase . '. Some ancestors left clear trails through photographs, letters, occupations, newspaper notices and family stories. Others are represented by only a few dates or places, but they still belong to the wider family journey.',
            'This book should be read as a living family history rather than a finished work. As new records, memories and photographs are discovered, the story of the people who came before ' . $past_phrase . ' can be refined, corrected and passed on with greater understanding to future generations.',
        ];
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $by_generation
     * @return array<int,string>
     */
    private function bookSurnames(array $by_generation): array
    {
        $counts = [];

        foreach ($by_generation as $generation => $profiles) {
            if ((int) $generation > 5) {
                continue;
            }

            foreach ((array) $profiles as $profile) {
                $surname = trim((string) ($profile['surname'] ?? ''));

                if ($surname !== '' && strlen($surname) > 1) {
                    $counts[$surname] = ($counts[$surname] ?? 0) + 1;
                }
            }
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $by_generation
     */
    private function earliestBirthYear(array $by_generation): string
    {
        $earliest = null;

        foreach ($by_generation as $profiles) {
            foreach ((array) $profiles as $profile) {
                $year = (string) ($profile['birth_year'] ?? '');

                if (preg_match('/^\d{4}$/', $year)) {
                    $int_year = (int) $year;
                    $earliest = $earliest === null ? $int_year : min($earliest, $int_year);
                }
            }
        }

        return $earliest === null ? '' : (string) $earliest;
    }

    private function surnameFromName(string $name): string
    {
        $name = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = preg_replace('/\b(J\.?P\.?|B\.?A\.?|M\.?B\.?E\.?|Sr\.?|Jr\.?|Dr\.?|Rev\.?)\b/i', '', $name) ?? $name;
        $name = preg_replace('/[^\pL\pN\s\'-]/u', ' ', $name) ?? $name;
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if ($parts === []) {
            return '';
        }

        return ucfirst(strtolower((string) end($parts)));
    }

    /**
     * @param array<int,string> $items
     */
    private function plainJoin(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
        $count = count($items);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $items[0];
        }

        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[$count - 1];
    }

    /**
     * @return array<int,Individual>
     */
    private function parents(Individual $person): array
    {
        $parents = [];

        foreach ($person->childFamilies() as $family) {
            if ($family instanceof Family) {
                foreach ($family->spouses() as $parent) {
                    if ($parent instanceof Individual && $parent->canShow()) {
                        $parents[] = $parent;
                    }
                }
            }
        }

        return $parents;
    }

    /**
     * @param array<int,string> $path
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function profile(Individual $root, Individual $person, int $distance, array $path, string $direct_child_xref, array $options): array
    {
        $relationship = $this->relationshipLabel($distance, $person->sex(), (string) $options['wording']);
        $birth = $this->firstEvent($person, 'BIRT');
        $notes = ($options['include_notes'] && (($options['story_detail'] ?? 'detailed') === 'research')) ? $this->notes($person) : [];
        $families = $this->spouseFamilyProfiles($person, (bool) $options['include_children'], $distance, $direct_child_xref, (string) $options['wording']);

        return [
            'xref' => $person->xref(),
            'name' => $person->fullName(),
            'relationship' => $relationship,
            'line' => $path !== [] ? implode(' → ', $path) : '',
            'birth_year' => $this->yearFromEvent($birth),
            'birth_place' => $this->shortPlace((string) ($birth['place'] ?? '')),
            'surname' => $this->surnameFromName($person->fullName()),
            'photo_html' => $options['include_media'] ? $person->displayImage(90, 90, 'crop', ['class' => 'nab-photo-img']) : '',
            'paragraphs' => $this->chronologicalNarrativeParagraphs($person, $relationship, $birth, $families, $options),
            'notes' => $notes,
            'families' => $families,
        ];
    }

    /**
     * @param array<string,string> $birth
     * @param array<int,array<string,mixed>> $families
     * @param array<string,mixed> $options
     * @return array<int,array<string,string>>
     */
    private function chronologicalNarrativeParagraphs(Individual $person, string $relationship, array $birth, array $families, array $options): array
    {
        $p = $this->pronouns($person->sex(), $relationship === 'you');
        $paragraphs = [];
        $intro = $this->introParagraph($person, $relationship, $birth, $p);

        if ($intro !== '') {
            $paragraphs[] = ['text' => $intro, 'media_html' => ''];
        }

        $timeline = $this->lifeTimeline($person, $families, $birth, $options);
        $items = [];

        $child_buffer = [];
        $flush_children = function () use (&$items, &$child_buffer, $person, $birth, $p, $options): void {
            if ($child_buffer === []) {
                return;
            }

            if (count($child_buffer) >= 5) {
                $items[] = $this->childBirthGroupItem($child_buffer, $birth, $p, (string) ($options['story_detail'] ?? 'detailed'));
            } else {
                foreach ($child_buffer as $child_item) {
                    $sentence = $this->timelineSentence($person, $child_item, $birth, $p, $options);

                    if ($sentence !== '') {
                        $items[] = [
                            'text' => $sentence,
                            'media_html' => (string) ($child_item['media_html'] ?? ''),
                        ];
                    }
                }
            }

            $child_buffer = [];
        };

        foreach ($timeline as $item) {
            if ((string) ($item['type'] ?? '') === 'child_birth') {
                $child_buffer[] = $item;
                continue;
            }

            $flush_children();
            $sentence = $this->timelineSentence($person, $item, $birth, $p, $options);

            if ($sentence !== '') {
                $items[] = [
                    'text' => $sentence,
                    'media_html' => (string) ($item['media_html'] ?? ''),
                ];
            }
        }

        $flush_children();

        foreach ($this->groupNarrativeItemsIntoParagraphs($items) as $paragraph) {
            $paragraphs[] = $paragraph;
        }

        return $paragraphs;
    }

    /**
     * @return array<int,string>
     */
    private function parentNames(Individual $person): array
    {
        $names = [];

        foreach ($person->childFamilies() as $family) {
            if (!$family instanceof Family || !$family->canShow()) {
                continue;
            }

            foreach ($family->spouses() as $parent) {
                if ($parent instanceof Individual && $parent->canShowName()) {
                    $names[] = strip_tags($parent->fullName());
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Create the opening sentence for a person profile.
     *
     * @param array<string,string> $birth
     * @param array<string,mixed> $p
     */
    private function introParagraph(Individual $person, string $relationship, array $birth, array $p): string
    {
        $name = strip_tags($person->fullName());
        $when_where = $birth !== [] ? $this->eventWhenWhere($birth) : '';
        $is_self = !empty($p['is_self']);
        $sentences = [];

        if ($when_where !== '') {
            if ($is_self) {
                $sentences[] = 'You were born' . $when_where . '.';
            } else {
                $sentences[] = ucfirst($relationship) . ', ' . $name . ', was born' . $when_where . '.';
            }
        } else {
            if ($is_self) {
                $sentences[] = 'This story begins with you.';
            } else {
                $sentences[] = 'Only limited details are recorded for ' . $relationship . ', ' . $name . '.';
            }
        }

        $parents = $this->parentNames($person);

        if ($parents !== []) {
            $parent_text = $this->joinNames($parents);

            if ($is_self) {
                $sentences[] = 'Your parents were ' . $parent_text . '.';
            } elseif (count($parents) === 1) {
                $sentences[] = $p['possessive_cap'] . ' parent was ' . $parent_text . '.';
            } else {
                $sentences[] = $p['possessive_cap'] . ' parents were ' . $parent_text . '.';
            }
        }

        return implode(' ', $sentences);
    }

    /**
     * @param array<int,string> $names
     */
    private function joinNames(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names)));
        $count = count($names);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $names[0];
        }

        if ($count === 2) {
            return $names[0] . ' and ' . $names[1];
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * @param array<int,array<string,string>> $items
     * @return array<int,array<string,string>>
     */
    private function groupNarrativeItemsIntoParagraphs(array $items): array
    {
        $paragraphs = [];
        $text_buffer = [];
        $text_length = 0;
        $media_text_buffer = [];
        $media_html_buffer = [];

        $flush_text = static function () use (&$paragraphs, &$text_buffer, &$text_length): void {
            if ($text_buffer !== []) {
                $paragraphs[] = [
                    'text' => implode(' ', $text_buffer),
                    'media_html' => '',
                    'kind' => 'text',
                ];
                $text_buffer = [];
                $text_length = 0;
            }
        };

        $flush_media = static function () use (&$paragraphs, &$media_text_buffer, &$media_html_buffer): void {
            if ($media_text_buffer !== [] || $media_html_buffer !== []) {
                $paragraphs[] = [
                    'text' => implode(' ', $media_text_buffer),
                    'media_html' => implode('', $media_html_buffer),
                    'kind' => 'media',
                ];
                $media_text_buffer = [];
                $media_html_buffer = [];
            }
        };

        foreach ($items as $item) {
            $sentence = trim((string) ($item['text'] ?? ''));
            $media_html = trim((string) ($item['media_html'] ?? ''));

            if ($sentence === '') {
                continue;
            }

            if ($media_html !== '') {
                $flush_text();
                $media_text_buffer[] = $sentence;
                $media_html_buffer[] = $media_html;

                // Keep photo groups compact. Four photo events is a good size for a single row/grid in the book.
                if (count($media_html_buffer) >= 4) {
                    $flush_media();
                }

                continue;
            }

            $flush_media();
            $text_buffer[] = $sentence;
            $text_length += strlen($sentence);

            if (count($text_buffer) >= 3 || $text_length >= 650) {
                $flush_text();
            }
        }

        $flush_media();
        $flush_text();

        return $paragraphs;
    }

    private function lifeTimeline(Individual $person, array $families, array $birth, array $options): array
    {
        $items = [];
        $order = 0;

        $addEvents = function (array $events, string $type) use (&$items, &$order, $person, $options): void {
            foreach ($events as $event) {
                $items[] = [
                    'type' => $type,
                    'event' => $event,
                    'media_html' => !empty($options['include_media']) ? $this->eventMediaHtml($event, $person, $type) : '',
                    'sort' => $this->sortKeyForEvent($event, $order++),
                ];
            }
        };

        $addEvents($this->events($person, ['CHR', 'BAPM']), 'christening');

        if (!empty($options['include_migration'])) {
            $addEvents($this->events($person, ['EMIG', 'IMMI', 'NATU']), 'migration');
        }

        if (!empty($options['include_education'])) {
            $addEvents($this->events($person, ['EDUC', 'GRAD']), 'education');
        }

        if (!empty($options['include_occupations'])) {
            $addEvents($this->events($person, ['OCCU']), 'occupation');
        }

        if (!empty($options['include_residences'])) {
            $addEvents($this->events($person, ['RESI', 'CENS']), 'residence');
        }

        $addEvents($this->events($person, ['MILI', '_MILI']), 'military');
        $addEvents($this->events($person, ['PROP', 'WILL', 'PROB', 'RELI', 'EVEN']), 'life_event');

        foreach ($families as $family) {
            $marriage = (array) ($family['marriage'] ?? []);
            $family_sort_base = 80000000 + $order;

            if ($marriage !== []) {
                $family_sort_base = $this->sortKeyForEvent($marriage, $order++);
                $items[] = [
                    'type' => 'marriage',
                    'event' => $marriage,
                    'spouse_name' => (string) ($family['spouse_name'] ?? I18N::translate('an unknown spouse')),
                    'media_html' => !empty($options['include_media']) ? $this->eventMediaHtml($marriage, $person, 'marriage') : '',
                    'sort' => $family_sort_base,
                ];
            } elseif ((string) ($family['spouse_name'] ?? '') !== '') {
                $family_sort_base = 80000000 + $order++;
                $items[] = [
                    'type' => 'marriage_unknown_date',
                    'event' => [],
                    'spouse_name' => (string) ($family['spouse_name'] ?? I18N::translate('an unknown spouse')),
                    'sort' => $family_sort_base,
                ];
            }

            if (!empty($options['include_children'])) {
                $child_index = 0;
                foreach ((array) ($family['children'] ?? []) as $child) {
                    $child_birth = (array) ($child['birth'] ?? []);
                    $child_adoption = (array) ($child['adoption'] ?? []);
                    $child_family_status = (string) ($child['family_status'] ?? '');
                    $is_care_child = in_array($child_family_status, ['adopted', 'raised', 'foster'], true);
                    $sort_event = $is_care_child && (!empty($child_adoption['date']) || !empty($child_adoption['place'])) ? $child_adoption : $child_birth;
                    $child_sort = $this->sortKeyForEvent($sort_event, $order++);

                    if (empty($sort_event['date']) && empty($sort_event['place']) && $family_sort_base < 80000000) {
                        $child_sort = $family_sort_base + 1 + $child_index;
                    }

                    ++$child_index;

                    $items[] = [
                        'type' => $is_care_child ? 'child_care' : 'child_birth',
                        'event' => $is_care_child ? $sort_event : $child_birth,
                        'birth_event' => $child_birth,
                        'adoption_event' => $child_adoption,
                        'child_family_status' => $child_family_status,
                        'child_name' => (string) ($child['name'] ?? I18N::translate('a child')),
                        'child_relationship' => (string) ($child['relationship'] ?? ''),
                        'child_role' => (string) ($child['role'] ?? I18N::translate('child')),
                        'spouse_name' => (string) ($family['spouse_name'] ?? ''),
                        'media_html' => !empty($options['include_media']) ? $this->eventMediaHtml($child_birth, $person, 'child_birth') : '',
                        'sort' => $child_sort,
                    ];
                }
            }
        }

        $addEvents($this->events($person, ['DEAT']), 'death');
        $addEvents($this->events($person, ['BURI', 'CREM']), 'burial');

        usort($items, static function (array $a, array $b): int {
            return ((int) $a['sort']) <=> ((int) $b['sort']);
        });

        $detail = (string) ($options['story_detail'] ?? 'detailed');
        $items = array_values(array_filter($items, function (array $item) use ($detail): bool {
            return $this->shouldIncludeTimelineItem($item, $detail);
        }));

        $items = $this->deduplicateTimelineItems($items);
        $items = $this->combineReligionTimelineItems($items);

        if ($detail === 'summary') {
            $items = $this->reduceSummaryTimelineItems($items);
        }

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function combineReligionTimelineItems(array $items): array
    {
        $religion_indexes = [];
        $religions = [];
        $first_sort = null;

        foreach ($items as $index => $item) {
            $event = (array) ($item['event'] ?? []);

            if ((string) ($item['type'] ?? '') !== 'life_event' || (string) ($event['tag'] ?? '') !== 'RELI') {
                continue;
            }

            $religion_indexes[] = $index;
            $first_sort = $first_sort === null ? (int) ($item['sort'] ?? 0) : min($first_sort, (int) ($item['sort'] ?? 0));
            $religion = $this->displayEventLabel((string) ($event['value'] ?? ''));

            if ($religion !== '' && $religion !== 'this event') {
                $key = mb_strtolower($religion);
                $religions[$key] = $religion;
            }
        }

        if (count($religion_indexes) < 2) {
            return $items;
        }

        $first_index = $religion_indexes[0];
        $skip = array_fill_keys($religion_indexes, true);
        $combined = [];

        foreach ($items as $index => $item) {
            if ($index === $first_index) {
                $combined[] = [
                    'type' => 'religion_group',
                    'event' => [],
                    'religions' => array_values($religions),
                    'media_html' => '',
                    'sort' => $first_sort ?? (int) ($item['sort'] ?? 0),
                ];
            }

            if (isset($skip[$index])) {
                continue;
            }

            $combined[] = $item;
        }

        return $combined;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function deduplicateTimelineItems(array $items): array
    {
        $seen = [];
        $clean = [];

        foreach ($items as $item) {
            $key = $this->timelineDeduplicationKey($item);

            if ($key !== '' && isset($seen[$key])) {
                continue;
            }

            if ($key !== '') {
                $seen[$key] = true;
            }

            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function timelineDeduplicationKey(array $item): string
    {
        $type = (string) ($item['type'] ?? '');
        $event = (array) ($item['event'] ?? []);
        $date = strtoupper(trim((string) ($event['date'] ?? '')));
        $place = mb_strtolower($this->narrativePlace((string) ($event['place'] ?? '')));
        $value = mb_strtolower($this->cleanFactValue((string) ($event['value'] ?? '')));
        $note = mb_strtolower($this->cleanNoteText((string) ($event['note'] ?? '')));
        $child = mb_strtolower(strip_tags((string) ($item['child_name'] ?? '')));

        $value = preg_replace('/\.(pdf|jpg|jpeg|png|gif|mp3|wav)\b/i', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
        $note = preg_replace('/[^a-z0-9]+/i', ' ', $note) ?? $note;
        $value = trim($value);
        $note = trim($note);

        if ($type === 'child_birth') {
            return implode('|', [$type, $date, $child]);
        }

        return implode('|', [$type, $date, $place, $value, mb_substr($note, 0, 80)]);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function reduceSummaryTimelineItems(array $items): array
    {
        $result = [];
        $occupation_categories = [];
        $occupation_count = 0;

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');

            if ($type === 'occupation') {
                $event = (array) ($item['event'] ?? []);
                $category = $this->occupationSummaryCategory((string) ($event['value'] ?? ''));

                if ($category !== '' && isset($occupation_categories[$category])) {
                    continue;
                }

                if ($category !== '') {
                    $occupation_categories[$category] = true;
                }

                ++$occupation_count;

                if ($occupation_count > 4) {
                    continue;
                }
            }

            $result[] = $item;
        }

        return $result;
    }

    private function occupationSummaryCategory(string $value): string
    {
        $lower = $this->plainLower($value);

        if ($lower === '') {
            return '';
        }

        if (str_contains($lower, 'special')) {
            return 'special education';
        }

        if (str_contains($lower, 'head teacher') || str_contains($lower, 'teacher')) {
            return 'teaching';
        }

        if (str_contains($lower, 'photograph')) {
            return 'photography';
        }

        if (str_contains($lower, 'pastor')) {
            return 'pastoral ministry';
        }

        if (str_contains($lower, 'farmer')) {
            return 'farming';
        }

        if (str_contains($lower, 'home duties') || str_contains($lower, 'at home')) {
            return 'home duties';
        }

        return preg_replace('/[^a-z0-9]+/i', ' ', $lower) ?? $lower;
    }


    /**
     * @param array<string,mixed> $item
     */
    private function shouldIncludeTimelineItem(array $item, string $detail): bool
    {
        if ($detail === 'research') {
            return true;
        }

        $type = (string) ($item['type'] ?? '');

        if ($detail === 'summary') {
            return in_array($type, ['migration', 'education', 'occupation', 'military', 'marriage', 'child_birth', 'child_care', 'death', 'burial'], true);
        }

        if ($type !== 'life_event') {
            return true;
        }

        $event = (array) ($item['event'] ?? []);
        $label = mb_strtolower($this->cleanFactValue((string) ($event['value'] ?? '')) . ' ' . $this->cleanFactValue((string) ($event['note'] ?? '')));

        // Detailed mode keeps the family story flowing and leaves minor legal/source-only items to Research mode.
        foreach (['debt', 'garnishee', 'court', 'accused', 'police fine', 'easement', 'in memory'] as $minor) {
            if (str_contains($label, $minor)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,string> $birth
     * @param array<string,string> $p
     */
    private function timelineSentence(Individual $person, array $item, array $birth, array $p, array $options = []): string
    {
        $event = (array) ($item['event'] ?? []);
        $type = (string) ($item['type'] ?? '');
        $lead = $this->dateLead($event['date'] ?? '');
        $place = $this->placePhrase($event);
        $residence_place = $this->residencePlacePhrase($event);
        $value = $this->cleanFactValue((string) ($event['value'] ?? ''));
        $note = $this->eventNoteSentence($event);
        $sentence = '';

        switch ($type) {
            case 'christening':
                $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' christened' . $place . '.');
                break;

            case 'migration':
                $tag = (string) ($event['tag'] ?? '');
                $migration_place = $this->migrationPlacePhrase($event, $tag);
                if ($tag === 'EMIG') {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' left' . $migration_place . $this->adultAgePhrase($event, $birth) . '.');
                } elseif ($tag === 'IMMI') {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' arrived' . $migration_place . $this->adultAgePhrase($event, $birth) . '.');
                } elseif ($tag === 'NATU') {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' became naturalised' . $migration_place . $this->adultAgePhrase($event, $birth) . '.');
                }
                break;

            case 'education':
                $sentence = $this->sentenceWithLead($lead, $this->educationSentenceBody($value, $place, $p));
                break;

            case 'occupation':
                if ($value !== '') {
                    $occupation = $this->occupationText($value);
                    if ($occupation === '') {
                        break;
                    }
                    $plain_place = $this->narrativePlace(trim($event['place'] ?? ''));

                    if ($plain_place !== '' && preg_match('/special education teacher|teacher at a special school/i', $occupation) && preg_match('/Special School/i', $plain_place)) {
                        $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' worked as a special education teacher at ' . $plain_place . $this->youthAgePhrase($event, $birth) . '.');
                    } elseif (in_array($occupation, ['home duties', 'at home'], true)) {
                        $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' recorded as undertaking ' . $occupation . $place . '.');
                    } else {
                        $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' worked as ' . $occupation . $place . $this->youthAgePhrase($event, $birth) . '.');
                    }
                } else {
                    // Empty occupation facts rarely add narrative value.
                    $sentence = '';
                }
                break;

            case 'residence':
                if ($residence_place !== '') {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' lived' . $residence_place . '.');
                }
                break;

            case 'military':
                if ($value !== '') {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' served with ' . $this->displayEventLabel($value) . $place . $this->adultAgePhrase($event, $birth) . '.');
                } else {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' served in the military' . $place . $this->adultAgePhrase($event, $birth) . '.');
                }
                break;

            case 'religion_group':
                $religions = array_values(array_filter(array_map('strval', (array) ($item['religions'] ?? [])), static fn (string $religion): bool => trim($religion) !== ''));

                if ($religions !== []) {
                    $sentence = $p['possessive_cap'] . ' religious affiliations were recorded as ' . $this->plainJoin($religions) . '.';
                } else {
                    $sentence = $p['possessive_cap'] . ' religious life was recorded in more than one family-tree entry.';
                }
                break;

            case 'life_event':
                $sentence = $this->lifeEventSentence($event, $value, $lead, $place, $residence_place, $p, $birth);
                break;

            case 'marriage':
                $spouse = strip_tags((string) ($item['spouse_name'] ?? I18N::translate('an unknown spouse')));
                if ($spouse !== '' && $spouse !== I18N::translate('an unknown spouse')) {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' married ' . $spouse . $place . $this->eventAgePhrase($event, $birth) . '.');
                }
                break;

            case 'marriage_unknown_date':
                $spouse = strip_tags((string) ($item['spouse_name'] ?? I18N::translate('an unknown spouse')));
                if ($spouse !== '' && $spouse !== I18N::translate('an unknown spouse')) {
                    $sentence = $p['subject_cap'] . ' married ' . $spouse . '.';
                }
                break;

            case 'child_birth':
                $sentence = $this->childBirthSentence($item, $event, $birth, $lead, $place, $p);
                break;

            case 'child_care':
                $sentence = $this->childCareSentence($person, $item, $birth, $p);
                break;

            case 'death':
                if ($lead !== '' || $place !== '' || $this->eventAgePhrase($event, $birth) !== '') {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' died' . $place . $this->eventAgePhrase($event, $birth) . '.');
                }
                break;

            case 'burial':
                if ($lead !== '' || $place !== '') {
                    $sentence = $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' ' . (($event['tag'] ?? '') === 'CREM' ? 'cremated' : 'buried') . $place . '.');
                }
                break;
        }

        $show_note = !empty($options['include_notes']) && ((string) ($options['story_detail'] ?? 'detailed') !== 'summary');

        if ($sentence !== '' && $note !== '' && $show_note) {
            $sentence .= ' ' . $note;
        }

        return $sentence;
    }

    /**
     * @param array<string,string> $event
     */
    private function lifeEventSentence(array $event, string $value, string $lead, string $place, string $residence_place, array $p, array $birth): string
    {
        $tag = (string) ($event['tag'] ?? 'EVEN');
        $label = $value !== '' ? $value : $this->labelForTag($tag);
        $clean_label = $this->cleanFactValue($label);
        $lower = mb_strtolower($clean_label);
        $age = $this->adultAgePhrase($event, $birth);

        if ($tag === 'RELI') {
            $religion = $this->displayEventLabel($value);
            return $religion !== 'this event'
                ? $this->sentenceWithLead($lead, $p['possessive_cap'] . ' religious affiliation was recorded as ' . $religion . $place . '.')
                : $this->sentenceWithLead($lead, $p['possessive_cap'] . ' religious life was part of the story' . $place . '.');
        }

        if ($this->isPhotoLikeLabel($lower) || in_array($lower, ['cousins'], true) || str_contains($lower, 'wedding') || str_contains($lower, 'velma and noela')) {
            $photo_label = $this->displayEventLabel($clean_label);
            $at = '';

            if (in_array($lower, ['cousins'], true)) {
                $at = ' with cousins';
            } elseif (str_contains($lower, 'wedding')) {
                $at = ' at ' . (str_contains($lower, 'photo') ? 'a wedding' : $photo_label);
            }

            return $this->sentenceWithLead($lead, 'a family photograph shows ' . $p['object'] . $at . $residence_place . '.');
        }

        if ($this->isLetterLikeLabel($lower)) {
            return $this->sentenceWithLead($lead, 'a surviving family letter or card mentions ' . $p['object'] . $residence_place . '.');
        }

        if (str_contains($lower, 'newspaper') || str_contains($lower, 'argus') || str_contains($lower, 'guardian') || str_contains($lower, 'herald') || str_contains($lower, 'advocate')) {
            return $this->sentenceWithLead($lead, 'a newspaper item recorded ' . $p['object'] . $residence_place . $age . '.');
        }

        if (str_contains($lower, "son's in wwi") || str_contains($lower, 'sons in wwi')) {
            return $this->sentenceWithLead($lead, 'a family photograph or record connects ' . $p['object'] . ' with ' . $p['possessive'] . ' sons’ WWI service' . $place . $age . '.');
        }

        if (str_contains($lower, 'royal australian flying corps') || str_contains($lower, 'australian flying corps') || str_contains($lower, 'australian imperial force') || str_contains($lower, 'army') || str_contains($lower, 'wwi') || str_contains($lower, 'world war')) {
            return $this->sentenceWithLead($lead, $p['subject'] . ' served with ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
        }

        if (str_contains($lower, 'presented with medal') || str_contains($lower, 'from ior')) {
            return $this->sentenceWithLead($lead, $p['subject'] . ' was presented with a medal by the Independent Order of Rechabites' . $place . $age . '.');
        }

        if (str_contains($lower, 'first flight')) {
            return $this->sentenceWithLead($lead, 'a newspaper report recorded ' . $p['possessive'] . ' first flight' . $place . $age . '.');
        }

        if (str_contains($lower, 'recording') || str_contains($lower, '.mp3') || str_contains($lower, '.wav')) {
            return $this->sentenceWithLead($lead, 'an audio recording preserves a family memory of ' . $p['object'] . $place . $age . '.');
        }

        if (str_contains($lower, 'starrit') && str_contains($lower, 'rankin')) {
            return $this->sentenceWithLead($lead, 'a family photograph connects ' . $p['object'] . ' with the Starritt and Rankin families' . $place . $age . '.');
        }

        if (str_contains($lower, 'lacrosse') || str_contains($lower, 'football') || str_contains($lower, 'tennis') || str_contains($lower, 'cricket')) {
            $label = $this->displayEventLabel($clean_label);

            if (str_contains($lower, 'captain of lacrosse')) {
                return $this->sentenceWithLead($lead, $p['subject'] . ' captained the lacrosse team at Melbourne High School' . $place . $age . '.');
            }

            if (str_contains($lower, 'secretary')) {
                return $this->sentenceWithLead($lead, $p['subject'] . ' served as secretary of the tennis association' . $place . $age . '.');
            }

            if (str_contains($lower, 'champion')) {
                return $this->sentenceWithLead($lead, $p['subject'] . ' was recognised for ' . $label . $place . $age . '.');
            }

            return $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' involved with ' . $label . $place . $age . '.');
        }

        if (str_contains($lower, 'councillor') || str_contains($lower, 'council') || str_contains($lower, 'justice of the peace') || str_contains($lower, 'association') || str_contains($lower, 'president') || str_contains($lower, 'secretary')) {
            $label = $this->displayEventLabel($clean_label);

            if (str_contains($lower, 'councillor')) {
                return $this->sentenceWithLead($lead, $p['subject'] . ' served as a councillor' . $place . $age . '.');
            }

            if (str_contains($lower, 'justice of the peace')) {
                return $this->sentenceWithLead($lead, $p['subject'] . ' served as a Justice of the Peace' . $place . $age . '.');
            }

            if (str_contains($lower, 'elected vice president')) {
                return $this->sentenceWithLead($lead, $p['subject'] . ' was elected vice-president of the Undera Sports Association' . $place . $age . '.');
            }

            if (str_contains($lower, 'secretary')) {
                return $this->sentenceWithLead($lead, $p['subject'] . ' served as secretary of ' . $label . $place . $age . '.');
            }

            return $this->sentenceWithLead($lead, $p['subject'] . ' took part in community life through ' . $label . $place . $age . '.');
        }

        if (str_contains($lower, 'painting competition') || str_contains($lower, 'competition')) {
            return $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' mentioned in ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
        }

        if (str_contains($lower, 'birthday')) {
            return $this->sentenceWithLead($lead, 'a birthday card or family record marks this time in ' . $p['possessive'] . ' life' . $place . $age . '.');
        }

        if (str_contains($lower, 'funeral')) {
            return $this->sentenceWithLead($lead, 'a family photograph or record connects ' . $p['object'] . ' with ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
        }

        if (str_contains($lower, 'house fire')) {
            return $this->sentenceWithLead($lead, 'a family or newspaper record describes a house fire involving ' . $p['object'] . $place . $age . '.');
        }

        if (str_contains($lower, 'goes missing') || str_contains($lower, 'went missing')) {
            return $this->sentenceWithLead($lead, 'a newspaper report recorded that ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
        }

        if (str_contains($lower, 'theft') || str_contains($lower, 'conviction')) {
            return $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' convicted or charged in relation to ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
        }

        if (str_contains($lower, 'disobedience') || str_contains($lower, 'insolence')) {
            return $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' disciplined for ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
        }

        if (str_contains($lower, 'debt') || str_contains($lower, 'court') || str_contains($lower, 'accused') || str_contains($lower, 'police') || str_contains($lower, 'fine') || str_contains($lower, 'easement') || str_contains($lower, 'insolvency')) {
            return $this->sentenceWithLead($lead, 'a legal or newspaper record mentions ' . $p['object'] . ' in relation to ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
        }

        if (str_contains($lower, 'anniversary') || str_contains($lower, 'anniverary')) {
            return $this->sentenceWithLead($lead, 'a family or newspaper record marked an anniversary involving ' . $p['object'] . $place . $age . '.');
        }

        if (str_contains($lower, 'memory') || str_contains($lower, 'memorial')) {
            return $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' remembered in a memorial notice' . $place . '.');
        }

        if (str_contains($lower, 'presented with medal') || str_contains($lower, 'ior')) {
            return $this->sentenceWithLead($lead, $p['subject'] . ' was presented with a medal by the Independent Order of Rechabites' . $place . $age . '.');
        }

        if (str_contains($lower, 'first flight')) {
            return $this->sentenceWithLead($lead, 'a newspaper report recorded ' . $p['possessive'] . ' first flight' . $place . $age . '.');
        }

        if (in_array($lower, ['even', 'event'], true)) {
            return $this->sentenceWithLead($lead, 'a newspaper or family record mentioned ' . $p['object'] . $place . $age . '.');
        }

        if ($tag === 'PROP') {
            return $this->sentenceWithLead($lead, $p['subject'] . ' held property or had a property interest' . $place . $age . '.');
        }

        if ($tag === 'WILL') {
            return $this->sentenceWithLead($lead, $p['subject'] . ' made or was named in a will' . $place . $age . '.');
        }

        if ($tag === 'PROB') {
            return $this->sentenceWithLead($lead, 'probate was granted for ' . $p['object'] . $place . '.');
        }

        return $this->sentenceWithLead($lead, $p['subject'] . ' ' . $p['was'] . ' connected with ' . $this->displayEventLabel($clean_label) . $place . $age . '.');
    }


    /**
     * @param array<string,mixed> $item
     * @param array<string,string> $event
     * @param array<string,string> $birth
     * @param array<string,string> $p
     */
    private function childBirthSentence(array $item, array $event, array $birth, string $lead, string $place, array $p): string
    {
        $child_name = strip_tags((string) ($item['child_name'] ?? I18N::translate('a child')));
        $child_role = (string) ($item['child_role'] ?? I18N::translate('child'));
        $child_relationship = trim((string) ($item['child_relationship'] ?? ''));
        $spouse = strip_tags((string) ($item['spouse_name'] ?? ''));
        $relationship_text = ($child_relationship !== '' && !in_array($child_relationship, ['your child', 'the selected person’s child'], true)) ? ', ' . $child_relationship . ',' : '';
        $parent_age = $this->parentAgeAtChildBirthPhrase($event, $birth, $p);

        if ($spouse !== '' && $spouse !== I18N::translate('an unknown spouse')) {
            $family_possessive = !empty($p['is_self']) ? $p['possessive'] : 'their';
            $body = $p['subject'] . ' and ' . $spouse . ' welcomed ' . $family_possessive . ' ' . $child_role . ' ' . $child_name . $relationship_text . $place . $parent_age . '.';
        } else {
            $possessive = $lead !== '' ? $p['possessive'] : $p['possessive_cap'];
            $body = $possessive . ' ' . $child_role . ' ' . $child_name . $relationship_text . ' was born' . $place . $parent_age . '.';
        }

        return $this->sentenceWithLead($lead, $body);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,string> $birth
     * @param array<string,string> $p
     */
    private function childCareSentence(Individual $person, array $item, array $birth, array $p): string
    {
        $child_name = strip_tags((string) ($item['child_name'] ?? I18N::translate('a child')));
        $child_relationship = trim((string) ($item['child_relationship'] ?? ''));
        $spouse = strip_tags((string) ($item['spouse_name'] ?? ''));
        $status = (string) ($item['child_family_status'] ?? '');
        $birth_event = (array) ($item['birth_event'] ?? []);
        $adoption_event = (array) ($item['adoption_event'] ?? []);
        $birth_when_where = $this->eventWhenWhere($birth_event);
        $adoption_when_where = $this->eventWhenWhere($adoption_event);

        $relationship_suffix = '';
        if ($child_relationship !== '' && !in_array($child_relationship, ['your child', 'the selected person’s child'], true)) {
            $relationship_suffix = ', ' . trim($child_relationship, ' ,');
        }

        if (!empty($p['is_self'])) {
            $parents = $spouse !== '' && $spouse !== I18N::translate('an unknown spouse') ? 'you and ' . $spouse : 'you';
        } else {
            $parent_name = strip_tags($person->fullName());
            $parents = $spouse !== '' && $spouse !== I18N::translate('an unknown spouse') ? $parent_name . ' and ' . $spouse : $parent_name;
        }

        $child_subject = $child_name . $relationship_suffix;
        $birth_clause = $birth_when_where !== '' ? $child_subject . ' was born' . $birth_when_where : $child_subject;

        if ($status === 'adopted') {
            if ($adoption_when_where !== '') {
                return $this->cleanSentence($birth_clause . ' and was adopted by ' . $parents . $adoption_when_where . '.');
            }

            if ($birth_when_where !== '') {
                return $this->cleanSentence($birth_clause . ' and was later adopted by ' . $parents . '.');
            }

            return $this->cleanSentence($child_subject . ' was adopted by ' . $parents . '.');
        }

        if ($status === 'foster') {
            if ($birth_when_where !== '') {
                return $this->cleanSentence($birth_clause . ' and was later fostered by ' . $parents . '.');
            }

            return $this->cleanSentence($child_subject . ' was fostered by ' . $parents . '.');
        }

        if ($birth_when_where !== '') {
            return $this->cleanSentence($birth_clause . ' and was brought up by ' . $parents . '. The record does not show a formal adoption.');
        }

        return $this->cleanSentence($child_subject . ' was brought up by ' . $parents . '. The record does not show a formal adoption.');
    }

    /**
     * @param array<int,array<string,mixed>> $child_items
     * @param array<string,string> $birth
     * @param array<string,string> $p
     * @return array<string,string>
     */
    private function childBirthGroupItem(array $child_items, array $birth, array $p, string $detail_level = 'detailed'): array
    {
        $first = $child_items[0] ?? [];
        $spouse = strip_tags((string) ($first['spouse_name'] ?? ''));
        $count = count($child_items);

        $has_adopted_children = false;

        foreach ($child_items as $item) {
            if ((string) ($item['child_family_status'] ?? '') === 'adopted') {
                $has_adopted_children = true;
                break;
            }
        }

        if ($has_adopted_children) {
            if ($spouse !== '' && $spouse !== I18N::translate('an unknown spouse')) {
                $intro = !empty($p['is_self'])
                    ? 'Your family with ' . $spouse . ' included ' . $this->numberWord($count) . ' known children'
                    : $p['possessive_cap'] . ' family with ' . $spouse . ' included ' . $this->numberWord($count) . ' known children';
            } else {
                $intro = $p['possessive_cap'] . ' family included ' . $this->numberWord($count) . ' known children';
            }
        } elseif ($spouse !== '' && $spouse !== I18N::translate('an unknown spouse')) {
            $intro = !empty($p['is_self'])
                ? 'Together, you and ' . $spouse . ' had ' . $this->numberWord($count) . ' known children'
                : 'Together, ' . $p['subject'] . ' and ' . $spouse . ' had ' . $this->numberWord($count) . ' known children';
        } else {
            $intro = $p['possessive_cap'] . ' known children were';
        }

        $details = [];
        $direct_details = [];
        $media_html = [];

        foreach ($child_items as $item) {
            $event = (array) ($item['event'] ?? []);
            $name = strip_tags((string) ($item['child_name'] ?? I18N::translate('a child')));
            $relationship = trim((string) ($item['child_relationship'] ?? ''));
            $is_adopted = ((string) ($item['child_family_status'] ?? '')) === 'adopted';
            $adoption_event = (array) ($item['adoption_event'] ?? []);
            $adoption_when_where = $this->eventWhenWhere($adoption_event);
            $when_where = $this->eventWhenWhere($event);
            $age_text = $is_adopted && $adoption_when_where !== ''
                ? $this->parentAgeAtChildBirthCompact($adoption_event, $birth, $p)
                : ($is_adopted ? '' : $this->parentAgeAtChildBirthCompact($event, $birth, $p));
            $detail = $name;

            if ($is_adopted) {
                $detail .= ', adopted ' . $this->childRoleWithFamilyStatus((string) ($item['child_role'] ?? I18N::translate('child')), 'plain');

                if ($relationship !== '' && !in_array($relationship, ['your child', 'the selected person’s child'], true)) {
                    $detail .= ', ' . trim($relationship, ' ,');
                }

                if ($adoption_when_where !== '') {
                    $detail .= ', adopted' . $adoption_when_where;
                }

                if ($when_where !== '') {
                    $detail .= ', born' . $when_where;
                }
            } else {
                if ($relationship !== '' && !in_array($relationship, ['your child', 'the selected person’s child'], true)) {
                    $detail .= ', ' . trim($relationship, ' ,');
                }

                if ($when_where !== '') {
                    $detail .= ', born' . $when_where;
                }
            }

            if ($age_text !== '') {
                $detail .= ' (' . $age_text . ')';
            }

            $details[] = $detail;
            $relationship_lower = mb_strtolower($relationship);

            if ($relationship !== '' && preg_match('/\b(aunt|uncle|brother|sister|sibling)\b/i', $relationship_lower) !== 1) {
                $direct_details[] = $detail;
            }

            $media = trim((string) ($item['media_html'] ?? ''));

            if ($media !== '') {
                $media_html[] = $media;
            }
        }

        $details_for_text = $details;

        if ($detail_level === 'summary' && $count >= 5) {
            $details_for_text = $direct_details !== [] ? $direct_details : array_slice($details, 0, 3);
        }

        $text = $intro . ': ' . $this->joinNames($details_for_text) . '.';

        if ($count >= 5) {
            $text = $intro . '. They included ' . $this->joinNames($details_for_text) . '.';
        }

        return [
            'text' => $this->cleanSentence($text),
            'media_html' => implode('', $media_html),
        ];
    }



    private function numberWord(int $number): string
    {
        return match ($number) {
            0 => 'no',
            1 => 'one',
            2 => 'two',
            3 => 'three',
            4 => 'four',
            5 => 'five',
            6 => 'six',
            7 => 'seven',
            8 => 'eight',
            9 => 'nine',
            10 => 'ten',
            11 => 'eleven',
            12 => 'twelve',
            default => (string) $number,
        };
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $birth
     * @param array<string,string> $p
     */
    private function parentAgeAtChildBirthCompact(array $event, array $birth, array $p): string
    {
        $age = $this->ageAtEvent($event, $birth);

        if ($age === null) {
            return '';
        }

        return $p['subject'] . ' ' . $p['was'] . ' ' . $this->ageText($age, $event, $birth);
    }

    private function isPhotoLikeLabel(string $lower): bool
    {
        return str_contains($lower, 'photo')
            || str_contains($lower, 'photograph')
            || str_contains($lower, 'portrait')
            || str_contains($lower, 'image')
            || str_contains($lower, '.jpg')
            || str_contains($lower, '.jpeg')
            || str_contains($lower, '.png');
    }

    private function isLetterLikeLabel(string $lower): bool
    {
        return str_contains($lower, 'letter')
            || str_contains($lower, 'postcard')
            || str_contains($lower, 'post card')
            || str_contains($lower, 'card from')
            || str_contains($lower, 'birthday card');
    }

    private function displayEventLabel(string $value): string
    {
        $value = $this->cleanFactValue($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim($value, " \t\n\r\0\x0B.-");

        if ($value === '') {
            return 'this event';
        }

        $lower = mb_strtolower(preg_replace('/\s+/', ' ', $value) ?? $value);
        $map = [
            'royal australian flying corps' => 'the Royal Australian Flying Corps',
            'australian flying corps' => 'the Australian Flying Corps',
            'australian imperial force' => 'the Australian Imperial Force',
            'salvation army' => 'the Salvation Army',
            'church of england' => 'Church of England',
            'cofa' => 'Church of England',
            'full gospel protestant' => 'Full Gospel Protestant',
            'melbourne high school' => 'Melbourne High School',
            'lowanna college councillor' => 'Lowanna College council',
            'justice of the peace' => 'Justice of the Peace service',
            'justice of the peace in the act' => 'Justice of the Peace service in the ACT',
            'charlie and beryl\'s wedding' => 'Charlie and Beryl\'s wedding',
            'wedding photo' => 'a wedding',
            'presented with medal from ior' => 'a medal presented by the Independent Order of Rechabites',
            'first flight' => 'first flight',
            'captain of lacrosse team at melbourne high school' => 'captain of the lacrosse team at Melbourne High School',
            'councillor' => 'councillor',
            'son\'s in wwi' => 'sons in WWI',
            'sons in wwi' => 'sons in WWI',
            'oh velma and noela' => 'O.H., Velma and Noela',
            'funeral of o.h' => 'O.H. Potts’ funeral',
            'administered brother-in-law\'s will' => 'administering his brother-in-law’s will',
            'jim goes missing' => 'Jim went missing',
            'painting competition' => 'a painting competition',
            'home duties' => 'home duties',
            'at home' => 'at home',
            '44 transport squadron army reserve' => '44 Transport Squadron, Army Reserve',
            'undera football club' => 'the Undera Football Club',
            'elected vice president undera sports association' => 'the Undera Sports Association',
            'secretary of tennis association' => 'the tennis association',
            'tennis - women\'s singles champion' => 'tennis - women\'s singles champion',
            'starrit and rankin families - estimated 1918' => 'the Starritt and Rankin families',
            'starritt and rankin families - estimated 1918' => 'the Starritt and Rankin families',
            'move to drouin' => 'Drouin',
        ];

        if (isset($map[$lower])) {
            return $map[$lower];
        }

        foreach (['AIF', 'WWI', 'WWII', 'JP', 'M.B.E.', 'B.A.', 'SS', 'ACT'] as $abbr) {
            $lower = preg_replace('/\b' . preg_quote(mb_strtolower($abbr), '/') . '\b/i', $abbr, $lower) ?? $lower;
        }

        return $lower;
    }


    private function cleanNoteText(string $note): string
    {
        $note = html_entity_decode($note, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $note = preg_replace('/@[^@]+@/', '', $note) ?? $note;
        $note = preg_replace('/\b\d+\s+(?:CONT|CONC)\b/i', ' ', $note) ?? $note;
        $note = preg_replace('/\s*(?:CONT|CONC)\s*/i', ' ', $note) ?? $note;
        $note = preg_replace('/\b[2-9]\s+CONT\b/i', ' ', $note) ?? $note;
        $note = trim(strip_tags($note));
        $note = preg_replace('/\s+/', ' ', $note) ?? $note;
        $note = preg_replace('/^Extracts of the book [^:]+:\s*/i', '', $note) ?? $note;
        $note = preg_replace('/^From the book [^:]+-\s*/i', '', $note) ?? $note;
        $note = $this->commonTextCorrections($note);

        return trim($note);
    }


    private function commonTextCorrections(string $text): string
    {
        $text = str_replace(['AUstralia', 'Victoiria', 'Cemetary', 'Napolean'], ['Australia', 'Victoria', 'Cemetery', 'Napoleon'], $text);
        $text = preg_replace('/\bCooida\b/i', 'Cooinda', $text) ?? $text;
        $text = preg_replace('/\bCowinda\b/i', 'Cooinda', $text) ?? $text;
        $text = preg_replace('/\bHerbet Street\b/i', 'Herbert Street', $text) ?? $text;
        $text = preg_replace('/\bsyblings\b/i', 'siblings', $text) ?? $text;
        $text = preg_replace('/\bchidren\b/i', 'children', $text) ?? $text;
        $text = preg_replace('/\bpresbyterian\b/i', 'Presbyterian', $text) ?? $text;
        $text = preg_replace('/\b44 transport squadron army reserve\b/i', '44 Transport Squadron, Army Reserve', $text) ?? $text;
        $text = preg_replace('/\bundera football club\b/i', 'the Undera Football Club', $text) ?? $text;
        $text = preg_replace('/\bundera sports association\b/i', 'the Undera Sports Association', $text) ?? $text;
        $text = preg_replace('/\bstarrit and rankin\b/i', 'Starritt and Rankin', $text) ?? $text;

        return $text;
    }


    private function cleanSentence(string $sentence): string
    {
        $sentence = html_entity_decode($sentence, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sentence = preg_replace('/\s+/', ' ', $sentence) ?? $sentence;
        $sentence = $this->commonTextCorrections($sentence);
        $sentence = str_replace([' ,', ' .', ',.', ',,'], [',', '.', '.', ','], $sentence);

        if (preg_match('/\bmarried an unknown spouse\b/i', $sentence)) {
            return '';
        }

        $sentence = preg_replace('/\byou was\b/i', 'you were', $sentence) ?? $sentence;
        $sentence = preg_replace('/\ba home duties\b/i', 'home duties', $sentence) ?? $sentence;
        $sentence = preg_replace('/\ba at home\b/i', 'at home', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bteacher ss\b/i', 'special education teacher', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bteacher at a special school at ([^.]+Special School)/i', 'teacher at $1', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bteacher at a special school in ([^.]+Special School)/i', 'teacher at $1', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bworked as a teacher at a special school\b/i', 'worked as a special education teacher', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bat wedding photo\b/i', 'at a wedding', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bat a wedding photo\b/i', 'at a wedding', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bwas connected with presented with medal from ior\b/i', 'was presented with a medal by the Independent Order of Rechabites', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bwith presented with medal from ior\b/i', 'with a medal presented by the Independent Order of Rechabites', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bconnected with even\b/i', 'mentioned in a family or newspaper record', $sentence) ?? $sentence;
        $sentence = preg_replace('/\btook part in community life through councillor\b/i', 'served as a councillor', $sentence) ?? $sentence;
        $sentence = preg_replace('/, His\b/', ', his', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bin Victoria, Australia\b/i', 'in Victoria', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bat Victoria, Australia\b/i', 'in Victoria', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bKnown as Mt Pleasant Creek\b/i', 'Mount Pleasant Creek', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bthe h\.\.\.$/i', 'the home...', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bCemetary\b/i', 'Cemetery', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bVictoiria\b/i', 'Victoria', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bNapolean\b/i', 'Napoleon', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bIit\b/', 'It', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bsyblings\b/i', 'siblings', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bchidren\b/i', 'children', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bhas past\b/i', 'had passed', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bworked as a home duties\b/i', 'was recorded as undertaking home duties', $sentence) ?? $sentence;
        $sentence = preg_replace('/\bwas recorded as undertaking at home\b/i', 'was recorded as undertaking home duties', $sentence) ?? $sentence;
        $sentence = preg_replace('/served with the 44 Transport Squadron, Army Reserve/i', 'served with 44 Transport Squadron, Army Reserve', $sentence) ?? $sentence;
        $sentence = preg_replace('/\s+([,.;:])/', '$1', $sentence) ?? $sentence;
        $sentence = preg_replace('/,\s*,+/', ', ', $sentence) ?? $sentence;

        return trim($sentence);
    }

    /**
     * @param array<string,string> $p
     */
    private function educationSentenceBody(string $value, string $place, array $p): string
    {
        $clean = $this->cleanFactValue($value);
        $clean = preg_replace('/,\s*Victoria,\s*Australia$/i', '', $clean) ?? $clean;
        $clean = preg_replace('/,\s*Australia$/i', '', $clean) ?? $clean;
        $lower = strtolower($clean);

        if ($clean === '') {
            return $p['subject'] . ' continued ' . $p['possessive'] . ' education' . $place . '.';
        }

        if (str_contains($lower, 'matriculated')) {
            return $p['subject'] . ' matriculated' . $place . '.';
        }

        if (preg_match('/^(grade|year|form)\s+.*merit certificate/i', $clean)) {
            return $p['subject'] . ' received a ' . $clean . $place . '.';
        }

        if (preg_match('/^(grade|year|form)\s+/i', $clean)) {
            return $p['subject'] . ' ' . $p['was'] . ' in ' . $clean . $place . '.';
        }

        if (preg_match('/\b(grammar|high school|college|school)\b/i', $clean) && !preg_match('/certificate|bachelor|diploma|training/i', $clean)) {
            return $p['subject'] . ' attended ' . $clean . $place . '.';
        }

        if (preg_match('/merit certificate/i', $clean)) {
            return $p['subject'] . ' received a Merit Certificate' . $place . '.';
        }

        if (preg_match('/bachelor of arts/i', $clean)) {
            return $p['subject'] . ' completed a Bachelor of Arts' . $place . '.';
        }

        if (preg_match('/diploma/i', $clean)) {
            return $p['subject'] . ' completed ' . $clean . $place . '.';
        }

        if (preg_match('/training/i', $clean)) {
            return $p['subject'] . ' undertook ' . $clean . $place . '.';
        }

        return $p['subject'] . ' continued ' . $p['possessive'] . ' education, including ' . $clean . $place . '.';
    }
    private function sentenceWithLead(string $lead, string $sentence): string
    {
        $sentence = trim($sentence);

        if ($sentence === '') {
            return '';
        }

        if ($lead === '') {
            return $this->cleanSentence(ucfirst($sentence));
        }

        return $this->cleanSentence($lead . ', ' . $sentence);
    }

    /**
     * @param array<string,string> $event
     */
    private function placePhrase(array $event): string
    {
        $raw_place = trim($event['place'] ?? '');
        $place = $this->narrativePlace($raw_place);

        return $place !== '' ? ' ' . $this->placePreposition($raw_place, $place) . ' ' . $place : '';
    }

    private function placePreposition(string $raw_place, string $display_place): string
    {
        $combined = mb_strtolower($raw_place . ' ' . $display_place);

        if (preg_match('/^\d+\b/', trim($raw_place)) === 1
            || preg_match('/\b(st|street|rd|road|ave|avenue|cres|crescent|dr|drive|lane|ln|court|ct|place|pl|terrace|tce|highway|hwy)\b/i', $raw_place) === 1
            || preg_match('/\b(hospital|cemetery|school|college|church|chapel|hostel|station|farm|home|cemetary)\b/i', $combined) === 1) {
            return 'at';
        }

        return 'in';
    }

    private function migrationPlacePhrase(array $event, string $tag): string
    {
        $place = $this->narrativePlace(trim($event['place'] ?? ''));

        if ($place === '') {
            return '';
        }

        if ($tag === 'EMIG') {
            $lower = mb_strtolower($place);

            if (in_array($lower, ['united kingdom', 'england', 'ireland', 'scotland', 'wales', 'northern ireland', 'australia'], true)) {
                return ($lower === 'united kingdom' ? ' the ' : ' ') . $place;
            }

            return ' from ' . $place;
        }

        return ' in ' . $place;
    }


    /**
     * @param array<string,string> $event
     */
    private function eventNoteSentence(array $event): string
    {
        $note = $this->cleanNoteText((string) ($event['note'] ?? ''));

        if ($note === '') {
            return '';
        }

        $note_lower = mb_strtolower(trim($note));

        if (in_array($note_lower, ['home duties', 'at home', 'mentions jason potts'], true)) {
            return '';
        }

        // Source-only notes do not add much to the story and often make the book read like a citation list.
        if (preg_match('/^https?:\/\//i', $note)) {
            return '';
        }

        if (preg_match('/^(?:Australia|International|FamilySearch|Ancestry).*?(?:Index|Record|Registration|Name:|Groom|Bride)/i', $note)) {
            return '';
        }

        if (preg_match('/^(?:Name|Groom\'s Name|Bride\'s Name|Gender|Record Type|Home in)[: ]/i', $note)) {
            return '';
        }

        if (mb_strlen($note) > 300) {
            $note = mb_substr($note, 0, 297) . '...';
        }

        return 'A family note says: ' . $note;
    }

    private function ageWhenPhrase(array $event, array $birth, array $p): string
    {
        $age = $this->ageAtEvent($event, $birth);

        if ($age === null) {
            return '';
        }

        return ', when ' . $p['subject'] . ' ' . $p['was'] . ' ' . $this->ageText($age, $event, $birth);
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $birth
     */
    private function agedPhrase(array $event, array $birth): string
    {
        $age = $this->ageAtEvent($event, $birth);

        if ($age === null) {
            return '';
        }

        return ', aged ' . $this->ageText($age, $event, $birth);
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $birth
     */
    private function parentAgeAtChildBirthPhrase(array $event, array $birth, array $p): string
    {
        $age = $this->ageAtEvent($event, $birth);

        if ($age === null) {
            return '';
        }

        return ', when ' . $p['subject'] . ' ' . $p['was'] . ' ' . $this->ageText($age, $event, $birth);
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $birth
     */
    private function eventAgePhrase(array $event, array $birth): string
    {
        $age = $this->ageAtEvent($event, $birth);

        if ($age === null) {
            return '';
        }

        return ', aged ' . $this->ageText($age, $event, $birth);
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $birth
     */
    private function adultAgePhrase(array $event, array $birth): string
    {
        $age = $this->ageAtEvent($event, $birth);

        if ($age === null || $age < 18) {
            return '';
        }

        return ', aged ' . $this->ageText($age, $event, $birth);
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $birth
     */
    private function youthAgePhrase(array $event, array $birth): string
    {
        $age = $this->ageAtEvent($event, $birth);

        if ($age === null || $age > 21) {
            return '';
        }

        return ', aged ' . $this->ageText($age, $event, $birth);
    }
    private function ageText(int $age, array $event, array $birth): string
    {
        $event_precision = $this->datePrecision($event['date'] ?? '');
        $birth_precision = $this->datePrecision($birth['date'] ?? '');
        $about = ($event_precision < 3 || $birth_precision < 3) ? 'about ' : '';

        return $about . $age;
    }

    /**
     * @param array<string,string> $event
     * @param array<string,string> $birth
     */
    private function ageAtEvent(array $event, array $birth): ?int
    {
        $event_parts = $this->dateParts($event['date'] ?? '');
        $birth_parts = $this->dateParts($birth['date'] ?? '');

        if ($event_parts['year'] === null || $birth_parts['year'] === null) {
            return null;
        }

        $age = (int) $event_parts['year'] - (int) $birth_parts['year'];

        if ($event_parts['month'] !== null && $event_parts['day'] !== null && $birth_parts['month'] !== null && $birth_parts['day'] !== null) {
            if (((int) $event_parts['month'] * 100 + (int) $event_parts['day']) < ((int) $birth_parts['month'] * 100 + (int) $birth_parts['day'])) {
                --$age;
            }
        }

        return $age >= 0 && $age < 130 ? $age : null;
    }

    /**
     * @return array<string,int|null>
     */
    private function dateParts(string $date): array
    {
        $date = strtoupper(trim($date));
        $date = preg_replace('/@#[A-Z0-9_]+@/', '', $date) ?? $date;
        $date = trim($date);

        if ($date === '' || $date === 'Y') {
            return ['year' => null, 'month' => null, 'day' => null];
        }

        if (preg_match('/^(?:ABT|ABOUT|EST|ESTIMATED|CAL|BEF|BEFORE|AFT|AFTER|FROM|TO)\s+(.+)$/', $date, $match)) {
            $date = trim($match[1]);
        }

        if (preg_match('/^BET\s+(.+)\s+AND\s+(.+)$/', $date, $match)) {
            $date = trim($match[1]);
        }

        $months = [
            'JAN' => 1, 'JANUARY' => 1, 'FEB' => 2, 'FEBRUARY' => 2, 'MAR' => 3, 'MARCH' => 3, 'APR' => 4, 'APRIL' => 4,
            'MAY' => 5, 'JUN' => 6, 'JUNE' => 6, 'JUL' => 7, 'JULY' => 7, 'AUG' => 8, 'AUGUST' => 8,
            'SEP' => 9, 'SEPTEMBER' => 9, 'OCT' => 10, 'OCTOBER' => 10, 'NOV' => 11, 'NOVEMBER' => 11, 'DEC' => 12, 'DECEMBER' => 12,
        ];

        if (preg_match('/^(\d{1,2})\s+([A-Z]{3,9})\s+(\d{3,4})$/', $date, $match)) {
            return ['year' => (int) $match[3], 'month' => $months[$match[2]] ?? null, 'day' => (int) $match[1]];
        }

        if (preg_match('/^([A-Z]{3,9})\s+(\d{3,4})$/', $date, $match)) {
            return ['year' => (int) $match[2], 'month' => $months[$match[1]] ?? null, 'day' => null];
        }

        if (preg_match('/(\d{3,4})/', $date, $match)) {
            return ['year' => (int) $match[1], 'month' => null, 'day' => null];
        }

        return ['year' => null, 'month' => null, 'day' => null];
    }

    private function datePrecision(string $date): int
    {
        $parts = $this->dateParts($date);

        if ($parts['year'] === null) {
            return 0;
        }

        if ($parts['month'] === null) {
            return 1;
        }

        if ($parts['day'] === null) {
            return 2;
        }

        return 3;
    }

    /**
     * @param array<string,string> $event
     */
    private function sortKeyForEvent(array $event, int $order): int
    {
        $parts = $this->dateParts($event['date'] ?? '');

        if ($parts['year'] === null) {
            return 90000000 + $order;
        }

        $year = (int) $parts['year'];
        $month = $parts['month'] !== null ? (int) $parts['month'] : 0;
        $day = $parts['day'] !== null ? (int) $parts['day'] : 0;

        return ($year * 10000) + ($month * 100) + $day;
    }

    private function dateLead(string $date): string
    {
        $date = trim($date);

        if ($date === '') {
            return '';
        }

        $upper = strtoupper($date);

        if (preg_match('/^(?:ABT|ABOUT)\s+(.+)$/i', $date, $match)) {
            return 'Around ' . $this->formatGedcomDate($match[1]);
        }

        if (preg_match('/^(?:EST|ESTIMATED)\s+(.+)$/i', $date, $match)) {
            return 'Around ' . $this->formatGedcomDate($match[1]);
        }

        if (preg_match('/^CAL\s+(.+)$/i', $date, $match)) {
            return 'Around ' . $this->formatGedcomDate($match[1]);
        }

        if (preg_match('/^(?:BEF|BEFORE)\s+(.+)$/i', $date, $match)) {
            return 'Before ' . $this->formatGedcomDate($match[1]);
        }

        if (preg_match('/^(?:AFT|AFTER)\s+(.+)$/i', $date, $match)) {
            return 'After ' . $this->formatGedcomDate($match[1]);
        }

        if (preg_match('/^FROM\s+(.+)\s+TO\s+(.+)$/i', $date, $match)) {
            return 'From ' . $this->formatGedcomDate($match[1]) . ' to ' . $this->formatGedcomDate($match[2]);
        }

        if (preg_match('/^FROM\s+(.+)$/i', $date, $match)) {
            return 'From ' . $this->formatGedcomDate($match[1]);
        }

        if (preg_match('/^TO\s+(.+)$/i', $date, $match)) {
            return 'Until ' . $this->formatGedcomDate($match[1]);
        }

        if (preg_match('/^BET\s+(.+)\s+AND\s+(.+)$/i', $date, $match)) {
            return 'Between ' . $this->formatGedcomDate($match[1]) . ' and ' . $this->formatGedcomDate($match[2]);
        }

        if ($this->datePrecision($upper) >= 3) {
            return 'On ' . $this->formatGedcomDate($date);
        }

        if ($this->datePrecision($upper) >= 1) {
            return 'In ' . $this->formatGedcomDate($date);
        }

        return '';
    }

    /**
     * @param array<string,string> $event
     */
    private function eventWhenWhere(array $event): string
    {
        $date_phrase = $this->datePhraseForNarrative($event['date'] ?? '');
        $place = $this->narrativePlace(trim($event['place'] ?? ''));
        $parts = [];

        if ($date_phrase !== '') {
            $parts[] = $date_phrase;
        }

        if ($place !== '') {
            $parts[] = $this->placePreposition(trim($event['place'] ?? ''), $place) . ' ' . $place;
        }

        return $parts !== [] ? ' ' . implode(' ', $parts) : '';
    }

    private function datePhraseForNarrative(string $date): string
    {
        $lead = $this->dateLead($date);

        if ($lead === '') {
            return '';
        }

        if (str_starts_with($lead, 'On ')) {
            return 'on ' . substr($lead, 3);
        }

        if (str_starts_with($lead, 'In ')) {
            return 'in ' . substr($lead, 3);
        }

        return lcfirst($lead);
    }

    private function shortPlace(string $place): string
    {
        return $this->narrativePlace($place);
    }


    private function narrativePlace(string $place): string
    {
        $place = trim(html_entity_decode($place, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $place = preg_replace('/\s+/', ' ', $place) ?? $place;
        $place = $this->commonTextCorrections($place);
        $place = preg_replace('/^move to\s+/i', '', $place) ?? $place;
        $place = preg_replace('/^\(?aka\s+([^)]*)\)?$/i', '$1', $place) ?? $place;
        $place = trim($place);

        if ($place === '') {
            return '';
        }

        $parts = array_values(array_filter(array_map(static function (string $part): string {
            $part = trim($part);

            // U+FFFD means the original GEDCOM/place text contains broken or truncated Unicode.
            // Drop the affected place segment rather than showing a replacement glyph in the book.
            if ($part === '' || str_contains($part, "\u{FFFD}")) {
                return '';
            }

            return $part;
        }, explode(',', $place)), static fn (string $part): bool => $part !== ''));

        $clean_parts = [];
        foreach ($parts as $part) {
            if ($clean_parts === [] || mb_strtolower(end($clean_parts)) !== mb_strtolower($part)) {
                $clean_parts[] = $part;
            }
        }
        $parts = $clean_parts;
        $count = count($parts);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $parts[0];
        }

        $country = $parts[$count - 1];
        $first = $parts[0];
        $is_address = preg_match('/^\d+\b/', $first) === 1
            || preg_match('/\b(st|street|rd|road|ave|avenue|cres|crescent|dr|drive|lane|ln|court|ct|place|pl|terrace|tce|highway|hwy)\b/i', $first) === 1;

        if (strcasecmp($country, 'Australia') === 0) {
            if ($count >= 3 && $is_address) {
                return $parts[0] . ', ' . $parts[1];
            }

            if ($count >= 3 && preg_match('/\b(hospital|cemetery|school|college|church|hostel|station|farm)\b/i', $first) === 1) {
                return $parts[0] . ', ' . $parts[1];
            }

            if ($count >= 2) {
                $locality = $parts[0];
                if (in_array(mb_strtolower($locality), ['victoria', 'tasmania', 'queensland', 'new south wales', 'australian capital territory', 'south australia', 'western australia', 'northern territory'], true)) {
                    return $locality;
                }

                return $locality;
            }
        }

        if (strcasecmp($country, 'USA') === 0 || strcasecmp($country, 'United States') === 0) {
            if ($count >= 3) {
                return $parts[0] . ', ' . $parts[$count - 2];
            }
        }

        if (in_array(mb_strtolower($country), ['england', 'ireland', 'scotland', 'wales', 'northern ireland'], true)) {
            if ($count >= 3 && preg_match('/\b(st|saint|church|chapel|parish)\b/i', $first) === 1) {
                return $parts[0] . ', ' . $parts[1];
            }

            if ($count >= 2) {
                return $parts[0] . ', ' . $country;
            }
        }

        if ($count > 3) {
            return $parts[0] . ', ' . $parts[$count - 2] . ', ' . $country;
        }

        return implode(', ', $parts);
    }


    /**
     * @return array<int,array<string,mixed>>
     */
    private function spouseFamilyProfiles(Individual $person, bool $include_children, int $distance, string $direct_child_xref, string $wording): array
    {
        $profiles = [];

        foreach ($person->spouseFamilies() as $family) {
            if (!$family instanceof Family || !$family->canShow()) {
                continue;
            }

            $spouse = $family->spouse($person);
            $children = [];

            if ($include_children) {
                foreach ($family->children() as $child) {
                    if ($child instanceof Individual && $child->canShowName()) {
                        $family_status = $this->childFamilyStatus($family, $child);
                        $adoption = $family_status === 'adopted' ? $this->childAdoptionEvent($child, $family) : [];

                        $children[] = [
                            'xref' => $child->xref(),
                            'name' => strip_tags($child->fullName()),
                            'role' => $this->childRole($child),
                            'family_status' => $family_status,
                            'relationship' => $this->childRelationshipLabel($child, $distance, $direct_child_xref, $wording),
                            'birth' => $this->firstEvent($child, 'BIRT'),
                            'adoption' => $adoption,
                        ];
                    }
                }
            }

            $profiles[] = [
                'spouse_name' => $spouse instanceof Individual && $spouse->canShowName() ? strip_tags($spouse->fullName()) : I18N::translate('an unknown spouse'),
                'marriage' => $this->firstEvent($family, 'MARR'),
                'children' => $children,
            ];
        }

        return $profiles;
    }

    private function childRole(Individual $child): string
    {
        return match ($child->sex()) {
            'M' => I18N::translate('son'),
            'F' => I18N::translate('daughter'),
            default => I18N::translate('child'),
        };
    }

    private function childRoleWithFamilyStatus(string $role, string $status): string
    {
        $role = trim($role);

        if ($role === '') {
            $role = I18N::translate('child');
        }

        if ($status === 'adopted') {
            return 'adopted ' . $role;
        }

        if ($status === 'foster') {
            return 'foster ' . $role;
        }

        return $role;
    }

    private function childFamilyStatus(Family $family, Individual $child): string
    {
        $child_xref = preg_quote($child->xref(), '/');
        $family_gedcom = method_exists($family, 'gedcom') ? (string) $family->gedcom() : '';

        if (preg_match('/^1\s+CHIL\s+@' . $child_xref . '@(?:\R[2-9]\s+[^\r\n]*)*/mi', $family_gedcom, $match)) {
            $block = mb_strtolower($match[0]);

            if (preg_match('/\bPEDI\s+(?:adopted|adoptive|adop)\b/i', $block)) {
                return 'adopted';
            }

            if (preg_match('/\bPEDI\s+foster\b/i', $block)) {
                return 'foster';
            }
        }

        $child_gedcom = method_exists($child, 'gedcom') ? (string) $child->gedcom() : '';
        $family_xref = method_exists($family, 'xref') ? preg_quote((string) $family->xref(), '/') : '';

        if ($family_xref !== '' && preg_match('/^1\s+FAMC\s+@' . $family_xref . '@(?:\R[2-9]\s+[^\r\n]*)*/mi', $child_gedcom, $match)) {
            $block = mb_strtolower($match[0]);

            if (preg_match('/\bPEDI\s+(?:adopted|adoptive|adop)\b/i', $block)) {
                return 'adopted';
            }

            if (preg_match('/\bPEDI\s+foster\b/i', $block)) {
                return 'foster';
            }
        }

        if ($family_xref !== '' && preg_match_all('/^1\s+ADOP(?:\s+[^\r\n]*)?(?:\R[2-9]\s+[^\r\n]*)*/mi', $child_gedcom, $matches)) {
            foreach ($matches[0] as $block) {
                if (preg_match('/\bFAMC\s+@' . $family_xref . '@/i', $block)) {
                    return 'adopted';
                }
            }
        }

        $evidence = mb_strtolower($this->childCareEvidenceText($child));

        if (preg_match('/\bnot\s+officially\s+adopted\b|\bnot\s+legally\s+adopted\b|\bnot\s+adopted\b/i', $evidence)) {
            return 'raised';
        }

        if (preg_match('/\b(brought\s+up|raised|reared|cared\s+for)\s+by\b/i', $evidence)) {
            return 'raised';
        }

        if (preg_match('/\badopted\b|\badoption\b/i', $evidence)) {
            return 'adopted';
        }

        if (preg_match('/\bfoster(?:ed)?\b/i', $evidence)) {
            return 'foster';
        }

        return '';
    }

    /**
     * @return array<string,string>
     */
    private function childAdoptionEvent(Individual $child, Family $family): array
    {
        $family_xref = method_exists($family, 'xref') ? (string) $family->xref() : '';
        $events = $this->events($child, ['ADOP']);

        if ($events !== []) {
            if ($family_xref !== '' && method_exists($child, 'gedcom')) {
                $child_gedcom = (string) $child->gedcom();

                if (preg_match_all('/^1\s+ADOP(?:\s+[^\r\n]*)?(?:\R[2-9]\s+[^\r\n]*)*/mi', $child_gedcom, $matches)) {
                    foreach ($matches[0] as $index => $block) {
                        if (preg_match('/\bFAMC\s+@' . preg_quote($family_xref, '/') . '@/i', $block)) {
                            return $events[$index] ?? $events[0];
                        }
                    }
                }
            }

            return $events[0];
        }

        $date = $this->adoptionDateFromText($this->childCareEvidenceText($child));

        if ($date !== '') {
            return [
                'tag' => 'ADOP',
                'value' => '',
                'date' => $date,
                'place' => '',
                'note' => '',
                'media_refs' => [],
            ];
        }

        return [];
    }

    private function childCareEvidenceText(Individual $child): string
    {
        $parts = [];

        if (method_exists($child, 'gedcom')) {
            $parts[] = (string) $child->gedcom();
        }

        foreach ($this->events($child, ['BIRT', 'ADOP', 'EVEN', 'FACT']) as $event) {
            $parts[] = (string) ($event['value'] ?? '');
            $parts[] = (string) ($event['note'] ?? '');
        }

        foreach ($this->notes($child) as $note) {
            $parts[] = $note;
        }

        return implode(' ', $parts);
    }

    private function adoptionDateFromText(string $text): string
    {
        $normalised = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (preg_match('/\badopted\s+(?:on\s+)?([0-9]{1,2}\s+[A-Za-z.]+\s+[0-9]{4})\b/i', $normalised, $match)) {
            return strtoupper(str_replace('.', '', $match[1]));
        }

        if (preg_match('/\badopted\s+(?:in\s+)?([A-Za-z.]+\s+[0-9]{4})\b/i', $normalised, $match)) {
            return strtoupper(str_replace('.', '', $match[1]));
        }

        if (preg_match('/\badopted\s+(?:in\s+)?([0-9]{4})\b/i', $normalised, $match)) {
            return $match[1];
        }

        return '';
    }

    private function childRelationshipLabel(Individual $child, int $parent_distance, string $direct_child_xref, string $wording): string
    {
        $prefix = $wording === 'neutral' ? 'the selected person’s' : 'your';

        if ($parent_distance === 0) {
            return $wording === 'neutral' ? 'the selected person’s child' : 'your child';
        }

        if ($direct_child_xref !== '' && $child->xref() === $direct_child_xref) {
            return $this->relationshipLabel(max(0, $parent_distance - 1), $child->sex(), $wording);
        }

        if ($parent_distance === 1) {
            return match ($child->sex()) {
                'M' => $prefix . ' brother',
                'F' => $prefix . ' sister',
                default => $prefix . ' sibling',
            };
        }

        if ($parent_distance === 2) {
            return match ($child->sex()) {
                'M' => $prefix . ' uncle',
                'F' => $prefix . ' aunt',
                default => $prefix . ' aunt or uncle',
            };
        }

        $great_count = $parent_distance - 2;
        $great = $great_count === 1 ? 'great-' : $great_count . 'x great-';

        return match ($child->sex()) {
            'M' => $prefix . ' ' . $great . 'uncle',
            'F' => $prefix . ' ' . $great . 'aunt',
            default => $prefix . ' ' . $great . 'aunt or uncle',
        };
    }

    /**
     * @return array<string,string>
     */
    private function firstEvent(object $record, string $tag): array
    {
        $events = $this->events($record, [$tag], 1);
        return $events[0] ?? [];
    }

    /**
     * @param array<int,string> $tags
     * @return array<int,array<string,string>>
     */
    private function events(object $record, array $tags, int $limit = 0): array
    {
        if (!method_exists($record, 'gedcom')) {
            return [];
        }

        $gedcom = (string) $record->gedcom();
        $lines = preg_split('/\R/', $gedcom) ?: [];
        $events = [];
        $tag_pattern = implode('|', array_map(static fn (string $tag): string => preg_quote($tag, '/'), $tags));
        $line_count = count($lines);

        for ($i = 0; $i < $line_count; ++$i) {
            $line = $lines[$i];

            if (!preg_match('/^1\s+(' . $tag_pattern . ')(?:\s+(.*))?$/', $line, $match)) {
                continue;
            }

            $event = [
                'tag' => $match[1],
                'value' => trim((string) ($match[2] ?? '')),
                'date' => '',
                'place' => '',
                'note' => '',
                'media_refs' => [],
            ];

            for ($j = $i + 1; $j < $line_count; ++$j) {
                $subline = $lines[$j];

                if (preg_match('/^1\s+/', $subline)) {
                    break;
                }

                if (preg_match('/^2\s+DATE\s+(.+)$/', $subline, $sub)) {
                    $event['date'] = trim($sub[1]);
                } elseif (preg_match('/^2\s+PLAC\s+(.+)$/', $subline, $sub)) {
                    $event['place'] = trim($sub[1]);
                } elseif (preg_match('/^2\s+ADDR\s+(.+)$/', $subline, $sub)) {
                    $event['place'] = trim($sub[1]);
                } elseif (preg_match('/^2\s+NOTE\s+(.+)$/', $subline, $sub)) {
                    $event['note'] = trim($sub[1]);
                } elseif (preg_match('/^2\s+OBJE\s+@([^@]+)@/', $subline, $sub)) {
                    $event['media_refs'][] = strtoupper(trim($sub[1]));
                } elseif (preg_match('/^3\s+(?:CONT|CONC)\s*(.*)$/', $subline, $sub)) {
                    $event['note'] = trim((string) $event['note'] . ' ' . $sub[1]);
                }
            }

            $events[] = $event;

            if ($limit > 0 && count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    /**
     * @return array<int,string>
     */
    private function notes(object $record): array
    {
        if (!method_exists($record, 'gedcom')) {
            return [];
        }

        $gedcom = (string) $record->gedcom();
        $notes = [];
        $seen = [];

        if (preg_match_all('/\n1 NOTE\s+([^\n]+)((?:\n[2-9]\s+(?:CONT|CONC)\s*[^\n]*)*)/', $gedcom, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = $match[1] . ' ' . preg_replace('/\n[2-9]\s+(?:CONT|CONC)\s*/', ' ', $match[2] ?? '');
                $text = $this->cleanNoteText($text);

                if ($text === '') {
                    continue;
                }

                if (preg_match('/^(?:Australia|International|FamilySearch|Ancestry).*?(?:Index|Record|Registration|Name:|Groom|Bride)/i', $text)) {
                    continue;
                }

                $key = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', mb_substr($text, 0, 500)) ?? $text);
                if ($key !== '' && isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                if (strlen($text) > 1000) {
                    $text = substr($text, 0, 997) . '...';
                }

                $notes[] = $text;
            }
        }

        return $notes;
    }

    private function relationshipLabel(int $distance, string $sex, string $wording): string
    {
        $prefix = $wording === 'neutral' ? 'the selected person’s' : 'your';

        if ($distance === 0) {
            return $wording === 'neutral' ? 'the selected individual' : 'you';
        }

        $gendered = match ($sex) {
            'M' => ['father', 'grandfather', 'great-grandfather'],
            'F' => ['mother', 'grandmother', 'great-grandmother'],
            default => ['parent', 'grandparent', 'great-grandparent'],
        };

        if ($distance === 1) {
            return $prefix . ' ' . $gendered[0];
        }

        if ($distance === 2) {
            return $prefix . ' ' . $gendered[1];
        }

        if ($distance === 3) {
            return $prefix . ' ' . $gendered[2];
        }

        return $prefix . ' ' . ($distance - 2) . 'x ' . $gendered[2];
    }

    private function parentPathWord(Individual $person): string
    {
        return match ($person->sex()) {
            'M' => I18N::translate('father'),
            'F' => I18N::translate('mother'),
            default => I18N::translate('parent'),
        };
    }

    /**
     * @return array<string,string>
     */
    private function pronouns(string $sex, bool $is_self = false): array
    {
        if ($is_self) {
            return [
                'subject' => 'you',
                'subject_cap' => 'You',
                'object' => 'you',
                'possessive' => 'your',
                'possessive_cap' => 'Your',
                'is_self' => true,
                'was' => 'were',
            ];
        }

        return match ($sex) {
            'M' => ['subject' => 'he', 'subject_cap' => 'He', 'object' => 'him', 'possessive' => 'his', 'possessive_cap' => 'His', 'is_self' => false, 'was' => 'was'],
            'F' => ['subject' => 'she', 'subject_cap' => 'She', 'object' => 'her', 'possessive' => 'her', 'possessive_cap' => 'Her', 'is_self' => false, 'was' => 'was'],
            default => ['subject' => 'they', 'subject_cap' => 'They', 'object' => 'them', 'possessive' => 'their', 'possessive_cap' => 'Their', 'is_self' => false, 'was' => 'were'],
        };
    }

    /**
     * @param array<string,string> $event
     */
    private function residencePlacePhrase(array $event): string
    {
        $raw_place = trim($event['place'] ?? '');
        $place = $this->narrativePlace($raw_place);

        if ($place === '') {
            return '';
        }

        $is_address = preg_match('/^\d+\b/', $raw_place) === 1
            || preg_match('/\b(st|street|rd|road|ave|avenue|cres|crescent|dr|drive|lane|ln|court|ct|place|pl|terrace|tce|highway|hwy)\b/i', $raw_place) === 1;

        return ($is_address ? ' at ' : ' in ') . $place;
    }


    private function cleanFactValue(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function plainLower(string $value): string
    {
        $value = $this->cleanFactValue($value);

        return mb_strtolower($value);
    }

    private function occupationText(string $value): string
    {
        $value = $this->plainLower($value);

        if ($value === '' || in_array($value, ['working', 'occupation'], true)) {
            return '';
        }

        $value = preg_replace('/\bteacher ss\b/i', 'special education teacher', $value) ?? $value;
        $value = preg_replace('/\bteacher \(special\)\b/i', 'special education teacher', $value) ?? $value;
        $value = preg_replace('/\bphotography and temp teacher\b/i', 'photographer and temporary teacher', $value) ?? $value;
        $value = preg_replace('/\bhead teacher school ss\s*(\d+)\b/i', 'head teacher at School $1', $value) ?? $value;
        $value = preg_replace('/\bteacher school ss\s*(\d+)\b/i', 'teacher at School $1', $value) ?? $value;
        $value = preg_replace('/\bhead teacher school\s+(\d+)\b/i', 'head teacher at School $1', $value) ?? $value;
        $value = preg_replace('/\bteacher school\s+(\d+)\b/i', 'teacher at School $1', $value) ?? $value;
        $value = preg_replace('/\bhead teacher of school\s+(\d+)\s+(.+)$/i', 'head teacher of School $1 at $2', $value) ?? $value;
        $value = preg_replace('/\bknown as mt pleasant creek\b/i', 'Mount Pleasant Creek', $value) ?? $value;

        $proper = [
            'royal australian flying corps' => 'Royal Australian Flying Corps',
            'australian imperial force' => 'Australian Imperial Force',
            'melbourne high school' => 'Melbourne High School',
            'tally ho boys home' => 'Tally Ho Boys Home',
        ];

        foreach ($proper as $needle => $replacement) {
            $value = preg_replace('/\b' . preg_quote($needle, '/') . '\b/i', $replacement, $value) ?? $value;
        }

        if (in_array($value, ['home duties', 'at home'], true)) {
            return 'home duties';
        }

        $value = preg_replace('/\bmatron of students residence\b/i', 'matron of a student residence', $value) ?? $value;
        $value = preg_replace('/\bplanning coordinator water industry\b/i', 'planning coordinator in the water industry', $value) ?? $value;
        $value = preg_replace('/\bmanager assets and infrastructure\b/i', 'Manager Assets and Infrastructure', $value) ?? $value;

        if (preg_match('/^(a|an|the)\s+/i', $value)) {
            return $value;
        }

        $article = preg_match('/^[aeiou]/i', $value) ? 'an ' : 'a ';

        return $article . $value;
    }

    private function labelForTag(string $tag): string
    {
        return match ($tag) {
            'BIRT' => I18N::translate('birth'),
            'CHR', 'BAPM' => I18N::translate('christening'),
            'DEAT' => I18N::translate('death'),
            'BURI' => I18N::translate('burial'),
            'CREM' => I18N::translate('cremation'),
            'OCCU' => I18N::translate('occupation'),
            'RESI' => I18N::translate('residence'),
            'CENS' => I18N::translate('census'),
            'EDUC' => I18N::translate('education'),
            'GRAD' => I18N::translate('graduation'),
            'IMMI' => I18N::translate('immigration'),
            'EMIG' => I18N::translate('emigration'),
            'NATU' => I18N::translate('naturalisation'),
            'MILI', '_MILI' => I18N::translate('military service'),
            'PROP' => I18N::translate('property'),
            'WILL' => I18N::translate('will'),
            'PROB' => I18N::translate('probate'),
            'RELI' => I18N::translate('religion'),
            default => $tag,
        };
    }

    private function formatGedcomDate(string $date): string
    {
        $date = trim($date);
        $date = preg_replace('/@#[A-Z0-9_]+@/', '', $date) ?? $date;
        $date = trim($date);

        if ($date === '') {
            return '';
        }

        $months = [
            'JAN' => 'January', 'FEB' => 'February', 'MAR' => 'March', 'APR' => 'April',
            'MAY' => 'May', 'JUN' => 'June', 'JUL' => 'July', 'AUG' => 'August',
            'SEP' => 'September', 'OCT' => 'October', 'NOV' => 'November', 'DEC' => 'December',
        ];

        $qualifiers = [
            'ABT' => 'about', 'ABOUT' => 'about', 'EST' => 'estimated', 'ESTIMATED' => 'estimated', 'CAL' => 'calculated',
            'BEF' => 'before', 'BEFORE' => 'before', 'AFT' => 'after', 'AFTER' => 'after', 'FROM' => 'from', 'TO' => 'to',
            'EARLY' => 'early', 'LATE' => 'late',
        ];

        if (preg_match('/^FROM\s+(.+)\s+TO\s+(.+)$/i', $date, $match)) {
            return 'from ' . $this->formatGedcomDate($match[1]) . ' to ' . $this->formatGedcomDate($match[2]);
        }

        foreach ($qualifiers as $gedcom => $word) {
            if (preg_match('/^' . $gedcom . '\s+(.+)$/i', $date, $match)) {
                return $word . ' ' . $this->formatGedcomDate($match[1]);
            }
        }

        if (preg_match('/^BET\s+(.+)\s+AND\s+(.+)$/i', $date, $match)) {
            return 'between ' . $this->formatGedcomDate($match[1]) . ' and ' . $this->formatGedcomDate($match[2]);
        }

        foreach ($months as $short => $long) {
            $date = preg_replace('/\b' . $short . '\b/i', $long, $date) ?? $date;
        }

        foreach (['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'] as $month) {
            $date = preg_replace('/\b' . $month . '\b/i', ucfirst(strtolower($month)), $date) ?? $date;
        }

        $date = preg_replace('/\b0([1-9])\s+/', '$1 ', $date) ?? $date;

        return strtolower($date) === 'y' ? '' : $date;
    }
    /**
     * @param array<string,mixed> $event
     */
    private function eventMediaHtml(array $event, Individual $context, string $type): string
    {
        $refs = (array) ($event['media_refs'] ?? []);

        if ($refs === []) {
            return '';
        }

        if (!$this->shouldShowInlineMedia($event, $type)) {
            return '';
        }

        $tree = method_exists($context, 'tree') ? $context->tree() : null;

        if (!$tree instanceof Tree) {
            return '';
        }

        $html = [];

        foreach ($refs as $ref) {
            $media = $this->mediaRecord((string) $ref, $tree);

            if ($media === null || !method_exists($media, 'displayImage')) {
                continue;
            }

            $image = (string) $media->displayImage(115, 115, 'crop', ['class' => 'nab-inline-photo-img']);

            if ($image === '') {
                continue;
            }

            $title = method_exists($media, 'fullName') ? html_entity_decode(strip_tags((string) $media->fullName()), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $caption = $title !== '' ? '<figcaption class="nab-inline-photo-caption">' . e($title) . '</figcaption>' : '';
            $html[] = '<figure class="nab-inline-photo">' . $image . $caption . '</figure>';

            if (count($html) >= 2) {
                break;
            }
        }

        return implode('', $html);
    }

    /**
     * @param array<string,mixed> $event
     */
    private function shouldShowInlineMedia(array $event, string $type): bool
    {
        if ($type === 'life_event') {
            return true;
        }

        if (in_array($type, ['marriage', 'child_birth'], true)) {
            $value = strtolower($this->cleanFactValue((string) ($event['value'] ?? '')));
            $note = strtolower($this->cleanFactValue((string) ($event['note'] ?? '')));
            $combined = $value . ' ' . $note;

            return str_contains($combined, 'photo')
                || str_contains($combined, 'photograph')
                || str_contains($combined, 'portrait')
                || str_contains($combined, 'image');
        }

        return false;
    }

    private function mediaRecord(string $xref, Tree $tree): ?object
    {
        $xref = strtoupper(trim($xref));

        if ($xref === '') {
            return null;
        }

        $media = null;

        try {
            if (method_exists(Registry::class, 'mediaFactory')) {
                $media = Registry::mediaFactory()->make($xref, $tree);
            }
        } catch (Throwable $ex) {
            $media = null;
        }

        if ($media === null && class_exists('Fisharebest\Webtrees\Media')) {
            try {
                if (method_exists('Fisharebest\Webtrees\Media', 'getInstance')) {
                    /** @phpstan-ignore-next-line Compatibility with older webtrees releases. */
                    $media = Fisharebest\Webtrees\Media::getInstance($xref, $tree);
                }
            } catch (Throwable $ex) {
                $media = null;
            }
        }

        if (is_object($media)) {
            if (method_exists($media, 'canShow') && !$media->canShow()) {
                return null;
            }

            return $media;
        }

        return null;
    }

    private function downloadHtml(array $book, Tree $tree): ResponseInterface
    {
        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', strip_tags((string) $book['title'])) ?: 'ancestor-book';
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . e((string) $book['title']) . '</title>';
        $html .= '<style>body{font-family:Georgia,serif;line-height:1.55;max-width:900px;margin:40px auto;color:#222}.person{break-inside:avoid;margin:0 0 2rem}.photo{float:right;margin:0 0 1rem 1rem}.photo img{width:90px;height:90px;object-fit:cover;border-radius:4px}.nab-story-media-block{width:100%;margin:1rem 0;padding:.75rem;border:1px solid #ddd;border-radius:6px;background:#fafafa;break-inside:avoid}.nab-photo-grid{display:flex;flex-wrap:wrap;gap:.6rem;margin:.55rem 0 0}.nab-inline-photo{margin:0;width:115px;padding:.25rem;border:1px solid #ddd;border-radius:4px;background:#fff;vertical-align:top}.nab-inline-photo img{width:105px;height:105px;object-fit:cover;border-radius:4px;display:block}.nab-inline-photo-caption{display:block;font-size:.82rem;color:#666;line-height:1.2;margin-top:.25rem}.nab-media-text{margin:0 0 .55rem}h1,h2,h3{font-family:Arial,sans-serif}.meta{color:#666}.line{color:#666;font-size:.95rem}</style>';
        $html .= '</head><body>';
        $html .= '<h1>' . e((string) $book['title']) . '</h1><p class="meta">Generated from ' . e($tree->title()) . ' on ' . e((string) $book['created']) . '.</p>';
        $html .= '<p>This book follows ' . e(strip_tags((string) $book['root_name'])) . ' across ' . e((string) $book['generations']) . ' generations and includes ' . e((string) $book['people_count']) . ' recorded people.</p>';

        if (!empty($book['introduction'])) {
            $html .= '<h2>Introduction</h2>';
            foreach ((array) $book['introduction'] as $paragraph) {
                $html .= '<p>' . e((string) $paragraph) . '</p>';
            }
        }

        foreach ((array) $book['by_generation'] as $generation => $profiles) {
            $generation_label = (string) (($book['generation_labels'][$generation] ?? '') ?: ('Generation ' . (string) $generation));
            $html .= '<h2>' . e($generation_label) . '</h2>';
            foreach ((array) $profiles as $profile) {
                $html .= '<section class="person">';
                if ((string) $profile['photo_html'] !== '') {
                    $html .= '<div class="photo">' . $profile['photo_html'] . '</div>';
                }
                $html .= '<h3>' . e(strip_tags((string) $profile['name'])) . '</h3>';
                $html .= '<p class="meta">Relationship: ' . e((string) $profile['relationship']) . '</p>';
                if ((string) $profile['line'] !== '') {
                    $html .= '<p class="line">Line: ' . e((string) $profile['line']) . '</p>';
                }
                foreach ((array) $profile['paragraphs'] as $paragraph) {
                    if (is_array($paragraph)) {
                        $media_html = (string) ($paragraph['media_html'] ?? '');
                        $text = e((string) ($paragraph['text'] ?? ''));

                        if ($media_html !== '') {
                            $html .= '<div class="nab-story-media-block"><p class="nab-media-text">' . $text . '</p><div class="nab-photo-grid">' . $media_html . '</div></div>';
                        } else {
                            $html .= '<p>' . $text . '</p>';
                        }
                    } else {
                        $html .= '<p>' . e((string) $paragraph) . '</p>';
                    }
                }
                foreach ((array) $profile['notes'] as $note) {
                    $html .= '<p><strong>Research note:</strong> ' . e($note) . '</p>';
                }
                $html .= '</section>';
            }
        }

        if (!empty($book['conclusion'])) {
            $html .= '<h2>Conclusion</h2>';
            foreach ((array) $book['conclusion'] as $paragraph) {
                $html .= '<p>' . e((string) $paragraph) . '</p>';
            }
        }

        $html .= '</body></html>';

        return response($html)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '.html"');
    }
};
